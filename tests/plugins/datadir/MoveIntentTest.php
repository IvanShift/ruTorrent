<?php

require_once(__DIR__ . '/../../php/TestCase.php');
umask(0022);
$candidate = __DIR__ . '/../../../plugins/datadir/moveintent.php';
if (is_file($candidate)) require_once($candidate);

function dataDirMoveFixture()
{
    $root = (getenv('TMPDIR') ?: sys_get_temp_dir()) . '/datadir-intent-' . bin2hex(random_bytes(8));
    if (!mkdir($root, 0700) || !mkdir($root . '/from', 0700)
        || !mkdir($root . '/to', 0700) || !mkdir($root . '/receipts', 0700))
        throw new RuntimeException('Cannot create DataDir intent fixture');
    return $root;
}

function dataDirRemoveFixture($path)
{
    if (!is_dir($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (array_diff(scandir($path), array('.', '..')) as $entry)
        dataDirRemoveFixture($path . '/' . $entry);
    rmdir($path);
}

function dataDirNoReplace($source, $destination)
{
    if (@lstat($destination) !== false)
        return false;
    return @rename($source, $destination);
}

$tests = array(
    'a two-file receipt resumes after a crash cut without moving either file twice' => function () {
        $root = dataDirMoveFixture();
        try {
            file_put_contents($root . '/from/one', 'one');
            file_put_contents($root . '/from/two', 'two');
            $receipt = $root . '/receipts/' . str_repeat('A', 40) . '.json';
            $intent = DataDirMoveIntent::prepare($receipt, str_repeat('b', 32), str_repeat('A', 40),
                $root . '/from', $root . '/to', array('one', 'two'), 'dataDirNoReplace');
            testAssertSame('pending', $intent->advance(1), 'first file moved and receipt remains');
            testAssertSame(false, $intent->markFinishing(), 'partial transfer cannot enter daemon finish');
            testAssertSame(false, file_exists($root . '/from/one'), 'first source is gone');
            testAssertSame('one', file_get_contents($root . '/to/one'), 'first destination has original bytes');
            testAssertSame('two', file_get_contents($root . '/from/two'), 'second source is untouched');
            testAssertSame(true, is_file($receipt), 'the receipt survives the worker');

            $intent = DataDirMoveIntent::load($receipt, 'dataDirNoReplace');
            testAssertSame('complete', $intent->advance(), 'recovery finishes the remaining file');
            testAssertSame('one', file_get_contents($root . '/to/one'), 'first file remains intact');
            testAssertSame('two', file_get_contents($root . '/to/two'), 'second file is published');
            testAssertSame(true, $intent->markFinishing(), 'daemon finish is durably announced');
            testAssertSame(true, $intent->markTerminal(), 'daemon terminal answer is durably recorded');
            testAssertSame(true, $intent->markAckPending(), 'witness cleanup retains ACK receipt');
            testAssertSame(true, $intent->retire(), 'daemon ACK permits receipt removal');
            testAssertSame(false, file_exists($receipt), 'completed receipt is removed');
        } finally {
            dataDirRemoveFixture($root);
        }
    },
    'an occupied second destination survives and recovery waits without overwriting it' => function () {
        $root = dataDirMoveFixture();
        try {
            file_put_contents($root . '/from/one', 'one');
            file_put_contents($root . '/from/two', 'two');
            $receipt = $root . '/receipts/' . str_repeat('C', 40) . '.json';
            $intent = DataDirMoveIntent::prepare($receipt, str_repeat('d', 32), str_repeat('C', 40),
                $root . '/from', $root . '/to', array('one', 'two'), 'dataDirNoReplace');
            file_put_contents($root . '/to/two', 'foreign');
            testAssertSame('occupied-destination', $intent->advance(), 'second path is blocked');
            testAssertSame('foreign', file_get_contents($root . '/to/two'), 'foreign destination is intact');
            testAssertSame('two', file_get_contents($root . '/from/two'), 'second source is intact');
            testAssertSame(true, is_file($receipt), 'blocked intent is retained');
            unlink($root . '/to/two');
            testAssertSame('complete', DataDirMoveIntent::load($receipt, 'dataDirNoReplace')->advance(),
                'the same job resumes after the collision is cleared');
            testAssertSame('two', file_get_contents($root . '/to/two'), 'source reaches destination');
        } finally {
            dataDirRemoveFixture($root);
        }
    },
    'a missing no-replace mover refuses before publishing a job receipt' => function () {
        $root = dataDirMoveFixture();
        try {
            file_put_contents($root . '/from/one', 'one');
            $receipt = $root . '/receipts/' . str_repeat('D', 40) . '.json';
            try {
                DataDirMoveIntent::prepare($receipt, str_repeat('5', 32), str_repeat('D', 40),
                    $root . '/from', $root . '/to', array('one'));
                throw new RuntimeException('expected move-unavailable refusal');
            } catch (RuntimeException $error) {
                testAssertSame('move-unavailable', $error->getMessage(),
                    'standalone runtime refuses before a daemon claim can use this receipt');
            }
            testAssertSame(false, file_exists($receipt), 'no unexecutable job was published');
            testAssertSame('one', file_get_contents($root . '/from/one'), 'source is untouched');
            testAssertSame(false, file_exists($root . '/to/one'), 'destination stays absent');
        } finally {
            dataDirRemoveFixture($root);
        }
    },
    'preflight refuses an occupied later path before moving any file' => function () {
        $root = dataDirMoveFixture();
        try {
            file_put_contents($root . '/from/one', 'one');
            file_put_contents($root . '/from/two', 'two');
            file_put_contents($root . '/to/two', 'foreign');
            $receipt = $root . '/receipts/' . str_repeat('1', 40) . '.json';
            try {
                DataDirMoveIntent::prepare($receipt, str_repeat('2', 32), str_repeat('1', 40),
                    $root . '/from', $root . '/to', array('one', 'two'), 'dataDirNoReplace');
                throw new RuntimeException('expected occupied-destination refusal');
            } catch (RuntimeException $error) {
                testAssertSame('occupied-destination', $error->getMessage(), 'classified preflight refusal');
            }
            testAssertSame('one', file_get_contents($root . '/from/one'), 'first source never moved');
            testAssertSame('foreign', file_get_contents($root . '/to/two'), 'foreign destination remains');
            testAssertSame(false, file_exists($receipt), 'no receipt was published');
        } finally {
            dataDirRemoveFixture($root);
        }
    },
    'a replaced source inode cannot be moved under an old receipt' => function () {
        $root = dataDirMoveFixture();
        try {
            file_put_contents($root . '/from/one', 'original');
            $receipt = $root . '/receipts/' . str_repeat('3', 40) . '.json';
            $intent = DataDirMoveIntent::prepare($receipt, str_repeat('4', 32), str_repeat('3', 40),
                $root . '/from', $root . '/to', array('one'), 'dataDirNoReplace');
            file_put_contents($root . '/from/replacement', 'foreign');
            rename($root . '/from/replacement', $root . '/from/one');
            testAssertSame('changed-source', $intent->advance(), 'identity mismatch refuses transfer');
            testAssertSame('foreign', file_get_contents($root . '/from/one'), 'new source is intact');
            testAssertSame(false, file_exists($root . '/to/one'), 'nothing is published');
            testAssertSame(true, is_file($receipt), 'receipt stays for diagnosis');
        } finally {
            dataDirRemoveFixture($root);
        }
    },
    'a source replaced at the native move seam is held on replay, not certified' => function () {
        $root = dataDirMoveFixture();
        try {
            file_put_contents($root . '/from/one', 'original');
            file_put_contents($root . '/from/replacement', 'foreign');
            $receipt = $root . '/receipts/' . str_repeat('6', 40) . '.json';
            $mover = function ($source, $destination) use ($root) {
                rename($source, $root . '/from/aside');
                rename($root . '/from/replacement', $source);
                return dataDirNoReplace($source, $destination);
            };
            $intent = DataDirMoveIntent::prepare($receipt, str_repeat('7', 32), str_repeat('6', 40),
                $root . '/from', $root . '/to', array('one'), $mover);
            testAssertSame('occupied-destination', $intent->advance(),
                'the post-move identity check refuses the substituted file');
            testAssertSame('foreign', file_get_contents($root . '/to/one'),
                'foreign bytes are held, never deleted by a path rollback');
            testAssertSame('original', file_get_contents($root . '/from/aside'),
                'original inode remains available for reconciliation');
            testAssertSame('occupied-destination', DataDirMoveIntent::load($receipt, 'dataDirNoReplace')->advance(),
                'a process restart cannot certify the foreign destination');
            testAssertSame(true, is_file($receipt), 'the hold remains visible');
        } finally {
            dataDirRemoveFixture($root);
        }
    },
    'a changed destination parent is refused before publishing data outside the target' => function () {
        $root = dataDirMoveFixture();
        try {
            mkdir($root . '/from/sub', 0700);
            mkdir($root . '/to/sub', 0700);
            mkdir($root . '/outside', 0700);
            file_put_contents($root . '/from/sub/one', 'one');
            $receipt = $root . '/receipts/' . str_repeat('5', 40) . '.json';
            $intent = DataDirMoveIntent::prepare($receipt, str_repeat('6', 32), str_repeat('5', 40),
                $root . '/from', $root . '/to', array('sub/one'), 'dataDirNoReplace');
            rmdir($root . '/to/sub');
            symlink($root . '/outside', $root . '/to/sub');
            testAssertSame('destination-outside-root', $intent->advance(), 'parent escape is refused');
            testAssertSame('one', file_get_contents($root . '/from/sub/one'), 'source remains');
            testAssertSame(false, file_exists($root . '/outside/one'), 'outside path untouched');
        } finally {
            dataDirRemoveFixture($root);
        }
    },
    'a retained inode witness prevents ABA and restores a missing destination' => function () {
        $root = dataDirMoveFixture();
        try {
            file_put_contents($root . '/from/one', 'original');
            $receipt = $root . '/receipts/' . str_repeat('7', 40) . '.json';
            $intent = DataDirMoveIntent::prepare($receipt, str_repeat('8', 32), str_repeat('7', 40),
                $root . '/from', $root . '/to', array('one'), 'dataDirNoReplace');
            testAssertSame('complete', $intent->advance(), 'original reaches destination');
            clearstatcache(true, $root . '/to/one');
            $originalInode = lstat($root . '/to/one')['ino'];
            unlink($root . '/to/one');
            file_put_contents($root . '/to/one', 'foreign');
            clearstatcache(true, $root . '/to/one');
            testAssertSame(false, $originalInode === lstat($root . '/to/one')['ino'],
                'witness keeps the original inode allocated');
            $recovered = DataDirMoveIntent::load($receipt, 'dataDirNoReplace');
            testAssertSame('occupied-destination', $recovered->advance(), 'foreign bytes never count as complete');
            testAssertSame('foreign', file_get_contents($root . '/to/one'), 'foreign destination is preserved');
            unlink($root . '/to/one');
            testAssertSame('complete', $recovered->advance(), 'witness restores the missing destination');
            testAssertSame('original', file_get_contents($root . '/to/one'), 'original bytes are recovered');
            testAssertSame(true, $recovered->markFinishing(), 'prepare daemon finalization');
            testAssertSame(true, $recovered->markTerminal(), 'record daemon terminal outcome');
            testAssertSame(true, DataDirMoveIntent::load($receipt, 'dataDirNoReplace')->markAckPending(),
                'witness cleanup works in a fresh process');
            testAssertSame(true, DataDirMoveIntent::load($receipt, 'dataDirNoReplace')->retire(),
                'ACKed receipt is retired in a fresh process');
        } finally {
            dataDirRemoveFixture($root);
        }
    },
    'a missing witness blocks replay before any source mutation' => function () {
        $root = dataDirMoveFixture();
        try {
            file_put_contents($root . '/from/one', 'one');
            $receipt = $root . '/receipts/' . str_repeat('d', 40) . '.json';
            $intent = DataDirMoveIntent::prepare($receipt, str_repeat('e', 32), str_repeat('d', 40),
                $root . '/from', $root . '/to', array('one'), 'dataDirNoReplace');
            $data = json_decode(file_get_contents($receipt), true);
            unlink($data['witness'] . '/0');
            testAssertSame('missing-or-changed-witness', $intent->advance(), 'inode authority is required');
            testAssertSame('one', file_get_contents($root . '/from/one'), 'source is unchanged');
            testAssertSame(false, file_exists($root . '/to/one'), 'destination is untouched');
            testAssertSame(true, is_file($receipt), 'receipt remains visible');
        } finally {
            dataDirRemoveFixture($root);
        }
    },
    'preparing phase cleans linked witnesses after a preclaim worker cut' => function () {
        $root = dataDirMoveFixture();
        try {
            file_put_contents($root . '/from/one', 'one');
            $receipt = $root . '/receipts/' . str_repeat('9', 40) . '.json';
            $intent = DataDirMoveIntent::prepare($receipt, str_repeat('a', 32), str_repeat('9', 40),
                $root . '/from', $root . '/to', array('one'), 'dataDirNoReplace');
            $data = json_decode(file_get_contents($receipt), true);
            $data['phase'] = 'preparing';
            file_put_contents($receipt, json_encode($data));
            $recovered = DataDirMoveIntent::load($receipt, 'dataDirNoReplace');
            testAssertSame('preparing', $recovered->phase(), 'recovery sees the preclaim phase');
            testAssertSame(true, $recovered->abortPreparation(), 'orphan witnesses are removed');
            testAssertSame('one', file_get_contents($root . '/from/one'), 'source link is untouched');
            testAssertSame(false, file_exists($data['witness']), 'private witness root is gone');
            testAssertSame(false, file_exists($receipt), 'preparing receipt is retired');
        } finally {
            dataDirRemoveFixture($root);
        }
    },
    'terminal cleanup resumes after one witness link was already removed' => function () {
        $root = dataDirMoveFixture();
        try {
            file_put_contents($root . '/from/one', 'one');
            file_put_contents($root . '/from/two', 'two');
            $receipt = $root . '/receipts/' . str_repeat('b', 40) . '.json';
            $intent = DataDirMoveIntent::prepare($receipt, str_repeat('c', 32), str_repeat('b', 40),
                $root . '/from', $root . '/to', array('one', 'two'), 'dataDirNoReplace');
            testAssertSame('complete', $intent->advance(), 'payload reaches destination');
            testAssertSame(true, $intent->markFinishing(), 'finish intent is recorded');
            testAssertSame(true, $intent->markTerminal(), 'daemon terminal result is recorded');
            $data = json_decode(file_get_contents($receipt), true);
            unlink($data['witness'] . '/0');
            testAssertSame(true, DataDirMoveIntent::load($receipt, 'dataDirNoReplace')->markAckPending(),
                'cleanup completes after a process cut');
            testAssertSame(true, is_file($receipt), 'the daemon ACK obligation remains discoverable');
            testAssertSame(true, DataDirMoveIntent::load($receipt, 'dataDirNoReplace')->retire(),
                'ACKed receipt is removed after cleanup');
            testAssertSame('one', file_get_contents($root . '/to/one'), 'payload remains');
            testAssertSame('two', file_get_contents($root . '/to/two'), 'second payload remains');
            testAssertSame(false, file_exists($data['witness']), 'witness root is gone');
            testAssertSame(false, file_exists($receipt), 'terminal receipt is gone');
        } finally {
            dataDirRemoveFixture($root);
        }
    },
    'preparing phase cannot retire without a daemon terminal result' => function () {
        $root = dataDirMoveFixture();
        try {
            file_put_contents($root . '/from/one', 'one');
            $receipt = $root . '/receipts/' . str_repeat('9', 40) . '.json';
            $intent = DataDirMoveIntent::prepare($receipt, str_repeat('a', 32), str_repeat('9', 40),
                $root . '/from', $root . '/to', array('one'), 'dataDirNoReplace');
            testAssertSame('ready', $intent->phase(), 'published receipt may be claimed');
            testAssertSame(false, $intent->retire(), 'uncommitted ready receipt cannot be retired');
            testAssertSame(true, is_file($receipt), 'receipt remains');
        } finally {
            dataDirRemoveFixture($root);
        }
    },
    'daemon DONE cleanup keeps a discoverable receipt until ACK' => function () {
        $root = dataDirMoveFixture();
        try {
            file_put_contents($root . '/from/one', 'one');
            $receipt = $root . '/receipts/' . str_repeat('F', 40) . '.json';
            $intent = DataDirMoveIntent::prepare($receipt, str_repeat('1', 32), str_repeat('F', 40),
                $root . '/from', $root . '/to', array('one'), 'dataDirNoReplace');
            testAssertSame('complete', $intent->advance(), 'payload is at destination');
            testAssertSame(true, $intent->markFinishing(), 'finish intent is recorded');
            testAssertSame(true, $intent->markTerminal(), 'matched daemon DONE is recorded');
            testAssertSame(false, $intent->retire(), 'receipt cannot vanish before daemon ACK');
            testAssertSame(true, $intent->markAckPending(), 'witness cleanup is completed');
            testAssertSame(true, is_file($receipt), 'receipt remains discoverable for lost ACK response');
            testAssertSame('ack-pending', DataDirMoveIntent::load($receipt)->phase(),
                'fresh worker sees ACK obligation');
            testAssertSame(true, DataDirMoveIntent::load($receipt)->retire(),
                'only a caller with confirmed daemon ACK may remove the receipt');
            testAssertSame(false, file_exists($receipt), 'ACKed receipt is gone');
        } finally {
            dataDirRemoveFixture($root);
        }
    },
    'ACK cleanup resumes after one witness was removed before a worker cut' => function () {
        $root = dataDirMoveFixture();
        try {
            file_put_contents($root . '/from/one', 'one');
            file_put_contents($root . '/from/two', 'two');
            $receipt = $root . '/receipts/' . str_repeat('A', 40) . '.json';
            $intent = DataDirMoveIntent::prepare($receipt, str_repeat('2', 32), str_repeat('A', 40),
                $root . '/from', $root . '/to', array('one', 'two'), 'dataDirNoReplace');
            testAssertSame('complete', $intent->advance(), 'both payload files moved');
            testAssertSame(true, $intent->markFinishing(), 'finish intent recorded');
            testAssertSame(true, $intent->markTerminal(), 'daemon DONE recorded');
            $data = json_decode(file_get_contents($receipt), true);
            unlink($data['witness'] . '/0');
            $recovered = DataDirMoveIntent::load($receipt);
            testAssertSame(true, $recovered->markAckPending(), 'cleanup resumes idempotently');
            testAssertSame(false, file_exists($data['witness']), 'all witness links are removed');
            testAssertSame(true, is_file($receipt), 'ACK obligation survives cleanup');
        } finally {
            dataDirRemoveFixture($root);
        }
    },
    'a changed destination cannot become daemon DONE under an old witness' => function () {
        $root = dataDirMoveFixture();
        try {
            file_put_contents($root . '/from/one', 'original');
            $receipt = $root . '/receipts/' . str_repeat('B', 40) . '.json';
            $intent = DataDirMoveIntent::prepare($receipt, str_repeat('3', 32), str_repeat('B', 40),
                $root . '/from', $root . '/to', array('one'), 'dataDirNoReplace');
            testAssertSame('complete', $intent->advance(), 'original payload moved');
            testAssertSame(true, $intent->markFinishing(), 'finish intent recorded');
            unlink($root . '/to/one');
            file_put_contents($root . '/to/one', 'foreign');
            testAssertSame(false, $intent->markTerminal(), 'foreign destination cannot be certified');
            testAssertSame('finishing', DataDirMoveIntent::load($receipt)->phase(),
                'the unproved receipt remains recoverable');
        } finally {
            dataDirRemoveFixture($root);
        }
    },
    'ACK receipt remains readable after the empty source root is removed' => function () {
        $root = dataDirMoveFixture();
        try {
            file_put_contents($root . '/from/one', 'one');
            $receipt = $root . '/receipts/' . str_repeat('C', 40) . '.json';
            $intent = DataDirMoveIntent::prepare($receipt, str_repeat('4', 32), str_repeat('C', 40),
                $root . '/from', $root . '/to', array('one'), 'dataDirNoReplace');
            testAssertSame('complete', $intent->advance(), 'payload moved');
            testAssertSame(true, $intent->markFinishing(), 'finish intent recorded');
            testAssertSame(true, $intent->markTerminal(), 'daemon DONE recorded');
            testAssertSame(true, $intent->markAckPending(), 'cleanup leaves a receipt');
            rmdir($root . '/from');
            testAssertSame('ack-pending', DataDirMoveIntent::load($receipt)->phase(),
                'missing old root cannot strand the ACK');
        } finally {
            dataDirRemoveFixture($root);
        }
    },
    'cross-filesystem move refuses before the first file and before receipt publication' => function () {
        $root = dataDirMoveFixture();
        $other = '/dev/shm/datadir-intent-' . bin2hex(random_bytes(8));
        try {
            if (@stat('/dev/shm')['dev'] === @stat($root)['dev'])
                throw new RuntimeException('Cross-filesystem fixture needs distinct /dev/shm');
            if (!mkdir($other, 0700))
                throw new RuntimeException('Cannot create cross-filesystem fixture');
            file_put_contents($root . '/from/one', 'one');
            $receipt = $root . '/receipts/' . str_repeat('E', 40) . '.json';
            try {
                DataDirMoveIntent::prepare($receipt, str_repeat('f', 32), str_repeat('E', 40),
                    $root . '/from', $other, array('one'), 'dataDirNoReplace');
                throw new RuntimeException('expected cross-filesystem refusal');
            } catch (RuntimeException $error) {
                testAssertSame('cross-filesystem', $error->getMessage(), 'classified refusal');
            }
            testAssertSame('one', file_get_contents($root . '/from/one'), 'source remains intact');
            testAssertSame(false, file_exists($other . '/one'), 'destination remains empty');
            testAssertSame(false, file_exists($receipt), 'no pending receipt exists');
        } finally {
            dataDirRemoveFixture($root);
            if (is_dir($other)) dataDirRemoveFixture($other);
        }
    },

    'source writable by another UID refuses before witness publication' => function () {
        $root = dataDirMoveFixture();
        try {
            file_put_contents($root . '/from/a', 'A');
            chmod($root . '/from/a', 0666);
            $receipt = $root . '/receipts/' . str_repeat('B', 40) . '.json';
            try {
                DataDirMoveIntent::prepare($receipt, str_repeat('b', 32), str_repeat('B', 40),
                    $root . '/from', $root . '/to', array('a'), 'dataDirNoReplace');
                throw new RuntimeException('unsafe file was accepted');
            } catch (RuntimeException $e) {
                testAssertSame('untrusted-file', $e->getMessage(),
                    'foreign writer must not mutate a witness inode in place');
            }
            testAssertSame('A', file_get_contents($root . '/from/a'), 'source remains');
            testAssertSame(false, file_exists($receipt), 'no witness was published');
        } finally {
            dataDirRemoveFixture($root);
        }
    },
    'destination writable by another UID refuses before receipt publication' => function () {
        $root = dataDirMoveFixture();
        try {
            file_put_contents($root . '/from/a', 'A');
            chmod($root . '/to', 0777);
            $receipt = $root . '/receipts/' . str_repeat('D', 40) . '.json';
            try {
                DataDirMoveIntent::prepare($receipt, str_repeat('d', 32), str_repeat('D', 40),
                    $root . '/from', $root . '/to', array('a'), 'dataDirNoReplace');
                throw new RuntimeException('unsafe directory was accepted');
            } catch (RuntimeException $e) {
                testAssertSame('untrusted-directory', $e->getMessage(),
                    'shared writable parent cannot bind a daemon path');
            }
            testAssertSame('A', file_get_contents($root . '/from/a'), 'source remains');
            testAssertSame(false, file_exists($receipt), 'no job receipt was published');
        } finally {
            dataDirRemoveFixture($root);
        }
    },
    'ready receipt aborts only while all original files remain in place' => function () {
        $root = dataDirMoveFixture();
        try {
            file_put_contents($root . '/from/a', 'A');
            file_put_contents($root . '/from/b', 'B');
            $receipt = $root . '/receipts/' . str_repeat('E', 40) . '.json';
            $intent = DataDirMoveIntent::prepare($receipt, str_repeat('e', 32), str_repeat('E', 40),
                $root . '/from', $root . '/to', array('a', 'b'), 'dataDirNoReplace');
            file_put_contents($root . '/to/b', 'foreign');
            testAssertSame(false, $intent->abortUnmoved(), 'foreign destination holds the receipt');
            testAssertSame(true, is_file($receipt), 'receipt remains discoverable');
            unlink($root . '/to/b');
            testAssertSame(true, $intent->abortUnmoved(), 'unmoved work may be cancelled');
            testAssertSame('A', file_get_contents($root . '/from/a'), 'first source remains');
            testAssertSame('B', file_get_contents($root . '/from/b'), 'second source remains');
            testAssertSame(false, file_exists($receipt), 'cancelled receipt retired');
            $intent = DataDirMoveIntent::prepare($receipt, str_repeat('f', 32), str_repeat('E', 40),
                $root . '/from', $root . '/to', array('a', 'b'), 'dataDirNoReplace');
            testAssertSame('pending', $intent->advance(1), 'first file moved');
            testAssertSame(false, $intent->abortUnmoved(), 'partial move cannot be cancelled');
            testAssertSame(true, is_file($receipt), 'partial receipt remains for recovery');
        } finally {
            dataDirRemoveFixture($root);
        }
    },

);

exit(testRunCases($tests));
