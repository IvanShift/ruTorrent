<?php

// The durable outcome channel of datadir and AutoTools (ACC-OWNB-01,
// plugins/erasedata/outcome.php): rTorrent clears d.message on the next
// successful tracker answer, so an outcome is also kept in a custom key of
// the download and listed once per WebUI session while it still applies.

require_once dirname(__DIR__) . '/rutracker_check/TestLib.php';
require_once testFindRepoRoot() . '/plugins/erasedata/outcome.php';

const OUTCOME_HASH = 'ABCDEF0123456789ABCDEF0123456789ABCDEF01';

// A kept value as record() stores it: URL-encoded JSON.
function outcomeValue($directory, $message, $whileStopped = false)
{
    return rawurlencode(json_encode(array('at' => 1, 'reason' => 'r', 'message' => $message,
        'directory' => $directory, 'while_stopped' => $whileStopped), JSON_UNESCAPED_SLASHES));
}

$suite = new StrictTestSuite();

$suite->test('an outcome is kept in the custom key and shown as d.message, with the directory it is about', function () {
    rXMLRPCRequest::reset();
    rXMLRPCRequest::queue(getCmd('d.get_directory'), true, false, array('/data/downloads/x'));
    $write = getCmd('d.set_custom') . '|' . getCmd('d.message.set');
    rXMLRPCRequest::queue($write, true, false, array('0', '0'));
    rXMLRPCRequest::queue(getCmd('d.save_full_session'), true, false, array('0'));
    strictAssertSame(true, FileOperationOutcome::record('datadir', OUTCOME_HASH, 'stopped-after-hash-check',
        'datadir: stopped', true), 'the daemon confirmed the outcome');
    $set = rXMLRPCRequest::requestsFor($write);
    strictAssertSame(1, count($set), 'one write');
    list($custom, $message) = $set[0]['commands'];
    strictAssertSame(array(OUTCOME_HASH, 'datadir: stopped'), $message->params, 'd.message is the immediate hint');
    strictAssertSame(array(OUTCOME_HASH, 'x-datadir-outcome'), array_slice($custom->params, 0, 2), 'the plugin key');
    // Stored URL-encoded: nothing in it for the core parser to escape.
    strictAssertSame(false, strpbrk($custom->params[2], "\"\\") !== false, 'the kept value has no quote or backslash');
    $kept = json_decode(rawurldecode($custom->params[2]), true);
    strictAssertSame(array('stopped-after-hash-check', 'datadir: stopped', '/data/downloads/x', true),
        array($kept['reason'], $kept['message'], base64_decode($kept['directory'], true), $kept['while_stopped']), 'the kept outcome');
    strictAssertSame(array(2, 'base64'), array($kept['version'], $kept['directory_encoding']), 'the raw directory encoding is explicit and versioned');
    strictAssertTrue(is_int($kept['at']) && abs($kept['at'] - time()) < 60, 'with its time');
    strictAssertSame(1, count(rXMLRPCRequest::requestsFor(getCmd('d.save_full_session'))), 'and a session save');
});

$suite->test('legacy byte directories survive record and pending comparisons exactly', function () {
    foreach(array('/data/中文 🙂 %FF', "/data/raw\xFF\\%41") as $directory) {
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue(getCmd('d.get_directory'), true, false, array($directory));
        $write = getCmd('d.set_custom').'|'.getCmd('d.message.set');
        rXMLRPCRequest::queue($write, true, false, array('0','0'));
        rXMLRPCRequest::queue(getCmd('d.save_full_session'), true, false, array('0'));
        strictAssertSame(true, FileOperationOutcome::record('datadir', OUTCOME_HASH, 'held', "path needs attention: \xFF", true), 'the outcome is confirmed');
        $requests = rXMLRPCRequest::requestsFor($write);
        $kept = $requests[0]['commands'][0]->params[2];
        $stored = json_decode(rawurldecode($kept), true);
        strictAssertSame($directory, base64_decode($stored['directory'], true), 'the journal keeps exact directory bytes, including literal percent and backslash');
        strictAssertSame('path needs attention: \\xFF', $requests[0]['commands'][1]->params[1], 'the immediate message displays unknown bytes visibly');
        rXMLRPCRequest::queue('d.multicall', true, false, array(OUTCOME_HASH, "name\xFF", $directory, '0', $kept));
        $pending = FileOperationOutcome::pending('datadir');
        strictAssertSame(1, count($pending), 'the kept outcome remains applicable to its original directory');
        strictAssertSame($directory, $pending[0][2]['directory'], 'the reader restores raw identity before comparing');
        rXMLRPCRequest::queue('d.multicall', true, false, array(OUTCOME_HASH, "name\xFF", $directory, '0', $kept));
        $notice = FileOperationOutcome::sessionNotice('datadir');
        strictAssertTrue(strpos($notice, json_encode('name\\xFF')) !== false || strpos($notice, 'name\\\\xFF') !== false, 'the session notice displays the raw torrent name visibly');
        strictAssertSame(false, strpos($notice, '\\ufffd') !== false, 'the notice introduces no replacement characters');
        rXMLRPCRequest::queue('d.multicall', true, false, array(OUTCOME_HASH, 'moved', $directory.'/different', '0', $kept));
        strictAssertSame(array(), FileOperationOutcome::pending('datadir'), 'an actual directory change still retires the notice');
        rXMLRPCRequest::queue('d.multicall', true, false, array(OUTCOME_HASH, 'started', $directory, '1', $kept));
        strictAssertSame(array(), FileOperationOutcome::pending('datadir'), 'starting a stopped download still retires its notice');
    }
});

$suite->test('old literal directories remain literal and malformed encoded identities are refused', function () {
    rXMLRPCRequest::reset();
    $directory = '/data/literal%FF%2F %41';
    $legacy = outcomeValue($directory, 'old valid record');
    rXMLRPCRequest::queue('d.multicall', true, false, array(OUTCOME_HASH, 'old', $directory, '0', $legacy));
    strictAssertSame(1, count(FileOperationOutcome::pending('datadir')), 'an old valid directory is never percent-decoded');
    $valid = array('version'=>2, 'directory_encoding'=>'base64', 'directory'=>base64_encode($directory), 'message'=>'notice');
    foreach(array(array('version'=>3), array('directory_encoding'=>'percent'), array('directory'=>'%2Fdata'),
        array('directory'=>base64_encode($directory)."\n"), array('directory'=>array()), array('directory'=>null)) as $bad) {
        $record = array_replace($valid, $bad);
        rXMLRPCRequest::queue('d.multicall', true, false, array(OUTCOME_HASH, 'bad', $directory, '0', rawurlencode(json_encode($record))));
        strictAssertSame(array(), FileOperationOutcome::pending('datadir'), 'unknown versions and malformed/noncanonical encoded directories cannot name an outcome');
    }
});

$suite->test('unavailable byte codecs refuse recording and skip unreadable notices without crashing', function () {
    $root = testFindRepoRoot();
    $record = rawurlencode(json_encode(array('version'=>2, 'directory_encoding'=>'base64',
        'directory'=>base64_encode("/raw\xFF"), 'message'=>'kept')));
    $script = 'require '.var_export($root.'/tests/plugins/rutracker_check/TestLib.php',true).';'
        .' require '.var_export($root.'/plugins/erasedata/outcome.php',true).';'
        .' $directory="/raw".chr(255);rXMLRPCRequest::reset();'
        .' rXMLRPCRequest::queue("d.get_directory",true,false,array($directory));'
        .' $ok=FileOperationOutcome::record("datadir",'.var_export(OUTCOME_HASH,true).',"held","notice");'
        .' rXMLRPCRequest::queue("d.multicall",true,false,array('.var_export(OUTCOME_HASH,true).',"name",$directory,"0",'.var_export($record,true).'));'
        .' $pending=FileOperationOutcome::pending("datadir");'
        .' echo json_encode(array($ok,count(rXMLRPCRequest::requestsFor("d.set_custom|d.message.set")),count($pending)));';
    foreach(array('base64_encode','base64_decode','base64_encode,base64_decode') as $disabled) {
        $output = array();
        exec(escapeshellarg(PHP_BINARY).' -d disable_functions='.escapeshellarg($disabled).' -r '.escapeshellarg($script).' 2>&1', $output, $status);
        strictAssertSame(0, $status, 'disabled byte codecs cannot crash the outcome owner: '.implode("\n",$output));
        strictAssertSame(array(false,0,0), json_decode(implode("\n",$output),true), 'unrepresentable identity never reaches a write or guessed comparison');
    }
});

$suite->test('an outcome is not written without its directory, a known plugin or a valid hash', function () {
    rXMLRPCRequest::reset();
    strictAssertSame(false, FileOperationOutcome::record('datadir', OUTCOME_HASH, 'r', 'm'), 'an unread directory');
    strictAssertSame(false, FileOperationOutcome::record('other', OUTCOME_HASH, 'r', 'm'), 'an unknown plugin');
    strictAssertSame(false, FileOperationOutcome::record('datadir', strtolower(OUTCOME_HASH), 'r', 'm'), 'a hash');
    strictAssertSame(false, FileOperationOutcome::clear('other', OUTCOME_HASH), 'nor cleared for an unknown plugin');
    strictAssertSame(array(getCmd('d.get_directory')), array_column(rXMLRPCRequest::$requests, 'key'),
        'only the directory read was tried');
});

$suite->test('a kept outcome is listed while it applies, and the notice escapes it', function () {
    rXMLRPCRequest::reset();
    $rows = array(
        OUTCOME_HASH, 'Shown "name" </script>', '/d/a', '1', outcomeValue('/d/a', 'kept <b>message</b>'),
        str_repeat('B', 40), 'Moved since', '/d/new', '1', outcomeValue('/d/old', 'stale'),
        str_repeat('C', 40), 'Started again', '/d/c', '1', outcomeValue('/d/c', 'while stopped', true),
        str_repeat('D', 40), 'Still stopped', '/d/d', '0', outcomeValue('/d/d', 'stays stopped', true),
        str_repeat('E', 40), 'None', '/d/e', '0', '',
        str_repeat('F', 40), 'Broken', '/d/f', '0', '{broken');
    rXMLRPCRequest::queue('d.multicall', true, false, $rows);
    $notice = FileOperationOutcome::sessionNotice('autotools');
    $request = rXMLRPCRequest::$requests[0]['commands'][0]->params;
    strictAssertSame(array('main', 'd.get_hash=', 'd.get_name=', 'd.get_directory=', 'd.get_state=',
        'd.get_custom=x-autotools-outcome'), $request, 'one read of the plugin key for every download');
    strictAssertSame('noty(' . json_encode('AutoTools: Shown "name" </script> (' . OUTCOME_HASH . '): kept <b>message</b>',
        JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ",'error');\n"
        . 'noty(' . json_encode('AutoTools: Still stopped (' . str_repeat('D', 40) . '): stays stopped',
        JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ",'error');\n", $notice,
        'only outcomes that still apply are listed, as escaped JavaScript strings');
    strictAssertSame(false, strpos($notice, '</script>'), 'a name cannot close the script');
    rXMLRPCRequest::reset();
    strictAssertSame('', FileOperationOutcome::sessionNotice('autotools'), 'an unanswered read shows nothing');
});

$suite->test('a long list of outcomes is counted past the limit', function () {
    rXMLRPCRequest::reset();
    $rows = array();
    for ($index = 0; $index < FileOperationOutcome::NOTICE_LIMIT + 3; $index++)
        array_push($rows, sprintf('%040X', $index), 'n' . $index, '/d', '0', outcomeValue('/d', 'm'));
    rXMLRPCRequest::queue('d.multicall', true, false, $rows);
    $lines = explode("\n", trim(FileOperationOutcome::sessionNotice('datadir')));
    strictAssertSame(FileOperationOutcome::NOTICE_LIMIT + 1, count($lines), 'the limit and one count line');
    strictAssertSame('noty("DataDir: 3 more downloads keep an outcome (custom key x-datadir-outcome)",\'error\');',
        end($lines), 'the rest is counted');
});

exit($suite->run());
