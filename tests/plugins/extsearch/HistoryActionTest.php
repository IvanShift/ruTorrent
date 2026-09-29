<?php

require_once(__DIR__.'/../../php/TestCase.php');

class ExtsearchHistoryActionTest extends TestCase
{
	private $root;

	public function setUp()
	{
		$this->root = sys_get_temp_dir().'/rutorrent-extsearch-history-'
			.getmypid().'-'.bin2hex(random_bytes(4));
		mkdir($this->root.'/plugins/extsearch', 0700, true);
		mkdir($this->root.'/php', 0700, true);
		$source = getenv('RUTORRENT_EXTSEARCH_ACTION_SOURCE')
			?: __DIR__.'/../../../plugins/extsearch/action.php';
		copy($source, $this->root.'/plugins/extsearch/action.php');
		$gate = var_export($this->root.'/post-gate', true);
		$log = var_export($this->root.'/action.log', true);
		$settings = var_export($this->root.'/settings', true);
		$cursorFile = var_export($this->root.'/cursor.txt', true);
		$utility = var_export(__DIR__.'/../../../php/utility/utility.php', true);
		file_put_contents($this->root.'/php/util.php', '<?php '
			.'require_once('.$utility.'); '
			.'class FileUtil { public static function getSettingsPath() { '
			.'return '.$settings.'; } '
			.'public static function makeDirectory($path, $mode = null, $recursive = false) { '
			.'return is_dir($path) || mkdir($path, $mode ?? 0700, $recursive); } '
			.'public static function toLog($message) { '
			.'file_put_contents('.$log.', $message."\n", FILE_APPEND); }} '
			.'class Requests { public static function requirePost() { '
			.'if ($_SERVER["REQUEST_METHOD"] !== "POST") exit("wrong method"); '
			.'file_put_contents('.$gate.', "checked"); }} '
			.'class CachedEcho { public static function send($body, $type) { '
			.'echo $body; exit; }} '
			.'class JSON { public static function safeEncode($value) { return json_encode($value); }}');
		$scgi = var_export(__DIR__.'/../../../php/scgitransport.php', true);
		file_put_contents($this->root.'/php/rtorrent.php',
			'<?php require_once('.$scgi.');');
		$pending = var_export('https://example.invalid/pending', true);
		$known = var_export('https://example.invalid/download?file=a=b&n=1', true);
		$saved = var_export($this->root.'/saved.json', true);
		$probes = var_export($this->root.'/probe-log.jsonl', true);
		$scan = var_export($this->root.'/engine-scan', true);
		file_put_contents($this->root.'/plugins/extsearch/engines.php', '<?php '
			.'class ReviewHistory { public $reconciled = array(); public $lst = array(); '
			.'public function __construct() { $urls = $GLOBALS["activePendingUrls"] ?? null; '
			.'if ($urls === null) { $urls = array(); '
			.'foreach(Utility::legacyOrderedFormPairs($GLOBALS["HTTP_RAW_POST_DATA"]) as $parts) '
			.'if(count($parts) === 2 && $parts[0] === "url" && $this->isPending($parts[1])) '
			.'$urls[] = $parts[1]; } '
			.'foreach($urls as $url) $this->lst[$url] = array("receipt"=>true); } '
			.'public function isPending($url) { return strpos($url, '.$pending.') === 0; } '
			.'public function reconcile($url) { $this->reconciled[] = $url; '
			.'file_put_contents('.$probes.', json_encode(array("url"=>$url, '
			.'"connect"=>$GLOBALS["rpcTimeOut"] ?? null, '
			.'"reply"=>$GLOBALS["rpcTransferTimeOut"] ?? null))."\n", FILE_APPEND); '
			.'if (isset($GLOBALS["stallPort"])) { $failure = null; '
			.'rSCGITransport::send("127.0.0.1", $GLOBALS["stallPort"], '
			.'"<methodCall/>", true, $GLOBALS["rpcTimeOut"] ?? 5, '
			.'$failure, $GLOBALS["rpcTransferTimeOut"] ?? null); } } '
			.'public function getHash($url) { return $url === '.$known.' '
			.'? str_repeat("A", 40) : ""; }} '
			.'class rCache { public function get(&$cursor) { '
			.'$value = @file_get_contents('.$cursorFile.'); '
			.'if ($value === false) return false; $state = json_decode($value, true); '
			.'$cursor->next = $state["next"]; $cursor->last = $state["last"]; return true; } '
			.'public function set($cursor) { if (!empty($GLOBALS["failCursor"])) return false; '
			.'return file_put_contents('.$cursorFile.', json_encode(array('
			.'"next"=>$cursor->next, "last"=>$cursor->last))) !== false; }} '
			.'class engineManager { public static function load() { '
			.'file_put_contents('.$scan.', "called"); return new self(); } '
			.'public static function loadHistory() { return new ReviewHistory(); } '
			.'public static function saveHistory($history) { '
			.'file_put_contents('.$saved.', json_encode($history->reconciled)); '
			.'return true; }}');
	}

	public function tearDown()
	{
		foreach(array('post-gate', 'saved.json', 'probe-log.jsonl',
			'action.log', 'cursor.txt', 'stall-server.php', 'stall-port',
			'cache-merge.php',
			'engine-scan', 'bootstrap.php') as $file)
			@unlink($this->root.'/'.$file);
		foreach(array('action.php', 'engines.php') as $file)
			@unlink($this->root.'/plugins/extsearch/'.$file);
		@unlink($this->root.'/php/util.php');
		@unlink($this->root.'/php/rtorrent.php');
		foreach(glob($this->root.'/settings/*') ?: array() as $entry) @unlink($entry);
		@rmdir($this->root.'/settings');
		@rmdir($this->root.'/plugins/extsearch');
		@rmdir($this->root.'/plugins');
		@rmdir($this->root.'/php');
		@rmdir($this->root);
	}

	public function testRepeatedHistoryUrlsUseCacheOnlyAndPreserveDecodedKeys()
	{
		$known = 'https://example.invalid/download?file=a=b&n=1';
		$pending = 'https://example.invalid/pending';
		$unknown = 'https://example.invalid/unknown';
		$overLimit = 'https://example.invalid/over-limit';
		$body = 'mode=history&url='.rawurlencode($known)
			.'&url='.rawurlencode($pending).'&url='.rawurlencode($pending)
			.'&url='.rawurlencode($unknown).'&url='.rawurlencode($overLimit);
		$answer = $this->runHistory($body, 3);
		$this->assertSame(array($known=>str_repeat('A', 40),
			$pending=>null, $unknown=>''), $answer,
			'repeated encoded URLs map to exact confirmed, pending, and unknown values');
		$this->assertTrue(is_file($this->root.'/post-gate'),
			'history action enforces POST');
		$this->assertTrue(!is_file($this->root.'/engine-scan'),
			'history action does not scan engines or fetch trackers');
		$this->assertSame(array($pending),
			json_decode((string)@file_get_contents($this->root.'/saved.json'), true),
			'pending reconciliation is persisted once');
	}

	public function testHistoryCapsRpcsAndRotatesAcrossRepeatedRequests()
	{
		$urls = array();
		foreach(range(0, 7) as $i)
			$urls[] = 'https://example.invalid/pending/'.$i;
		$body = 'mode=history';
		foreach($urls as $url)
			$body .= '&url='.rawurlencode($url);
		$expected = array_fill_keys($urls, null);
		$this->assertSame($expected, $this->runHistory($body, 8),
			'all requested pending URLs remain in the response');
		$first = $this->readProbes();
		$this->assertSame(4, count($first), 'one request probes at most four pending loads');
		foreach($first as $probe)
		{
			$this->assertSame(0.5, $probe['connect'], 'history connect timeout is short');
			$this->assertSame(1.0, (float)$probe['reply'], 'history reply idle timeout is short');
		}
		$this->assertSame($expected, $this->runHistory($body, 8),
			'later history request still answers all pending URLs');
		$all = $this->readProbes();
		$this->assertSame(8, count($all), 'two requests keep the finite probe cap');
		$this->assertSame(8, count(array_unique(array_column($all, 'url'))),
			'the second request probes the four URLs skipped by the first');
		$cursor = json_decode((string)file_get_contents($this->root.'/cursor.txt'), true);
		$this->assertSame(8, $cursor['next'],
			'the persistent probe cursor advances across requests');
	}

	public function testInterleavedPanelsCannotStarveLaterPendingUrls()
	{
		$groups = array(array(), array());
		foreach(range(0, 15) as $i)
			$groups[$i < 8 ? 0 : 1][] = 'https://example.invalid/pending/'.$i;
		file_put_contents($this->root.'/cursor.txt', json_encode(array('next'=>0,
			'last'=>array('https://example.invalid/stale'=>99))));
		foreach(array(0, 1, 0) as $panel)
		{
			$body = 'mode=history';
			foreach($groups[$panel] as $url)
				$body .= '&url='.rawurlencode($url);
			$this->assertSame(array_fill_keys($groups[$panel], null),
				$this->runHistory($body, 8, false, null, array_merge($groups[0], $groups[1])),
				'each panel receives every pending key');
		}
		$probes = $this->readProbes();
		$this->assertSame(12, count($probes), 'three requests each use four probes');
		$firstPanel = array_intersect(array_column($probes, 'url'), $groups[0]);
		$this->assertSame(8, count(array_unique($firstPanel)),
			'the second A request probes the four A URLs skipped before B ran');
		$cursor = json_decode((string)file_get_contents($this->root.'/cursor.txt'), true);
		$this->assertTrue(!isset($cursor['last']['https://example.invalid/stale']),
			'cached recency is bounded to active pending history rows');
	}

	public function testOverflowPendingBeyondConfiguredHistoryLimitIsStillReconciled()
	{
		$body = 'mode=history';
		foreach(range(0, 17) as $i)
			$body .= '&url='.rawurlencode('https://example.invalid/unknown/'.$i);
		$pending = array();
		foreach(range(0, 3) as $i)
		{
			$pending[] = 'https://example.invalid/pending/'.$i;
			$body .= '&url='.rawurlencode($pending[$i]);
		}
		$answer = $this->runHistory($body, 2, false, null, $pending);
		foreach($pending as $url)
			$this->assertTrue(array_key_exists($url, $answer) && $answer[$url] === null,
				'a persisted pending URL after the normal count limit remains in the answer');
		$this->assertSame(4, count($this->readProbes()),
			'overflow pending URLs receive a bounded reconciliation turn');
		$this->assertTrue(strpos((string)file_get_contents($this->root.'/action.log'),
			'history query truncated') !== false, 'extra unrelated URL keys are visibly capped');
	}

	public function testDamagedCursorOrderResetsVisiblyAndResumesProbing()
	{
		$url = 'https://example.invalid/pending/0';
		file_put_contents($this->root.'/cursor.txt', json_encode(array('next'=>1,
			'last'=>array($url=>PHP_INT_MAX))));
		$body = 'mode=history&url='.rawurlencode($url);
		$this->assertSame(array($url=>null), $this->runHistory($body, 1),
			'a damaged cursor keeps the receipt pending while probing it');
		$this->assertSame(1, count($this->readProbes()),
			'the first request resumes a bounded probe after reset');
		$log = (string)file_get_contents($this->root.'/action.log');
		$this->assertTrue(strpos($log, 'history probe cursor reset: key='
			.'extsearch_history_probe_cursor.dat; invalid scheduling state') !== false,
			'the damaged cache key and reset reason are visible');
		$this->assertSame(array($url=>null), $this->runHistory($body, 1),
			'the recovered cursor remains usable on the next request');
		$this->assertSame(2, count($this->readProbes()),
			'the next request probes instead of remaining stalled');
		$cursor = json_decode((string)file_get_contents($this->root.'/cursor.txt'), true);
		$this->assertSame(2, $cursor['next'], 'the recovered cursor advances durably');
	}

	public function testHistoryKeepsPendingResponseAndLogsCursorStoreRefusal()
	{
		$urls = array('https://example.invalid/pending/a',
			'https://example.invalid/pending/b');
		$body = 'mode=history&url='.rawurlencode($urls[0])
			.'&url='.rawurlencode($urls[1]);
		$this->assertSame(array_fill_keys($urls, null),
			$this->runHistory($body, 2, true),
			'a failed cursor reservation does not hide requested pending URLs');
		$this->assertTrue(!is_file($this->root.'/probe-log.jsonl'),
			'a failed cursor reservation dispatches no unbounded RPC batch');
		$this->assertTrue(strpos((string)file_get_contents($this->root.'/action.log'),
			'extsearch: history probe cursor reservation failed') !== false,
			'the failed reservation is visible in the log');
	}

	public function testRealCacheMergesConcurrentStaleReservations()
	{
		$cachePath = var_export(__DIR__.'/../../../php/cache.php', true);
		file_put_contents($this->root.'/plugins/extsearch/engines.php',
			'<?php require_once('.$cachePath.'); '
			.'class engineManager { public static function load() { return new self(); }}');
		$phpDir = var_export($this->root.'/php', true);
		$action = var_export($this->root.'/plugins/extsearch/action.php', true);
		$script = '<?php set_include_path('.$phpDir.'.PATH_SEPARATOR.get_include_path()); '
			.'$_REQUEST = array(); $profileMask = 0700; require '.$action.'; '
			.'$urls = array(); for($i=0; $i<8; $i++) $urls[] = "url-".$i; '
			.'$active = array_fill_keys($urls, true); $cache = new rCache(); '
			.'$first = new ExtsearchHistoryProbeCursor(); '
			.'$second = new ExtsearchHistoryProbeCursor(); '
			.'$cache->get($first); $cache->get($second); '
			.'$first->reserve($urls, $active); $second->reserve($urls, $active); '
			.'$one = $cache->set($first); $two = $cache->set($second); '
			.'$saved = new ExtsearchHistoryProbeCursor(); $cache->get($saved); '
			.'echo json_encode(array("writes"=>array($one, $two), '
			.'"selected"=>array($first->selected, $second->selected), '
			.'"next"=>$saved->next, "last"=>$saved->last));';
		file_put_contents($this->root.'/cache-merge.php', $script);
		$process = proc_open(array(PHP_BINARY, $this->root.'/cache-merge.php'),
			array(0=>array('file', '/dev/null', 'r'),
				1=>array('pipe', 'w'), 2=>array('pipe', 'w')),
			$pipes, $this->root.'/plugins/extsearch');
		$this->assertTrue(is_resource($process), 'real rCache child starts');
		$output = stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$this->assertSame(0, proc_close($process), 'real rCache child finishes: '.$error);
		$result = json_decode($output, true);
		$this->assertSame(array(true, true), $result['writes'],
			'both stale-loaded reservations are durably accepted');
		$this->assertSame(8, count(array_unique(array_merge(
			$result['selected'][0], $result['selected'][1]))),
			'rCache merge reselects four different URLs for the second reservation');
		$this->assertSame(8, $result['next'], 'the durable cursor includes both reservations');
		$this->assertSame(8, count($result['last']),
			'all eight selected URL recencies survive the real merge');
	}

	public function testStalledScgiPeerReturnsHistoryBeforeWebuiTimeout()
	{
		$source = <<<'PHP'
<?php
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if($server === false) exit(1);
$name = stream_socket_get_name($server, false);
file_put_contents($argv[1], substr($name, strrpos($name, ':') + 1));
while(($client = stream_socket_accept($server, 5)) !== false)
{
	usleep(3000000);
	fclose($client);
}
PHP;
		file_put_contents($this->root.'/stall-server.php', $source);
		$server = proc_open(array(PHP_BINARY, $this->root.'/stall-server.php',
			$this->root.'/stall-port'), array(0=>array('file', '/dev/null', 'r'),
			1=>array('pipe', 'w'), 2=>array('pipe', 'w')), $pipes);
		$this->assertTrue(is_resource($server), 'SCGI stall fixture starts');
		try
		{
			for($i = 0; $i < 100 && !is_file($this->root.'/stall-port'); $i++)
				usleep(10000);
			$port = (int)@file_get_contents($this->root.'/stall-port');
			$this->assertTrue($port > 0, 'SCGI stall fixture publishes its port');
			$urls = array();
			$body = 'mode=history';
			foreach(range(0, 7) as $i)
			{
				$urls[] = 'https://example.invalid/pending/'.$i;
				$body .= '&url='.rawurlencode($urls[$i]);
			}
			$start = microtime(true);
			$answer = $this->runHistory($body, 8, false, $port);
			$elapsed = microtime(true) - $start;
			$this->assertSame(array_fill_keys($urls, null), $answer,
				'a real stalled SCGI peer leaves every requested entry pending');
			$this->assertTrue($elapsed < 9.0,
				'history responds before the 10-second WebUI timeout, elapsed='.$elapsed);
			$this->assertSame(4, count($this->readProbes()),
				'the stalled peer is contacted at most four times');
		}
		finally
		{
			proc_terminate($server);
			fclose($pipes[1]);
			fclose($pipes[2]);
			proc_close($server);
		}
	}

	private function readProbes()
	{
		$lines = file($this->root.'/probe-log.jsonl', FILE_IGNORE_NEW_LINES);
		return array_map(function($line) { return json_decode($line, true); }, $lines);
	}

	private function runHistory($body, $maxUrls, $failCursor = false,
		$stallPort = null, $activePendingUrls = null)
	{
		$bootstrap = '<?php $_SERVER["REQUEST_METHOD"] = "POST"; '
			.'$_REQUEST["mode"] = "history"; $searchHistoryMaxCount = '
			.(int)$maxUrls.'; $failCursor = '.($failCursor ? 'true' : 'false').'; '
			.'$stallPort = '.var_export($stallPort, true).'; '
			.'$activePendingUrls = '.var_export($activePendingUrls, true).'; '
			.'$HTTP_RAW_POST_DATA = '.var_export($body, true).';';
		file_put_contents($this->root.'/bootstrap.php', $bootstrap);
		$command = array(PHP_BINARY,
			'-d', 'auto_prepend_file='.$this->root.'/bootstrap.php',
			'-f', $this->root.'/plugins/extsearch/action.php');
		$process = proc_open($command, array(
			0=>array('file', '/dev/null', 'r'),
			1=>array('pipe', 'w'), 2=>array('pipe', 'w')),
			$pipes, $this->root.'/plugins/extsearch');
		$this->assertTrue(is_resource($process), 'history action child starts');
		$output = stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exit = proc_close($process);
		$this->assertSame(0, $exit, 'history action finishes: '.$error);
		return json_decode($output, true);
	}
}
