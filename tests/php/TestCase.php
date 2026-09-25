<?php

class TestCase
{
	private $failures = 0;

	public function failureCount()
	{
		return $this->failures;
	}

	public function runnableMethodCount(): int
	{
		return count($this->runnableMethods());
	}

	private function runnableMethods(): array
	{
		return array_values(array_filter(get_class_methods($this), function ($method) {
			return strncasecmp($method, 'test', 4) === 0;
		}));
	}

	function run(): int {
		$executed = 0;
		foreach ($this->runnableMethods() as $method) {
			echo ">>{$method}>>\n";
			try {
				call_user_func([$this, $method]);
			} catch (Throwable $e) {
				$this->failures++;
				echo "Test {$method} failed with error: {$e->getMessage()}\n";
			}
			echo "<<{$method}<<\n\n";
			$executed++;
		}
		return $executed;
	}

	public function setUp()
	{
	}

	public function tearDown()
	{
	}

	private function assertionMessage($message, string $default): string
	{
		if ($message === null) return $default;
		if (!is_string($message)) throw new TypeError('Assertion message must be a string or null');
		return $message;
	}

	private function reportAssertion(bool $passed, string $message): void
	{
		if ($passed) {
			echo "Passed: {$message}\n";
		} else {
			$this->failures++;
			echo "Failed: {$message}\n";
		}
	}

	private function describeAssertionValue($value): string
	{
		$warning = false;
		set_error_handler(function () use (&$warning) {
			$warning = true;
			return true;
		}, E_WARNING);
		try {
			$description = var_export($value, true);
		} finally {
			restore_error_handler();
		}
		return $warning ? gettype($value) . ' (recursive or unsupported)' : $description;
	}

	private function assertIdentical($expected, $actual, $message): void
	{
		$passed = $expected === $actual;
		if ($message === null) {
			$message = $passed ? 'Values are identical'
				: 'Expected ' . $this->describeAssertionValue($expected)
					. ' === ' . $this->describeAssertionValue($actual);
		}
		$this->reportAssertion($passed, $this->assertionMessage($message, 'Values are identical'));
	}

	public function assertEquals($a, $b, $message = null): void
	{
		$this->assertIdentical($a, $b, $message);
	}

	public function assertSame($expected, $actual, $message = null): void
	{
		$this->assertIdentical($expected, $actual, $message);
	}

	public function assertTrue($bool, $message = null): void
	{
		if (!is_bool($bool)) throw new TypeError('assertTrue requires a boolean condition');
		$this->reportAssertion($bool,
			$this->assertionMessage($message, 'Expected value to be true'));
	}
}

// Throwing assertions for small standalone suites. TestCase's instance
// assertions keep their own counted/printed contract.
function testAssertTrue($condition, $message)
{
	if (!$condition) throw new RuntimeException($message);
}

function testAssertSame($expected, $actual, $message)
{
	if ($expected !== $actual) {
		throw new RuntimeException($message . '; expected ' . var_export($expected, true)
			. ', got ' . var_export($actual, true));
	}
}

// Standalone suites own their optional setup/cleanup around this call. Return
// an exit status so cleanup can still run before the file exits.
function testRunCases(array $tests, $beforeEach = null)
{
	$failures = 0;
	foreach ($tests as $name => $callback) {
		try {
			if ($beforeEach !== null) $beforeEach();
			$callback();
			echo "ok - {$name}\n";
		} catch (Throwable $error) {
			$failures++;
			echo "not ok - {$name}\n";
			echo '  ' . get_class($error) . ': ' . $error->getMessage() . "\n";
		}
	}
	echo count($tests) . ' tests, ' . $failures . " failures\n";
	return $failures === 0 ? 0 : 1;
}

// PHP skips auto_append_file when a test calls exit(). At shutdown, flag any
// declared TestCase that never reached the shared runner's final marker.
if (getenv('RUTORRENT_PHP_TEST_RUNNER') === '1'
	&& basename((string) ini_get('auto_append_file')) === 'TestCaseRunner.php') {
	register_shutdown_function(function () {
		if (defined('RUTORRENT_TESTCASE_RUNNER_FINISHED')) return;
		foreach (get_declared_classes() as $cls) {
			if (is_subclass_of($cls, 'TestCase') && (new ReflectionClass($cls))->isInstantiable()) {
				echo "\nFailed: TestCase runner did not finish before shutdown\n";
				// Let later shutdown cleanups run before forcing a failing exit code.
				// An output buffer may discard the diagnostic itself.
				register_shutdown_function(function () { exit(1); });
				return;
			}
		}
	});
}
