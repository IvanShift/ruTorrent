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
    public $loseFinishOnce = false;
    public $loseSetterOnce = false;
    public $cancelRestore = false;
    private $prestate;

    private function receipt()
    {
        return $this->token . '|' . $this->prestate[0] . '|' . $this->prestate[1]
            . '|' . $this->prestate[2] . '|' . $this->localId;
    }

    public function rpc($method, $hash, $args)
    {
        $this->calls[] = $method;
        if ($method === 'd.directory') return $this->path;
        if ($method === 'd.stop_close_claim_state')
            return $this->phase === 'absent' ? 'absent'
                : $this->phase . '|' . ($this->phase === 'active' ? '' : ($this->cancelRestore ? 'K|' : 'S|'))
                    . $this->receipt();
        if ($method === 'd.stop_close_claim') {
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

function dataDirClaimClean($path)
{
    if (!is_dir($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (array_diff(scandir($path), array('.', '..')) as $name)
        dataDirClaimClean($path . '/' . $name);
    @rmdir($path);
}

function dataDirClaimJob($root, $daemon, $files, $move, $rename = null)
{
    $hash = str_repeat('A', 40);
    return DataDirMoveJob::create($root . '/jobs',
        array('hash' => $hash, 'directory' => $root . '/dst', 'local_id' => $daemon->localId,
            'add' => false, 'move' => $move, 'source' => $root . '/src',
            'destination' => $root . '/dst', 'files' => $files,
            'previous_directory' => $daemon->path),
        array($daemon, 'rpc'), $rename !== null ? $rename : function ($source, $destination) {
            if (file_exists($destination) || is_link($destination)) return false;
            return rename($source, $destination);
        });
}

function dataDirClaimReload($root, $daemon, $rename = null)
{
    $path = $root . '/jobs/' . str_repeat('A', 40) . '.json';
    return DataDirMoveJob::load($path, array($daemon, 'rpc'),
        $rename !== null ? $rename : function ($source, $destination) {
            if (file_exists($destination) || is_link($destination)) return false;
            return rename($source, $destination);
        });
}

$suite = new StrictTestSuite();

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
        dataDirClaimClean($root);
    }
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
        dataDirClaimClean($root);
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
        dataDirClaimClean($root);
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
        dataDirClaimClean($root);
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
        dataDirClaimClean($root);
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
        strictAssertSame(true, dataDirClaimReload($root, $daemon)->run(),
            'replay acknowledges previous terminal decision');
        strictAssertSame('A', file_get_contents($root . '/src/a'), 'source retained');
        strictAssertSame('foreign', file_get_contents($root . '/dst/a'), 'foreign destination retained');
        strictAssertSame(1, $daemon->state, 'original state restored once');
    } finally {
        dataDirClaimClean($root);
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
        dataDirClaimClean($root);
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
        dataDirClaimClean($root);
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
        dataDirClaimClean($root);
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
        } finally { dataDirClaimClean($root); }
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
    } finally { dataDirClaimClean($root); }
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
    } finally { dataDirClaimClean($root); }
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
    } finally { dataDirClaimClean($root); }
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
        strictAssertSame(true, dataDirClaimReload($root, $daemon)->run(), 'next recovery confirms and acknowledges cancellation');
        strictAssertSame('absent', $daemon->phase, 'claim is safely retired after confirmation');
        strictAssertSame('A', file_get_contents($root . '/src/a'), 'payload never moved');
    } finally { dataDirClaimClean($root); }
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
    } finally { dataDirClaimClean($root); }
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
            strictAssertSame(true, dataDirClaimReload($root, $daemon, function () use (&$moves) {
                ++$moves; return false;
            })->run(), 'cancellation resumes at cut ' . $cut);
            strictAssertSame(0, $moves, 'cancellation never retries a payload move');
            strictAssertSame('absent', $daemon->phase, 'cancelled claim is acknowledged');
            strictAssertSame(1, $daemon->state, 'original active state is restored');
            strictAssertSame('A', file_get_contents($root . '/src/a'), 'first source remains intact');
            strictAssertSame('B', file_get_contents($root . '/src/b'), 'second source remains intact');
            strictAssertSame(array(), array_values(array_diff(scandir($root . '/jobs'), array('.', '..'))),
                'every cancellation receipt is retired');
        } finally { dataDirClaimClean($root); }
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
        strictAssertSame(true, dataDirClaimReload($root, $daemon)->run(), 'recovery finishes after the foreign entry is removed');
        strictAssertSame('absent', $daemon->phase, 'repaired cancellation is acknowledged');
    } finally { dataDirClaimClean($root); }
});

exit($suite->run());
