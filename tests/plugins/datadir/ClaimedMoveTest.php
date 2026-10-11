<?php

require_once __DIR__ . '/../rutracker_check/TestLib.php';
umask(0022);
require_once __DIR__ . '/../../../plugins/datadir/movejob.php';

class DataDirClaimFake
{
    public $job = null;
    public $phase = 'absent';
    public $token = '0123456789abcdef0123456789abcdef:0000000000000001';
    public $localId = '0123456789abcdef0123456789abcdef';
    public $state = 1;
    public $open = 1;
    public $active = 1;
    public $path = '/old';
    public $calls = array();
    public $onClaim = null;
    public $onSetter = null;
    public $loseClaimOnce = false;
    public $refuseClaim = false;
    public $missing = false;
    public $presenceUnknown = false;
    public $loseFinishOnce = false;
    public $loseSetterOnce = false;
    public $cancelRestore = false;
    private $prestate;

    private function receipt()
    {
        return $this->token . '|' . $this->prestate[0] . '|' . $this->prestate[1]
            . '|' . $this->prestate[2] . '|' . $this->localId;
    }

    // The job's explicit presence callable, separate from daemon methods.
    public function presence($hash)
    {
        $this->calls[] = 'presence';
        return $this->presenceUnknown ? 'unknown' : ($this->missing ? 'absent' : 'present');
    }

    public function rpc($method, $hash, $args)
    {
        $this->calls[] = $method;
        if ($this->missing) return null;
        if ($method === 'd.directory') return $this->path;
        if ($method === 'd.stop_close_claim_state')
            return $this->phase === 'absent' ? 'absent'
                : $this->phase . '|' . ($this->phase === 'active' ? '' : ($this->cancelRestore ? 'K|' : 'S|'))
                    . $this->receipt();
        if ($method === 'd.stop_close_claim') {
            if ($this->refuseClaim) return null;
            if ($this->phase === 'absent') {
                if ($this->onClaim !== null) call_user_func($this->onClaim, $args[0]);
                $this->job = $args[0];
                $this->prestate = array($this->state, $this->open, $this->active);
                $this->phase = 'active';
                $this->state = 0;
                $this->open = 0;
                $this->active = 0;
            }
            if ($this->loseClaimOnce) {
                $this->loseClaimOnce = false;
                return null;
            }
            return $this->receipt();
        }
        if (strpos($method, 'd.directory.') === 0) {
            if ($this->onSetter !== null) call_user_func($this->onSetter);
            $this->path = $args[1];
            if ($this->loseSetterOnce) {
                $this->loseSetterOnce = false;
                return null;
            }
            return 1;
        }
        if (in_array($method, array('d.start_if_stop_close_claim',
            'd.open_if_stop_close_claim', 'd.release_stop_close_claim'), true)) {
            $this->phase = 'done';
            if (!$this->cancelRestore) {
                $this->state = $this->prestate[0];
                $this->open = $this->prestate[1];
                $this->active = $this->prestate[2];
            }
            if ($this->loseFinishOnce) {
                $this->loseFinishOnce = false;
                return null;
            }
            return $this->cancelRestore ? 0 : 1;
        }
        if ($method === 'd.replay_stop_close_claim') {
            $this->phase = 'done';
            return 1;
        }
        if ($method === 'd.ack_stop_close_claim') {
            $this->phase = 'absent';
            return 1;
        }
        throw new RuntimeException('unexpected fake method ' . $method);
    }
}

function dataDirClaimFixture()
{
    $parent = getenv('TMPDIR') ?: sys_get_temp_dir();
    $root = $parent . '/datadir-claimed-' . bin2hex(random_bytes(8));
    foreach (array('', '/src', '/dst', '/jobs') as $suffix)
        if (!mkdir($root . $suffix, 0700))
            throw new RuntimeException('fixture mkdir failed');
    return $root;
}

function dataDirClaimRename($source, $destination)
{
    if (file_exists($destination) || is_link($destination)) return false;
    return rename($source, $destination);
}

function dataDirClaimJob($root, $daemon, $files, $move, $rename = null)
{
    $hash = str_repeat('A', 40);
    return DataDirMoveJob::create($root . '/jobs',
        array('hash' => $hash, 'directory' => $root . '/dst', 'local_id' => $daemon->localId,
            'add' => false, 'move' => $move, 'source' => $root . '/src',
            'destination' => $root . '/dst', 'files' => $files,
            'previous_directory' => $daemon->path),
        array($daemon, 'rpc'), $rename !== null ? $rename : 'dataDirClaimRename', array($daemon, 'presence'));
}

function dataDirClaimReload($root, $daemon, $rename = null)
{
    $path = $root . '/jobs/' . str_repeat('A', 40) . '.json';
    return DataDirMoveJob::load($path, array($daemon, 'rpc'),
        $rename !== null ? $rename : 'dataDirClaimRename', array($daemon, 'presence'));
}

// A job exactly as the version-2 writer published it before 2026-10: the
// same keys in the same order, json_encode() with unescaped slashes, mode
// 0600. Its daemon strings came from rtDataDirSnapshot() through the old
// parser, which put a backslash before every '"' and '\' it read.
function dataDirClaimVersion2Job($root, $daemon, $fields)
{
    $hash = str_repeat('A', 40);
    $data = array_merge(array('version' => 2, 'phase' => 'new', 'job' => bin2hex(random_bytes(16)),
        'hash' => $hash, 'directory' => $root . '/dst/', 'add' => false, 'move' => true,
        'source' => $root . '/src', 'destination' => $root . '/dst', 'files' => array(),
        'local_id' => $daemon->localId, 'previous_directory' => $daemon->path), $fields);
    $path = $root . '/jobs/' . $hash . '.json';
    if (file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES)) === false || !chmod($path, 0600))
        throw new RuntimeException('fixture job write failed');
    return $path;
}

// Every entry below $path, relative to it, for exact before/after listings.
function dataDirClaimTree($path, $prefix = '')
{
    $entries = array();
    foreach (array_diff(scandir($path), array('.', '..')) as $name) {
        $entries[] = $prefix . $name;
        if (is_dir($path . '/' . $name) && !is_link($path . '/' . $name))
            $entries = array_merge($entries, dataDirClaimTree($path . '/' . $name, $prefix . $name . '/'));
    }
    sort($entries, SORT_STRING);
    return $entries;
}

class DataDirScheduledRecovery
{
    public static $root;
    public static $daemon;
    public static $moves = 0;
}
class FileUtil
{
    public static $log = array();
    public static function toLog($message) { self::$log[] = $message; }
}
require_once __DIR__ . '/../../../plugins/erasedata/outcome.php';
foreach (array('rtDataDirRecover', 'rtDataDirHeld', 'rtDataDirWorkerRefusal') as $function)
    eval(loadFunctionDefinition(__DIR__ . '/../../../plugins/datadir/util_setdir.php', $function));
function rtDataDirJournal() { return DataDirScheduledRecovery::$root . '/jobs'; }
function rtDataDirClaimRpc($method, $hash, $args)
{
    return DataDirScheduledRecovery::$daemon->rpc($method, $hash, $args);
}
function rtDataDirTorrentPresence($hash) { return DataDirScheduledRecovery::$daemon->presence($hash); }
function rtDataDirMoveNoReplace($from, $to)
{
    DataDirScheduledRecovery::$moves++;
    throw new RuntimeException('unexpected cancellation payload replay');
}
function dataDirClaimQueueOutcome()
{
    rXMLRPCRequest::queue('d.get_directory', true, false, array('/old'));
    rXMLRPCRequest::queue(array('d.set_custom', 'd.message.set'), true, false, array(0, 0));
    rXMLRPCRequest::queue('d.save_full_session', true, false, array(0));
}

$suite = new StrictTestSuite();

foreach (array('paused', 'late Stop') as $scene) {
    $suite->test('scheduled cancellation replaces its held outcome after ' . $scene, function () use ($scene) {
        $root = dataDirClaimFixture();
        try {
            $hash = str_repeat('A', 40);
            $path = $root . '/jobs/' . $hash . '.json';
            file_put_contents($root . '/src/a', 'source');
            file_put_contents($root . '/dst/a', 'foreign');
            $daemon = new DataDirClaimFake();
            if ($scene === 'paused') $daemon->state = $daemon->open = $daemon->active = 0;
            else $daemon->onClaim = function () use ($daemon) { $daemon->cancelRestore = true; };
            $daemon->loseFinishOnce = true;
            DataDirScheduledRecovery::$root = $root;
            DataDirScheduledRecovery::$daemon = $daemon;
            DataDirScheduledRecovery::$moves = 0;
            $job = dataDirClaimJob($root, $daemon, array('a'), true, 'rtDataDirMoveNoReplace');
            try { $job->run(); $held = 'unexpected-success'; }
            catch (RuntimeException $e) { $held = $e->getMessage(); }
            strictAssertSame('cancel-pending', dataDirClaimReload($root, $daemon)->phase(),
                'the lost cancellation reply retains the job');
            dataDirClaimQueueOutcome();
            rtDataDirHeld($hash, $held, $path);
            $before = rXMLRPCRequest::requestsFor('d.set_custom|d.message.set');
            strictAssertTrue(strpos(rawurldecode($before[0]['commands'][0]->params[2]),
                'claim-retained-start-and-move-blocked') !== false, 'initial outcome names the held lease');
            $calls = $daemon->calls;
            dataDirClaimQueueOutcome();
            strictAssertSame(true, rtDataDirRecover(), 'the schedule confirms the safe cancellation');
            strictAssertSame('absent', $daemon->phase, 'the daemon lease retires');
            strictAssertSame(false, file_exists($path), 'the job document retires');
            strictAssertSame(array(0, 0, 0), array($daemon->state, $daemon->open, $daemon->active),
                'a paused torrent or late Stop stays stopped');
            foreach (array('d.start_if_stop_close_claim', 'd.open_if_stop_close_claim',
                'd.release_stop_close_claim') as $method)
                strictAssertSame(count(array_keys($calls, $method, true)),
                    count(array_keys($daemon->calls, $method, true)), 'recovery does not repeat ' . $method);
            strictAssertSame(0, DataDirScheduledRecovery::$moves, 'neither cancellation attempt moves payload');
            strictAssertSame('source', file_get_contents($root . '/src/a'), 'source stays intact');
            strictAssertSame('foreign', file_get_contents($root . '/dst/a'), 'occupied destination stays intact');
            $writes = rXMLRPCRequest::requestsFor('d.set_custom|d.message.set');
            $latest = $writes[count($writes) - 1]['commands'][0]->params[2];
            rXMLRPCRequest::queue('d.multicall', true, false, array($hash, 'payload', '/old', '0', $latest));
            $pending = FileOperationOutcome::pending('datadir');
            strictAssertSame('occupied-destination', $pending[0][2]['reason'],
                'the original refusal replaces the obsolete held notice');
            strictAssertTrue(strpos($pending[0][2]['message'], 'claim-retained') === false,
                'the final notice does not describe a retired lease');
            strictAssertSame(2, count($writes), 'exactly one final refusal replaces the held outcome');
        } finally { strictRemoveTree($root); }
    });
}

$suite->test('job receipt exists before daemon claim and user Stop before claim stays stopped', function () {
    $root = dataDirClaimFixture();
    try {
        $daemon = new DataDirClaimFake();
        $daemon->onClaim = function ($id) use ($root, $daemon) {
            strictAssertTrue(is_file($root . '/jobs/' . str_repeat('A', 40) . '.json'),
                'durable job precedes daemon claim');
            $daemon->state = 0;
            $daemon->active = 0;
        };
        $job = dataDirClaimJob($root, $daemon, array(), false);
        strictAssertSame(true, $job->run(), 'no-move job completes');
        strictAssertSame(0, $daemon->state, 'pre-claim user Stop stays stopped');
        strictAssertSame(false, is_file($root . '/jobs/' . str_repeat('A', 40) . '.json'),
            'acknowledged job retires');
    } finally {
        strictRemoveTree($root);
    }
});

$suite->test('only a start the claim itself restored is reported for an outcome check', function () {
    foreach (array(
        array('started', 1, 1, 1, false, true),
        array('stopped later by the user', 1, 1, 1, true, false),
        array('stopped and open', 0, 1, 0, false, false),
        array('closed', 0, 0, 0, false, false),
    ) as $case) {
        $root = dataDirClaimFixture();
        try {
            $daemon = new DataDirClaimFake();
            list(, $daemon->state, $daemon->open, $daemon->active, $daemon->cancelRestore) = $case;
            $job = dataDirClaimJob($root, $daemon, array(), false);
            strictAssertSame(false, $job->restoredStart(), 'nothing is restored before the run');
            strictAssertSame(true, $job->run(), 'the change completes: ' . $case[0]);
            strictAssertSame($case[5], $job->restoredStart(), 'restored start for ' . $case[0]);
        } finally { strictRemoveTree($root); }
    }
});

$suite->test('a payload move creates its destination directories at most 0755, never group or other writable', function () {
    $root = dataDirClaimFixture();
    $umask = umask(0);
    try {
        mkdir($root . '/src/sub', 0700);
        file_put_contents($root . '/src/sub/file', 'payload');
        chmod($root . '/src/sub/file', 0644);
        $daemon = new DataDirClaimFake();
        $job = DataDirMoveJob::create($root . '/jobs',
            array('hash' => str_repeat('A', 40), 'directory' => $root . '/dst/new',
                'local_id' => $daemon->localId, 'add' => false, 'move' => true,
                'source' => $root . '/src', 'destination' => $root . '/dst/new',
                'files' => array('sub/file'), 'previous_directory' => $daemon->path),
            array($daemon, 'rpc'), function ($source, $destination) {
                return !file_exists($destination) && rename($source, $destination);
            }, array($daemon, 'presence'));
        strictAssertSame(true, $job->run(), 'the move completes');
        foreach (array('/dst/new', '/dst/new/sub') as $directory) {
            clearstatcache(true, $root . $directory);
            strictAssertSame(0755, fileperms($root . $directory) & 0777,
                $directory . ' is not group or other writable');
        }
        strictAssertSame('payload', file_get_contents($root . '/dst/new/sub/file'), 'the payload moved');
    } finally { umask($umask); strictRemoveTree($root); }
});

$suite->test('a refused uncaptured claim retires the new job with a classified refusal', function () {
    $root = dataDirClaimFixture();
    try {
        $daemon = new DataDirClaimFake();
        $daemon->refuseClaim = true;
        $job = dataDirClaimJob($root, $daemon, array(), false);
        try { $job->run(); throw new RuntimeException('claim refusal was accepted'); }
        catch (DataDirMoveRefused $e) {
            strictAssertSame('daemon-claim-refused', $e->getMessage(), 'refusal is visible and classified');
        }
        strictAssertSame(false, is_file($root . '/jobs/' . str_repeat('A', 40) . '.json'),
            'a proven absent claim has no durable job left');
        strictAssertSame(1, $daemon->state, 'refusal preserves the original run state');
        strictAssertSame('/old', $daemon->path, 'refusal does not repoint the torrent');
    } finally { strictRemoveTree($root); }
});

$suite->test('an erased torrent retires only a new job without a payload receipt', function () {
    $root = dataDirClaimFixture();
    try {
        $daemon = new DataDirClaimFake();
        $job = dataDirClaimJob($root, $daemon, array(), false);
        $daemon->missing = true;
        try { $job->run(); throw new RuntimeException('missing torrent was accepted'); }
        catch (DataDirMoveRefused $e) {
            strictAssertSame('torrent-absent', $e->getMessage(), 'missing torrent refusal is visible');
        }
        strictAssertSame(false, is_file($root . '/jobs/' . str_repeat('A', 40) . '.json'),
            'a missing torrent leaves no uncaptured job');
        strictAssertSame(false, in_array('d.stop_close_claim', $daemon->calls, true),
            'missing torrent is not claimed');
    } finally { strictRemoveTree($root); }
});

$suite->test('an unexpected payload receipt keeps a new job held without taking a claim', function () {
    $root = dataDirClaimFixture();
    try {
        $daemon = new DataDirClaimFake();
        $job = dataDirClaimJob($root, $daemon, array(), false);
        $path = $root . '/jobs/' . str_repeat('A', 40) . '.json';
        mkdir($path . '.move', 0700);
        try { $job->run(); throw new RuntimeException('unknown payload receipt was ignored'); }
        catch (DataDirMoveRefused $error) { throw new RuntimeException('unknown receipt must remain held'); }
        catch (RuntimeException $error) {
            strictAssertSame('move-receipt-untrusted', $error->getMessage(), 'unknown receipt is classified');
        }
        strictAssertSame(true, is_file($path), 'unknown receipt cannot retire the owning job');
        strictAssertSame(array(), $daemon->calls, 'unknown receipt cannot admit a daemon claim');
    } finally { strictRemoveTree($root); }
});

$suite->test('an unknown torrent presence holds the new job for recovery', function () {
    $root = dataDirClaimFixture();
    try {
        $daemon = new DataDirClaimFake();
        $job = dataDirClaimJob($root, $daemon, array(), false);
        $daemon->missing = true;
        $daemon->presenceUnknown = true;
        try { $job->run(); throw new RuntimeException('unknown state was accepted'); }
        catch (DataDirMoveRefused $e) { throw new RuntimeException('unknown state must retain recovery'); }
        catch (RuntimeException $e) {
            strictAssertTrue(strpos($e->getMessage(), 'unconfirmed') !== false, 'unknown state remains visible');
        }
        strictAssertSame(true, is_file($root . '/jobs/' . str_repeat('A', 40) . '.json'),
            'unknown presence cannot retire a job');
    } finally { strictRemoveTree($root); }
});

$suite->test('lost claim response reuses the original job and does not recapture state', function () {
    $root = dataDirClaimFixture();
    try {
        $daemon = new DataDirClaimFake();
        $daemon->loseClaimOnce = true;
        $job = dataDirClaimJob($root, $daemon, array(), false);
        try {
            $job->run();
            throw new RuntimeException('lost response was accepted');
        } catch (RuntimeException $e) {
            strictAssertTrue(strpos($e->getMessage(), 'unconfirmed') !== false,
                'lost reply leaves a visible hold');
        }
        strictAssertSame('active', $daemon->phase, 'daemon claim survived lost answer');
        strictAssertSame(true, dataDirClaimReload($root, $daemon)->run(), 'same job resumes');
        strictAssertSame(1, $daemon->state, 'pre-claim active state is restored');
    } finally {
        strictRemoveTree($root);
    }
});

$suite->test('two-file destination collision keeps both sources and the foreign destination', function () {
    $root = dataDirClaimFixture();
    try {
        file_put_contents($root . '/src/a', 'A');
        file_put_contents($root . '/src/b', 'B');
        file_put_contents($root . '/dst/b', 'foreign');
        $daemon = new DataDirClaimFake();
        $job = dataDirClaimJob($root, $daemon, array('a', 'b'), true);
        try {
            $job->run();
            throw new RuntimeException('occupied destination was accepted');
        } catch (RuntimeException $e) {
            strictAssertTrue(strpos($e->getMessage(), 'occupied-destination') !== false,
                'collision is classified');
        }
        strictAssertSame('A', file_get_contents($root . '/src/a'), 'first source did not move');
        strictAssertSame('B', file_get_contents($root . '/src/b'), 'second source did not move');
        strictAssertSame('foreign', file_get_contents($root . '/dst/b'), 'foreign destination retained');
        strictAssertSame('absent', $daemon->phase, 'proved-unmoved refusal restores daemon state');
        strictAssertSame(1, $daemon->state, 'pre-claim activity is restored');
        strictAssertSame(false, is_file($root . '/jobs/' . str_repeat('A', 40) . '.json'),
            'cancelled job is acknowledged');
    } finally {
        strictRemoveTree($root);
    }
});

$suite->test('later user Stop cancels only active restore, not the moved path', function () {
    $root = dataDirClaimFixture();
    try {
        $daemon = new DataDirClaimFake();
        $daemon->onSetter = function () use ($daemon) { $daemon->cancelRestore = true; };
        $job = dataDirClaimJob($root, $daemon, array(), false);
        strictAssertSame(true, $job->run(), 'job finishes after later Stop');
        strictAssertSame($root . '/dst', $daemon->path, 'new directory retained');
        strictAssertSame(0, $daemon->state, 'later user Stop not undone');
    } finally {
        strictRemoveTree($root);
    }
});

$suite->test('lost terminal reply resolves job-matched DONE then acknowledges', function () {
    $root = dataDirClaimFixture();
    try {
        file_put_contents($root . '/src/a', 'A');
        $daemon = new DataDirClaimFake();
        $daemon->loseFinishOnce = true;
        $job = dataDirClaimJob($root, $daemon, array('a'), true);
        try {
            $job->run();
            throw new RuntimeException('lost finish response was accepted');
        } catch (RuntimeException $e) {
            strictAssertTrue(strpos($e->getMessage(), 'unconfirmed') !== false,
                'terminal lost reply remains visible');
        }
        strictAssertSame('A', file_get_contents($root . '/dst/a'), 'payload was published once');
        strictAssertSame(true, dataDirClaimReload($root, $daemon)->run(), 'DONE recovery completes');
        strictAssertSame('absent', $daemon->phase, 'job acknowledged');
        strictAssertSame(false, file_exists($root . '/src/a'), 'source not republished');
    } finally {
        strictRemoveTree($root);
    }
});

$suite->test('lost cancellation reply resumes cancel-pending without publishing payload', function () {
    $root = dataDirClaimFixture();
    try {
        file_put_contents($root . '/src/a', 'A');
        file_put_contents($root . '/dst/a', 'foreign');
        $daemon = new DataDirClaimFake();
        $daemon->loseFinishOnce = true;
        $job = dataDirClaimJob($root, $daemon, array('a'), true);
        try {
            $job->run();
            throw new RuntimeException('lost cancellation reply was accepted');
        } catch (RuntimeException $e) {
            strictAssertTrue(strpos($e->getMessage(), 'unconfirmed') !== false,
                'lost cancellation reply remains visible');
        }
        strictAssertSame('cancel-pending', dataDirClaimReload($root, $daemon)->phase(),
            'cancellation obligation persists');
        try { dataDirClaimReload($root, $daemon)->run(); throw new RuntimeException('cancellation reason was lost'); }
        catch (DataDirMoveRefused $e) {
            strictAssertSame('occupied-destination', $e->getMessage(), 'replay reports the original refusal');
        }
        strictAssertSame('A', file_get_contents($root . '/src/a'), 'source retained');
        strictAssertSame('foreign', file_get_contents($root . '/dst/a'), 'foreign destination retained');
        strictAssertSame(1, $daemon->state, 'original state restored once');
    } finally {
        strictRemoveTree($root);
    }
});


$suite->test('generation changed before claim restores the new torrent without moving old files', function () {
    $root = dataDirClaimFixture();
    try {
        file_put_contents($root . '/src/a', 'A');
        $daemon = new DataDirClaimFake();
        $daemon->onClaim = function () use ($daemon) {
            $daemon->localId = 'ffffffffffffffffffffffffffffffff';
        };
        $job = dataDirClaimJob($root, $daemon, array('a'), true);
        try {
            $job->run();
            throw new RuntimeException('stale generation accepted');
        } catch (DataDirMoveRefused $e) {
            strictAssertSame('torrent-generation-changed', $e->getMessage(),
                'stale generation has a classified cancellation');
        }
        strictAssertSame(1, $daemon->state, 'new torrent activity restored');
        strictAssertSame('absent', $daemon->phase, 'claim acknowledged');
        strictAssertSame('A', file_get_contents($root . '/src/a'), 'old payload untouched');
        strictAssertSame(false, file_exists($root . '/dst/a'), 'destination untouched');
        strictAssertSame(false, is_file($root . '/jobs/' . str_repeat('A', 40) . '.json'),
            'cancelled job retired');
    } finally {
        strictRemoveTree($root);
    }
});


$suite->test('directory changed before claim cancels the lease before any payload move', function () {
    $root = dataDirClaimFixture();
    try {
        file_put_contents($root . '/src/a', 'A');
        $daemon = new DataDirClaimFake();
        $daemon->onClaim = function () use ($daemon) { $daemon->path = '/external'; };
        $job = dataDirClaimJob($root, $daemon, array('a'), true);
        try {
            $job->run();
            throw new RuntimeException('foreign path change was accepted');
        } catch (DataDirMoveRefused $e) {
            strictAssertSame('torrent-directory-changed', $e->getMessage(),
                'preclaim path change is classified');
        }
        strictAssertSame(1, $daemon->state, 'daemon prestate restored');
        strictAssertSame('absent', $daemon->phase, 'stale job acknowledged');
        strictAssertSame('/external', $daemon->path, 'foreign path choice retained');
        strictAssertSame('A', file_get_contents($root . '/src/a'), 'old source unchanged');
        strictAssertSame(false, file_exists($root . '/dst/a'), 'destination unchanged');
    } finally {
        strictRemoveTree($root);
    }
});

$suite->test('lost directory setter reply resumes metadata-only job on its own target', function () {
    $root = dataDirClaimFixture();
    try {
        $daemon = new DataDirClaimFake();
        $daemon->loseSetterOnce = true;
        $job = dataDirClaimJob($root, $daemon, array(), false);
        try {
            $job->run();
            throw new RuntimeException('lost setter reply accepted');
        } catch (RuntimeException $e) {
            strictAssertTrue(strpos($e->getMessage(), 'unconfirmed') !== false,
                'lost setter answer holds the job');
        }
        strictAssertSame($root . '/dst', $daemon->path, 'setter applied once');
        strictAssertSame(true, dataDirClaimReload($root, $daemon)->run(),
            'job resumes without treating its own setter as a foreign path');
        strictAssertSame(1, $daemon->state, 'daemon prestate restored');
    } finally {
        strictRemoveTree($root);
    }
});

$suite->test('a refused rename with unchanged payload cancels the claim and retires the job', function () {
    foreach (array(array(1, 1), array(0, 1), array(0, 0)) as $prestate) {
        $root = dataDirClaimFixture();
        try {
            file_put_contents($root . '/src/a', 'A');
            $daemon = new DataDirClaimFake();
            $daemon->state = $daemon->active = $prestate[0];
            $daemon->open = $prestate[1];
            $job = dataDirClaimJob($root, $daemon, array('a'), true, function () { return false; });
            try { $job->run(); throw new RuntimeException('refused move was accepted'); }
            catch (DataDirMoveRefused $e) {
                strictAssertSame('move-move-refused', $e->getMessage(), 'original move refusal is classified');
            }
            strictAssertSame('absent', $daemon->phase, 'claim is released and acknowledged');
            strictAssertSame($prestate[0], $daemon->state, 'original start state is restored');
            strictAssertSame($prestate[1], $daemon->open, 'original open state is restored');
            strictAssertSame('/old', $daemon->path, 'refused move never applies a directory setter');
            strictAssertSame('A', file_get_contents($root . '/src/a'), 'payload remains at source');
            strictAssertSame(array(), array_values(array_diff(scandir($root . '/jobs'), array('.', '..'))),
                'job, receipt and witness are retired after proven cancellation');
        } finally { strictRemoveTree($root); }
    }
});

$suite->test('an old claimed ready job self-heals after the same refused rename', function () {
    $root = dataDirClaimFixture();
    try {
        file_put_contents($root . '/src/a', 'A');
        $daemon = new DataDirClaimFake();
        $job = dataDirClaimJob($root, $daemon, array('a'), true);
        $hash = str_repeat('A', 40);
        $path = $root . '/jobs/' . $hash . '.json';
        $daemon->rpc('d.stop_close_claim', $hash, array($job->id()));
        $saved = json_decode(file_get_contents($path), true);
        $saved['phase'] = 'claimed';
        file_put_contents($path, json_encode($saved));
        DataDirMoveIntent::prepare($path . '.move', $job->id(), $hash, $root . '/src',
            $root . '/dst', array('a'), function () { return false; });
        try { dataDirClaimReload($root, $daemon, function () { return false; })->run();
            throw new RuntimeException('old refused move was accepted'); }
        catch (DataDirMoveRefused $e) {
            strictAssertSame('move-move-refused', $e->getMessage(), 'recovery classifies the proven cancellation');
        }
        strictAssertSame('absent', $daemon->phase, 'persisted old claim is released');
        strictAssertSame(1, $daemon->state, 'old active state is restored');
        strictAssertSame(false, file_exists($path), 'old job is no longer an indefinite recovery hold');
        strictAssertSame('A', file_get_contents($root . '/src/a'), 'old payload remains unchanged');
    } finally { strictRemoveTree($root); }
});

$suite->test('a partial move retains its recovery receipt and later finishes the remaining payload', function () {
    $root = dataDirClaimFixture();
    try {
        file_put_contents($root . '/src/a', 'A'); file_put_contents($root . '/src/b', 'B');
        $daemon = new DataDirClaimFake();
        $job = dataDirClaimJob($root, $daemon, array('a', 'b'), true,
            function ($source, $destination) { return basename($source) === 'a' ? rename($source, $destination) : false; });
        try { $job->run(); throw new RuntimeException('partial move was accepted'); }
        catch (RuntimeException $e) {
            strictAssertSame(false, $e instanceof DataDirMoveRefused, 'partial publication retains a recovery obligation');
            strictAssertSame('move-move-refused', $e->getMessage(), 'partial refusal keeps the original classification');
        }
        strictAssertSame('active', $daemon->phase, 'partial publication keeps the claim');
        strictAssertSame(0, $daemon->state, 'partial publication does not restart the torrent');
        strictAssertSame('A', file_get_contents($root . '/dst/a'), 'first file remains published');
        strictAssertSame('B', file_get_contents($root . '/src/b'), 'second file remains at source');
        strictAssertSame(true, dataDirClaimReload($root, $daemon)->run(), 'later recovery finishes safely');
        strictAssertSame('B', file_get_contents($root . '/dst/b'), 'remaining file is published once');
        strictAssertSame('absent', $daemon->phase, 'completed recovery acknowledges the claim');
    } finally { strictRemoveTree($root); }
});

$suite->test('a later user Stop remains stopped when a refused unmoved job is cancelled', function () {
    $root = dataDirClaimFixture();
    try {
        file_put_contents($root . '/src/a', 'A');
        $daemon = new DataDirClaimFake();
        $job = dataDirClaimJob($root, $daemon, array('a'), true, function () use ($daemon) {
            $daemon->cancelRestore = true; return false;
        });
        try { $job->run(); throw new RuntimeException('refused move was accepted'); }
        catch (DataDirMoveRefused $e) {
            strictAssertSame('move-move-refused', $e->getMessage(), 'refused move is safely cancelled');
        }
        strictAssertSame(0, $daemon->state, 'later user Stop is never undone');
        strictAssertSame('absent', $daemon->phase, 'cancelled claim is acknowledged');
    } finally { strictRemoveTree($root); }
});

$suite->test('lost cancellation reply after rename refusal preserves its reason and replays cancellation', function () {
    $root = dataDirClaimFixture();
    try {
        file_put_contents($root . '/src/a', 'A');
        $daemon = new DataDirClaimFake();
        $daemon->loseFinishOnce = true;
        $job = dataDirClaimJob($root, $daemon, array('a'), true, function () { return false; });
        try { $job->run(); throw new RuntimeException('lost cancellation reply was accepted'); }
        catch (RuntimeException $e) {
            strictAssertSame(false, $e instanceof DataDirMoveRefused, 'unconfirmed cancellation remains a recovery obligation');
            strictAssertSame('move-move-refused', $e->getMessage(), 'original refused move reason is retained');
        }
        strictAssertSame('cancel-pending', dataDirClaimReload($root, $daemon)->phase(), 'cancel phase survives the lost reply');
        try { dataDirClaimReload($root, $daemon)->run(); throw new RuntimeException('cancellation reason was lost'); }
        catch (DataDirMoveRefused $e) {
            strictAssertSame('move-move-refused', $e->getMessage(), 'recovery reports the original cancellation reason');
        }
        strictAssertSame('absent', $daemon->phase, 'claim is safely retired after confirmation');
        strictAssertSame('A', file_get_contents($root . '/src/a'), 'payload never moved');
    } finally { strictRemoveTree($root); }
});

$suite->test('a claimed ready job with a changed generation stays held without restoring an unrelated torrent', function () {
    $root = dataDirClaimFixture();
    try {
        file_put_contents($root . '/src/a', 'A');
        $daemon = new DataDirClaimFake();
        $job = dataDirClaimJob($root, $daemon, array('a'), true);
        $hash = str_repeat('A', 40);
        $path = $root . '/jobs/' . $hash . '.json';
        $daemon->rpc('d.stop_close_claim', $hash, array($job->id()));
        $saved = json_decode(file_get_contents($path), true); $saved['phase'] = 'claimed';
        file_put_contents($path, json_encode($saved));
        DataDirMoveIntent::prepare($path . '.move', $job->id(), $hash, $root . '/src',
            $root . '/dst', array('a'), function () { return false; });
        $daemon->localId = 'ffffffffffffffffffffffffffffffff';
        try { dataDirClaimReload($root, $daemon, function () { return false; })->run();
            throw new RuntimeException('unrelated generation was restored'); }
        catch (RuntimeException $e) {
            strictAssertSame('torrent-generation-changed', $e->getMessage(), 'stale generation remains classified');
            strictAssertSame(false, $e instanceof DataDirMoveRefused, 'existing receipt keeps a recovery obligation');
        }
        strictAssertSame('active', $daemon->phase, 'stale receipt is never applied to a new generation');
        strictAssertSame(true, is_file($path . '.move'), 'stale receipt evidence remains available');
        strictAssertSame('A', file_get_contents($root . '/src/a'), 'stale payload remains untouched');
    } finally { strictRemoveTree($root); }
});

$suite->test('unmoved cancellation resumes across its durable phase and witness cleanup cuts', function () {
    strictAssertTrue(method_exists('DataDirMoveIntent', 'beginAbortUnmoved'),
        'unmoved proof has a durable phase before witness cleanup');
    foreach (array('intent-phase', 'first-witness', 'all-witnesses', 'receipt-removed') as $cut) {
        $root = dataDirClaimFixture();
        try {
            file_put_contents($root . '/src/a', 'A'); file_put_contents($root . '/src/b', 'B');
            $daemon = new DataDirClaimFake();
            $job = dataDirClaimJob($root, $daemon, array('a', 'b'), true);
            $hash = str_repeat('A', 40);
            $path = $root . '/jobs/' . $hash . '.json';
            $daemon->rpc('d.stop_close_claim', $hash, array($job->id()));
            $saved = json_decode(file_get_contents($path), true); $saved['phase'] = 'claimed';
            file_put_contents($path, json_encode($saved));
            $intent = DataDirMoveIntent::prepare($path . '.move', $job->id(), $hash,
                $root . '/src', $root . '/dst', array('a', 'b'), function () { return false; });
            strictAssertSame(true, $intent->beginAbortUnmoved(), 'unmoved proof is persisted before cleanup');
            strictAssertSame('aborting', DataDirMoveIntent::load($path . '.move')->phase(), 'abort marker survives reload');
            if ($cut !== 'intent-phase') {
                $saved['phase'] = 'cancel-pending';
                file_put_contents($path, json_encode($saved));
                $move = json_decode(file_get_contents($path . '.move'), true);
                unlink($move['witness'] . '/0');
                if ($cut === 'all-witnesses' || $cut === 'receipt-removed') {
                    unlink($move['witness'] . '/1'); rmdir($move['witness']);
                }
                if ($cut === 'receipt-removed') unlink($path . '.move');
            }
            $moves = 0;
            try {
                dataDirClaimReload($root, $daemon, function () use (&$moves) {
                    ++$moves; return false;
                })->run();
                throw new RuntimeException('legacy cancellation completed silently');
            } catch (DataDirMoveRefused $e) {
                strictAssertSame('interrupted-cancellation', $e->getMessage(),
                    'the old record cannot invent its lost reason at cut ' . $cut);
            }
            strictAssertSame(0, $moves, 'cancellation never retries a payload move');
            strictAssertSame('absent', $daemon->phase, 'cancelled claim is acknowledged');
            strictAssertSame(1, $daemon->state, 'original active state is restored');
            strictAssertSame('A', file_get_contents($root . '/src/a'), 'first source remains intact');
            strictAssertSame('B', file_get_contents($root . '/src/b'), 'second source remains intact');
            strictAssertSame(array(), array_values(array_diff(scandir($root . '/jobs'), array('.', '..'))),
                'every cancellation receipt is retired');
        } finally { strictRemoveTree($root); }
    }
});

$suite->test('durable cancellation refuses an unexpected witness entry and can recover after repair', function () {
    strictAssertTrue(method_exists('DataDirMoveIntent', 'beginAbortUnmoved'), 'durable abort phase exists');
    $root = dataDirClaimFixture();
    try {
        file_put_contents($root . '/src/a', 'A');
        $daemon = new DataDirClaimFake();
        $job = dataDirClaimJob($root, $daemon, array('a'), true);
        $hash = str_repeat('A', 40); $path = $root . '/jobs/' . $hash . '.json';
        $daemon->rpc('d.stop_close_claim', $hash, array($job->id()));
        $saved = json_decode(file_get_contents($path), true); $saved['phase'] = 'claimed';
        file_put_contents($path, json_encode($saved));
        $intent = DataDirMoveIntent::prepare($path . '.move', $job->id(), $hash,
            $root . '/src', $root . '/dst', array('a'), function () { return false; });
        strictAssertSame(true, $intent->beginAbortUnmoved(), 'cancellation proof is durable');
        $move = json_decode(file_get_contents($path . '.move'), true);
        file_put_contents($move['witness'] . '/unexpected', 'foreign');
        try { dataDirClaimReload($root, $daemon)->run(); throw new RuntimeException('unexpected entry was erased'); }
        catch (RuntimeException $e) {
            strictAssertSame('unmoved-abort-unconfirmed', $e->getMessage(), 'uncertain cleanup remains held');
        }
        strictAssertSame('active', $daemon->phase, 'unconfirmed cleanup never restores the torrent');
        strictAssertSame('cancel-pending', dataDirClaimReload($root, $daemon)->phase(),
            'cancellation is durable before attempted witness cleanup');
        strictAssertSame('aborting', DataDirMoveIntent::load($path . '.move')->phase(),
            'partial cleanup retains its durable proof');
        strictAssertSame(false, file_exists($move['witness'] . '/0'), 'cleanup reached a partial witness cut');
        strictAssertSame('foreign', file_get_contents($move['witness'] . '/unexpected'), 'foreign entry is preserved');
        unlink($move['witness'] . '/unexpected');
        try { dataDirClaimReload($root, $daemon)->run(); throw new RuntimeException('legacy cancellation completed silently'); }
        catch (DataDirMoveRefused $e) {
            strictAssertSame('interrupted-cancellation', $e->getMessage(), 'recovery reports the old cancellation after repair');
        }
        strictAssertSame('absent', $daemon->phase, 'repaired cancellation is acknowledged');
    } finally { strictRemoveTree($root); }
});

// AUD-ESC-08: a version-2 job written before the parser handed out the
// daemon's own bytes. Its previous directory is the escaped spelling of the
// daemon's, so neither spelling may decide whether the torrent moved.
$suite->test('a version-2 job with an escaped previous directory is refused as ambiguous and restores the run state', function () {
    foreach (array(array(1, 1, 1), array(0, 1, 0), array(0, 0, 0)) as $prestate) {
        $root = dataDirClaimFixture();
        try {
            file_put_contents($root . '/src/a', 'A');
            $daemon = new DataDirClaimFake();
            list($daemon->state, $daemon->open, $daemon->active) = $prestate;
            $daemon->path = '/old "x"';
            $path = dataDirClaimVersion2Job($root, $daemon,
                array('files' => array('a'), 'previous_directory' => '/old \"x\"'));
            $outcome = 'completed';
            try { dataDirClaimReload($root, $daemon)->run(); }
            catch (DataDirMoveRefused $e) { $outcome = 'refused ' . $e->getMessage(); }
            strictAssertSame('refused job-paths-ambiguous', $outcome,
                'the job is refused as ambiguous, prestate ' . implode('', $prestate));
            strictAssertSame($prestate, array($daemon->state, $daemon->open, $daemon->active),
                'the pre-claim state, open and active values are restored');
            strictAssertSame('absent', $daemon->phase, 'the cancelled claim is acknowledged');
            strictAssertSame(false, file_exists($path) || file_exists($path . '.move'),
                'the refused job and any receipt are retired');
            strictAssertSame(false, in_array('d.directory', $daemon->calls, true),
                'no daemon read is compared with the ambiguous bytes');
            strictAssertSame('/old "x"', $daemon->path, 'the torrent is not repointed');
            strictAssertSame('A', file_get_contents($root . '/src/a'), 'the payload stays at its source');
            strictAssertSame(array(), dataDirClaimTree($root . '/dst'), 'nothing is created at the destination');
        } finally { strictRemoveTree($root); }
    }
});

// The directory check alone cannot catch an escaped payload string: the
// daemon directory of a single-file download, or of any download moved with
// its parent unchanged, carries neither character. On the old reading the
// escaped spelling names a real neighbour, whose files would be moved.
function dataDirClaimEscapedNeighbourCase($shape)
{
    $root = dataDirClaimFixture();
    try {
        if ($shape === 'escaped source directory') {
            mkdir($root . '/a"b', 0700);
            mkdir($root . '/a\"b', 0700);
            file_put_contents($root . '/a"b/f.bin', 'real');
            file_put_contents($root . '/a\"b/f.bin', 'neighbour');
            $fields = array('source' => $root . '/a\"b', 'files' => array('f.bin'));
            $real = $root . '/a"b/f.bin';
            $neighbour = $root . '/a\"b/f.bin';
        } else {
            file_put_contents($root . '/src/x"y.bin', 'real');
            file_put_contents($root . '/src/x\"y.bin', 'neighbour');
            $fields = array('files' => array('x\"y.bin'));
            $real = $root . '/src/x"y.bin';
            $neighbour = $root . '/src/x\"y.bin';
        }
        $daemon = new DataDirClaimFake();
        $path = dataDirClaimVersion2Job($root, $daemon, $fields);
        $moves = 0;
        $outcome = 'completed';
        try {
            dataDirClaimReload($root, $daemon, function ($source, $destination) use (&$moves) {
                ++$moves;
                return !file_exists($destination) && rename($source, $destination);
            })->run();
        } catch (DataDirMoveRefused $e) { $outcome = 'refused ' . $e->getMessage(); }
        strictAssertSame('neighbour', @file_get_contents($neighbour),
            'the neighbour named by the escaped spelling stays where it was: ' . $shape);
        strictAssertSame(array(), dataDirClaimTree($root . '/dst'),
            'nothing is created at the destination: ' . $shape);
        strictAssertSame('refused job-paths-ambiguous', $outcome, 'the job is refused as ambiguous: ' . $shape);
        strictAssertSame(0, $moves, 'no rename is attempted: ' . $shape);
        strictAssertSame('real', file_get_contents($real), 'the download\'s own file stays too: ' . $shape);
        strictAssertSame(array(1, 1, 1), array($daemon->state, $daemon->open, $daemon->active),
            'the pre-claim run state is restored: ' . $shape);
        strictAssertSame('absent', $daemon->phase, 'the claim is acknowledged: ' . $shape);
        strictAssertSame('/old', $daemon->path, 'the torrent is not repointed: ' . $shape);
        strictAssertSame(false, file_exists($path) || file_exists($path . '.move'),
            'the job retires without a receipt: ' . $shape);
    } finally { strictRemoveTree($root); }
}

$suite->test('a new version-2 move job whose source directory names an escaped neighbour leaves the neighbour in place', function () {
    dataDirClaimEscapedNeighbourCase('escaped source directory');
});

$suite->test('a new version-2 move job whose file name names an escaped neighbour leaves the neighbour in place', function () {
    dataDirClaimEscapedNeighbourCase('escaped file name');
});

// The old worker took the claim and stopped before preparing its move: the
// replay re-takes the same claim, then cancels it instead of preparing.
$suite->test('a claimed version-2 job with an escaped file list is cancelled before any move is prepared', function () {
    $root = dataDirClaimFixture();
    try {
        file_put_contents($root . '/src/x"y.bin', 'real');
        file_put_contents($root . '/src/x\"y.bin', 'neighbour');
        $daemon = new DataDirClaimFake();
        $job = bin2hex(random_bytes(16));
        $daemon->rpc('d.stop_close_claim', str_repeat('A', 40), array($job));
        $path = dataDirClaimVersion2Job($root, $daemon,
            array('phase' => 'claimed', 'job' => $job, 'files' => array('x\"y.bin')));
        $outcome = 'completed';
        try { dataDirClaimReload($root, $daemon)->run(); }
        catch (DataDirMoveRefused $e) { $outcome = 'refused ' . $e->getMessage(); }
        strictAssertSame('neighbour', @file_get_contents($root . '/src/x\"y.bin'),
            'the neighbour named by the escaped file name stays where it was');
        strictAssertSame('refused job-paths-ambiguous', $outcome, 'the claimed job is refused as ambiguous');
        strictAssertSame(array(), dataDirClaimTree($root . '/dst'), 'no witness or payload reaches the destination');
        strictAssertSame('real', file_get_contents($root . '/src/x"y.bin'), 'the download\'s own file stays');
        strictAssertSame(array(1, 1, 1), array($daemon->state, $daemon->open, $daemon->active),
            'the state the old claim captured is restored');
        strictAssertSame('absent', $daemon->phase, 'the old claim is acknowledged');
        strictAssertSame(false, file_exists($path) || file_exists($path . '.move'), 'the job retires');
    } finally { strictRemoveTree($root); }
});

// R5-REV-01: an old worker that took the claim of a version-2 job with an
// escaped file list and stopped after publishing its payload receipt. Each
// listed name, 'x\"y.bin', is the escaped spelling of the download's own
// 'x"y.bin' and also names a real neighbour. $moved lists the receipt entries
// whose file the old worker had already renamed into the destination.
function dataDirClaimVersion2WithReceipt($root, $daemon, $receiptPhase, $files, $moved = array())
{
    $job = bin2hex(random_bytes(16));
    $daemon->rpc('d.stop_close_claim', str_repeat('A', 40), array($job));
    $path = dataDirClaimVersion2Job($root, $daemon,
        array('phase' => 'claimed', 'job' => $job, 'files' => $files));
    $source = realpath($root . '/src');
    $destination = realpath($root . '/dst');
    $witness = $destination . '/.rutorrent-datadir-' . $job;
    $entries = array();
    // A preparing receipt has no identities yet; its witness may hold some links.
    if (!mkdir($witness, 0700))
        throw new RuntimeException('fixture witness mkdir failed');
    foreach ($files as $index => $file) {
        if (!link($source . '/' . $file, $witness . '/' . $index))
            throw new RuntimeException('fixture witness link failed');
        $stat = lstat($witness . '/' . $index);
        $entries[] = array('path' => $file,
            'identity' => $receiptPhase === 'preparing' ? '' : $stat['dev'] . ':' . $stat['ino']);
    }
    foreach ($moved as $index)
        if (!rename($source . '/' . $files[$index], $destination . '/' . $files[$index]))
            throw new RuntimeException('fixture partial move failed');
    $receipt = array('version' => 2, 'phase' => $receiptPhase, 'job' => $job, 'hash' => str_repeat('A', 40),
        'source' => $source, 'destination' => $destination, 'witness' => $witness, 'files' => $entries);
    if (file_put_contents($path . '.move', json_encode($receipt, JSON_UNESCAPED_SLASHES)) === false
        || !chmod($path . '.move', 0600))
        throw new RuntimeException('fixture receipt write failed');
    return $path;
}

foreach (array('preparing', 'ready') as $receiptPhase) {
    $suite->test('a claimed version-2 job with an escaped file list and a ' . $receiptPhase
        . ' receipt is cancelled without a move', function () use ($receiptPhase) {
        $root = dataDirClaimFixture();
        try {
            file_put_contents($root . '/src/x"y.bin', 'real');
            file_put_contents($root . '/src/x\"y.bin', 'neighbour');
            $daemon = new DataDirClaimFake();
            $path = dataDirClaimVersion2WithReceipt($root, $daemon, $receiptPhase, array('x\"y.bin'));
            $moves = 0;
            $outcome = 'completed';
            try {
                dataDirClaimReload($root, $daemon, function ($source, $destination) use (&$moves) {
                    ++$moves;
                    return !file_exists($destination) && rename($source, $destination);
                })->run();
            }
            catch (DataDirMoveRefused $e) { $outcome = 'refused ' . $e->getMessage(); }
            catch (RuntimeException $e) { $outcome = 'held ' . $e->getMessage(); }
            strictAssertSame('neighbour', @file_get_contents($root . '/src/x\"y.bin'),
                'the neighbour named by the escaped file name stays where it was');
            strictAssertSame('refused job-paths-ambiguous', $outcome, 'the job is refused as ambiguous');
            strictAssertSame(0, $moves, 'no rename is attempted');
            strictAssertSame(array(), dataDirClaimTree($root . '/dst'),
                'the witness links are released and nothing reaches the destination');
            strictAssertSame('real', file_get_contents($root . '/src/x"y.bin'), 'the download\'s own file stays');
            strictAssertSame(array(1, 1, 1), array($daemon->state, $daemon->open, $daemon->active),
                'the state the old claim captured is restored');
            strictAssertSame('absent', $daemon->phase, 'the old claim is acknowledged');
            strictAssertSame('/old', $daemon->path, 'the torrent is not repointed');
            strictAssertSame(false, file_exists($path) || file_exists($path . '.move'),
                'the job and its receipt retire');
        } finally { strictRemoveTree($root); }
    });
}

// The old worker had already renamed one bound file. Advancing would move the
// rest of the files the escaped strings named, and nothing proves which
// download they belong to; putting the moved one back is not this job's call.
$suite->test('a claimed version-2 job with an escaped file list whose move had started is held, not advanced', function () {
    $root = dataDirClaimFixture();
    try {
        foreach (array('x', 'z') as $name) {
            file_put_contents($root . '/src/' . $name . '"y.bin', 'real ' . $name);
            file_put_contents($root . '/src/' . $name . '\"y.bin', 'neighbour ' . $name);
        }
        $daemon = new DataDirClaimFake();
        $path = dataDirClaimVersion2WithReceipt($root, $daemon, 'ready', array('x\"y.bin', 'z\"y.bin'), array(0));
        $job = json_decode(file_get_contents($path), true);
        $receipt = file_get_contents($path . '.move');
        $moves = 0;
        $outcome = 'completed';
        try {
            dataDirClaimReload($root, $daemon, function ($source, $destination) use (&$moves) {
                ++$moves;
                return !file_exists($destination) && rename($source, $destination);
            })->run();
        }
        catch (DataDirMoveRefused $e) { $outcome = 'refused ' . $e->getMessage(); }
        catch (RuntimeException $e) { $outcome = 'held ' . $e->getMessage(); }
        strictAssertSame('neighbour z', @file_get_contents($root . '/src/z\"y.bin'),
            'the file not moved yet stays where it was');
        strictAssertSame('held job-paths-ambiguous-unmoved-unproven', $outcome,
            'the job is held with a reason that names the ambiguity');
        strictAssertSame(0, $moves, 'no rename is attempted');
        strictAssertSame('neighbour x', @file_get_contents($root . '/dst/x\"y.bin'),
            'the file the old worker moved is left where it is');
        strictAssertSame('active', $daemon->phase, 'the claim stays with the held job');
        strictAssertSame('/old', $daemon->path, 'the torrent is not repointed');
        strictAssertSame(false, in_array('d.directory.base.set_if_stop_close_claim', $daemon->calls, true),
            'no directory setter is sent');
        strictAssertSame($job, json_decode(file_get_contents($path), true), 'the job is kept unchanged');
        strictAssertSame($receipt, file_get_contents($path . '.move'), 'and so is its receipt');
        strictAssertSame(array('real x', 'real z'), array(file_get_contents($root . '/src/x"y.bin'),
            file_get_contents($root . '/src/z"y.bin')), 'the download\'s own files stay');
    } finally { strictRemoveTree($root); }
});

// Past the physical move the job only publishes the request's own directory
// and finishes the claim with its id and token: it keeps working.
foreach (array('claimed', 'setter-pending') as $jobPhase) {
    $suite->test('a ' . $jobPhase . ' version-2 job with an escaped file list and a finishing receipt still completes',
        function () use ($jobPhase) {
        $root = dataDirClaimFixture();
        try {
            file_put_contents($root . '/src/x\"y.bin', 'moved');
            $daemon = new DataDirClaimFake();
            $path = dataDirClaimVersion2WithReceipt($root, $daemon, 'finishing', array('x\"y.bin'), array(0));
            $job = json_decode(file_get_contents($path), true);
            $job['phase'] = $jobPhase;
            file_put_contents($path, json_encode($job, JSON_UNESCAPED_SLASHES));
            strictAssertSame(true, dataDirClaimReload($root, $daemon)->run(), 'the job completes');
            strictAssertSame($root . '/dst/', $daemon->path, 'the setter gets the job\'s own directory');
            strictAssertSame(1, $daemon->state, 'the restored start is kept');
            strictAssertSame('absent', $daemon->phase, 'the claim is acknowledged');
            strictAssertSame(array('x\"y.bin'), dataDirClaimTree($root . '/dst'), 'the witness is cleaned');
            strictAssertSame(false, file_exists($path) || file_exists($path . '.move'),
                'the job and its receipt retire');
        } finally { strictRemoveTree($root); }
    });
}

$suite->test('a version-2 job without a backslash still runs exactly as before', function () {
    $root = dataDirClaimFixture();
    try {
        // A raw quote without a backslash is the same byte under either
        // reading: the old parser never left a quote unescaped.
        mkdir($root . '/dst "q"', 0700);
        mkdir($root . '/src/sub', 0700);
        file_put_contents($root . '/src/a', 'A');
        file_put_contents($root . '/src/sub/b', 'B');
        $daemon = new DataDirClaimFake();
        $path = dataDirClaimVersion2Job($root, $daemon, array('directory' => $root . '/dst "q"/',
            'destination' => $root . '/dst "q"', 'files' => array('a', 'sub/b')));
        strictAssertSame(true, dataDirClaimReload($root, $daemon)->run(), 'the version-2 job completes');
        strictAssertSame('A', @file_get_contents($root . '/dst "q"/a'), 'the first file moved');
        strictAssertSame('B', @file_get_contents($root . '/dst "q"/sub/b'), 'the nested file moved');
        strictAssertSame(array('sub'), dataDirClaimTree($root . '/src'), 'no payload is left at the source');
        strictAssertSame($root . '/dst "q"/', $daemon->path, 'the setter gets the job\'s directory');
        strictAssertSame(1, $daemon->state, 'the restored start is kept');
        strictAssertSame('absent', $daemon->phase, 'the claim is acknowledged');
        strictAssertSame(false, file_exists($path), 'the job retires');
    } finally { strictRemoveTree($root); }
});

$suite->test('a new job keeps raw backslash names as version 3 and moves exactly those files', function () {
    $root = dataDirClaimFixture();
    try {
        // Raw bytes: the directory a\"b holds c\\d.bin. Unescaped once, they
        // would read a"b and c\d.bin, which exist next to them.
        mkdir($root . '/a\"b', 0700);
        mkdir($root . '/a"b', 0700);
        file_put_contents($root . '/a\"b/c\\\\d.bin', 'raw');
        file_put_contents($root . '/a"b/c\\d.bin', 'neighbour');
        $daemon = new DataDirClaimFake();
        $job = DataDirMoveJob::create($root . '/jobs',
            array('hash' => str_repeat('A', 40), 'directory' => $root . '/dst/', 'local_id' => $daemon->localId,
                'add' => false, 'move' => true, 'source' => $root . '/a\"b', 'destination' => $root . '/dst',
                'files' => array('c\\\\d.bin'), 'previous_directory' => $daemon->path),
            array($daemon, 'rpc'), function ($source, $destination) {
                return !file_exists($destination) && rename($source, $destination);
            }, array($daemon, 'presence'));
        $saved = json_decode(file_get_contents($root . '/jobs/' . str_repeat('A', 40) . '.json'), true);
        $outcome = 'completed';
        try { $job->run(); }
        catch (DataDirMoveRefused $e) { $outcome = 'refused ' . $e->getMessage(); }
        strictAssertSame('completed', $outcome, 'the new job completes');
        strictAssertSame(array('c\\\\d.bin'), dataDirClaimTree($root . '/dst'), 'exactly the raw file is at the destination');
        strictAssertSame('raw', file_get_contents($root . '/dst/c\\\\d.bin'), 'with its bytes');
        strictAssertSame(array(), dataDirClaimTree($root . '/a\"b'), 'nothing is left at the raw source');
        strictAssertSame(array('c\\d.bin'), dataDirClaimTree($root . '/a"b'), 'the unescaped neighbour keeps its file');
        strictAssertSame('neighbour', file_get_contents($root . '/a"b/c\\d.bin'), 'with its bytes');
        strictAssertSame(3, $saved['version'], 'a new job records that its strings are the daemon\'s own bytes');
        strictAssertSame(array('c\\\\d.bin'), $saved['files'], 'the file list is stored unchanged');
    } finally { strictRemoveTree($root); }
});

// R5-REV-06: a release that reads only version 2 holds a version-3 job as
// job-corrupt forever, and with it the claim and every later change of that
// download. Without a backslash in a daemon string both versions mean the
// same bytes, so such a job stays readable after a rollback.
$suite->test('a new job needs version 3 when a daemon string holds a quote or backslash', function () {
    $cases = array(
        'no special byte' => array(array(), 2),
        'a quote without a backslash' => array(array('previous_directory' => '/old "x"',
            'source' => '/src "s"', 'destination' => '/dst "d"', 'files' => array('a "b".bin')), 3),
        'a backslash only in the request\'s own directory' => array(array('directory' => '/dst\\new/'), 2),
        'a backslash in the previous directory' => array(array('previous_directory' => '/old\\x'), 3),
        'a backslash in the source' => array(array('source' => '/src\\s'), 3),
        'a backslash in the destination' => array(array('destination' => '/dst\\d'), 3),
        'a backslash in a file name' => array(array('files' => array('a', 'b\\c.bin')), 3),
    );
    foreach ($cases as $label => $case) {
        $root = dataDirClaimFixture();
        try {
            $daemon = new DataDirClaimFake();
            $fields = array_merge(array('hash' => str_repeat('A', 40), 'directory' => '/dst/',
                'local_id' => $daemon->localId, 'add' => false, 'move' => true, 'source' => '/src',
                'destination' => '/dst', 'files' => array('a'), 'previous_directory' => '/old'), $case[0]);
            DataDirMoveJob::create($root . '/jobs', $fields, array($daemon, 'rpc'),
                function () { return false; }, array($daemon, 'presence'));
            $saved = json_decode(file_get_contents($root . '/jobs/' . str_repeat('A', 40) . '.json'), true);
            strictAssertSame($case[1], $saved['version'], 'version for ' . $label);
            strictAssertSame(array('version', 'phase', 'job', 'hash', 'directory', 'add', 'move', 'source',
                'destination', 'files', 'local_id', 'previous_directory'), array_keys($saved),
                'the layout the version-2 reader checks, for ' . $label);
            foreach (array('directory', 'source', 'destination', 'files', 'previous_directory') as $key)
                strictAssertSame($fields[$key], $saved[$key], $key . ' is stored unchanged for ' . $label);
            strictAssertSame('new', dataDirClaimReload($root, $daemon)->phase(), 'the job loads for ' . $label);
            strictAssertSame(array(), $daemon->calls, 'writing never reaches the daemon for ' . $label);
        } finally { strictRemoveTree($root); }
    }
});

$suite->test('only an exact version 2 or 3 job loads', function () {
    $root = dataDirClaimFixture();
    try {
        $daemon = new DataDirClaimFake();
        foreach (array(2, 3) as $version) {
            dataDirClaimVersion2Job($root, $daemon, array('version' => $version));
            try { $result = dataDirClaimReload($root, $daemon)->phase(); }
            catch (RuntimeException $e) { $result = $e->getMessage(); }
            strictAssertSame('new', $result, 'version ' . $version . ' loads');
        }
        foreach (array(1, 4, '3', '2', 3.0, null, 'missing') as $version) {
            $path = dataDirClaimVersion2Job($root, $daemon, array('version' => $version));
            if ($version === 'missing' || $version === 3.0) {
                // json_encode() writes 3.0 as 3 unless asked to keep the fraction.
                $data = json_decode(file_get_contents($path), true);
                if ($version === 'missing') unset($data['version']); else $data['version'] = 3.0;
                file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
                strictAssertTrue($version === 'missing' || strpos(file_get_contents($path), '{"version":3.0,') === 0,
                    'the fixture writes a fractional version');
            }
            $label = var_export($version, true);
            try { dataDirClaimReload($root, $daemon); $result = 'loaded'; }
            catch (RuntimeException $e) { $result = $e->getMessage(); }
            strictAssertSame('job-corrupt', $result, 'version ' . $label . ' is refused');
        }
        strictAssertSame(array(), $daemon->calls, 'loading never reaches the daemon');
    } finally { strictRemoveTree($root); }
});

// POST-1011-01: on drvfs over 9p (cache=0x5) lstat of a new name answered
// ENOENT once right after its successful rename. Read as missing, a moved
// file made advance() answer move-incomplete and held the whole job.
require_once __DIR__ . '/../autotools/SettlingRenameFiles.php';
function dataDirSettleInstall($files)
{
    // Absent before the shared settle was wired in; the behaviour is then
    // what the run shows.
    if (property_exists('DataDirMoveIntent', 'files'))
        strictSetPrivateStatic('DataDirMoveIntent', 'files', $files);
}

foreach (array('confirmed late' => 3, 'never answers' => ERASEDATA_RENAME_SETTLE_ATTEMPTS + 1,
    'another inode' => 2) as $case => $revealAt)
    $suite->test('a moved file whose destination answers late: ' . $case, function () use ($case, $revealAt) {
        $root = dataDirClaimFixture();
        try {
            $hash = str_repeat('A', 40);
            file_put_contents($root . '/src/a', 'alpha');
            file_put_contents($root . '/src/b', 'beta');
            $daemon = new DataDirClaimFake();
            $files = new SettlingRenameFiles('#^' . preg_quote($root . '/dst/b', '#') . '$#D', $revealAt,
                $case === 'another inode');
            $moves = 0;
            $move = function ($from, $to) use ($files, &$moves) { $moves++; return $files->renameNoReplace($from, $to); };
            dataDirSettleInstall($files);
            $job = dataDirClaimJob($root, $daemon, array('a', 'b'), true, $move);
            try { $result = $job->run(); }
            catch (RuntimeException $e) { $result = $e->getMessage(); }
            strictAssertSame(2, $moves, 'each file is renamed once');
            if ($case === 'confirmed late') {
                strictAssertSame(true, $result, 'the move completes in the same run');
                strictAssertSame(array($root . '/dst/b' => 3), $files->lookups, 'the destination is confirmed on its third lookup');
                strictAssertSame(array('a', 'b'), dataDirClaimTree($root . '/dst'), 'both files and nothing else at the destination');
                strictAssertSame('beta', file_get_contents($root . '/dst/b'), 'moved bytes');
                strictAssertSame(array(), dataDirClaimTree($root . '/src'), 'the sources are gone');
                strictAssertSame(array(), dataDirClaimTree($root . '/jobs'), 'the job and its receipt retire');
                strictAssertSame('absent', $daemon->phase, 'the daemon claim is acknowledged');
                return;
            }
            $bound = $case === 'never answers' ? ERASEDATA_RENAME_SETTLE_ATTEMPTS : 2;
            strictAssertSame(array($root . '/dst/b' => $bound), $files->lookups, 'the settle stops at its bound or first answer');
            strictAssertSame($case === 'never answers' ? 'move-move-incomplete' : 'move-occupied-destination', $result,
                'the classification is the one an absent or foreign destination always had');
            strictAssertSame(true, is_file($root . '/jobs/' . $hash . '.json'), 'the job is retained for recovery');
            strictAssertSame('active', $daemon->phase, 'the claim stays held');
            strictAssertSame('beta', file_get_contents(SettlingRenameFiles::hiddenName($root . '/dst/b')), 'the moved inode is untouched');
            if ($case === 'another inode') return;
            rename(SettlingRenameFiles::hiddenName($root . '/dst/b'), $root . '/dst/b');
            strictAssertSame(true, dataDirClaimReload($root, $daemon, $move)->run(), 'recovery finishes the job');
            strictAssertSame(2, $moves, 'recovery renames nothing again');
            strictAssertSame(array('a', 'b'), dataDirClaimTree($root . '/dst'), 'both files at the destination');
        } finally { dataDirSettleInstall(null); strictRemoveTree($root); }
    });

exit($suite->run());
