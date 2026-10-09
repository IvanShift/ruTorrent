<?php

require_once __DIR__ . '/../rutracker_check/TestLib.php';
require_once __DIR__ . '/../../php/RtorrentCommandParser.php';
// Use the real core quote owner, without bootstrapping a server profile.
eval('class rTorrent { public static '
    . loadFunctionDefinition(testFindRepoRoot() . '/php/rtorrent.php', 'quoteCommandArg') . '}');
require_once testFindRepoRoot() . '/plugins/datadir/recoverycommand.php';

$suite = new StrictTestSuite();
$suite->test('recovery schedule survives both daemon grammar and shell argv parsing', function () {
    $php = '/admin/php "q" \\bin\'s';
    $script = '/srv/ru "torrent" \\dir/recover.php';
    $parsed = RtorrentCommandParser::commands(rtDataDirRecoveryCommand($php, $script));
    strictAssertSame(1, count($parsed), 'exactly one execute command');
    $command = $parsed[0];
    strictAssertSame('execute', $command['name'], 'the schedule invokes execute');
    strictAssertSame(array('sh', '-c', escapeshellarg($php) . ' ' . escapeshellarg($script) . ' --all &'),
        $command['args'][0]['block'], 'the daemon hands the shell the exact quoted argv command');
});
exit($suite->run());
