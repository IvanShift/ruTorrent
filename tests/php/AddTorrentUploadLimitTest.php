<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/AddTorrentHttpHarness.php');

/**
 * PHP registers at most max_file_uploads file parts of a POST and drops the
 * rest before php/addtorrent.php runs, without counting them. The image
 * allows 100, so a batch of 101 answered for 100 files and the 101st
 * vanished (R3C-03). The add dialog now sends how many files it selected
 * (torrent_file_count), and the answer names the files left out.
 *
 * PHP's own startup warning is only a fallback for clients that send no
 * count: error_get_last() keeps the last startup diagnostic, and PHP-FPM
 * 8.5 with register_argc_argv on raises a deprecation after the warning
 * (measured on the round-3 image, 2026-10-08; the image's FPM pool has
 * turned the directive off since).
 */
final class AddTorrentUploadLimitTest extends TestCase
{
	/** What PHP-FPM 8.5 with register_argc_argv on raises after the upload warning. */
	const LATER_STARTUP_DIAGNOSTIC = '@trigger_error(\'Deriving $_SERVER["argv"] from the query string is deprecated\', E_USER_DEPRECATED);';

	private $server = null;

	public function tearDown()
	{
		if ($this->server !== null) {
			// PHP's own refusals of the upload are what these cases provoke.
			$diagnostics = $this->server->phpDiagnostics(array(
				'/Maximum number of allowable file uploads has been exceeded/',
				'/POST Content-Length of [0-9]+ bytes exceeds the limit/',
				'/Deriving \$_SERVER\["argv"\] from the query string is deprecated/'));
			$this->server->close();
			$this->assertSame(array(), $diagnostics,
				'no PHP diagnostic beyond the provoked ones: ' . implode(' | ', $diagnostics));
		}
		$this->server = null;
	}

	/**
	 * Upload $count invalid torrents to a server that accepts $limit files,
	 * declaring $declared selected files when it is not null.
	 */
	private function upload($count, $limit, $declared = null, array $options = array())
	{
		$this->server = AddTorrentHttpHarness::forAddTorrent(
			new FakeRtorrentAddTorrentReplies(array(), array(), false),
			array_merge(array('ini' => array('max_file_uploads' => (string)$limit)), $options));
		$names = array();
		for ($index = 0; $index < $count; $index++)
			$names[] = sprintf('%02d.torrent', $index);
		list($contentType, $body) = AddTorrentHttpHarness::multipart(
			AddTorrentHttpHarness::torrentFiles($names, str_repeat('x', 512)));
		$query = $declared === null ? '' : '?torrent_file_count=' . rawurlencode((string)$declared);
		$response = $this->server->request('POST', '/php/addtorrent.php' . $query, $body,
			array('Content-Type' => $contentType));
		$this->assertSame(302, $response['code'], 'the upload answers with its result redirect');
		$location = $response['headers']['location'] ?? '';
		parse_str((string)substr((string)strstr($location, '?'), 1), $fields);
		return array('location' => $location,
			'results' => $fields['result'] ?? array(), 'names' => $fields['name'] ?? array());
	}

	public function testTheDialogCountNamesTheFilesBeyondTheLimit()
	{
		$answer = $this->upload(5, 3, 5);
		$this->assertSame(array('FailedFile', 'FailedFile', 'FailedFile', 'Failed'), $answer['results'],
			'the three registered files keep their results and the rest are one failure');
		$this->assertSame('Not processed: 2 of the 5 selected files (max_file_uploads=3)',
			$answer['names'][3] ?? null, 'the failure counts the files left out and names the limit');
		$this->assertTrue(strpos($this->server->applicationLog(), 'addtorrent: upload incomplete: '
			. 'not processed: 2 of the 5 selected files (max_file_uploads=3)') !== false,
			'the application log records the classified refusal: ' . $this->server->applicationLog());
		$result = $this->server->request('GET', '/php/addtorrent.php' . strstr($answer['location'], '?'));
		$this->assertTrue(strpos($result['body'], 'noty("Not processed: 2 of the 5 selected files '
			. '(max_file_uploads=3) - "+theUILang["addTorrent"+"Failed"],"error");') !== false,
			'the dialog shows the left-out files as an error: ' . $result['body']);
	}

	public function testTheCountSurvivesALaterStartupDiagnostic()
	{
		$answer = $this->upload(5, 3, 5, array('preamble' => self::LATER_STARTUP_DIAGNOSTIC));
		$this->assertSame('Not processed: 2 of the 5 selected files (max_file_uploads=3)',
			$answer['names'][3] ?? null,
			'a diagnostic after the upload warning cannot hide what the count shows');
	}

	public function testWithoutACountThePhpWarningStillReportsTheDrop()
	{
		$answer = $this->upload(5, 3);
		$this->assertSame(array('FailedFile', 'FailedFile', 'FailedFile', 'Failed'), $answer['results'],
			'a client without a count still learns that files were left out');
		$this->assertSame('Not processed: the files after the first 3 (max_file_uploads=3)',
			$answer['names'][3] ?? null, 'from the warning PHP left when nothing replaced it');
	}

	public function testAnUploadWithinTheLimitHasNoLimitFailure()
	{
		foreach (array(3, null) as $declared) {
			$answer = $this->upload(3, 3, $declared);
			$this->assertSame(array('FailedFile', 'FailedFile', 'FailedFile'), $answer['results'],
				'an upload at the limit answers for its files only, count ' . var_export($declared, true));
			$this->assertTrue(strpos($this->server->applicationLog(), 'upload incomplete') === false,
				'and logs no refusal, count ' . var_export($declared, true));
			$this->tearDown();
		}
	}

	public function testAnImplausibleCountIsIgnored()
	{
		foreach (array('2', 'x', '-1', '1e3', '') as $declared) {
			$answer = $this->upload(3, 5, $declared);
			$this->assertSame(array('FailedFile', 'FailedFile', 'FailedFile'), $answer['results'],
				'a count of ' . var_export($declared, true) . ' adds no failure');
			$this->tearDown();
		}
	}

	public function testFilesLostToPostMaxSizeAreCountedWithoutBlamingTheFileLimit()
	{
		$answer = $this->upload(3, 100, 3, array('ini' => array('max_file_uploads' => '100',
			'post_max_size' => '1K')));
		$this->assertSame(array('Failed'), $answer['results'],
			'PHP discarded the whole body, and the answer is the one failure');
		$this->assertSame(array('Not processed: 3 of the 3 selected files'), $answer['names'],
			'it counts the files and does not name a limit that was not reached');
	}
}
