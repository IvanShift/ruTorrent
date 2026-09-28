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

function dataDirClaimJob($root, $daemon, $files, $move)
{
    $hash = str_repeat('A', 40);
    return DataDirMoveJob::create($root . '/jobs',
        array('hash' => $hash, 'directory' => $root . '/dst', 'local_id' => $daemon->localId,
            'add' => false, 'move' => $move, 'source' => $root . '/src',
            'destination' => $root . '/dst', 'files' => $files,
            'previous_directory' => $daemon->path),
        array($daemon, 'rpc'), function ($source, $destination) {
            if (file_exists($destination) || is_link($destination)) return false;
            return rename($source, $destination);
        });
}

function dataDirClaimReload($root, $daemon)
{
    $path = $root . '/jobs/' . str_repeat('A', 40) . '.json';
    return DataDirMoveJob::load($path, array($daemon, 'rpc'),
        function ($source, $destination) {
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

exit($suite->run());
