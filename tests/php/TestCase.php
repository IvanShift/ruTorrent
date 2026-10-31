<?php

class TestCase
{
	private $failures = 0;

	protected static function makeAccessible($member)
	{
		if(PHP_VERSION_ID < 80100)
			$member->setAccessible(true);
		return $member;
	}

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
			$this->runMethod($method);
			$executed++;
		}
		return $executed;
	}

	// The named-case runner uses this same lifecycle for a selected method.
	public function runMethod(string $method): void
	{
		$matched = null;
		foreach ($this->runnableMethods() as $candidate) {
			if (strcasecmp($candidate, $method) === 0) {
				$matched = $candidate;
				break;
			}
		}
		if ($matched === null) {
			throw new InvalidArgumentException("Not a runnable test method: {$method}");
		}
		$method = $matched;
		echo ">>{$method}>>\n";
		$phase = 'setUp';
		$ready = false;
		try {
			$this->setUp();
			$ready = true;
			$phase = 'test';
			call_user_func([$this, $method]);
		} catch (Throwable $e) {
			$this->failures++;
			$source = $phase === 'setUp' ? get_class($this) : $method;
			echo "Test {$source} failed with error: {$e->getMessage()}\n";
		} finally {
			if ($ready) {
				try {
					$this->tearDown();
				} catch (Throwable $e) {
					$this->failures++;
					echo 'Test ' . get_class($this) . ' tearDown failed with error: '
						. $e->getMessage() . " (after {$method})\n";
				}
			}
			echo "<<{$method}<<\n\n";
		}
	}

	// Use these for fixtures intentionally shared by all methods in one class.
	public function setUpClass()
	{
	}

	public function tearDownClass()
	{
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

// exit() skips auto_append_file. Fail an interrupted TestCase even when its
// output buffer discards the diagnostic, while allowing later cleanup hooks.
// PHP 7.4 disabled functions return NULL; warnings alone can be buffered away.
foreach (array('function_exists', 'getenv', 'basename', 'ini_get', 'register_shutdown_function',
	'defined', 'get_declared_classes', 'is_subclass_of', 'array_values', 'array_filter',
	'get_class_methods', 'substr', 'count', 'call_user_func', 'fwrite', 'strlen', 'getmypid') as $function) {
	if (function_exists($function) !== true) {
		throw new RuntimeException("Required test function unavailable: {$function}");
	}
}
if (getenv('RUTORRENT_PHP_TEST_RUNNER') === '1'
	&& basename((string) ini_get('auto_append_file')) === 'TestCaseRunner.php') {
	// pcntl_fork() children inherit this hook; only the runner's process reports.
	register_shutdown_function(function ($owner) {
		if (defined('RUTORRENT_TESTCASE_RUNNER_FINISHED') || getmypid() !== $owner) return;
		foreach (get_declared_classes() as $cls) {
			if (is_subclass_of($cls, 'TestCase') && (new ReflectionClass($cls))->isInstantiable()) {
				// A later cleanup may exit(0) before our deferred failing exit.
				// STDERR also preserves the refusal when output buffers discard it.
				$message = "\nFailed: TestCase runner did not finish before shutdown\n";
				$written = 0;
				try {
					while ($written < strlen($message)) {
						$remaining = substr($message, $written);
						$count = @fwrite(STDERR, $remaining);
						// Without a complete refusal, a later exit(0) could hide us.
						if ($count <= 0) exit(1);
						$written += $count;
					}
				} catch (Throwable $error) {
					exit(1);
				}
				register_shutdown_function(function () { exit(1); });
				return;
			}
		}
	}, getmypid());
}
