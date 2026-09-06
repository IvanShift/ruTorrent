<?php

// Clear retrackers recovery markers from a live rTorrent.
//
//   docker exec <container> php85 /tmp/retrackers-clear-markers.php          report only
//   docker exec <container> php85 /tmp/retrackers-clear-markers.php apply    clear them
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
	if (strpos($answer, 'faultString') !== false) {
		fwrite(STDERR, 'rtorrent refused ' . $method . ': '
			. substr(strstr($answer, 'faultString'), 0, 160) . "\n");
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

echo 'rtorrent: ' . $target . "\n";

// Four requested columns means four <string> cells per row, in order, so the
// flat list chunks exactly. Cell text is XML-escaped, so no name can forge a
// </string> and shift the chunking.
preg_match_all('|<value><string>(.*?)</string></value>|s',
	rpc($target, 'd.multicall2', array('', 'main', 'd.hash=', 'd.name=',
		'd.custom=retrackers-recovery', 'd.custom=retrackers-recovery-ack')), $cells);
$flat = $cells[1];
if (count($flat) % 4 !== 0) {
	fwrite(STDERR, 'unexpected reply shape: ' . count($flat) . " cells is not a multiple of 4\n");
	exit(2);
}
$marked = array();
for ($index = 0; $index < count($flat); $index += 4)
	if ($flat[$index + 2] !== '' || $flat[$index + 3] !== '')
		$marked[] = array($flat[$index], $flat[$index + 1]);

printf("%d torrents, %d carry a recovery marker\n", count($flat) / 4, count($marked));
foreach ($marked as $row)
	printf("  %s  %s\n", $row[0], substr(html_entity_decode($row[1]), 0, 60));
if (!$marked) exit(0);
if (!$apply) {
	echo "\nreport only -- rerun with the argument: apply\n";
	exit(0);
}

foreach ($marked as $row)
	foreach (array('retrackers-recovery', 'retrackers-recovery-ack') as $key)
		rpc($target, 'd.custom.set', array($row[0], $key, ''));

// d.custom.set writes to memory; the value reaches disk only with a session
// save. Without this a daemon restart brings the old markers back -- measured,
// it does.
rpc($target, 'session.save', array());
echo "session saved\n";

// Count what is left by the SAME rule the selection used -- either custom
// non-empty -- and not by a 'v1:' prefix, which would call a marker in any other
// shape "gone" while it still sits on the torrent.
preg_match_all('|<value><string>(.*?)</string></value>|s',
	rpc($target, 'd.multicall2', array('', 'main', 'd.hash=', 'd.name=',
		'd.custom=retrackers-recovery', 'd.custom=retrackers-recovery-ack')), $after);
$rest = $after[1];
if (count($rest) % 4 !== 0) {
	fwrite(STDERR, 'unexpected reply shape on the read-back: ' . count($rest)
		. " cells is not a multiple of 4\n");
	exit(2);
}
$remaining = 0;
for ($index = 0; $index < count($rest); $index += 4)
	if ($rest[$index + 2] !== '' || $rest[$index + 3] !== '') $remaining++;
printf("cleared %d, remaining %d\n", count($marked), $remaining);
if ($remaining !== 0) exit(3);
