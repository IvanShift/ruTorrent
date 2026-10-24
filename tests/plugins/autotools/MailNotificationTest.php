<?php
require_once dirname(__DIR__, 2) . '/plugins/rutracker_check/TestLib.php';
require_once dirname(__DIR__, 3) . '/php/utility/utf.php';
$source = getenv('AUTOTOOLS_CLASS_SOURCE') ?: dirname(__DIR__, 3) . '/plugins/autotools/autotools.php';
eval(loadClassDefinition($source, 'rAutoTools'));

$suite = new StrictTestSuite();
$suite->test('documented .mailto is sent from the nearest destination parent', function () {
    $root = (getenv('TMPDIR') ?: sys_get_temp_dir()) . '/autotools-mail-' . bin2hex(random_bytes(8));
    mkdir($root);
    mkdir($root . '/parent');
    mkdir($root . '/parent/leaf');
    try {
        file_put_contents($root . '/.mailto', "TO: wrong@example.test\nroot body\n");
        file_put_contents($root . '/parent/.mailto', "TO: right@example.test\n"
            . "CC: cc@example.test\nFROM: sender@example.test\n"
            . "SUBJECT: Finished {TORRENT}\nBody for {TORRENT}\n");
        $sent = [];
        $result = rAutoTools::notifyCompletedFileTransfer($root . '/parent/leaf', $root,
            'Example', function ($to, $subject, $body, $headers) use (&$sent) {
                $sent[] = [$to, $subject, $body, $headers];
                return true;
            });
        strictAssertSame(true, $result, 'the nearest configured notification is sent');
        strictAssertSame([['right@example.test', 'Finished Example', "Body for Example\n",
            "From: sender@example.test\r\nCC: cc@example.test\r\nContent-type: text/plain; charset=utf-8\r\n"]],
            $sent, 'the shared sender preserves .mailto headers and torrent interpolation');
    } finally {
        unlink($root . '/parent/.mailto');
        unlink($root . '/.mailto');
        rmdir($root . '/parent/leaf');
        rmdir($root . '/parent');
        rmdir($root);
    }
});
$suite->test('completion mail renders raw torrent bytes in its UTF-8 subject and body', function () {
    $root = (getenv('TMPDIR') ?: sys_get_temp_dir()) . '/autotools-mail-' . bin2hex(random_bytes(8));
    mkdir($root);
    $destination = $root . "/raw\xFF";
    mkdir($destination);
    try {
        file_put_contents($destination . '/.mailto', "TO: local@example.test\n"
            . "SUBJECT: Finished {TORRENT}\nBody for {TORRENT}\n");
        $sent = [];
        $result = rAutoTools::notifyCompletedFileTransfer($destination, $root,
            "raw\xFF", function ($to, $subject, $body, $headers) use (&$sent) {
                $sent[] = [$to, $subject, $body, $headers];
                return true;
            });
        strictAssertSame(true, $result, 'the raw destination still selects its own .mailto file');
        strictAssertSame([['local@example.test', 'Finished raw\\xFF', "Body for raw\\xFF\n",
            "From: \r\nContent-type: text/plain; charset=utf-8\r\n"]], $sent,
            'the UTF-8 message displays the byte without changing the file lookup');
    } finally {
        unlink($destination . '/.mailto');
        rmdir($destination);
        rmdir($root);
    }
});
exit($suite->run());
