<?php

/**
 * Isolated real-daemon schedule regression. Invoke inside a disposable image:
 * php ScheduleDaemonDriver.php /private/lab /usr/local/bin/rtorrent
 * The lab must be empty and writable; no torrent or tracker is contacted.
 * The child calls back over SCGI before recording argv, proving execution
 * stays asynchronous. Run against current master and legacy 0.9.8 images.
 */
function scheduleDriverWire($base, $method, $args = array())
{
    $xml = '<?xml version="1.0"?><methodCall><methodName>' . $method . '</methodName><params>';
    foreach ($args as $arg)
        $xml .= '<param><value><string>' . htmlspecialchars($arg, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</string></value></param>';
    $xml .= '</params></methodCall>';
    $headers = "CONTENT_LENGTH\0" . strlen($xml) . "\0SCGI\0001\0";
    $socket = stream_socket_client('unix://' . $base . '/rpc.sock', $errno, $error, 2);
    if (!$socket) throw new RuntimeException('SCGI connect failed');
    stream_set_timeout($socket, 4);
    fwrite($socket, strlen($headers) . ':' . $headers . ',' . $xml);
    $reply = stream_get_contents($socket);
    $timedOut = stream_get_meta_data($socket)['timed_out'];
    fclose($socket);
    if ($timedOut || strpos($reply, '<fault>') !== false || strpos($reply, '<methodResponse>') === false)
        throw new RuntimeException('SCGI response failed');
    return $reply;
}

if (isset($argv[1]) && $argv[1] === '--record') {
    $reply = scheduleDriverWire($argv[2], 'system.client_version');
    file_put_contents($argv[2] . '/argv.json', json_encode(array($argv, $reply)));
    exit(0);
}

require_once(__DIR__ . '/TestLib.php');
require_once(testFindRepoRoot() . '/plugins/rutracker_check/launcher.php');
eval(loadClassDefinition(testFindRepoRoot() . '/php/rtorrent.php', 'rTorrent'));

$base = isset($argv[1]) ? $argv[1] : '';
$binary = isset($argv[2]) ? $argv[2] : '/usr/local/bin/rtorrent';
if ($base === '' || $base[0] !== '/' || !is_dir($base) || count(scandir($base)) !== 2
    || !RtorrentCommandArguments::isLiteral($base))
    throw new RuntimeException('provide an empty absolute private lab directory');
mkdir($base . '/session', 0700);
mkdir($base . '/data', 0700);
file_put_contents($base . '/rtorrent.rc', "system.daemon.set = true\n"
    . 'directory.default.set = ' . rTorrent::quoteCommandArg($base . '/data') . "\n"
    . 'session.path.set = ' . rTorrent::quoteCommandArg($base . '/session') . "\n"
    . 'network.scgi.open_local = ' . rTorrent::quoteCommandArg($base . '/rpc.sock') . "\n"
    . "dht.mode.set = disable\ntrackers.use_udp.set = no\n");
$proc = proc_open(array($binary, '-n', '-o', 'import=' . $base . '/rtorrent.rc'), array(
    0 => array('file', '/dev/null', 'r'),
    1 => array('file', $base . '/daemon.log', 'a'),
    2 => array('file', $base . '/daemon.log', 'a'),
), $pipes);
if (!is_resource($proc)) throw new RuntimeException('daemon could not start');
try {
    $deadline = microtime(true) + 6;
    while (!file_exists($base . '/rpc.sock') && microtime(true) < $deadline) usleep(20000);
    $version = scheduleDriverWire($base, 'system.client_version');
    $methods = scheduleDriverWire($base, 'system.listMethods');
    $capability = strpos($methods, '<string>execute.raw.bg</string>') !== false ? 'available' : 'unsupported';
    $legacy = strpos($version, '0.9.8') !== false;
    symlink(PHP_BINARY, $base . "/php'quote");
    $recorder = $base . "/update'probe.php";
    symlink(__FILE__, $recorder);
    $user = "a';touch " . $base . "/forbidden;'b\"\\,;{}";
    foreach (array($capability, 'unsupported') as $index => $mode) {
        @unlink($base . '/argv.json');
        $arguments = array('--record', $base, $user);
        $command = RuTrackerDetachedPhp::scheduleCommand($base . "/php'quote", $recorder, $arguments, $mode);
        $before = microtime(true);
        scheduleDriverWire($base, $legacy ? 'schedule2' : 'schedule',
            array('', 'checker-proof-' . $index, '1', '60', $command));
        $deadline = microtime(true) + 8;
        // The child creates the file before it writes it.
        while (!is_array($got = json_decode((string)@file_get_contents($base . '/argv.json'), true)) && microtime(true) < $deadline) {
            scheduleDriverWire($base, 'system.client_version');
            usleep(20000);
        }
        if (!is_array($got)) throw new RuntimeException('scheduled child did not complete: ' . file_get_contents($base . '/daemon.log'));
        if ($got[0] !== array_merge(array($recorder), $arguments)) throw new RuntimeException('argv changed');
        if (file_exists($base . '/forbidden')) throw new RuntimeException('shell side effect escaped');
        echo 'PASS mode=' . $mode . ' child_rpc=alive argv=exact elapsed=' . round(microtime(true) - $before, 3) . "\n";
        scheduleDriverWire($base, $legacy ? 'schedule_remove2' : 'schedule.remove', array('', 'checker-proof-' . $index));
    }
    echo 'VERSION ' . trim(preg_replace('/<[^>]*>/', '', substr($version, strpos($version, '<?xml')))) . "\n";
    echo 'BINARY ' . hash_file('sha256', $binary) . "\n";
} finally {
    proc_terminate($proc);
    proc_close($proc);
}
