<?php

// Clear retrackers recovery markers from a live rTorrent.
//
//   docker exec <container> php85 /tmp/retrackers-clear-markers.php          report only
//   docker exec <container> php85 /tmp/retrackers-clear-markers.php apply HASH FINGERPRINT
//      clear one exact, previously reviewed marker generation
//
// WHY THIS EXISTS, and when reaching for it is right.
//
// A recovery marker (d.custom=retrackers-recovery, with its -ack) lives in the
// session file and therefore outlives the daemon. The receipts that authorise
// clearing one live in rr.receipts.v1, which is a set of rTorrent method keys
// and exists only in the daemon's memory. So a daemon that dies mid-transaction
// leaves a marker no code can ever clear: RetrackersHandoffReleaseCallback's
// first condition is a receipt that is now gone.
//
// The plugin then refuses to start, for ever, with 'receipt-ledger-corrupt' --
// correctly, because a marker it cannot account for is evidence of an unfinished
// transaction, and absence is a known state while present-but-unreadable is not.
// A marker written by an older generation of this plugin reads the same way: its
// shape is not one this code accepts, so it counts as unreadable rather than as
// nothing.
//
// Deciding that such a marker is stale is an operator's judgement, not the
// plugin's, which is why this is a tool and not a startup path. Read the list it
// prints first. Clearing a marker whose transaction is genuinely unfinished
// tells the plugin a download is whole when it may not be.
//
// It does NOT translate old markers into the current shape, and must not: these
// markers carry no acknowledgement and no receipt, so nothing is owed on them.
// Rewriting one into a shape the plugin accepts would manufacture work rather
// than retire it.

$apply = isset($argv[1]) && $argv[1] === 'apply';
if (($apply && (count($argv) !== 4 ||
        preg_match('/^[0-9a-fA-F]{40}$/D', $argv[2]) !== 1 ||
        preg_match('/^[0-9a-f]{64}$/D', $argv[3]) !== 1)) ||
    (!$apply && count($argv) !== 1)) {
    fwrite(STDERR, "usage: retrackers-clear-markers.php [apply HASH FINGERPRINT]\n"
        . "report first, then select one exact hash and its printed fingerprint\n");
    exit(2);
}

$scgi_host = '127.0.0.1';
$scgi_port = 5000;
@include('/rutorrent/app/conf/config.php');

// $scgi_host may already carry a scheme ("unix:///run/rtorrent/rtorrent.sock"),
// may be a bare socket path, or may be a hostname with $scgi_port set.
if (strpos($scgi_host, '://') !== false) $target = $scgi_host;
elseif (substr($scgi_host, 0, 1) === '/') $target = 'unix://' . $scgi_host;
elseif (isset($scgi_port) && $scgi_port > 0) $target = 'tcp://' . $scgi_host . ':' . $scgi_port;
else $target = 'unix://' . $scgi_host;

function rpc($target, $method, $arguments)
{
	$body = '<?xml version="1.0"?><methodCall><methodName>' . $method . '</methodName><params>';
	foreach ($arguments as $argument)
		$body .= '<param><value><string>' . htmlspecialchars($argument, ENT_XML1) . '</string></value></param>';
	$body .= '</params></methodCall>';
	$header = "CONTENT_LENGTH\0" . strlen($body) . "\0SCGI\0" . "1\0";
	$socket = @stream_socket_client($target, $errno, $errstr, 15);
	if (!$socket) {
		fwrite(STDERR, 'cannot reach rtorrent at ' . $target . ': ' . $errstr . "\n");
		exit(2);
	}
	fwrite($socket, strlen($header) . ':' . $header . ',' . $body);
	$answer = stream_get_contents($socket);
	fclose($socket);
	if (preg_match('~<fault(?:\s[^>]*)?>~', $answer) === 1) {
		$faultAt = strpos($answer, 'faultString');
		fwrite(STDERR, 'rtorrent refused ' . $method . ': '
			. ($faultAt === false ? 'XML-RPC fault' : substr($answer, $faultAt, 160)) . "\n");
		exit(2);
	}
	// A peer that accepts the request and closes without answering is not a
	// successful call, and this tool must never read one as "nothing to do" or
	// as "cleared". An empty or truncated body decodes to zero cells, which
	// every count below would otherwise accept: 0 torrents, 0 marked, 0
	// remaining, exit 0. So the envelope is required to be whole before any
	// number is taken out of it.
	if (strpos($answer, '<methodResponse>') === false
		|| strpos($answer, '</methodResponse>') === false) {
		fwrite(STDERR, 'rtorrent gave no complete answer to ' . $method
			. ' (' . strlen($answer) . " bytes); refusing to report a result\n");
		exit(2);
	}
	return($answer);
}

function markerFingerprint($row)
{
    return hash('sha256', implode("\0", array($row[0], $row[4], $row[2], $row[3])));
}

// This standalone operator tool is often copied to /tmp, outside the app's
// autoloader. Quote the same command grammar as rTorrent::quoteCommandArg().
function quoteCommandArg($value)
{
    return '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), $value) . '"';
}

function scalarString($response)
{
    if (preg_match_all('|<value><string>(.*?)</string></value>|s', $response, $matches) !== 1)
        return false;
    return html_entity_decode($matches[1][0], ENT_QUOTES | ENT_XML1, 'UTF-8');
}

echo 'rtorrent: ' . $target . "\n";

// A fifth column binds the public hash to its current daemon local_id.
// Values are XML-escaped and the flat list chunks exactly.
preg_match_all('|<value><string>(.*?)</string></value>|s',
    rpc($target, 'd.multicall2', array('', 'main', 'd.hash=', 'd.name=',
        'd.custom=retrackers-recovery', 'd.custom=retrackers-recovery-ack',
        'd.local_id=')), $cells);
$flat = $cells[1];
if (count($flat) % 5 !== 0) {
    fwrite(STDERR, 'unexpected reply shape: ' . count($flat) . " cells is not a multiple of 5\n");
    exit(2);
}
$marked = array();
for ($index = 0; $index < count($flat); $index += 5)
    if ($flat[$index + 2] !== '' || $flat[$index + 3] !== '')
        $marked[] = array($flat[$index], $flat[$index + 1],
            $flat[$index + 2], $flat[$index + 3], $flat[$index + 4]);

printf("%d torrents, %d carry a recovery marker\n", count($flat) / 5, count($marked));
foreach ($marked as $row) {
    $kind = preg_match('/^v1:candidate-claim:[0-9a-f]{32}$/D', $row[2]) === 1
        ? 'candidate-claim' : 'other';
    printf("  %s  %s  [%s; ack %s; fingerprint %s]\n", $row[0],
        substr(html_entity_decode($row[1]), 0, 60), $kind,
        $row[3] === '' ? 'empty' : 'present', markerFingerprint($row));
}
if (!$marked) {
    if ($apply) {
        fwrite(STDERR, "selected marker absent; report again\n");
        exit(2);
    }
    exit(0);
}
if (!$apply) {
    echo "\nreport only -- apply requires one hash and its exact fingerprint\n";
    exit(0);
}

$selected = null;
foreach ($marked as $row) {
    if (strtoupper($row[0]) !== strtoupper($argv[2])) continue;
    if ($selected !== null) {
        fwrite(STDERR, "duplicate hash in daemon inventory; refusing\n");
        exit(2);
    }
    $selected = $row;
}
if ($selected === null || markerFingerprint($selected) !== $argv[3]) {
    fwrite(STDERR, "selected hash or marker fingerprint changed; report again\n");
    exit(2);
}
list($hash, $name, $marker, $ack, $localId) = $selected;
if (preg_match('/^[0-9a-fA-F]{40}$/D', $hash) !== 1 ||
    preg_match('/^[0-9A-F]{40}$/D', $localId) !== 1 ||
    strlen($marker) > 512 || strlen($ack) > 512 ||
    preg_match('/^[A-Za-z0-9:._-]*$/D', $marker) !== 1 ||
    preg_match('/^[A-Za-z0-9:._-]*$/D', $ack) !== 1) {
    fwrite(STDERR, "selected marker/local_id is not safe for a conditional clear; retain it\n");
    exit(2);
}

// The full ledger must be empty at the very instant the marker is cleared.
// This branch checks the exact local_id, marker and ack under one daemon lock;
// a worker changing any of them after the inventory scan makes it refuse.
$equals = function ($getter, $value) {
    return 'equal=' . quoteCommandArg($getter) . ','
        . quoteCommandArg('cat=' . quoteCommandArg($value));
};
$conditions = array(
    'not=(method.list_keys,rr.receipts.v1)',
    $equals('d.local_id=', $localId),
    $equals('d.custom=retrackers-recovery', $marker),
    $equals('d.custom=retrackers-recovery-ack', $ack),
);
$condition = array_shift($conditions);
foreach ($conditions as $next)
    $condition = 'and=' . quoteCommandArg($condition) . ',' . quoteCommandArg($next);
$clear = 'cat=' . quoteCommandArg('$d.custom.set=retrackers-recovery,') . ','
    . quoteCommandArg('$d.custom.set=retrackers-recovery-ack,') . ',CLEARED';
$status = scalarString(rpc($target, 'branch', array($hash, $condition, $clear,
    'cat=REFUSED')));
if ($status !== 'CLEARED') {
    fwrite(STDERR, 'conditional clear refused or unconfirmed; marker/ledger may have changed' . "\n");
    exit(3);
}
foreach (array('retrackers-recovery', 'retrackers-recovery-ack') as $key) {
    $current = scalarString(rpc($target, 'd.custom', array($hash, $key)));
    if ($current !== '') {
        fwrite(STDERR, 'clear reply did not match daemon state; not saving session' . "\n");
        exit(3);
    }
}

// Persist only after the selected generation was confirmed clear.
rpc($target, 'session.save', array());
echo "session.save requested; verify persistence before daemon restart\n";
foreach (array('retrackers-recovery', 'retrackers-recovery-ack') as $key) {
    $current = scalarString(rpc($target, 'd.custom', array($hash, $key)));
    if ($current !== '') {
        fwrite(STDERR, 'marker changed after save; inspect the daemon again' . "\n");
        exit(3);
    }
}
echo 'marker empty in daemon memory ' . $hash . "\n";
