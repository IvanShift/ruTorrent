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
		copy(__DIR__.'/../../../plugins/extsearch/action.php',
			$this->root.'/plugins/extsearch/action.php');
		$gate = var_export($this->root.'/post-gate', true);
		$utility = var_export(__DIR__.'/../../../php/utility/utility.php', true);
		file_put_contents($this->root.'/php/util.php', '<?php '
			.'require_once('.$utility.'); '
			.'class FileUtil { public static function toLog($message) {} } '
			.'class Requests { public static function requirePost() { '
			.'if ($_SERVER["REQUEST_METHOD"] !== "POST") exit("wrong method"); '
			.'file_put_contents('.$gate.', "checked"); }} '
			.'class CachedEcho { public static function send($body, $type) { '
			.'echo $body; exit; }} '
			.'class JSON { public static function safeEncode($value) { return json_encode($value); }}');
		file_put_contents($this->root.'/php/rtorrent.php', '<?php');
		$pending = var_export('https://example.invalid/pending', true);
		$known = var_export('https://example.invalid/download?file=a=b&n=1', true);
		$saved = var_export($this->root.'/saved.json', true);
		$scan = var_export($this->root.'/engine-scan', true);
		file_put_contents($this->root.'/plugins/extsearch/engines.php', '<?php '
			.'class ReviewHistory { public $reconciled = array(); '
			.'public function isPending($url) { return $url === '.$pending.'; } '
			.'public function reconcile($url) { $this->reconciled[] = $url; } '
			.'public function getHash($url) { return $url === '.$known.' '
			.'? str_repeat("A", 40) : ""; }} '
			.'class engineManager { public static function load() { '
			.'file_put_contents('.$scan.', "called"); return new self(); } '
			.'public static function loadHistory() { return new ReviewHistory(); } '
			.'public static function saveHistory($history) { '
			.'file_put_contents('.$saved.', json_encode($history->reconciled)); '
			.'return true; }}');
	}

	public function tearDown()
	{
		foreach(array('post-gate', 'saved.json', 'engine-scan', 'bootstrap.php') as $file)
			@unlink($this->root.'/'.$file);
		foreach(array('action.php', 'engines.php') as $file)
			@unlink($this->root.'/plugins/extsearch/'.$file);
		@unlink($this->root.'/php/util.php');
		@unlink($this->root.'/php/rtorrent.php');
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
		$bootstrap = '<?php $_SERVER["REQUEST_METHOD"] = "POST"; '
			.'$_REQUEST["mode"] = "history"; $searchHistoryMaxCount = 3; '
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
		$answer = json_decode($output, true);
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
}
