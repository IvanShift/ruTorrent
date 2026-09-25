<?php

require_once(__DIR__ . '/TestCase.php');

final class AddTorrentURLResponseTest extends TestCase
{
	private function probe($status, $body)
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
			. '$_REQUEST["url"] = "https://tracker.example/download";'
			. 'set_include_path(' . var_export($phpDir, true) . ' . PATH_SEPARATOR . get_include_path());'
			. 'require_once ' . var_export($phpDir . '/util.php', true) . ';'
			. '$pathToExternals["curl"] = getenv("ADDTORRENT_TEST_CURL");'
			. 'register_shutdown_function(function () { global $uploaded_files;'
			. 'echo json_encode($uploaded_files); });'
			. 'require ' . var_export($phpDir . '/addtorrent.php', true) . ';');
		$env = array_merge(getenv(), array(
			'ADDTORRENT_TEST_STATUS' => (string)$status,
			'ADDTORRENT_TEST_BODY' => $body,
			'ADDTORRENT_TEST_CURL' => $curl,
			'ADDTORRENT_TEST_PROFILE' => $root . '/profile',
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
			$result = json_decode($output, true);
			if(!is_array($result)) throw new RuntimeException('addtorrent returned: ' . $output . '; ' . $errors);
			return array($result[0]['status'], glob($root . '/profile/torrents/*'),
				array_key_exists('file', $result[0]), @file_get_contents($root . '/errors.log'));
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
}
