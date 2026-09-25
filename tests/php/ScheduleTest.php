<?php

require_once(__DIR__ . '/TestCase.php');

// The settings singleton normally restores share/settings/rtorrent.dat. A
// developer checkout can legitimately contain that ignored runtime cache, but
// this test exercises schedule construction in isolation and must not inherit
// its daemon version or alias table.
$scheduleProfilePath = sys_get_temp_dir() . '/rutorrent-schedule-test-' . getmypid();
$_ENV['RU_PROFILE_PATH'] = $scheduleProfilePath;

function scheduleRemoveProfile($path)
{
    if (!is_dir($path)) {
        return;
    }
    foreach (array_diff(scandir($path), array('.', '..')) as $entry) {
        $child = $path . '/' . $entry;
        if (is_dir($child) && !is_link($child)) {
            scheduleRemoveProfile($child);
        } else {
            unlink($child);
        }
    }
    rmdir($path);
}

scheduleRemoveProfile($scheduleProfilePath);
register_shutdown_function(function () use ($scheduleProfilePath) {
    scheduleRemoveProfile($scheduleProfilePath);
});

// A minimal local runner, for the same reason as tests/php/SnoopyTest.php:
// settings.php drags in the real rXMLRPC* classes, which collide with the
// doubles in tests/plugins/rutracker_check/TestLib.php.
require_once(__DIR__ . '/../../php/settings.php');

// The instant a registration made at $now would fire.
function scheduleFiresAt($name, $interval, $now)
{
    return $now + rTorrentSettings::getAlignedStart($name, $interval, $now);
}

// One registration of $name at $now, as both the caller (the &$startAt
// out-parameter) and rTorrent (the serialized command) see it. Driving $now
// rather than reading the clock is the whole point: the promise is that two
// registrations made at *different* instants resolve to the same absolute fire
// time, and the seconds where a broken implementation gives itself away are a
// handful out of an interval, so sampling the real clock would only reach them
// by luck.
function scheduleCommandAt($name, $intervalMinutes, $now)
{
    $startAt = 0;
    $command = rTorrentSettings::get()->getScheduleCommand(
        $name, $intervalMinutes, 'print=noop', $startAt, $now
    );
    return array(
        'now' => $now,
        'startAt' => $startAt,
        'firesAt' => $now + $startAt,
        'key' => $command->params[0]->value,
        'reported' => $command->params[1]->value,
        'interval' => $command->params[2]->value,
    );
}

// The second within an interval that $name's key claims for itself.
function scheduleJitterOffset($name)
{
    global $schedule_rand;
    return abs(crc32($name . User::getUser())) % ($schedule_rand + 1);
}

$hourly = 3600;
$daily = 86400;
$quarterMinute = 15;

// conf/config.php's default, pinned here so the offsets the tests compute are
// the ones the code computes.
$schedule_rand = 10;

$tests = array(
    'reloads do not move the fire time' => function () use ($hourly) {
        $now = 1755200000;
        $first = scheduleFiresAt('ratio', $hourly, $now);

        for ($reload = $now; $reload < $first; $reload += 137) {
            testAssertSame(
                $first,
                scheduleFiresAt('ratio', $hourly, $reload),
                'Re-registering ' . ($reload - $now) . 's later moved the fire time'
            );
        }
    },
    'intervals longer than an hour are stable too' => function () use ($daily) {
        $now = 1755200000;
        $first = scheduleFiresAt('loginmgr', $daily, $now);

        for ($reload = $now; $reload < $first; $reload += 3607) {
            testAssertSame(
                $first,
                scheduleFiresAt('loginmgr', $daily, $reload),
                'Re-registering ' . ($reload - $now) . 's later moved the daily fire time'
            );
        }
    },
    'intervals shorter than a minute are stable too' => function () use ($quarterMinute) {
        $now = 1755200000;
        $first = scheduleFiresAt('erasedata', $quarterMinute, $now);

        for ($reload = $now; $reload < $first; $reload++) {
            testAssertSame(
                $first,
                scheduleFiresAt('erasedata', $quarterMinute, $reload),
                'Re-registering ' . ($reload - $now) . 's later moved the 15s fire time'
            );
        }
    },
    'a task that already fired moves to the next slot, not a fresh interval' => function () use ($hourly) {
        $now = 1755200000;
        $first = scheduleFiresAt('ratio', $hourly, $now);

        testAssertSame(
            $first + $hourly,
            scheduleFiresAt('ratio', $hourly, $first + 1),
            'The slot after a fire is not one interval later'
        );
        testAssertSame(
            $first + $hourly,
            scheduleFiresAt('ratio', $hourly, $first + $hourly - 1),
            'A reload just before the next fire moved it'
        );
    },
    'the task keeps firing on its own period' => function () use ($hourly) {
        $now = 1755200000;
        $first = scheduleFiresAt('scheduler', $hourly, $now);
        $next = scheduleFiresAt('scheduler', $hourly, $first + 1);

        testAssertSame($first + $hourly, $next, 'The period drifted after the task fired');
    },
    'a reload never asks for an immediate run' => function () use ($hourly) {
        for ($second = 0; $second < 120; $second++) {
            $start = rTorrentSettings::getAlignedStart('autowatch', $hourly, 1755200000 + $second * 29);
            testAssertTrue(
                $start >= 1 && $start <= $hourly,
                "Start {$start} is outside 1..{$hourly}, so a reload could fire the task at once"
            );
        }
    },
    'each task gets its own slot within the jitter window' => function () use ($hourly) {
        global $schedule_rand;
        $schedule_rand = 10;

        $offsets = array();
        foreach (array('ratio', 'scheduler', 'loginmgr', 'autowatch', 'erasedata') as $name) {
            $offsets[$name] = scheduleFiresAt($name, $hourly, 1755200000) % $hourly;
        }

        foreach ($offsets as $name => $offset) {
            testAssertTrue(
                $offset % $hourly <= $schedule_rand || $hourly - ($offset % $hourly) <= $schedule_rand,
                "{$name} landed at offset {$offset}, outside the jitter window"
            );
            testAssertSame(
                $offset,
                scheduleFiresAt($name, $hourly, 1755200000 + 900) % $hourly,
                "{$name} did not keep its slot across a reload"
            );
        }
        testAssertTrue(count(array_unique($offsets)) > 1, 'Every task landed on the same second');
    },
    'a reload inside the jitter window does not move a getScheduleCommand fire time' => function () {
        global $schedule_rand;

        // The window this case exists to walk runs from the interval boundary
        // up to the key's own second within the jitter spread, so it needs a
        // key whose offset is not 0 or there is nothing between the two to
        // walk. getScheduleCommand's own callers cannot supply one -- 'trafic'
        // and 'rss' both happen to hash to 0 -- so 'ratio' is borrowed here for
        // its offset of 9; the alignment is a pure function of the key, so any
        // key exercises the same code.
        $name = 'ratio';
        $offset = scheduleJitterOffset($name);
        testAssertTrue($offset > 0, "{$name} hashes to offset 0, so this test walks an empty window");

        $intervalMinutes = 60;
        $interval = $intervalMinutes * 60;
        $boundary = 1755198000;                 // a multiple of $interval, so the slot is $boundary+$offset
        testAssertSame(0, $boundary % $interval, 'The chosen instant is not an interval boundary');
        $slot = $boundary + $offset;

        // Every second from just before the boundary to the far end of the
        // jitter spread. Up to the slot the answer must be the slot; from the
        // slot on it must be the next one, one whole interval later and not a
        // fresh countdown. An implementation that jumps to the *next* boundary
        // as soon as this one passes -- the behaviour the deterministic
        // alignment replaced -- reports slot+$interval for the seconds in
        // between.
        for ($now = $boundary - 1; $now <= $boundary + $schedule_rand; $now++) {
            $sample = scheduleCommandAt($name, $intervalMinutes, $now);
            $expected = ($now < $slot) ? $slot : $slot + $interval;
            testAssertSame(
                $expected,
                $sample['firesAt'],
                'A reload at boundary' . sprintf('%+d', $now - $boundary)
                . 's fires at boundary' . sprintf('%+d', $sample['firesAt'] - $boundary)
                . 's instead of boundary' . sprintf('%+d', $expected - $boundary) . 's'
            );
            testAssertSame(
                (string) $sample['startAt'],
                $sample['reported'],
                'A reload at boundary' . sprintf('%+d', $now - $boundary)
                . 's told rTorrent a start the caller was never given'
            );
            testAssertSame((string) $interval, $sample['interval'], 'The interval reached rTorrent in minutes');
            testAssertTrue(
                $sample['startAt'] >= 1 && $sample['startAt'] <= $interval,
                "Start {$sample['startAt']} is outside 1..{$interval}, so a reload could fire the task at once"
            );
        }
    },
    'a getScheduleCommand fire time holds for a whole interval of reloads' => function () {
        $name = 'ratio';
        $intervalMinutes = 60;
        $interval = $intervalMinutes * 60;
        $slot = 1755198000 + scheduleJitterOffset($name);

        // The slot is reached from anywhere in the interval that precedes it,
        // one second at a time; the second the slot itself arrives belongs to
        // the following one.
        for ($now = $slot - $interval; $now < $slot; $now++) {
            testAssertSame(
                $slot,
                scheduleCommandAt($name, $intervalMinutes, $now)['firesAt'],
                'A reload ' . ($slot - $now) . 's before the slot moved it'
            );
        }
        testAssertSame(
            $slot + $interval,
            scheduleCommandAt($name, $intervalMinutes, $slot)['firesAt'],
            'The slot after a fire is not one interval later'
        );
    },
    'each scheduled task gets its own getScheduleCommand slot' => function () {
        global $schedule_rand;

        $intervalMinutes = 5;
        $interval = $intervalMinutes * 60;
        $now = 1755198000;
        $offsets = array();
        foreach (array('trafic', 'rss', 'ratio', 'loginmgr', 'scheduler') as $name) {
            $sample = scheduleCommandAt($name, $intervalMinutes, $now);
            $offsets[$name] = $sample['firesAt'] % $interval;

            testAssertSame(
                scheduleJitterOffset($name),
                $offsets[$name],
                "{$name} did not land on the slot its name picks out"
            );
            testAssertSame(
                $offsets[$name],
                scheduleCommandAt($name, $intervalMinutes, $now + 137)['firesAt'] % $interval,
                "{$name} did not keep its slot across a reload"
            );
            testAssertSame($name . User::getUser(), $sample['key'], "{$name} registered under the wrong key");
        }
        testAssertTrue(count(array_unique($offsets)) > 1, 'Every task landed on the same second');
        testAssertTrue(max($offsets) <= $schedule_rand, 'A task landed outside the jitter window');
    },
    'the clock seam is optional' => function () {
        // Production callers -- plugins/trafic/init.php and
        // plugins/rss/rss.php -- pass four arguments and get time(). Retried
        // until the second holds still across the call, since a tick
        // underneath it would move the answer for an honest reason.
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $startAt = 0;
            $now = time();
            $command = rTorrentSettings::get()->getScheduleCommand('ratio', 60, 'print=noop', $startAt);
            if (time() !== $now) {
                continue;
            }
            testAssertSame(
                (string) $startAt,
                $command->params[1]->value,
                'The out-parameter and the command disagree'
            );
            testAssertSame(
                scheduleCommandAt('ratio', 60, $now)['firesAt'],
                $now + $startAt,
                'Reading the clock and being handed the same instant give different answers'
            );
            return;
        }
        throw new RuntimeException('The clock ticked through every attempt at a stable call');
    },
);

exit(testRunCases($tests));
