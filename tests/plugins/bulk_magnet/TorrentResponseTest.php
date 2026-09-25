<?php

require_once(__DIR__ . '/../../php/TestCase.php');

final class BulkMagnetTorrentResponseTest extends TestCase
{
	private function probe($status, $body, $saveFails = false, $loadMode = 'success')
	{
		$root = tempnam(sys_get_temp_dir(), 'bulk-response-');
		unlink($root);
		mkdir($root . '/plugins/bulk_magnet', 0700, true);
		mkdir($root . '/php', 0700, true);
		if($saveFails) mkdir($root . '/unwritable-target', 0700);
		copy(__DIR__ . '/../../../plugins/bulk_magnet/action.php',
			$root . '/plugins/bulk_magnet/action.php');
		$helper = __DIR__ . '/../../../php/torrentfetch.php';
		if(is_file($helper)) copy($helper, $root . '/php/torrentfetch.php');
		file_put_contents($root . '/php/Snoopy.class.inc', <<<'PHPSTUB'
<?php
class Snoopy {
	const CREDENTIAL_REDIRECT_REFUSED = 'credential-redirect-refused';
	public $status = 0;
	public $results = '';
	public $error = '';
	public function fetchComplex($url) {
		$this->status = (int)getenv('BULK_TEST_STATUS');
		$this->results = getenv('BULK_TEST_BODY');
		return true;
	}
	public function get_filename() { return false; }
	public static function isSuccessfulResponse($client) {
		return $client->status >= 200 && $client->status < 300;
	}
	public static function isTorrentResponse($client) {
		return self::isSuccessfulResponse($client)
			&& $client->results === getenv('BULK_TEST_VALID_BODY');
	}
}
PHPSTUB
		);
		file_put_contents($root . '/php/rtorrent.php', <<<'PHPSTUB'
<?php
class FileUtil {
	public static function getUniqueUploadedFilename($name) { return getenv('BULK_TEST_FILE'); }
	public static function toLog($message) { file_put_contents(getenv('BULK_TEST_LOG'), $message . "\n", FILE_APPEND); }
}
class rTorrent {
	public static function sendTorrent($fname, $start, $addPath, $dir, $label, $save, $fast, $new,
		$addition = null, &$receipt = null) {
		file_put_contents(getenv('BULK_TEST_CALLS'), "send\n", FILE_APPEND);
		$mode = getenv('BULK_TEST_LOAD');
		if($mode === 'pending-raw' || $mode === 'pending-file') {
			$receipt = array('raw' => $mode === 'pending-raw');
			return null;
		}
		return $mode === 'failure' ? false : str_repeat('A', 40);
	}
	public static function sendMagnet($magnet, $start, $addPath, $dir, $label) { return false; }
}
class JSON { public static function safeEncode($data) { return json_encode($data); } }
class CachedEcho { public static function send($body, $type, $cache = false) { echo $body; } }
PHPSTUB
		);
		$driver = '<?php $profileMask = 0664; '
			. '$HTTP_RAW_POST_DATA = "torrent=https%3A%2F%2Ftracker.test%2Fdownload"; '
			. 'require ' . var_export($root . '/plugins/bulk_magnet/action.php', true) . ';';
		file_put_contents($root . '/driver.php', $driver);
		$env = array_merge(getenv(), array(
			'BULK_TEST_STATUS' => (string)$status,
			'BULK_TEST_BODY' => $body,
			'BULK_TEST_LOAD' => $loadMode,
			'BULK_TEST_VALID_BODY' => 'd4:infod4:name4:testee',
			'BULK_TEST_FILE' => $root . ($saveFails ? '/unwritable-target' : '/stored.torrent'),
			'BULK_TEST_LOG' => $root . '/errors.log',
			'BULK_TEST_CALLS' => $root . '/calls.log',
		));
		try
		{
			$process = proc_open(array(PHP_BINARY, '-c', __DIR__ . '/../../php-test.ini', $root . '/driver.php'),
				array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
				$pipes, $root, $env);
			if(!is_resource($process)) throw new RuntimeException('bulk_magnet child did not start');
			fclose($pipes[0]);
			$output = stream_get_contents($pipes[1]); fclose($pipes[1]);
			$errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
			$exit = proc_close($process);
			if($exit !== 0) throw new RuntimeException('bulk_magnet child failed: ' . $errors);
			$result = json_decode($output, true);
			if(!is_array($result)) throw new RuntimeException('bulk_magnet returned: ' . $output . '; ' . $errors);
			return array($result, is_file($root . '/calls.log') ? file_get_contents($root . '/calls.log') : '',
				is_file($root . '/errors.log') ? file_get_contents($root . '/errors.log') : '',
				is_file($root . '/stored.torrent'));
		}
		finally
		{
			$entries = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
				RecursiveIteratorIterator::CHILD_FIRST);
			foreach($entries as $entry)
			{
				if($entry->isDir()) rmdir($entry->getPathname());
				else unlink($entry->getPathname());
			}
			rmdir($root);
		}
	}

	public function testHtmlResponseIsRefusedBeforeSendAndLogged()
	{
		$result = $this->probe(200, '<html>Login required</html>');
		$this->assertEquals(array('error' => 1, 'success' => 0, 'pending' => 0), $result[0], 'HTML is not counted as a torrent');
		$this->assertEquals('', $result[1], 'HTML never reaches sendTorrent');
		$this->assertTrue(strpos($result[2], 'bulk_magnet: torrent fetch refused: invalid-torrent-response') !== false,
			'HTML rejection has a classified log reason');
	}

	public function testHttpErrorIsRefusedAndLogged()
	{
		$result = $this->probe(503, '<html>Unavailable</html>');
		$this->assertEquals(array('error' => 1, 'success' => 0, 'pending' => 0), $result[0], 'HTTP failure is not counted as a torrent');
		$this->assertEquals('', $result[1], 'HTTP failure never reaches sendTorrent');
		$this->assertTrue(strpos($result[2], 'bulk_magnet: torrent fetch refused: http-status-503') !== false,
			'HTTP rejection has a classified log reason');
	}

	public function testLocalSaveFailureIsClassifiedBeforeSend()
	{
		$result = $this->probe(200, 'd4:infod4:name4:testee', true);
		$this->assertEquals(array('error' => 1, 'success' => 0, 'pending' => 0), $result[0],
			'failed local write is not counted as a dispatched torrent');
		$this->assertEquals('', $result[1], 'failed local write never reaches sendTorrent');
		$this->assertTrue(strpos($result[2], 'bulk_magnet: torrent save refused: local-write-failed') !== false,
			'local write refusal has a classified log reason');
	}

	public function testPendingRawLoadIsCountedAndDisposableSourceRemoved()
	{
		$result = $this->probe(200, 'd4:infod4:name4:testee', false, 'pending-raw');
		$this->assertEquals(array('error' => 0, 'success' => 0, 'pending' => 1),
			$result[0], 'unconfirmed is not counted as a successful add');
		$this->assertTrue(!$result[3], 'a raw load no longer needs the downloaded source');
	}

	public function testPendingFileLoadRetainsSourceForDaemon()
	{
		$result = $this->probe(200, 'd4:infod4:name4:testee', false, 'pending-file');
		$this->assertEquals(array('error' => 0, 'success' => 0, 'pending' => 1),
			$result[0], 'file-backed load remains unconfirmed');
		$this->assertTrue($result[3], 'the daemon may still need to read the source path');
	}

	public function testTorrentResponseReachesSend()
	{
		$result = $this->probe(200, 'd4:infod4:name4:testee');
		$this->assertEquals(array('error' => 0, 'success' => 1, 'pending' => 0), $result[0], 'valid response is counted as dispatched');
		$this->assertEquals("send\n", $result[1], 'valid response reaches sendTorrent');
		$this->assertEquals('', $result[2], 'accepted response has no refusal log');
	}
}
