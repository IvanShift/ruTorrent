<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/FakeRtorrentDaemon.php');
require_once(__DIR__ . '/../../php/HalfLoadedReplies.php');
require_once(__DIR__ . '/../../php/PluginEntryDriver.php');
require_once(__DIR__ . '/../../php/TestTree.php');

/**
 * plugins/scheduler applies the hour's mode when the daemon starts (the CLI
 * and the web plugin init) and every hour after (update.php). In a "seeding
 * only" hour it stops the started downloads that leech and keeps them in
 * leechStopped, which the next "full speed" hour starts again.
 *
 * A started download the daemon has not resumed yet reads "leech" whatever it
 * will do, and the CLI and the web init apply at once on every start. In the
 * b5repro lab (2026-11-16) the init stopped 332 of 421 completed downloads,
 * and in another start stopped 324 and stored an empty leechStopped. The
 * daemon here answers with what rtorrent answered while it loaded
 * (HalfLoadedReplies).
 */
class SchedulerApplyTest extends TestCase
{
    public const STARTED = 'd.multicall:started|d.hash=|d.connection_current=|d.complete=|d.hashing=|d.custom=sch_ignore';
    public const STARTED_BASE = 'd.multicall:started|d.hash=|d.connection_current=|d.custom=sch_ignore';
    // The two loaded seeds of the captured "started" view, and two downloads
    // it does not list that an earlier hour stopped.
    public const SEEDS = ['83506816B02146343020C6017473AB11723AEA0C', 'B2697E1CB31063F2D790DF0AE0608BED44434D06'];
    public const SEED_STOPPED = '1111111111111111111111111111111111111111';
    public const LEECH_STOPPED = '2222222222222222222222222222222222222222';

    private $base;
    private $daemon;

    public function setUp()
    {
        $this->base = sys_get_temp_dir() . '/rutorrent-scheduler-apply-' . getmypid();
        TestTree::remove($this->base);
        mkdir($this->base . '/profile/settings', 0700, true);
    }

    public function tearDown()
    {
        if ($this->daemon !== null) {
            $this->daemon->stop();
            $this->daemon = null;
        }
        TestTree::remove($this->base);
    }

    public function testASeedingOnlyHourStopsOnlyTheIncompleteDownloadOfAStartingDaemon()
    {
        $this->daemon([self::STARTED => 'started', self::STARTED_BASE => 'started-base']);
        $this->applySchedule('');
        $this->assertSame(
            [HalfLoadedReplies::INCOMPLETE],
            $this->stopped(),
            'complete downloads still queued for their check are seeds; the incomplete one leeches; calls: '
            . implode(', ', $this->daemon->calls()),
        );
        $this->assertSame(
            [HalfLoadedReplies::INCOMPLETE],
            array_keys($this->stored()['leechStopped']),
            'and leechStopped holds what was stopped, for the next full-speed hour',
        );
        $this->assertSame(
            ['hash', 'modified', 'enabled', 'UL', 'DL', 'week', 'seedStopped', 'leechStopped'],
            array_values(array_diff(array_keys($this->stored()), ['__PHP_Incomplete_Class_Name'])),
            'the document keeps the fields it always had',
        );
    }

    /**
     * The web init loads the document before the CLI init stores it, and
     * reads the started view after the CLI init has stopped its leech. Only
     * loaded downloads are listed here, so both request shapes classify alike.
     */
    public function testAConcurrentApplyKeepsTheDownloadsTheOtherOneStopped()
    {
        $answers = [];
        foreach ([self::STARTED => 'started', self::STARTED_BASE => 'started-base'] as $key => $name) {
            $loaded = self::loaded($name);
            $answers[$key] = [$loaded, self::without($loaded, [HalfLoadedReplies::INCOMPLETE])];
        }
        $this->daemon($answers);
        $this->applySchedule('$late = rScheduler::load(); $early = rScheduler::load(); $early->apply(); $late->apply(); exit(0);');
        $this->assertSame([HalfLoadedReplies::INCOMPLETE], $this->stopped(), 'the first apply stops the leech');
        $this->assertSame(
            [HalfLoadedReplies::INCOMPLETE],
            array_keys($this->stored()['leechStopped']),
            'the later store keeps it in leechStopped',
        );
    }

    /** Both read the started view before either stopped its leech (b5repro c3b). */
    public function testTwoAppliesThatStopTheSameLeechKeepIt()
    {
        $this->daemon([self::STARTED => self::loaded(), self::STARTED_BASE => self::loaded('started-base')]);
        $this->applySchedule('$late = rScheduler::load(); $early = rScheduler::load(); $early->apply(); $late->apply(); exit(0);');
        $this->assertSame(
            [HalfLoadedReplies::INCOMPLETE, HalfLoadedReplies::INCOMPLETE],
            $this->stopped(),
            'each apply stops the leech it read',
        );
        $this->assertSame(
            [HalfLoadedReplies::INCOMPLETE],
            array_keys($this->stored()['leechStopped']),
            'and the later store keeps it',
        );
    }

    /** The later store also keeps a leech only it saw and stopped. */
    public function testTheLaterApplyKeepsWhatOnlyItStopped()
    {
        $answers = [];
        foreach ([self::STARTED => 'started', self::STARTED_BASE => 'started-base'] as $key => $name) {
            $loaded = self::loaded($name);
            $answers[$key] = [self::without($loaded, [HalfLoadedReplies::INCOMPLETE]), $loaded];
        }
        $this->daemon($answers);
        $this->applySchedule('$late = rScheduler::load(); $early = rScheduler::load(); $early->apply(); $late->apply(); exit(0);');
        $this->assertSame([HalfLoadedReplies::INCOMPLETE], $this->stopped(), 'only the later apply stops the leech');
        $this->assertSame(
            [HalfLoadedReplies::INCOMPLETE],
            array_keys($this->stored()['leechStopped']),
            'and its store keeps it',
        );
    }

    /**
     * A full-speed or restricted apply that loaded the document before a
     * stop-hour one stored it starts what it loaded and removes only that:
     * the seeds and the leech the other one stopped stay listed for the next
     * hour that starts them.
     */
    public function testAnApplyThatStartsRemovesOnlyWhatItStarted()
    {
        $this->daemon([self::STARTED => self::loaded(), self::STARTED_BASE => self::loaded('started-base')]);
        foreach (['SCH_FAST', 'SCH_RESTRICT1'] as $mode) {
            $this->applySchedule(
                '$start = rScheduler::load(); $start->week = array_fill(0, 7, array_fill(0, 24, ' . $mode . '));'
                . ' $stop = rScheduler::load(); $stop->apply(); $start->apply(); exit(0);',
                '$seed->week = array_fill(0, 7, array_fill(0, 24, SCH_STOP));'
                . ' $seed->seedStopped = [' . var_export(self::SEED_STOPPED, true) . ' => true];'
                . ' $seed->leechStopped = [' . var_export(self::LEECH_STOPPED, true) . ' => true];',
            );
            $this->assertSame(
                [self::SEEDS, [HalfLoadedReplies::INCOMPLETE]],
                [$this->storedList('seedStopped'), $this->storedList('leechStopped')],
                $mode . ': the downloads it started leave the lists and the ones the stop hour stopped stay',
            );
            $this->assertSame(1, $this->stored()['week'][0][0], $mode . ': an apply stores the newer week, not its own copy');
        }
    }

    /** Two stop-hour applies: the later store keeps the seeds and the leech only it stopped. */
    public function testAStopHourApplyKeepsWhatOnlyItStopped()
    {
        $answers = [];
        foreach ([self::STARTED => 'started', self::STARTED_BASE => 'started-base'] as $key => $name) {
            $loaded = self::loaded($name);
            $answers[$key] = [self::without($loaded, [HalfLoadedReplies::INCOMPLETE, self::SEEDS[1]]), $loaded];
        }
        $this->daemon($answers);
        $this->applySchedule(
            '$late = rScheduler::load(); $early = rScheduler::load(); $early->apply(); $late->apply(); exit(0);',
            '$seed->week = array_fill(0, 7, array_fill(0, 24, SCH_STOP));',
        );
        $this->assertSame(
            [self::SEEDS, [HalfLoadedReplies::INCOMPLETE]],
            [$this->storedList('seedStopped'), $this->storedList('leechStopped')],
            'the lists hold every download either apply stopped',
        );
    }

    /**
     * A seeding-only apply starts the stopped seeds it loaded and removes only
     * those: the seeds a stop-hour apply stopped and stored in between stay.
     */
    public function testASeedingOnlyApplyRemovesOnlyTheSeedsItStarted()
    {
        $this->daemon([self::STARTED => self::loaded(), self::STARTED_BASE => self::loaded('started-base')]);
        $this->applySchedule(
            '$seeding = rScheduler::load(); $stop = rScheduler::load(); $stop->week = array_fill(0, 7, array_fill(0, 24, SCH_STOP));'
            . ' $stop->apply(); $seeding->apply(); exit(0);',
            '$seed->seedStopped = [' . var_export(self::SEED_STOPPED, true) . ' => true];',
        );
        $this->assertSame(
            [self::SEEDS, [HalfLoadedReplies::INCOMPLETE]],
            [$this->storedList('seedStopped'), $this->storedList('leechStopped')],
            'the seed it started leaves seedStopped, the ones the stop hour stopped stay',
        );
    }

    /**
     * A download whose sch_ignore the user set is never stopped. The captured
     * answer had no such download; this one holds "1" in the incomplete
     * download's sch_ignore cell, where the daemon puts d.custom=sch_ignore.
     */
    public function testADownloadTheUserExemptedIsNeverStopped()
    {
        $answers = [];
        foreach ([self::STARTED => 'started', self::STARTED_BASE => 'started-base'] as $key => $name) {
            $answers[$key] = preg_replace(
                '|(<string>' . HalfLoadedReplies::INCOMPLETE . '</string>.*?<value><string>)(</string></value></data></array></value>)|',
                '${1}1${2}',
                self::loaded($name),
                1,
                $count,
            );
            $this->assertSame(1, $count, 'the incomplete download\'s sch_ignore cell is set in ' . $name);
        }
        $this->daemon($answers);
        $this->applySchedule('');
        $this->assertSame([], $this->stopped(), 'the only leech is exempt, so a seeding-only hour stops nothing');
        $this->assertSame([], $this->storedList('leechStopped'), 'and lists nothing');
    }

    /** The dialog's settings survive an apply that stores before or after it. */
    public function testTheDialogsSettingsSurviveAConcurrentApply()
    {
        $this->daemon([self::STARTED => self::loaded(), self::STARTED_BASE => self::loaded('started-base')]);
        foreach (['$user->set(); $init->apply();', '$init->apply(); $user->set();'] as $order) {
            $this->applySchedule('$_POST = ["enabled" => "0"]; $user = rScheduler::load(); $init = rScheduler::load(); '
                . $order . ' exit(0);');
            $this->assertSame(0, $this->stored()['enabled'], 'the dialog\'s choice is kept: ' . $order);
        }
    }

    /** The captured "started" answer without the four downloads still queued. */
    private static function loaded($name = 'started')
    {
        preg_match_all('|<string>([0-9A-F]{40})</string></value><value><string>leech</string></value>'
            . '<value><i8>1</i8></value><value><i8>1</i8>|', HalfLoadedReplies::fixture('started'), $queued);
        return self::without(HalfLoadedReplies::fixture($name), $queued[1]);
    }

    /** A captured d.multicall answer without the rows of $hashes. */
    private static function without($xml, array $hashes)
    {
        foreach ($hashes as $hash) {
            $xml = preg_replace(
                '|<value><array><data><value><string>' . $hash . '</string>.*?</data></array></value>|',
                '',
                $xml,
                -1,
                $count,
            );
            if ($count !== 1) {
                throw new RuntimeException('the fixture holds no row of ' . $hash);
            }
        }
        return $xml;
    }

    /**
     * One process: seed a schedule with every hour "seeding only" ($seeding
     * may change the seed before it is stored), then update.php or $code.
     */
    private function applySchedule($code, $seeding = '')
    {
        $root = realpath(__DIR__ . '/../../..');
        list($status, $out, $err) = PluginEntryDriver::run(
            $this->base,
            $this->daemon->port(),
            'plugins/scheduler/update.php',
            ['before' => '$_SERVER["argv"] = $argv = [' . var_export($root
                . '/plugins/scheduler/update.php', true) . '];'
                . ' require_once(' . var_export($root . '/plugins/scheduler/scheduler.php', true) . ');'
                . ' (function () { require(' . var_export($root . '/php/methods-0.9.4.php', true) . '); })'
                . '->call(rTorrentSettings::get());'
                . ' $seed = rScheduler::load(); $seed->enabled = 1;'
                . ' $seed->week = array_fill(0, 7, array_fill(0, 24, SCH_SEEDONLY)); ' . $seeding
                . ' $seed->store(); unset($seed);'
                . $code],
        );
        $this->assertSame(0, $status, 'the run exits cleanly: ' . $out . $err);
    }

    private function daemon(array $answers)
    {
        $this->daemon = new FakeRtorrentDaemon(
            new HalfLoadedReplies($answers),
            $this->base . '/calls.log',
            false,
            [__DIR__ . '/../../php/HalfLoadedReplies.php'],
        );
    }

    /** The hashes every d.stop the daemon was sent named, in order. */
    private function stopped()
    {
        $hashes = [];
        foreach ($this->daemon->bodies() as $body) {
            preg_match_all('|<string>d\.stop</string></value></member><member><name>params</name><value><array><data>\s*'
                . '<value><string>([0-9A-F]{40})</string>|', $body, $match);
            $hashes = array_merge($hashes, $match[1]);
        }
        return $hashes;
    }

    /** A stored list's hashes, sorted. */
    private function storedList($name)
    {
        $hashes = array_keys((array) $this->stored()[$name]);
        sort($hashes);
        return $hashes;
    }

    private function stored()
    {
        $bytes = file_get_contents($this->base . '/profile/settings/scheduler.dat');
        return (array) unserialize($bytes, ['allowed_classes' => false]);
    }
}
