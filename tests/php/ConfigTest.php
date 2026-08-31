<?php

require_once(__DIR__ . '/TestCase.php');

class ConfigTest extends TestCase
{
	private $hadProfileMask;
	private $oldProfileMask;
	private $hadLocalhosts;
	private $oldLocalhosts;

	public function setUp()
	{
		$this->hadProfileMask = array_key_exists('RU_PROFILE_MASK', $_ENV);
		$this->oldProfileMask = $_ENV['RU_PROFILE_MASK'] ?? null;
		$this->hadLocalhosts = array_key_exists('RU_LOCALHOSTS', $_ENV);
		$this->oldLocalhosts = $_ENV['RU_LOCALHOSTS'] ?? null;
	}

	public function tearDown()
	{
		if ($this->hadProfileMask) {
			$_ENV['RU_PROFILE_MASK'] = $this->oldProfileMask;
		} else {
			unset($_ENV['RU_PROFILE_MASK']);
		}
		if ($this->hadLocalhosts) {
			$_ENV['RU_LOCALHOSTS'] = $this->oldLocalhosts;
		} else {
			unset($_ENV['RU_LOCALHOSTS']);
		}
	}

	private function configuredProfileMask($value, &$diagnostic = null)
	{
		$_ENV['RU_PROFILE_MASK'] = $value;
		$errorLog = tempnam(sys_get_temp_dir(), 'rut-mask-log-');
		$oldErrorLog = ini_get('error_log');
		ini_set('error_log', $errorLog);
		try {
			include(__DIR__ . '/../../conf/config.php');
		} finally {
			$diagnostic = is_file($errorLog) ? file_get_contents($errorLog) : '';
			ini_set('error_log', $oldErrorLog);
			unlink($errorLog);
		}
		return $profileMask;
	}

	public function testEnvironmentProfileMaskIsParsedAsOctalInteger()
	{
		$mask = $this->configuredProfileMask('0770');
		$this->assertTrue(is_int($mask), 'RU_PROFILE_MASK becomes an integer before permission operations');
		$this->assertEquals(0770, $mask, 'RU_PROFILE_MASK=0770 keeps its documented octal meaning');
	}

	public function testEmptyEnvironmentProfileMaskUsesTheDefault()
	{
		$this->assertEquals(
			0777,
			$this->configuredProfileMask(''),
			'An empty RU_PROFILE_MASK behaves like an unset value instead of crashing requests'
		);
	}

	public function testAMaskThatCouldNotBeReadSaysSo()
	{
		// The fallback is 0777, which is wider than any mask an admin sets one
		// for. Taking it silently turns a typo into world-writable profiles.
		$diagnostic = null;
		$mask = $this->configuredProfileMask('02770', $diagnostic);

		$this->assertEquals(0777, $mask, 'a mask that could not be read falls back to the default');
		$this->assertTrue($diagnostic !== '', 'and the fallback is reported');
		$this->assertTrue(strpos($diagnostic, 'RU_PROFILE_MASK') !== false,
			'and the report names the setting: ' . var_export($diagnostic, true));
	}

	public function testInvalidMaskDiagnosticUsesTheServerLogInsteadOfTheResponse()
	{
		$errorLog = tempnam(sys_get_temp_dir(), 'rut-mask-log-');
		$code = '$_ENV["RU_PROFILE_MASK"] = "02770"; require '
			. var_export(__DIR__ . '/../../conf/config.php', true) . ';';
		$output = array();
		$status = 0;
		exec(
			escapeshellarg(PHP_BINARY)
				. ' -d variables_order=GPCS -d error_reporting=-1'
				. ' -d display_errors=1 -d log_errors=0'
				. ' -d error_log=' . escapeshellarg($errorLog)
				. ' -r ' . escapeshellarg($code) . ' 2>&1',
			$output,
			$status
		);
		$response = implode("\n", $output);
		$diagnostic = is_file($errorLog) ? file_get_contents($errorLog) : '';
		unlink($errorLog);

		$this->assertEquals(0, $status, 'an invalid mask does not terminate configuration loading');
		$this->assertEquals('', $response,
			'the configuration diagnostic cannot corrupt an HTTP response body');
		$this->assertTrue(strpos($diagnostic, 'RU_PROFILE_MASK') !== false,
			'the invalid mask remains visible in the server error log: ' . var_export($diagnostic, true));
	}

	public function testAMaskThatIsSimplyUnsetSaysNothing()
	{
		$diagnostic = null;
		$mask = $this->configuredProfileMask('', $diagnostic);

		$this->assertEquals(0777, $mask, 'an empty mask uses the documented default');
		$this->assertEquals('', $diagnostic, 'and says nothing, because nothing was asked for');
	}

	public function testInvalidEnvironmentProfileMaskUsesTheDefault()
	{
		$mask = $this->configuredProfileMask('not-a-mask');

		$this->assertEquals(
			0777,
			$mask,
			'An invalid RU_PROFILE_MASK cannot reach chmod or mkdir as a string'
		);
	}

	/**
	 * The list config.php builds. setUp() runs once for the file, not once per
	 * test, so a mask an earlier case left behind would be read here too --
	 * these cases are about RU_LOCALHOSTS and nothing else.
	 */
	private function configuredLocalhosts()
	{
		unset($_ENV['RU_PROFILE_MASK']);
		include(__DIR__ . '/../../conf/config.php');
		return $localhosts;
	}

	public function testAnAddressFromTheEnvironmentJoinsTheLocalInterfaces()
	{
		$_ENV['RU_LOCALHOSTS'] = '10.1.2.3';
		$hosts = $this->configuredLocalhosts();
		$this->assertTrue(in_array('10.1.2.3', $hosts, true),
			'RU_LOCALHOSTS is added to the local interfaces');
		$this->assertTrue(in_array('127.0.0.1', $hosts, true),
			'and the built-in ones are kept');
	}

	/**
	 * The list must never carry a null. It is compared against the address a
	 * request came from, and an entry that is not an address is at best noise
	 * in a security decision.
	 */
	public function testAnEnvironmentWithoutTheSettingAddsNothing()
	{
		unset($_ENV['RU_LOCALHOSTS']);
		$hosts = $this->configuredLocalhosts();
		$this->assertEquals(array('::1', '127.0.0.1', 'localhost'), $hosts,
			'an unset RU_LOCALHOSTS leaves the list as it ships');
	}

	public function testAnEmptySettingAddsNothing()
	{
		$_ENV['RU_LOCALHOSTS'] = '';
		$hosts = $this->configuredLocalhosts();
		$this->assertEquals(array('::1', '127.0.0.1', 'localhost'), $hosts,
			'an empty RU_LOCALHOSTS adds no entry');
	}

	/**
	 * getenv() and $_ENV disagree whenever variables_order has no E, which is
	 * the CLI default: the process carries the variable and the superglobal
	 * does not. Testing one and reading the other appended a null.
	 *
	 * The disagreement is created here rather than inherited from whatever the
	 * suite happens to be run with -- otherwise this passes on a clean machine
	 * whether the bug is present or not.
	 */
	public function testTheListIsBuiltWithoutReadingAKeyThatIsNotThere()
	{
		putenv('RU_LOCALHOSTS=10.9.9.9');
		unset($_ENV['RU_LOCALHOSTS']);
		$this->assertTrue(getenv('RU_LOCALHOSTS') === '10.9.9.9',
			'the process carries the variable');
		$this->assertTrue(!isset($_ENV['RU_LOCALHOSTS']),
			'and the superglobal does not');

		$raised = null;
		set_error_handler(function ($errno, $errstr) use (&$raised) {
			$raised = $errstr;
			return true;
		});
		$hosts = $this->configuredLocalhosts();
		restore_error_handler();
		putenv('RU_LOCALHOSTS');

		$this->assertTrue($raised === null,
			'building the list raises no diagnostic: ' . var_export($raised, true));
		$this->assertTrue(!in_array(null, $hosts, true),
			'and the list carries no null entry');
		$this->assertEquals(array('::1', '127.0.0.1', 'localhost'), $hosts,
			'and nothing was added from a source config.php does not read');
	}
}
