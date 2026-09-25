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
				array('Test: SetupProbe', 'setup refused'),
			),
			array(
				'<?php require ' . $testCase . '; class ClassSetupProbe extends TestCase {'
				. ' public function setUpClass() { throw new RuntimeException("class setup refused"); }'
				. ' public function testNever() { echo "should not run\n"; }'
				. ' public function tearDownClass() { echo "class teardown\n"; } }',
				array('Test: ClassSetupProbe', 'class setup refused'),
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
				if (strpos($probe[0], 'ClassSetupProbe') !== false) {
					$this->assertTrue(strpos($output, 'class teardown') === false,
						'a failed class setup does not tear down a partial fixture');
				} else if (strpos($probe[0], 'SetupProbe') !== false) {
					$this->assertTrue(strpos($output, 'teardown') === false,
						'a failed method setup does not tear down a partial fixture');
				}
			} finally {
				@unlink($script);
			}
		}
	}

	public function testEachMethodGetsSetupAndTeardownEvenAfterErrors(): void
	{
		$script = tempnam(sys_get_temp_dir(), 'rt-case-lifecycle-');
		if ($script === false) throw new RuntimeException('Unable to create lifecycle probe');
		$source = '<?php require ' . var_export(__DIR__ . '/TestCase.php', true) . '; '
			. 'class LifecycleProbe extends TestCase {'
			. ' private $attempt = 0;'
			. ' public function setUpClass() { echo "event:class-setup\\n"; }'
			. ' public function tearDownClass() { echo "event:class-teardown\\n"; }'
			. ' public function setUp() { $this->attempt++; echo "event:setup-{$this->attempt}\\n";'
			. ' if ($this->attempt === 2) throw new RuntimeException("setup refused"); }'
			. ' public function tearDown() { echo "event:teardown-{$this->attempt}\\n";'
			. ' if ($this->attempt === 4) throw new RuntimeException("teardown refused"); }'
			. ' public function testOne() { echo "event:body-1\\n"; }'
			. ' public function testTwo() { echo "event:body-2\\n"; }'
			. ' public function testThree() { echo "event:body-3\\n";'
			. ' throw new RuntimeException("body refused"); }'
			. ' public function testFour() { echo "event:body-4\\n"; }'
			. ' public function testFive() { echo "event:body-5\\n"; }'
			. ' }';
		try {
			file_put_contents($script, $source);
			$lines = array();
			$code = 0;
			exec(escapeshellarg(PHP_BINARY) . ' -d auto_append_file='
				. escapeshellarg(__DIR__ . '/TestCaseRunner.php') . ' '
				. escapeshellarg($script) . ' 2>&1', $lines, $code);
			$output = implode("\n", $lines);
			preg_match_all('/^event:[^\\r\\n]+$/m', $output, $events);
			$this->assertEquals(1, $code, 'lifecycle errors make the appended runner fail');
			$this->assertEquals(array(
				'event:class-setup',
				'event:setup-1', 'event:body-1', 'event:teardown-1',
				'event:setup-2',
				'event:setup-3', 'event:body-3', 'event:teardown-3',
				'event:setup-4', 'event:body-4', 'event:teardown-4',
				'event:setup-5', 'event:body-5', 'event:teardown-5',
				'event:class-teardown',
			), $events[0], 'class fixtures run once and completed setup gets method cleanup');
			$this->assertTrue(strpos($output, 'event:body-2') === false,
				'a failed method setup skips only its own test body');
			$this->assertTrue(strpos($output, 'setup refused') !== false
				&& strpos($output, 'body refused') !== false
				&& strpos($output, 'teardown refused') !== false,
				'each lifecycle error is visible');
			$this->assertEquals(3, substr_count($output, 'failed with error:'),
				'setup, body, and teardown errors are each counted exactly once');
			$this->assertTrue(strpos($output, 'TestCase runner finished: 1 classes, 5 methods') !== false,
				'all five methods are accounted for after lifecycle errors');
		} finally {
			@unlink($script);
		}
	}

	public function testNamedCaseRunnerUsesTheSameMethodLifecycle(): void
	{
		$script = tempnam(sys_get_temp_dir(), 'rt-named-lifecycle-');
		if ($script === false) throw new RuntimeException('Unable to create named-case probe');
		$source = '<?php require ' . var_export(__DIR__ . '/TestCase.php', true) . '; '
			. 'class NamedLifecycleProbe extends TestCase {'
			. ' private $attempt = 0;'
			. ' public function setUpClass() { echo "event:class-setup\\n"; }'
			. ' public function tearDownClass() { echo "event:class-teardown\\n"; }'
			. ' public function setUp() { $this->attempt++; echo "event:setup-{$this->attempt}\\n";'
			. ' if ($this->attempt === 2) throw new RuntimeException("setup refused"); }'
			. ' public function tearDown() { echo "event:teardown-{$this->attempt}\\n"; }'
			. ' public function testOne() { echo "event:body-1\\n"; $this->assertTrue(true); }'
			. ' public function testTwo() { echo "event:body-2\\n"; $this->assertTrue(false); }'
			. ' public function testThree() { echo "event:body-3\\n"; $this->assertTrue(true); }'
			. ' }';
		try {
			file_put_contents($script, $source);
			$runner = __DIR__ . '/../plugins/erasedata/RunNamedCases.php';
			$lines = array();
			$code = 0;
			exec(escapeshellarg(PHP_BINARY) . ' -c ' . escapeshellarg(__DIR__ . '/../php-test.ini')
				. ' ' . escapeshellarg($runner) . ' ' . escapeshellarg($script)
				. ' testone testTwo testThree 2>&1', $lines, $code);
			$output = implode("\n", $lines);
			preg_match_all('/^event:[^\\r\\n]+$/m', $output, $events);
			$this->assertEquals(1, $code, 'a failed named case makes the manual runner fail');
			$this->assertEquals(array(
				'event:class-setup',
				'event:setup-1', 'event:body-1', 'event:teardown-1',
				'event:setup-2',
				'event:setup-3', 'event:body-3', 'event:teardown-3',
				'event:class-teardown',
			), $events[0], 'named methods use class and method lifecycle hooks');
			$this->assertTrue(strpos($output, 'event:body-2') === false,
				'a failed named method setup skips its body');
			$this->assertTrue(strpos($output, 'RunNamedCases: 3/3 cases finished') !== false,
				'the manual runner completes and accounts for later cases');
		} finally {
			@unlink($script);
		}
	}

	public function testPartialPendingQueueClassSetupCleansItsMirror(): void
	{
		$root = sys_get_temp_dir() . '/rt-pending-setup-' . bin2hex(random_bytes(8));
		$testDir = $root . '/tests/plugins/erasedata';
		$phpDir = $root . '/tests/php';
		mkdir($testDir, 0777, true);
		mkdir($phpDir, 0777, true);
		$testFile = $testDir . '/PendingQueueTest.php';
		$caseFile = $phpDir . '/TestCase.php';
		$probeFile = $root . '/probe.php';
		try {
			if (!copy(__DIR__ . '/TestCase.php', $caseFile)
				|| !copy(__DIR__ . '/../plugins/erasedata/PendingQueueTest.php', $testFile)) {
				throw new RuntimeException('Unable to stage the partial setup probe');
			}
			$source = '<?php require ' . var_export($testFile, true) . '; '
				. '$test = new PendingQueueTest(); $refused = false;'
				. 'try { $test->setUpClass(); } catch (Throwable $e) { $refused = true; }'
				. '$mirror = sys_get_temp_dir() . "/rutorrent-pending-" . getmypid();'
				. '$leftBehind = is_dir($mirror); $test->tearDownClass();'
				. 'echo json_encode(array($refused, $leftBehind));';
			file_put_contents($probeFile, $source);
			$lines = array();
			$code = 0;
			exec(escapeshellarg(PHP_BINARY) . ' -c '
				. escapeshellarg(__DIR__ . '/../php-test.ini') . ' '
				. escapeshellarg($probeFile) . ' 2>&1', $lines, $code);
			$this->assertEquals(0, $code, 'the incomplete mirror probe exits normally');
			$this->assertEquals(array(true, false), json_decode((string) end($lines), true),
				'a failed mirror copy is refused and its partial tree is removed');
		} finally {
			@unlink($probeFile);
			@unlink($testFile);
			@unlink($caseFile);
			@rmdir($testDir);
			@rmdir($root . '/tests/plugins');
			@rmdir($phpDir);
			@rmdir($root . '/tests');
			@rmdir($root);
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

	private function captureAssertion(callable $assertion): array
	{
		$probe = new TestCase();
		ob_start();
		try {
			$assertion($probe);
			$output = ob_get_contents();
		} finally {
			ob_end_clean();
		}
		return array($probe, $output);
	}

	public function testEqualsRejectsCrossTypeValuesAndShowsNonUtf8Operands(): void
	{
		list($probe, $output) = $this->captureAssertion(function ($case) {
			$case->assertEquals(false, 0);
			$case->assertEquals("\xff", "\xfe");
		});
		$this->assertTrue($probe->failureCount() === 2,
			'assertEquals refuses false versus zero and distinct binary strings');
		$this->assertTrue(strpos($output, 'Expected false === 0') !== false,
			'the strict failure shows both scalar operands');
		$this->assertTrue(strpos($output, "\xff") !== false && strpos($output, "\xfe") !== false,
			'the binary failure preserves both non-UTF-8 operands');
	}

	public function testEqualRecursiveValuesDoNotFormatUnusedDiagnostics(): void
	{
		$value = array();
		$value['self'] = &$value;
		$warnings = array();
		set_error_handler(function ($severity, $message) use (&$warnings) {
			$warnings[] = $message;
			return true;
		});
		try {
			list($probe, $output) = $this->captureAssertion(function ($case) use (&$value) {
				$case->assertEquals($value, $value, 'equal recursive structure');
				$case->assertEquals($value, $value);
			});
		} finally {
			restore_error_handler();
		}
		$this->assertTrue($warnings === array(),
			'equal recursive values do not trigger unused var_export warnings');
		$this->assertTrue($probe->failureCount() === 0 && strpos($output, 'Passed:') !== false,
			'equal recursive values remain passing assertions');
	}

	public function testRecursiveFailureMessageDoesNotWarn(): void
	{
		$value = array();
		$value['self'] = &$value;
		$warnings = array();
		set_error_handler(function ($severity, $message) use (&$warnings) {
			$warnings[] = $message;
			return true;
		});
		try {
			list($probe, $output) = $this->captureAssertion(function ($case) use (&$value) {
				$case->assertEquals($value, array());
			});
		} finally {
			restore_error_handler();
		}
		$this->assertTrue($warnings === array(),
			'a recursive failure is formatted without a PHP warning');
		$this->assertTrue($probe->failureCount() === 1
			&& strpos($output, 'recursive') !== false,
			'a recursive mismatch remains a counted and described failure');
	}

	public function testStrictComparisonDoesNotRaiseObjectConversionWarnings(): void
	{
		$warnings = array();
		set_error_handler(function ($severity, $message) use (&$warnings) {
			$warnings[] = $message;
			return true;
		});
		try {
			list($probe, $output) = $this->captureAssertion(function ($case) {
				$case->assertEquals(new stdClass(), 1, 'an object is not an integer');
			});
		} finally {
			restore_error_handler();
		}
		$this->assertTrue($warnings === array(),
			'comparing an object with a scalar emits no conversion diagnostic');
		$this->assertTrue($probe->failureCount() === 1
			&& strpos($output, 'Failed: an object is not an integer') !== false,
			'the type mismatch remains a counted failure');
	}

	public function testSameUsesStrictComparison(): void
	{
		list($probe, $output) = $this->captureAssertion(function ($case) {
			$case->assertSame(0, '0');
		});
		$this->assertTrue($probe->failureCount() === 1 && strpos($output, 'Failed:') !== false,
			'assertSame refuses an integer versus its string spelling');
	}

	public function testTrueUsesExpectedMessageAndPreservesZeroMessage(): void
	{
		list($probe, $output) = $this->captureAssertion(function ($case) {
			$case->assertTrue(false);
			$case->assertTrue(false, '0');
		});
		$this->assertTrue($probe->failureCount() === 2,
			'both false assertions count as failures');
		$this->assertTrue(strpos($output, "Failed: Expected value to be true\n") !== false,
			'the default message states the expected value');
		$this->assertTrue(strpos($output, "Failed: 0\n") !== false,
			'an explicit zero message is not replaced');
	}

	public function testTrueRejectsTruthyStrings(): void
	{
		$threw = false;
		try {
			$this->captureAssertion(function ($case) {
				$case->assertTrue('1 == 2');
			});
		} catch (TypeError $error) {
			$threw = true;
		}
		$this->assertTrue($threw, 'assertTrue requires a boolean condition');
	}

	public function testAssertionMessagesRejectNonStrings(): void
	{
		foreach (array('assertTrue', 'assertEquals') as $method) {
			$threw = false;
			try {
				$this->captureAssertion(function ($case) use ($method) {
					if ($method === 'assertTrue') $case->assertTrue(true, array('bad'));
					else $case->assertEquals(1, 1, array('bad'));
				});
			} catch (TypeError $error) {
				$threw = true;
			}
			$this->assertTrue($threw, $method . ' rejects a non-string message');
		}
	}

	public function testEqualsDoesNotDispatchThroughOverriddenTrue(): void
	{
		$probe = new class extends TestCase {
			public function assertTrue($condition, $message = null): void
			{
				echo "Passed: overridden\n";
			}
		};
		ob_start();
		try {
			$probe->assertEquals(false, 0);
			$output = ob_get_contents();
		} finally {
			ob_end_clean();
		}
		$this->assertTrue($probe->failureCount() === 1,
			'assertEquals records its own failure despite an assertTrue override');
		$this->assertTrue(strpos($output, 'overridden') === false,
			'assertEquals does not call the override');
	}

}
