<?php
// Synthetic registered-hook probe for a disposable stock 0.9.8 image.
// Run setup on base app bytes, install the candidate worker without re-registering,
// then run event and verify; no live torrent or native source is touched.
$_SERVER['REMOTE_USER'] = 'torrent';
require_once '/rutorrent/app/php/rtorrent.php';
require_once '/rutorrent/app/plugins/autotools/autotools.php';
function labEncode($value) {
    if (is_int($value)) return 'i' . $value . 'e';
    if (is_string($value)) return strlen($value) . ':' . $value;
    if (array_keys($value) === range(0, count($value)-1))
        return 'l' . implode('', array_map('labEncode', $value)) . 'e';
    ksort($value, SORT_STRING);
    $result = 'd';
    foreach ($value as $key => $item) $result .= labEncode((string) $key) . labEncode($item);
    return $result . 'e';
}
function labRpc($method, $params = array()) {
    $request = new rXMLRPCRequest(new rXMLRPCCommand($method, $params));
    $request->important = false;
    return array('ok' => $request->success(), 'fault' => $request->fault,
        'val' => $request->val);
}
$action = $argv[1];
$mode = $argv[2];
$kind = strtolower($mode) . '-v14-' . ($argv[3] ?? 'normal');
$name = 'autotools-' . $kind . '.bin';
$content = 'payload-' . $kind;
$source = '/data/downloads/' . $name;
$destinationRoot = '/data/finished-v14';
$destination = $destinationRoot . '/' . $name;
$info = array('length' => strlen($content), 'name' => $name,
    'piece length' => 16384, 'pieces' => sha1($content, true));
$hash = strtoupper(sha1(labEncode($info)));
$session = rtrim(rTorrentSettings::get()->session, '/');
if ($action === 'setup') {
    @mkdir(dirname($source), 0775, true);
    @mkdir($destinationRoot, 0775, true);
    file_put_contents($source, $content);
    $torrent = labEncode(array('announce' => 'http://127.0.0.1:1/announce', 'info' => $info));
    $path = '/tmp/' . $name . '.torrent';
    file_put_contents($path, $torrent);
    $at = rAutoTools::load();
    $at->enable_move = 0;
    $at->setHandlers();
    $loaded = rTorrent::sendTorrent($path, true, false, dirname($source), 'lab', true, false);
    if ($loaded !== $hash) throw new RuntimeException('sendTorrent ' . var_export($loaded, true));
    file_put_contents($session . '/' . $hash . '.torrent', $torrent);
    $deadline = microtime(true) + 5;
    while ((int) (labRpc('d.complete', $hash)['val'][0] ?? 0) !== 1 && microtime(true) < $deadline)
        usleep(50000);
    if ((int) (labRpc('d.complete', $hash)['val'][0] ?? 0) !== 1)
        throw new RuntimeException('synthetic torrent did not become complete');
    $at->enable_move = 1;
    $at->path_to_finished = $destinationRoot;
    $at->fileop_type = $mode;
    $at->automove_filter = '/.*/';
    $at->store();
    if (!$at->setHandlers()) throw new RuntimeException('setHandlers failed');
    if (($argv[4] ?? '') === 'hold-recovery') {
        $remove = new rXMLRPCRequest(rTorrentSettings::get()->getRemoveScheduleCommand('autorecover'));
        if (!$remove->success()) throw new RuntimeException('cannot suspend autorecover in disposable lab');
    }
    $hook = labRpc('method.get', array('', 'event.download.finished'));
    $beforeMarker = labRpc('d.custom', array($hash, 'x-autotools-move-job'))['val'][0] ?? null;
    if (!$hook['ok'] || strpos(implode(' ', $hook['val']), 'x-autotools-move-job') === false
        || $beforeMarker !== '')
        throw new RuntimeException('base Move hook was not registered with a clean marker');
    echo json_encode(array('hash' => $hash, 'mode' => $mode,
        'hook' => $hook, 'marker_before' => $beforeMarker)), "\n";
} elseif ($action === 'event') {
    echo json_encode(array('hash' => $hash, 'event' => labRpc('event.download.finished', $hash))), "\n";
} elseif ($action === 'verify') {
    $marker = labRpc('d.custom', array($hash, 'x-autotools-move-job'))['val'][0] ?? null;
    $state = array();
    foreach (array('d.base_path', 'd.directory', 'd.state', 'd.is_open', 'd.is_active') as $method)
        $state[$method] = labRpc($method, $hash)['val'][0] ?? null;
    $sidecar = $session . '/' . $hash . '.torrent.rtorrent';
    $sidecarMarker = null;
    if (is_file($sidecar)) {
        require_once '/rutorrent/app/php/Torrent.php';
        $torrent = Torrent::fromRawBytes(file_get_contents($sidecar));
        $custom = $torrent->meta('custom');
        if ($torrent->errors() === false && is_array($custom))
            $sidecarMarker = $custom['x-autotools-move-job'] ?? null;
    }
    $checks = array(
        'sidecar-marker-absent' => $sidecarMarker === '',
        'source-unchanged' => is_file($source) && file_get_contents($source) === $content,
        'destination-absent' => @lstat($destination) === false,
        'daemon-unchanged' => $state === array('d.base_path' => $source,
            'd.directory' => dirname($source), 'd.state' => '1',
            'd.is_open' => '1', 'd.is_active' => '1'),
        'journal-absent' => !is_file($session . '/.autotools-file-jobs/' . $hash . '.move.json'),
        'move-marker-absent' => $marker === '',
    );
    echo json_encode(array('hash' => $hash, 'checks' => $checks, 'marker' => $marker, 'sidecar_marker' => $sidecarMarker,
        'daemon' => $state)), "\n";
    if (in_array(false, $checks, true)) exit(1);
} elseif ($action === 'state') {
    $values = array();
    foreach (array('d.base_path','d.directory','d.state','d.is_open','d.is_active','d.complete') as $method)
        $values[$method] = labRpc($method, $hash)['val'][0] ?? null;
    $values['x-dest'] = labRpc('d.custom', array($hash, 'x-dest'))['val'][0] ?? null;
    $values['x-autotools-move-job'] = labRpc('d.custom', array($hash, 'x-autotools-move-job'))['val'][0] ?? null;
    $journalRoot = $session . '/.autotools-file-jobs';
    $sidecar = $session . '/' . $hash . '.torrent.rtorrent';
    $diskXdest = null;
    if (is_file($sidecar)) {
        require_once '/rutorrent/app/php/Torrent.php';
        $torrent = Torrent::fromRawBytes(file_get_contents($sidecar));
        $custom = $torrent->meta('custom');
        if ($torrent->errors() === false && is_array($custom)) $diskXdest = $custom['x-dest'] ?? null;
    }
    echo json_encode(array('hash' => $hash, 'values' => $values,
        'source' => @lstat($source) !== false, 'destination' => @lstat($destination) !== false,
        'dest_link' => is_link($destination) ? readlink($destination) : null,
        'source_inode' => @fileinode($source) ?: null,
        'dest_inode' => @fileinode($destination) ?: null,
        'dest_content' => is_file($destination) ? @file_get_contents($destination) : null,
        'journal_move' => is_file($journalRoot . '/' . $hash . '.move.json'),
        'journal_nonmove' => array_map('basename', glob($journalRoot . '/*.nonmove.json') ?: array()),
        'sidecar_xdest' => $diskXdest)), "\n";
}
