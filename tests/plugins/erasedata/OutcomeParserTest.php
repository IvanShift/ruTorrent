<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/RealParserDriver.php');

/**
 * Daemon strings through the real php/xmlrpc.php parser, which hands them out
 * in the daemon's own bytes (AUD-ESC, round 5). 0.9.8 answers a stored JSON
 * value with literal quotes (captured 2026-10-08, L4 evidence
 * round4/lab-autolabel/outcome-debug/pending-parser-098.txt); while the parser
 * escaped every quote, a kept outcome stored as plain JSON never decoded and no
 * session notice was ever shown (ACC-OWNB-01), and a path read the same way
 * named a file nobody uses, so a claim missed the real one (ACC-OWNB-04). The
 * names here carry a backslash-quote and a backslash-backslash as well: L4b
 * unescaped the answers once, and that second unescape, kept after the parser
 * stopped escaping, dropped one byte of each.
 */
class OutcomeParserTest extends TestCase
{
	const HASH = 'ABCDEF0123456789ABCDEF0123456789ABCDEF01';
	// A directory and a file name with a quote, a backslash, a backslash-quote
	// and a backslash-backslash (bytes: /data/dl/say \"hi\" \\there,
	// odd "name" \\x \"y\").
	const DIRECTORY = '/data/dl/say \\"hi\\" \\\\there';
	const NAME = 'odd "name" \\\\x \\"y\\"';

	private $base;
	private $driver;

	public function setUp()
	{
		$this->base = sys_get_temp_dir() . '/rutorrent-outcome-parser-' . getmypid() . '-' . uniqid();
		@mkdir($this->base, 0700, true);
		$this->driver = new ErasedataRealParserDriver($this->base);
	}

	public function tearDown()
	{
		$this->driver->stop();
		$this->remove($this->base);
	}

	// The driver's profile directory lives under the base as well.
	private function remove($path)
	{
		if (is_dir($path) && !is_link($path))
		{
			foreach (array_diff(scandir($path), array('.', '..')) as $entry)
				$this->remove($path . '/' . $entry);
			@rmdir($path);
		}
		else
			@unlink($path);
	}

	private function repoRoot()
	{
		return realpath(__DIR__ . '/../../..');
	}

	// Runs $body with the real core parser against a fake daemon answering
	// $replies in order, and returns what the body printed as JSON.
	private function drive(array $replies, $body)
	{
		return $this->driver->drive($replies, $body);
	}

	public function testAKeptOutcomeIsListedThroughTheRealParser()
	{
		$outcome = $this->repoRoot() . '/plugins/erasedata/outcome.php';
		// record(): the directory, the custom key with d.message, the save.
		$recorded = $this->drive(array(array(self::DIRECTORY), array(0, 0), array(0)),
			'require_once(' . var_export($outcome, true) . ");\n"
			. 'echo json_encode(array("recorded" => FileOperationOutcome::record("autotools", '
			. var_export(self::HASH, true) . ', "lab", "autotools: say \"no\"\\\\now")));');
		$this->assertSame(true, $recorded['recorded'], 'the daemon confirmed the outcome');
		$written = null;
		foreach ($this->driver->daemon()->bodies() as $request)
			if (strpos($request, 'x-autotools-outcome') !== false
				&& preg_match('~<value><string>x-autotools-outcome</string></value>\s*<value><string>([^<]*)</string>~', $request, $match))
				$written = html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
		$this->assertTrue(is_string($written) && $written !== '', 'the kept value was sent to the daemon');
		$this->assertSame(false, strpbrk((string) $written, "\"\\") !== false,
			'the kept value is URL-encoded, with no quote or backslash in it: ' . $written);
		$kept = json_decode(rawurldecode((string) $written), true);
		$this->assertSame(self::DIRECTORY, isset($kept['directory']) ? $kept['directory'] : null,
			'the directory is kept in the daemon\'s own bytes');
		// A session lists it: the daemon answers the stored bytes, as 0.9.8 does.
		$notice = $this->drive(array(array(self::HASH, self::NAME, self::DIRECTORY, 0, (string) $written)),
			'require_once(' . var_export($outcome, true) . ");\n"
			. 'echo json_encode(array("notice" => FileOperationOutcome::sessionNotice("autotools")));');
		$this->assertSame('noty(' . json_encode('AutoTools: ' . self::NAME . ' (' . self::HASH
			. '): autotools: say "no"\\now', JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ",'error');\n",
			$notice['notice'], 'the session notice names the download in its own bytes');
	}

	public function testClaimedDaemonPathsAreTheDaemonsOwnBytes()
	{
		$file = self::DIRECTORY . '/' . self::NAME;
		$claims = $this->drive(array(array(self::DIRECTORY, self::NAME, $file)),
			'require_once(' . var_export($this->repoRoot() . '/plugins/erasedata/removewithdata.php', true) . ");\n"
			. 'echo json_encode(erasedataCleanupDaemonClaims(' . var_export(self::HASH, true) . '));');
		$this->assertSame(array('files' => array($file)), $claims,
			'the stored and the frozen path name the same file, as the daemon spelled it');
	}
}
