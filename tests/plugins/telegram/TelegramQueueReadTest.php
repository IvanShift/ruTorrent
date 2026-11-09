<?php

require_once __DIR__ . '/../../php/TestCase.php';
require_once __DIR__ . '/../../php/PhpProcess.php';

class TelegramQueueReadTest extends TestCase
{
    public function testReadFailureUsesTheRealLogOwnerOrFallback()
    {
        $dir = sys_get_temp_dir() . '/telegram-logging-' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0700)) {
            throw new RuntimeException('could not create the exclusive queue directory');
        }
        $file = $dir . '/1.event';
        $log = $dir . '/test.log';
        try {
            file_put_contents($file, "added\0Movie\0HASH\0\0");
            $code = <<<'PHP'
require $argv[1];
require $argv[2];
$log_file = $argv[4];
$queue = new rTelegramQueue($argv[3]);
echo json_encode([$queue->drain(new class {}, null, 0, 0, STDIN), is_file($argv[3] . '/1.event')]);
PHP;
            foreach (['stream_get_contents', 'stream_get_contents,strlen', 'stream_get_contents,fopen', 'stream_get_contents,fputs', 'stream_get_contents,fclose', 'stream_get_contents,function_exists', 'stream_get_contents,class_exists'] as $disabled) {
                file_put_contents($log, '');
                $run = PhpProcess::run(array_merge(PhpProcess::sameIni(), ['-d', 'log_errors=1', '-d', 'error_log=php://stderr', '-d', 'disable_functions=' . $disabled, '-r', $code, __DIR__ . '/../../../php/utility/fileutil.php', __DIR__ . '/../../../plugins/telegram/queue.php', $dir, $log]), ['timeout' => 10]);
                $this->assertTrue(!$run['timedOut'] && $run['code'] === 0 && $run['out'] === '[false,true]', $disabled . ' retains the record without terminating: ' . $run['err']);
                $message = 'telegram: queued notification read failed: 1.event; drain stopped, retry on the next drain';
                if ($disabled === 'stream_get_contents') {
                    $this->assertTrue(strpos(file_get_contents($log), $message) !== false && strpos($run['err'], $message) !== false, 'the refusal reaches both log destinations');
                } else {
                    $this->assertTrue(strpos($run['err'], $message) !== false, $disabled . ' reports through the PHP error log');
                }
            }
            if (is_writable('/dev/full')) {
                $run = PhpProcess::run(array_merge(PhpProcess::sameIni(), ['-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=php://stderr', '-d', 'disable_functions=stream_get_contents', '-r', $code, __DIR__ . '/../../../php/utility/fileutil.php', __DIR__ . '/../../../plugins/telegram/queue.php', $dir, '/dev/full']), ['timeout' => 10]);
                $this->assertTrue(!$run['timedOut'] && $run['code'] === 0 && $run['out'] === '[false,true]' && strpos($run['err'], $message) !== false, 'a failed real log write keeps the event and reports the classified refusal: ' . $run['err']);
            }
            $run = PhpProcess::run(array_merge(PhpProcess::sameIni(), ['-d', 'disable_functions=stream_get_contents,strlen,error_log', '-r', $code, __DIR__ . '/../../../php/utility/fileutil.php', __DIR__ . '/../../../plugins/telegram/queue.php', $dir, $log]), ['timeout' => 10]);
            $this->assertTrue(!$run['timedOut'] && $run['code'] === 0 && $run['out'] === '[false,true]', 'an unavailable fallback still retains the record: ' . $run['err']);
            file_put_contents($file, 'corrupt');
            file_put_contents($log, '');
            $run = PhpProcess::run(array_merge(PhpProcess::sameIni(), ['-d', 'error_log=php://stderr', '-r', $code, __DIR__ . '/../../../php/utility/fileutil.php', __DIR__ . '/../../../plugins/telegram/queue.php', $dir, $log]), ['timeout' => 10]);
            $this->assertTrue(!$run['timedOut'] && $run['code'] === 0 && $run['out'] === '[false,false]' && $run['err'] === '' && strpos(file_get_contents($log), 'telegram: discarded an invalid queued notification') !== false, 'other queue logs keep their existing single destination');
        } finally {
            @unlink($file);
            @unlink($log);
            @rmdir($dir);
        }
    }

    public function testDisabledReadFunctionsKeepTheRecord()
    {
        $dir = sys_get_temp_dir() . '/telegram-disabled-' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0700)) {
            throw new RuntimeException('could not create the exclusive queue directory');
        }
        $file = $dir . '/1.event';
        try {
            file_put_contents($file, "added\0Movie\0HASH\0\0");
            $code = <<<'PHP'
require $argv[1];
$queue = new rTelegramQueue($argv[2]);
$config = new class {};
echo json_encode([$queue->drain($config, null, 0, 0, STDIN), is_file($argv[2] . '/1.event')]);
PHP;
            foreach (['fopen', 'is_resource', 'fstat', 'stream_get_contents', 'strlen', 'fclose', 'stream_get_contents,rawurlencode', 'stream_get_contents,basename'] as $function) {
                $run = PhpProcess::run(array_merge(PhpProcess::sameIni(), ['-d', 'disable_functions=' . $function, '-r', $code, __DIR__ . '/../../../plugins/telegram/queue.php', $dir]), ['timeout' => 10]);
                $this->assertTrue(!$run['timedOut'] && $run['code'] === 0, $function . ' refusal exits normally: ' . $run['err']);
                $this->assertEquals('[false,true]', $run['out'], $function . ' refusal retains the notification');
                $name = strpos($function, ',') === false ? '1.event' : '[unavailable]';
                $this->assertTrue(strpos($run['err'], 'telegram: queued notification read failed: ' . $name . '; drain stopped, retry on the next drain') !== false, $function . ' refusal is visible');
            }
            file_put_contents($file, '');
            $run = PhpProcess::run(array_merge(PhpProcess::sameIni(), ['-d', 'disable_functions=stream_get_contents', '-r', $code, __DIR__ . '/../../../plugins/telegram/queue.php', $dir]), ['timeout' => 10]);
            $this->assertTrue(!$run['timedOut'] && $run['code'] === 0 && $run['out'] === '[false,true]', 'a disabled read retains even a zero-byte record: ' . $run['err']);
        } finally {
            @unlink($file);
            @rmdir($dir);
        }
    }

    public function testShortReadAndChangedFileAreRetained()
    {
        $code = <<<'PHP'
require $argv[1];
class PartialRecordStream {
    public $context;
    private $offset = 0;
    private $stats = 0;
    public function stream_open($path, $mode, $options, &$opened) { return true; }
    public function stream_read($count) {
        if ($GLOBALS['case'] === 'read-error') { throw new RuntimeException('read failed'); }
        if ($GLOBALS['case'] === 'read-false') { return false; }
        $text = "added\0Movie\0HASH\0\0";
        $result = substr($text, $this->offset, $count);
        $this->offset += strlen($result);
        return $result;
    }
    public function stream_eof() { return $this->offset >= strlen("added\0Movie\0HASH\0\0"); }
    public function stream_stat() {
        $this->stats++;
        if (($GLOBALS['case'] === 'first-stat' && $this->stats === 1)
            || ($GLOBALS['case'] === 'last-stat' && $this->stats >= 2)) { return false; }
        return ['size' => strlen("added\0Movie\0HASH\0\0") + ($GLOBALS['case'] === 'short' ? 4 : 0)
                + ($GLOBALS['case'] === 'changed-size' && $this->stats > 1 ? 1 : 0),
            'mtime' => $GLOBALS['case'] === 'changed-mtime' ? $this->stats : 1,
            'ctime' => $GLOBALS['case'] === 'changed-ctime' ? $this->stats : 1];
    }
    public function stream_close() {}
}
stream_wrapper_register('partialrecord', 'PartialRecordStream');
$method = new ReflectionMethod('rTelegramQueue', 'readItem');
if (PHP_VERSION_ID < 80100) { $method->setAccessible(true); }
foreach (['short', 'changed-size', 'changed-mtime', 'changed-ctime', 'first-stat', 'last-stat', 'read-error', 'read-false', 'complete'] as $case) {
    $result = $method->invoke(new rTelegramQueue(), 'partialrecord://item');
    echo json_encode($result === false ? false : $result['torrent']), "\n";
}
PHP;
        $run = PhpProcess::run(array_merge(PhpProcess::sameIni(), ['-r', $code, __DIR__ . '/../../../plugins/telegram/queue.php']), ['timeout' => 10]);
        $this->assertTrue(!$run['timedOut'] && $run['code'] === 0 && $run['err'] === '', 'the stream failure probes complete normally: ' . $run['err']);
        $this->assertEquals("false\nfalse\nfalse\nfalse\nfalse\nfalse\nfalse\nfalse\n\"Movie\"\n", $run['out'], 'short reads, changed metadata and read failures are refused, but a complete old record is read: ' . json_encode($run['out']));
    }

    public function testUnreadableRecordIsRetainedAndRetried()
    {
        $code = <<<'PHP'
require $argv[1];
class rTelegram { const MAX_TEMPLATE_LENGTH = 4096; }
class QueueConfig {
    public $messages = [];
    public function canNotify($event) { return true; }
    public function render($event, $torrent, $hash, $comment, $details) { return $torrent; }
    public function sendMessage($text, $client, $context, $unused) { $this->messages[] = $text; return ['ok' => true]; }
}
$dir = sys_get_temp_dir() . '/telegram-read-' . bin2hex(random_bytes(8));
if (!mkdir($dir, 0700)) { exit(2); }
$file = $dir . '/' . $argv[2];
try {
    file_put_contents($dir . '/0.event', 'corrupt');
    file_put_contents($dir . '/1.event', "added\0First\0HASH\0\0");
    file_put_contents($file, "added\0Second\0HASH\0\0");
    file_put_contents($dir . '/3.event', "added\0Third\0HASH\0\0");
    chmod($file, 0000);
    $canRead = @file_get_contents($file) !== false;
    $queue = new rTelegramQueue($dir);
    $config = new QueueConfig();
    if (!$canRead) {
        $first = $queue->drain($config, null, 0, 0);
        clearstatcache(true, $file);
        echo json_encode([$first, is_file($file), $config->messages]), "\n";
    } else {
        echo "privileged\n";
    }
    chmod($file, 0600);
    $second = $queue->drain($config, null, 0, 0);
    echo json_encode([$second, $queue->getPendingCount(), $config->messages]), "\n";
} finally {
    foreach (glob($dir . '/*') as $entry) { @unlink($entry); }
    rmdir($dir);
}
PHP;
        foreach (['2.0123456789.event', "2.\nunsafe.event"] as $name) {
            $run = PhpProcess::run(array_merge(PhpProcess::sameIni(), ['-r', $code, __DIR__ . '/../../../plugins/telegram/queue.php', $name]), ['timeout' => 10]);
            $this->assertTrue(!$run['timedOut'] && $run['code'] === 0, 'the sender exits normally within the deadline: ' . $run['err']);
            $this->assertTrue(strpos($run['err'], 'telegram: discarded an invalid queued notification') !== false, 'a fully read corrupt record is still discarded');
            if (strpos($run['out'], "privileged\n") === 0) {
                $this->assertEquals("privileged\n[true,0,[\"First\\n\\nSecond\\n\\nThird\"]]\n", $run['out'], 'privileged runners still exercise the successful drain');
            } else {
                $this->assertEquals("[false,true,[]]\n[true,0,[\"First\\n\\nSecond\\n\\nThird\"]]\n", $run['out'], 'an unreadable event stops the drain, retains the later event, and is sent on retry');
                $this->assertTrue(strpos($run['err'], 'telegram: queued notification read failed: ' . rawurlencode($name) . '; drain stopped, retry on the next drain') !== false, 'the classified refusal identifies the record without control characters');
            }
        }
    }
}
