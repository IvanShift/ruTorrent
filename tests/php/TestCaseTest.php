<?php

require_once(__DIR__ . '/TestCase.php');

class TestCaseTest extends TestCase
{
	public function testFalseValueIsReportedAsFailureWhenAssertionsAreDisabled(): void
	{
		// The suite deliberately enables assertions, so use a fresh process to
		// prove that TestCase itself does not depend on that runner setting.
		$script = 'require ' . var_export(__DIR__ . '/TestCase.php', true)
			. '; (new TestCase())->assertTrue(false, "deliberately false");';
		$lines = array();
		$exitCode = 0;
		exec(escapeshellarg(PHP_BINARY) . ' -d zend.assertions=-1 -r '
			. escapeshellarg($script), $lines, $exitCode);
		$output = implode("\n", $lines) . (count($lines) ? "\n" : '');

		if ($exitCode !== 0 || $output !== "Failed: deliberately false\n") {
			throw new RuntimeException('A false assertion was not reported as Failed');
		}
		echo "Passed: a false value is reported as Failed when assertions are disabled\n";
	}

	public function testFailedAssertionsIncrementRunnerFailureCount(): void
	{
		$probe = new TestCase();
		ob_start();
		try {
			$probe->assertTrue(false, 'count this failed assertion');
		} finally {
			ob_end_clean();
		}
		$this->assertEquals(1, $probe->failureCount(),
			'a failed assertion is counted independently of output formatting');
	}

	public function testRunnerUsesStrictDiagnosticSettings(): void
	{
		$actual = array(
			'zend.assertions' => (int) ini_get('zend.assertions'),
			'error_reporting' => error_reporting(),
			'display_errors' => (int) ini_get('display_errors'),
		);
		$expected = array(
			'zend.assertions' => 1,
			'error_reporting' => -1,
			'display_errors' => 1,
		);

		if ($actual !== $expected) {
			throw new RuntimeException(
				'Test runner diagnostics differ: ' . json_encode($actual)
			);
		}
		echo "Passed: the runner enables strict diagnostics\n";
	}
	public function testStandaloneCaseRunnerCountsAssertionAndSetupFailures(): void
	{
		$runner = __DIR__ . '/TestCaseRunner.php';
		$testCase = var_export(__DIR__ . '/TestCase.php', true);
		$probes = array(
			array(
				'<?php require ' . $testCase . '; class AssertionProbe extends TestCase {'
				. ' public function testRefusal() { $this->assertTrue(false, "deliberate assertion"); }'
				. ' public function tearDown() { echo "teardown\n"; } }',
				array('Test: AssertionProbe', '>>testRefusal>>', 'Failed: deliberate assertion',
					'<<testRefusal<<', 'teardown'),
			),
			array(
				'<?php require ' . $testCase . '; class SetupProbe extends TestCase {'
				. ' public function setUp() { throw new RuntimeException("setup refused"); }'
				. ' public function testNever() { echo "should not run\n"; }'
				. ' public function tearDown() { echo "teardown\n"; } }',
				array('Test: SetupProbe', 'setup refused', 'teardown'),
			),
		);
		foreach ($probes as $probe) {
			$script = tempnam(sys_get_temp_dir(), 'rt-case-runner-');
			if ($script === false) throw new RuntimeException('Unable to create runner probe');
			try {
				file_put_contents($script, $probe[0]);
				$lines = array();
				$code = 0;
				exec(escapeshellarg(PHP_BINARY) . ' -d auto_append_file='
					. escapeshellarg($runner) . ' ' . escapeshellarg($script) . ' 2>&1',
					$lines, $code);
				$output = implode("\n", $lines);
				$this->assertTrue($code === 1, 'the appended runner refuses a failed class');
				foreach ($probe[1] as $marker) {
					$this->assertTrue(strpos($output, $marker) !== false,
						'the appended runner preserves ' . $marker);
				}
				$this->assertTrue(strpos($output, 'should not run') === false,
					'a failed setUp never runs its test body');
			} finally {
				@unlink($script);
			}
		}
	}

	public function testStandaloneClosureRunnerCountsFailureAndPrintsNonemptySummary(): void
	{
		ob_start();
		try {
			$code = testRunCases(array(
				'first' => function () {},
				'second' => function () { throw new RuntimeException('deliberate refusal'); },
			));
			$output = ob_get_contents();
		} finally {
			ob_end_clean();
		}
		$this->assertTrue($code === 1, 'a failed closure makes the runner fail');
		$this->assertTrue(strpos($output, "ok - first\n") !== false,
			'the passing case keeps its marker');
		$this->assertTrue(strpos($output, "not ok - second\n") !== false,
			'the failing case keeps its marker');
		$this->assertTrue(strpos($output, "2 tests, 1 failures\n") !== false,
			'the summary counts both cases and the one failure');
	}

}
