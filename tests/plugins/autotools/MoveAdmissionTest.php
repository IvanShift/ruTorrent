<?php
require_once dirname(__DIR__, 2) . '/plugins/rutracker_check/TestLib.php';

class FileUtil
{
    public static $messages = array();
    public static function toLog($message) { self::$messages[] = $message; }
}

eval(loadClassDefinition(dirname(__DIR__, 3) . '/php/Torrent.php', 'Torrent'));

eval(loadFunctionDefinition(dirname(__DIR__, 3) . '/php/xmlrpc.php',
    'rpcMethodCapability'));
eval(loadClassDefinition(dirname(__DIR__, 3) . '/plugins/autotools/autotools.php',
    'rAutoTools'));
eval(loadClassDefinition(dirname(__DIR__, 3) . '/plugins/autotools/move_tx_lib.php',
    'AutoToolsMoveTransaction'));

function moveAdmissionFixture()
{
    $root = (getenv('TMPDIR') ?: sys_get_temp_dir()) . '/move-admission-' . bin2hex(random_bytes(6));
    mkdir($root, 0700);
    mkdir($root . '/session', 0700);
    mkdir($root . '/source', 0700);
    file_put_contents($root . '/source/payload', 'original');
    rTorrentSettings::get()->session = $root . '/session';
    return $root;
}

function removeMoveAdmissionFixture($path)
{
    if (!is_dir($path) || is_link($path)) return @unlink($path);
    foreach (scandir($path) as $name)
        if ($name !== '.' && $name !== '..') removeMoveAdmissionFixture($path . '/' . $name);
    return rmdir($path);
}

$suite = new StrictTestSuite();
$hash = str_repeat('A', 40);
$complete = array('system.listMethods', 'd.stop_close_claim_state', 'd.stop_close_claim',
    'd.replay_stop_close_claim', 'd.ack_stop_close_claim',
    'd.directory.set_if_stop_close_claim', 'd.start_if_stop_close_claim');

$suite->test('stock daemon missing one claim method refuses before a new Move receipt',
    function () use ($hash, $complete) {
        $root = moveAdmissionFixture();
        try {
            rXMLRPCRequest::reset(); FileUtil::$messages = array();
            rXMLRPCRequest::queue('system.listMethods', true, false,
                array_values(array_diff($complete, array('d.directory.set_if_stop_close_claim'))));
            AutoToolsMoveTransaction::run(array($hash));
            strictAssertSame(array('system.listMethods'), array_column(rXMLRPCRequest::$requests, 'key'),
                'only read-only capability RPC may run');
            strictAssertSame('original', file_get_contents($root . '/source/payload'),
                'source remains unchanged');
            strictAssertSame(false, is_dir($root . '/finished'), 'no destination is created');
            strictAssertSame(array(), glob($root . '/session/.autotools-file-jobs/*.move.json'),
                'no new Move receipt is published');
            strictAssertSame('autotools: move ' . $hash . ' refused: daemon-claim-abi-unsupported',
                end(FileUtil::$messages), 'missing method is a visible classified refusal');
        } finally { rXMLRPCRequest::reset(); removeMoveAdmissionFixture($root); }
    });

$suite->test('transport failure leaves Move capability unknown and publishes no receipt',
    function () use ($hash) {
        $root = moveAdmissionFixture();
        try {
            rXMLRPCRequest::reset(); FileUtil::$messages = array();
            rXMLRPCRequest::queue('system.listMethods', false, false);
            AutoToolsMoveTransaction::run(array($hash));
            strictAssertSame(array('system.listMethods'), array_column(rXMLRPCRequest::$requests, 'key'),
                'transport failure does not initiate a claim');
            strictAssertSame(array(), glob($root . '/session/.autotools-file-jobs/*.move.json'),
                'unknown capability creates no Move receipt');
            strictAssertSame('autotools: move ' . $hash . ' refused: daemon-claim-abi-unconfirmed',
                end(FileUtil::$messages), 'transport failure is not classified as unsupported');
        } finally { rXMLRPCRequest::reset(); removeMoveAdmissionFixture($root); }
    });

$suite->test('complete claim command set remains eligible for the current daemon',
    function () use ($complete) {
        rXMLRPCRequest::reset();
        try {
            rXMLRPCRequest::queue('system.listMethods', true, false,
                array_merge(array('d.hash'), $complete));
            strictAssertSame('available', rAutoTools::claimAbiStatus(),
                'all Move claim commands permit admission');
            strictAssertSame(array('system.listMethods'), array_column(rXMLRPCRequest::$requests, 'key'),
                'capability check is read-only');
        } finally { rXMLRPCRequest::reset(); }
    });

$suite->test('RPC fault is unknown rather than a missing-method verdict',
    function () {
        rXMLRPCRequest::reset();
        try {
            rXMLRPCRequest::queue('system.listMethods', true, true, array('-501'), 'Temporary fault');
            strictAssertSame('unconfirmed', rAutoTools::claimAbiStatus(),
                'fault does not identify unsupported claim methods');
        } finally { rXMLRPCRequest::reset(); }
    });

$suite->test('pending Move receipt is held rather than erased when claim state is unknown',
    function () use ($hash) {
        $root = moveAdmissionFixture();
        try {
            mkdir($root . '/session/.autotools-file-jobs', 0700);
            $path = $root . '/session/.autotools-file-jobs/' . $hash . '.move.json';
            $job = array('hash'=>$hash, 'src'=>$root . '/source/payload',
                'dst'=>$root . '/finished/payload', 'identity'=>'1:1',
                'daemon_dir'=>$root . '/finished', 'phase'=>'intent', 'job_id'=>str_repeat('a', 32));
            file_put_contents($path, json_encode($job));
            rXMLRPCRequest::reset(); FileUtil::$messages = array();
            rXMLRPCRequest::queue('d.stop_close_claim_state', false, false);
            AutoToolsMoveTransaction::run(array($hash));
            strictAssertSame(array('d.stop_close_claim_state'), array_column(rXMLRPCRequest::$requests, 'key'),
                'existing receipt is reconciled before capability admission');
            strictAssertSame(json_encode($job), file_get_contents($path),
                'ambiguous pending receipt is preserved byte for byte');
            strictAssertSame('original', file_get_contents($root . '/source/payload'),
                'source remains unchanged while recovery is held');
            strictAssertSame(true, strpos(end(FileUtil::$messages), 'hold: rpc-d.stop_close_claim_state-unconfirmed') !== false,
                'recovery hold is visible');
        } finally { rXMLRPCRequest::reset(); removeMoveAdmissionFixture($root); }
    });

$suite->test('unsupported daemon clears only the unjournaled marker from its old hook',
    function () use ($hash) {
        $root = moveAdmissionFixture();
        $marker = str_repeat('a', 32);
        try {
            $sidecar = $root . '/session/' . $hash . '.torrent.rtorrent';
            file_put_contents($sidecar, 'd6:customd20:x-autotools-move-job32:' . $marker . 'ee');
            rXMLRPCRequest::reset(); FileUtil::$messages = array();
            rXMLRPCRequest::queue('system.listMethods', true, false, array('system.listMethods'));
            rXMLRPCRequest::queue('branch', true, false, array('0'));
            rXMLRPCRequest::queue(getCmd('d.save_full_session'), true, false,
                function ($commands) use ($sidecar) {
                    file_put_contents($sidecar, 'd6:customd20:x-autotools-move-job0:ee');
                    return array('0');
                });
            rXMLRPCRequest::queue(getCmd('d.get_custom'), true, false, array(''));
            AutoToolsMoveTransaction::run(array($hash, '', '', '', '', '', '', $marker));
            strictAssertSame(array('system.listMethods', 'branch', getCmd('d.save_full_session'),
                getCmd('d.get_custom')),
                array_column(rXMLRPCRequest::$requests, 'key'),
                'old hook marker is conditionally cleared before refusal');
            $branch = rXMLRPCRequest::requestsFor('branch')[0]['commands'][0]->params;
            strictAssertSame($hash, $branch[0], 'clear targets the same torrent');
            strictAssertSame(true, strpos($branch[1], $marker) !== false,
                'clear checks the exact worker token');
            strictAssertSame(true, strpos($branch[2], 'x-autotools-move-job') !== false,
                'true arm clears only the Move marker');
            strictAssertSame(array(), glob($root . '/session/.autotools-file-jobs/*.move.json'),
                'refused Move does not publish a journal');
            $saved = Torrent::fromRawBytes(file_get_contents($sidecar));
            strictAssertSame('', $saved->meta('custom')['x-autotools-move-job'],
                'old hook marker is absent from persisted session as well');
            strictAssertSame('autotools: move ' . $hash
                . ' refused: daemon-claim-abi-unsupported; old-hook-marker-cleared',
                end(FileUtil::$messages), 'cleanup is visible');
        } finally { rXMLRPCRequest::reset(); removeMoveAdmissionFixture($root); }
    });

$suite->test('unsupported daemon preserves a marker changed after the old hook',
    function () use ($hash) {
        $root = moveAdmissionFixture();
        try {
            rXMLRPCRequest::reset(); FileUtil::$messages = array();
            rXMLRPCRequest::queue('system.listMethods', true, false, array('system.listMethods'));
            rXMLRPCRequest::queue('branch', true, false, array('SKIP'));
            AutoToolsMoveTransaction::run(array($hash, '', '', '', '', '', '', str_repeat('a', 32)));
            strictAssertSame(array('system.listMethods', 'branch'),
                array_column(rXMLRPCRequest::$requests, 'key'),
                'changed marker is not cleared or read as a successful cleanup');
            strictAssertSame('autotools: move ' . $hash
                . ' hold: daemon-claim-abi-unsupported; old-hook-marker-changed',
                end(FileUtil::$messages), 'concurrent marker change is visible');
        } finally { rXMLRPCRequest::reset(); removeMoveAdmissionFixture($root); }
    });

$suite->test('unsupported daemon holds when the old marker remains in the saved session',
    function () use ($hash) {
        $root = moveAdmissionFixture();
        $marker = str_repeat('b', 32);
        try {
            $sidecar = $root . '/session/' . $hash . '.torrent.rtorrent';
            file_put_contents($sidecar, 'd6:customd20:x-autotools-move-job32:' . $marker . 'ee');
            rXMLRPCRequest::reset(); FileUtil::$messages = array();
            rXMLRPCRequest::queue('system.listMethods', true, false, array('system.listMethods'));
            rXMLRPCRequest::queue('branch', true, false, array('0'));
            rXMLRPCRequest::queue(getCmd('d.save_full_session'), true, false, array('0'));
            rXMLRPCRequest::queue(getCmd('d.get_custom'), true, false, array(''));
            AutoToolsMoveTransaction::run(array($hash, '', '', '', '', '', '', $marker));
            strictAssertSame('autotools: move ' . $hash
                . ' hold: daemon-claim-abi-unsupported; old-hook-marker-unconfirmed',
                end(FileUtil::$messages), 'stale session value prevents a false cleanup verdict');
            strictAssertSame($marker, Torrent::fromRawBytes(file_get_contents($sidecar))
                ->meta('custom')['x-autotools-move-job'], 'sidecar is not rewritten by PHP');
        } finally { rXMLRPCRequest::reset(); removeMoveAdmissionFixture($root); }
    });

$suite->test('unsupported daemon holds a marker when the journal name is a nonfile entry',
    function () use ($hash) {
        $root = moveAdmissionFixture();
        try {
            $journal = $root . '/session/.autotools-file-jobs';
            mkdir($journal, 0700);
            mkdir($journal . '/' . $hash . '.move.json', 0700);
            rXMLRPCRequest::reset(); FileUtil::$messages = array();
            rXMLRPCRequest::queue('system.listMethods', true, false, array('system.listMethods'));
            AutoToolsMoveTransaction::run(array($hash, '', '', '', '', '', '',
                str_repeat('a', 32)));
            strictAssertSame(array('system.listMethods'),
                array_column(rXMLRPCRequest::$requests, 'key'),
                'ambiguous journal path prevents marker RPC');
            strictAssertSame('autotools: move ' . $hash
                . ' hold: daemon-claim-abi-unsupported; old-hook-marker-journal-present',
                end(FileUtil::$messages), 'ambiguous journal entry is visible');
        } finally { rXMLRPCRequest::reset(); removeMoveAdmissionFixture($root); }
    });

exit($suite->run());
