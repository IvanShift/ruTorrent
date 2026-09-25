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

	public function assertEquals($a, $b, $message = null): void
	{
		$this->assertTrue($a == $b, $message ? $message : 'Expected '.json_encode($a).' == '.json_encode($b));
	}

	public function assertTrue($bool, $message = null): void
	{
		$message = $message ? $message : 'Expected value to be ' . ($bool ? 'true' : 'false');
		if ($bool) {
			echo "Passed: {$message}\n";
		} else {
			$this->failures++;
			echo "Failed: {$message}\n";
		}
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
