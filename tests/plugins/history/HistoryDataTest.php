<?php

require_once(__DIR__ . '/../../php/TestCase.php');

// Deliberately not using tests/plugins/rutracker_check/TestLib.php here:
// history.php transitively loads php/Snoopy.class.inc and php/settings.php,
// whose real classes collide with TestLib's doubles in either require order.
// A minimal local runner keeps the real classes intact, the same way
// tests/php/SnoopyTest.php does.
require_once(__DIR__ . '/../../../plugins/history/history.php');

// A record shaped like the ones update.php builds, keyed the way add() keys
// them. Built by hand so a test can place one straight into $data without
// going through add(), which would try to write the cache file.
function historyRecord($key, $actionTime, $action = 1)
{
    return array(
        'action' => $action,
        'name' => 'torrent-' . $key,
        'action_time' => $actionTime,
        'hash' => $key,
    );
}

// A history object holding exactly $records, with no pending changes: this is
// what a process has right after load().
function historyLoaded($records)
{
    $data = new rHistoryData();
    $data->data = $records;
    return $data;
}

// Replays what a writer does between load() and store() without touching the
// filesystem: add() and delete() both call store(), which this test has no
// cache for, so the two bookkeeping arrays are filled through reflection.
function historyRecordAddition($object, $record, $limit)
{
    $object->data[$record['hash']] = $record;
    historySetProtected($object, 'ownAdditions',
        historyGetProtected($object, 'ownAdditions') + array($record['hash'] => $record));
    historySetProtected($object, 'limit', $limit);
}

function historyRecordRemoval($object, $key)
{
    unset($object->data[$key]);
    historySetProtected($object, 'ownRemovals',
        historyGetProtected($object, 'ownRemovals') + array($key => true));
}

function historyGetProtected($object, $property)
{
    $reflection = new ReflectionProperty('rHistoryData', $property);
    if (PHP_VERSION_ID < 80100) {
        $reflection->setAccessible(true);
    }
    return $reflection->getValue($object);
}

function historySetProtected($object, $property, $value)
{
    $reflection = new ReflectionProperty('rHistoryData', $property);
    if (PHP_VERSION_ID < 80100) {
        $reflection->setAccessible(true);
    }
    $reflection->setValue($object, $value);
}

$tests = array(
    // The live failure: three events land in the same second when a torrent is
    // replaced, each in its own process, and the last writer used to publish a
    // file without the rows the others had added.
    'a row another process added while this one was writing survives' => function () {
        $ours = historyLoaded(array('a' => historyRecord('a', 100)));
        historyRecordAddition($ours, historyRecord('b', 101), 500);

        // Meanwhile a second process recorded its own event and stored it.
        $onDisk = historyLoaded(array(
            'a' => historyRecord('a', 100),
            'c' => historyRecord('c', 102),
        ));

        testAssertSame(true, $ours->merge($onDisk, null), 'merge reports success');
        testAssertSame(array('c', 'b', 'a'), array_keys($ours->data),
            'both writers keep their row, newest first');
    },

    'a row this process deleted does not come back from the fresher copy' => function () {
        $ours = historyLoaded(array(
            'a' => historyRecord('a', 100),
            'b' => historyRecord('b', 101),
        ));
        historyRecordRemoval($ours, 'b');

        $onDisk = historyLoaded(array(
            'a' => historyRecord('a', 100),
            'b' => historyRecord('b', 101),
        ));

        $ours->merge($onDisk, null);
        testAssertSame(array('a'), array_keys($ours->data),
            'the deletion is replayed on top of the fresher copy');
    },

    'a delete never trims a history longer than the default cap' => function () {
        $records = array();
        for ($i = 0; $i < 600; $i++) {
            $records['k' . $i] = historyRecord('k' . $i, 1000 + $i);
        }
        $ours = historyLoaded($records);
        historyRecordRemoval($ours, 'k0');

        $ours->merge(historyLoaded($records), null);
        testAssertSame(599, count($ours->data),
            'a delete carries no configured limit and must not impose the default one');
    },

    'a recorded event still applies the configured cap' => function () {
        $records = array();
        for ($i = 0; $i < 10; $i++) {
            $records['k' . $i] = historyRecord('k' . $i, 1000 + $i);
        }
        $ours = historyLoaded($records);
        historyRecordAddition($ours, historyRecord('new', 2000), 4);

        $ours->merge(historyLoaded($records), null);
        testAssertSame(2, count($ours->data), 'over the cap, half of it is kept');
        testAssertSame('new', array_keys($ours->data)[0], 'the newest row is kept');
    },

    // rTorrent names a magnet that has no metadata yet <INFOHASH>.meta and
    // replaces that placeholder with the real download the moment metadata
    // arrives, so every magnet the user adds logs an arrival and a removal
    // nobody asked for. The hash is hex, so the pattern is case-insensitive on
    // the letters and anchored on both ends: only a name that IS a placeholder
    // qualifies, never one that merely looks related.
    'magnet placeholders are recognised, real downloads are not' => function () {
        $placeholders = array(
            'an uppercase placeholder' => str_repeat('A', 40) . '.meta',
            'a lowercase placeholder' => str_repeat('b', 40) . '.meta',
            'a mixed-case placeholder' => str_repeat('cD', 20) . '.meta',
        );
        foreach ($placeholders as $label => $name)
            testAssertSame(true, rHistoryData::isMagnetPlaceholder($name), $label . ' must be recognised');

        $real = array(
            'a normal download' => 'Some Release 1080p',
            'a name that merely ends in .meta' => 'metadata.meta',
            'a hash-named file with another extension' => str_repeat('A', 40) . '.mkv',
            'a hash-named directory with no extension' => str_repeat('A', 40),
            'a placeholder name with something appended' => str_repeat('A', 40) . '.meta.part',
            'a name one character short of a hash' => str_repeat('A', 39) . '.meta',
            'a name with a non-hex character' => str_repeat('A', 39) . 'Z.meta',
        );
        foreach ($real as $label => $name)
            testAssertSame(false, rHistoryData::isMagnetPlaceholder($name), $label . ' must be kept');
    },

    // The metadata fetcher marks its own download with an exact service label;
    // on a live instance such a fetcher stub was logged
    // as added, then deleted a cycle later under the same name as the real
    // torrent, so a single replacement read as two deletions.
    'only the metadata fetcher label suppresses a user event' => function () {
        $service = array(
            'a dot-labelled service download' => array('Some Release 1080p', '.chk-meta'),
            'a placeholder that is also labelled' => array(str_repeat('C', 40) . '.meta', '.chk-meta'),
            'a placeholder with no label' => array(str_repeat('A', 40) . '.meta', ''),
        );
        foreach ($service as $label => $row)
            testAssertSame(true, rHistoryData::isServiceEntry($row[0], $row[1]), $label . ' must be recognised');

        $real = array(
            'a normal download' => array('Some Release 1080p', 'Video/Movies'),
            'an unlabelled download' => array('Some Release 1080p', ''),
            'a label that merely contains a dot' => array('Some Release', 'Video/4K.HDR'),
            'a private user label' => array('Some Release', '.private'),
            'a similar prefix' => array('Some Release', '.chk-meta-extra'),
        );
        foreach ($real as $label => $row)
            testAssertSame(false, rHistoryData::isServiceEntry($row[0], $row[1]), $label . ' must be kept');
    },

    'update.php records a private-label addition and deletion but skips the service stub' => function () {
        $scratch = sys_get_temp_dir() . '/history-label-' . bin2hex(random_bytes(6));
        if (!mkdir($scratch, 0700))
            throw new RuntimeException('Cannot create isolated history profile');
        $update = realpath(__DIR__ . '/../../../plugins/history/update.php');
        $runner = '$_ENV["RU_PROFILE_PATH"]=$argv[1]; $script=$argv[2]; $argv=array_slice($argv,2); include $script; echo json_encode(array_values(rHistoryData::load()->data));';
        $invoke = function ($action, $label) use ($scratch, $update, $runner) {
            $args = array(
                PHP_BINARY, '-c', __DIR__ . '/../../php-test.ini', '-r', $runner, '--',
                $scratch, $update, (string) $action, 'A real release', '100', '0', '0',
                '0', '1', '2', '3', 'https://tracker.test/announce',
                rawurlencode($label), '1', 'historytest',
            );
            $process = proc_open($args, array(
                0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'),
            ), $pipes);
            if (!is_resource($process))
                throw new RuntimeException('Cannot run history update.php');
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            if ($exit !== 0 || $errors !== '')
                throw new RuntimeException('history update.php failed: ' . $errors);
            $rows = json_decode($output, true);
            if (!is_array($rows))
                throw new RuntimeException('history update.php returned invalid rows: ' . $output);
            return $rows;
        };
        try {
            $rows = $invoke(1, '.private');
            testAssertSame(1, count($rows), 'the user-labelled addition is recorded');
            $rows = $invoke(3, '.private');
            testAssertSame(2, count($rows), 'the user-labelled deletion is recorded');
            testAssertSame(array(1, 3), array_column($rows, 'action'),
                'both user events reach the history cache in event order');
            $rows = $invoke(1, '.chk-meta');
            testAssertSame(2, count($rows), 'the metadata stub is not recorded');
        } finally {
            FileUtil::deleteDirectory($scratch);
        }
    },

    'the stored format carries nothing but what it always carried' => function () {
        $ours = historyLoaded(array('a' => historyRecord('a', 100)));
        historyRecordAddition($ours, historyRecord('b', 101), 500);

        $restored = unserialize(serialize($ours));
        testAssertSame(array('hash', 'modified', 'data'), $ours->__sleep(),
            'only the three long-standing properties are serialised');
        testAssertSame(array('a', 'b'), array_keys($restored->data),
            'the records survive a round trip');
        testAssertSame(array(), historyGetProtected($restored, 'ownAdditions'),
            'bookkeeping does not outlive the process that did the writing');
    },
);

exit(testRunCases($tests));
