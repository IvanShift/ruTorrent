<?php
require_once dirname(__DIR__, 2) . '/plugins/rutracker_check/TestLib.php';
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
exit($suite->run());
