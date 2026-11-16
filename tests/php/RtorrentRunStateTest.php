<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/HalfLoadedReplies.php');
require_once(__DIR__ . '/../../php/rtorrent.php');

/**
 * rTorrent::isMeantToRun() and isLeeching(): what a download is meant to do
 * while the daemon has it queued for its hash check (d.hashing not 0) and
 * has not resumed it yet. Values arrive as rXMLRPCRequest hands them out,
 * strings of the daemon's <i8> and <string> answers.
 */
class RtorrentRunStateTest extends TestCase
{
    public function testAStartedDownloadIsMeantToRunWhileItIsQueuedOrChecked()
    {
        // The edit fixture: d.is_open, d.is_active, d.state at 1..3, d.hashing and d.hashing_failed last.
        list(, , $active, $state) = $captured = self::values(HalfLoadedReplies::fixture('edit'));
        list($hashing, $failed) = array_slice($captured, -2);
        $this->assertSame(['0', '1', '1', '0'], [$active, $state, $hashing, $failed], 'the captured download is closed, started and queued');
        $this->assertSame(true, rTorrent::isMeantToRun($state, $active, $hashing, $failed), 'a queued started download is meant to run');
        foreach ([
            [['1', '1', '0', '0'], true, 'running'],
            [[1, 1, 0, 0], true, 'running, read as integers'],
            [['1', '1', '0', null], true, 'running, whatever d.hashing_failed reads'],
            [['1', '0', '3', '0'], true, 'queued for a recheck the daemon resumes after'],
            [['1', '0', '3', '1'], false, 'its hash check failed: the daemon does not resume it'],
            [['1', '0', '0', '0'], false, 'paused, or closed by the daemon'],
            [['0', '0', '1', '0'], false, 'stopped while queued'],
            [['0', '1', '0', '0'], false, 'not started'],
            [[null, '1', '0', '0'], false, 'an unknown state'],
            [['1', null, '0', '0'], false, 'an unknown d.is_active'],
            [['1', '0', '', '0'], false, 'an unknown d.hashing'],
            [['1', '0', "1\n", '0'], false, 'a d.hashing that is not a plain number'],
            [['1', '0', '-1', '0'], false, 'a negative d.hashing'],
            [['1', '0', '1', null], false, 'an unknown d.hashing_failed'],
            [['1', '0', '1', ''], false, 'an empty d.hashing_failed'],
        ] as [$args, $expected, $what]) {
            $this->assertSame($expected, rTorrent::isMeantToRun(...$args), $what . ': ' . json_encode($args));
        }
    }

    /**
     * The edit read of a download in each state, captured on rtorrent
     * 0.16.28 and 0.9.8 (b10app cap-states.sh, 2026-11-16). "paused" is
     * ruTorrent's Pause (d.stop, the download stays open); "closed" a d.close
     * of a running download; "checking" a full recheck (d.check_hash) and
     * "queued" one waiting for it; "hashfailed" a recheck of a payload the
     * daemon cannot read ("Hash check I/O error ... Permission denied"),
     * after which d.hashing stays 3 and the daemon does not resume it.
     */
    public function testTheCapturedStatesOfBothDaemons()
    {
        $meant = ['stopped' => false, 'running' => true, 'paused' => false, 'closed' => false,
            'checking' => true, 'queued' => true, 'hashfailed' => false];
        foreach (['0.16.28-cdff6007', '0.9.8'] as $version) {
            $answers = json_decode(file_get_contents(__DIR__ . '/fixtures/run-state-edit-rtorrent-' . $version . '.json'), true);
            unset($answers['_capture']);
            $this->assertSame(array_keys($meant), array_keys($answers), $version . ': every state is captured');
            foreach ($answers as $state => $xml) {
                $values = self::values($xml);
                $this->assertSame(11, count($values), $version . ' ' . $state . ': the eleven answers of the edit read');
                $this->assertSame(
                    $meant[$state],
                    rTorrent::isMeantToRun($values[3], $values[2], $values[9], $values[10]),
                    $version . ' ' . $state . ': ' . json_encode(array_slice($values, 1, 3) + [9 => $values[9], 10 => $values[10]]),
                );
            }
        }
    }

    public function testAQueuedCompletedDownloadSeedsWhateverItsConnectionReads()
    {
        // Rows of the captured "started" view: hash, connection, d.complete, d.hashing.
        preg_match_all('|<value><array><data><value><string>([0-9A-F]{40})</string></value><value><string>([a-z_]*)</string></value>'
            . '<value><i8>([0-9]+)</i8></value><value><i8>([0-9]+)</i8></value>|', HalfLoadedReplies::fixture('started'), $rows, PREG_SET_ORDER);
        $this->assertSame(7, count($rows), 'the fixture holds seven rows');
        $leeches = [];
        foreach ($rows as $row) {
            if (rTorrent::isLeeching($row[2], $row[3], $row[4])) {
                $leeches[] = $row[1];
            }
        }
        $this->assertSame(
            [HalfLoadedReplies::INCOMPLETE],
            $leeches,
            'of the queued downloads that read "leech", only the incomplete one leeches',
        );
        foreach ([
            [['leech', '0', '0'], true, 'a resumed leech'],
            [['leech', '1', '0'], true, 'a resumed download whose connection is leech'],
            [['leech', '0', '1'], true, 'an incomplete download queued for its check'],
            [['leech', '1', '1'], false, 'a completed download queued for its check'],
            [['leech', 1, 2], false, 'a completed download in its final check, read as integers'],
            [['seed', '0', '1'], false, 'a partial seed queued for a recheck'],
            [['seed', '0', '0'], false, 'a seed'],
            [['initial_seed', '1', '0'], false, 'an initial seed'],
        ] as [$args, $expected, $what]) {
            $this->assertSame($expected, rTorrent::isLeeching(...$args), $what . ': ' . json_encode($args));
        }
    }

    /** The scalar answers of a captured system.multicall, in order. */
    private static function values($xml)
    {
        preg_match_all('#<value><(?:i8|string)>([^<]*)</#', $xml, $values);
        return $values[1];
    }
}
