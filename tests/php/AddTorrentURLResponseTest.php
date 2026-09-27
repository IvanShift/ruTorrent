<?php

require_once(__DIR__ . '/TestCase.php');

final class AddTorrentURLResponseTest extends TestCase
{
	private function probe($status, $body, $method = 'POST', $urlPlace = 'body',
		$url = 'https://tracker.example/download', $getOptions = array())
	{
		$root = tempnam(sys_get_temp_dir(), 'addtorrent-');
		unlink($root);
		mkdir($root);
		mkdir($root . '/profile');
		mkdir($root . '/profile/torrents');
		$curl = $root . '/curl';
		$script = $root . '/probe.php';
		file_put_contents($curl, <<<'SH'
#!/bin/sh
header_file=
body_file=
printf "called\n" >> "$ADDTORRENT_TEST_FETCHES"
while [ "$#" -gt 0 ]; do
	case "$1" in
		-D) shift; header_file=$1 ;;
		-o) shift; body_file=$1 ;;
	esac
	shift
done
printf 'HTTP/1.1 %s Test\r\n\r\n' "$ADDTORRENT_TEST_STATUS" > "$header_file"
printf '%s' "$ADDTORRENT_TEST_BODY" > "$body_file"
SH
		);
		chmod($curl, 0700);
		$phpDir = realpath(__DIR__ . '/../../php');
		file_put_contents($script, '<?php '
			. '$_ENV["RU_PROFILE_PATH"] = getenv("ADDTORRENT_TEST_PROFILE");'
			. '$_ENV["RU_LOG_FILE"] = getenv("ADDTORRENT_TEST_LOG");'
			. 'if(getenv("ADDTORRENT_TEST_TOP_DIR")) $_ENV["RU_TOP_DIR"] = getenv("ADDTORRENT_TEST_TOP_DIR");'
			. '$_SERVER["REQUEST_METHOD"] = getenv("ADDTORRENT_TEST_METHOD");'
			. '$_GET = json_decode(getenv("ADDTORRENT_TEST_GET"), true);'
			. '$_POST = json_decode(getenv("ADDTORRENT_TEST_POST"), true);'
			. '$_REQUEST = array_merge($_GET, $_POST);'
			. 'set_include_path(' . var_export($phpDir, true) . ' . PATH_SEPARATOR . get_include_path());'
			. 'require_once ' . var_export($phpDir . '/util.php', true) . ';'
			. '$pathToExternals["curl"] = getenv("ADDTORRENT_TEST_CURL");'
			. 'register_shutdown_function(function () { global $uploaded_files;'
			. 'echo "\n__RESULT__" . json_encode($uploaded_files ?? null); });'
			. 'require ' . var_export($phpDir . '/addtorrent.php', true) . ';');
		$env = array_merge(getenv(), array(
			'ADDTORRENT_TEST_STATUS' => (string)$status,
			'ADDTORRENT_TEST_BODY' => $body,
			'ADDTORRENT_TEST_METHOD' => $method,
			'ADDTORRENT_TEST_GET' => json_encode(array_merge($urlPlace === 'query' ? array('url' => $url) : array(), $getOptions)),
			'ADDTORRENT_TEST_POST' => json_encode($urlPlace === 'body' ? array('url' => $url) : array()),
			'ADDTORRENT_TEST_FETCHES' => $root . '/fetches',
			'ADDTORRENT_TEST_CURL' => $curl,
			'ADDTORRENT_TEST_PROFILE' => $root . '/profile',
			'ADDTORRENT_TEST_TOP_DIR' => $getOptions ? $root . '/profile/torrents/' : '',
			'ADDTORRENT_TEST_LOG' => $root . '/errors.log',
		));
		try
		{
			$process = proc_open(array(PHP_BINARY, '-c', __DIR__ . '/../php-test.ini', $script),
				array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
				$pipes, null, $env);
			if(!is_resource($process)) throw new RuntimeException('addtorrent child did not start');
			fclose($pipes[0]);
			$output = stream_get_contents($pipes[1]);
			fclose($pipes[1]);
			$errors = stream_get_contents($pipes[2]);
			fclose($pipes[2]);
			$exit = proc_close($process);
			if($exit !== 0) throw new RuntimeException('addtorrent child failed: ' . $errors);
			$parts = explode("\n__RESULT__", $output, 2);
			if(count($parts) !== 2) throw new RuntimeException('addtorrent returned: ' . $output . '; ' . $errors);
			$result = json_decode($parts[1], true);
			if(!is_array($result) && $result !== null)
				throw new RuntimeException('addtorrent returned invalid JSON: ' . $parts[1]);
			return array($result[0]['status'] ?? null, glob($root . '/profile/torrents/*'),
				isset($result[0]['file']), @file_get_contents($root . '/errors.log'),
				$parts[0], @file_get_contents($root . '/fetches'));
		}
		finally
		{
			@unlink($root . '/errors.log');
			unlink($script);
			unlink($curl);
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

	public function testSuccessfulHtmlIsAFileErrorWithoutSavedTorrent()
	{
		$result = $this->probe(200, '<html>Login required</html>');
		$this->assertEquals('FailedFile', $result[0], 'HTTP success with HTML is an invalid torrent');
		$this->assertEquals(array(), $result[1], 'HTML was not saved to the uploads directory');
		$this->assertEquals(false, $result[2], 'HTML never entered the file upload path');
		$this->assertTrue(strpos((string)$result[3], 'addtorrent: torrent fetch refused: invalid-torrent-response') !== false,
			'HTML rejection has a classified log reason');
	}

	public function testHttpFailureIsAUrlError()
	{
		$result = $this->probe(503, '<html>Unavailable</html>');
		$this->assertEquals('FailedURL', $result[0], 'HTTP failure is a URL error');
		$this->assertEquals(array(), $result[1], 'failed response was not saved');
		$this->assertTrue(strpos((string)$result[3], 'addtorrent: torrent fetch refused: http-status-503') !== false,
			'HTTP rejection has a classified log reason');
	}

	public function testGetTorrentUrlShowsEscapedConfirmationWithoutFetching()
	{
		$url = 'https://tracker.invalid/file.torrent?name="<script>alert(1)</script>';
		$result = $this->probe(404, 'not found', 'GET', 'query', $url);
		$this->assertEquals(false, $result[5], 'GET URL does not fetch or add the torrent');
		$this->assertEquals(null, $result[0], 'GET never enters the upload path');
		$this->assertTrue(strpos($result[4], '<form') !== false &&
			strpos($result[4], 'method="post"') !== false &&
			strpos($result[4], '&quot;&lt;script&gt;') !== false,
			'GET offers an escaped POST confirmation for the magnet handler and torrent URLs');
		$this->assertTrue(strpos($result[4], '<script>') === false,
			'untrusted URL cannot inject a script element');
		$this->assertTrue(strpos($result[4], '<meta name="referrer" content="same-origin">') !== false,
			'confirmation limits the referrer even when nginx adds a broader policy');
	}

	public function testPostQueryOnlyCannotFetch()
	{
		$result = $this->probe(404, 'not found', 'POST', 'query');
		$this->assertEquals(false, $result[5], 'POST query URL does not fetch or add a torrent');
	}

	public function testPostBodyUrlStillFetches()
	{
		$result = $this->probe(404, 'not found');
		$this->assertEquals("called\n", $result[5], 'confirmed POST body URL still reaches the fetcher');
	}

	public function testPostBodyUrlKeepsDesktopAndMobileQueryOptions()
	{
		$result = $this->probe(404, 'not found', 'POST', 'body',
			'https://tracker.example/download', array('dir_edit' => '/outside-permitted-root'));
		$this->assertEquals('FailedDirectory', $result[0], 'query directory option is still applied to a POST body URL');
		$this->assertEquals(false, $result[5], 'rejected directory prevents a fetch');
	}
}
