<?php
// Read-only inventory of current Move work. A clear report does not freeze admission.
require_once dirname(__DIR__) . '/php/cli-only.php';
rutorrent_require_cli(__FILE__);
$user = '';
if (count($argv) === 3 && $argv[1] === '--user') $user = $argv[2];
elseif (count($argv) !== 1) {
    fwrite(STDERR, "Usage: autotools-move-readiness.php [--user USER]\n"); exit(2);
}
if (!preg_match('/^[a-z0-9_-]*$/D', $user)) exit(2);
define('RUTORRENT_SETTINGS_READ_ONLY', true);
$_SERVER['REMOTE_USER'] = $user;
chdir(dirname(__DIR__) . '/plugins/autotools');
require_once './move_job_lib.php';
try {
    $session = rtrim(rTorrentSettings::get()->session, '/');
    $root = $session . '/' . AutoToolsMoveJob::JOURNAL_DIR;
    if (!ErasedataFilesystemOps::stateDirectoryTrusted($session))
        throw new RuntimeException('session-untrusted: path=' . erasedataLogPath($session));
    foreach (array($root, $session . '/.rutorrent-stop-intents') as $directory)
        if (erasedataPathExists($directory) && !ErasedataFilesystemOps::stateDirectoryTrusted($directory))
            throw new RuntimeException('state-directory-untrusted: path=' . erasedataLogPath($directory));
    $journals = $unsupportedDocuments = array();
    if (is_dir($root)) foreach (array('', '.') as $prefix) {
        $paths = erasedataDirectoryMatches($root, $prefix, '.json');
        if ($paths === false) throw new RuntimeException('journal-scan-failed');
        foreach ($paths as $path) {
            // Trust precedes the known NonMove filename exemption.
            if (is_link($path) || !ErasedataFilesystemOps::payloadFileTrusted($path) || !is_readable($path))
                throw new RuntimeException('journal-document-untrusted: path=' . erasedataLogPath($path));
            if (substr($path, -10) === '.move.json') $journals[] = $path;
            elseif (!preg_match('/^[0-9a-f]{24}\.nonmove(?:-request)?\.json$/D', basename($path)))
                $unsupportedDocuments[] = $path;
        }
    }
    $hashes = array_column(AutoToolsMoveQueue::downloadRows(), 0);
    $unsupportedMetadata = array();
    $knownKeys = array('x-autotools-nonmove-job', 'x-autotools-nonmove-source',
        'x-autotools-nonmove-target', FileOperationOutcome::KEYS['autotools']);
    foreach ($hashes as $hash) {
        // This native getter takes no argument after H; a multicall column adds one.
        $request = new rXMLRPCRequest(new rXMLRPCCommand('d.custom.items', array($hash)));
        $request->important = false;
        if (!$request->success() || $request->fault || $request->responseTypes !== array('struct')
            || count($request->responseValues) !== 1 || !is_array($request->responseValues[0]))
            throw new RuntimeException('custom-map-unconfirmed: hash=' . $hash);
        foreach ($request->responseValues[0] as $key => $value)
            if (is_string($key) && strpos($key, 'x-autotools-') === 0
                && !in_array($key, $knownKeys, true) && $value !== '')
                $unsupportedMetadata[] = array('hash'=>$hash, 'key'=>$key);
    }
    $nativeRoot = $session . '/.rutorrent-stop-intents';
    $records = is_dir($nativeRoot) ? erasedataDirectoryMatches($nativeRoot, '', '.move') : array();
    if ($records === false) throw new RuntimeException('native-directory-unreadable');
    foreach ($records as $path) {
        $name = basename($path, '.move');
        if (!preg_match('/^[0-9A-Fa-f]{40}$/D', $name)
            || !ErasedataFilesystemOps::payloadFileTrusted($path))
            throw new RuntimeException('native-record-untrusted: path=' . erasedataLogPath($path));
        $hashes[] = strtoupper($name);
    }
    $native = array();
    foreach (array_unique($hashes) as $hash) {
        $state = AutoToolsMoveQueue::state($hash);
        if ($state['phase'] !== 'none') $native[] = array('hash'=>$hash, 'state'=>$state,
            'owner'=>AutoToolsMoveQueue::owner($hash, $state));
    }
    $clear = !$journals && !$native && !$records && !$unsupportedDocuments && !$unsupportedMetadata;
    $report = json_encode(array('clear'=>$clear, 'journalRoot'=>$root, 'journals'=>$journals,
        'native'=>$native, 'nativeRecords'=>$records, 'unsupportedDocuments'=>$unsupportedDocuments,
        'unsupportedMetadata'=>$unsupportedMetadata), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($report)) throw new RuntimeException('report-encoding-failed');
    echo $report . "\n";
    exit($clear ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, 'AutoTools Move inventory unconfirmed: ' . $e->getMessage() . "; no state changed\n"); exit(2);
}
