<?php

require_once(__DIR__ . '/TestLib.php');
require_once(__DIR__ . '/../../php/RtorrentCommandParser.php');
require_once(testFindRepoRoot() . '/plugins/rutracker_check/launcher.php');
eval(loadClassDefinition(testFindRepoRoot() . '/php/rtorrent.php', 'rTorrent'));

$suite = new StrictTestSuite();

$suite->test('available background execute receives literal argv without a shell', function () {
    $php = "/opt/o'neil/php \\\"bin";
    $script = "/srv/rutorrent/a'b/update.php";
    $user = "alice';touch forbidden;' \\\"&;,{}";
    strictAssertTrue(method_exists('RuTrackerDetachedPhp', 'scheduleCommand'), 'the schedule uses the existing detached launcher owner');
    $command = RuTrackerDetachedPhp::scheduleCommand($php, $script, [$user], 'available');
    $parsed = RtorrentCommandParser::commands($command);
    strictAssertSame([['name' => 'execute.raw.bg', 'args' => [$php, '-f', $script, '--', $user]]],
        $parsed, 'apostrophes and command grammar bytes remain separate literal argv');
    strictAssertSame([], RtorrentCommandParser::substitutedArguments($parsed[0]['args']), 'no argv value is evaluated');
});

$suite->test('known unsupported background execute quotes the existing detached shell command once', function () {
    strictAssertTrue(method_exists('RuTrackerDetachedPhp', 'scheduleCommand'), 'the schedule builder exists');
    $php = "/opt/o'neil/php";
    $script = "/srv/rutorrent/a'b/update.php";
    $user = "alice';touch forbidden;'";
    $command = RuTrackerDetachedPhp::scheduleCommand($php, $script, [$user], 'unsupported');
    strictAssertSame([['name' => getCmd('execute'), 'args' => ['sh', '-c',
        RuTrackerDetachedPhp::command($php, $script, ['--', $user])]]],
        RtorrentCommandParser::commands($command), 'the whole shell script survives daemon parsing before the shell sees it');
});

$suite->test('unconfirmed capability and unrepresentable argv refuse schedule construction', function () {
    strictAssertTrue(method_exists('RuTrackerDetachedPhp', 'scheduleCommand'), 'the schedule builder exists');
    strictAssertSame(false, RuTrackerDetachedPhp::scheduleCommand('/usr/bin/php', '/srv/update.php', ['alice'], 'unconfirmed'),
        'an unknown capability reply cannot select a fallback');
    foreach (['$execute=touch,forbidden', "a\0b"] as $value) {
        foreach (['available', 'unsupported'] as $capability) {
            strictAssertSame(false, RuTrackerDetachedPhp::scheduleCommand($value, '/srv/update.php', ['alice'], $capability),
                'unrepresentable PHP path is refused');
            strictAssertSame(false, RuTrackerDetachedPhp::scheduleCommand('/usr/bin/php', $value, ['alice'], $capability),
                'unrepresentable script path is refused');
            strictAssertSame(false, RuTrackerDetachedPhp::scheduleCommand('/usr/bin/php', '/srv/update.php', [$value], $capability),
                'unrepresentable user is refused consistently');
        }
    }
});

exit($suite->run());
