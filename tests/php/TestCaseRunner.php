<?php

// Included by php-test.sh after each test file, so legacy TestCase classes
// still run even when a file only declares them.
$failures = 0;
$classes = 0;
$methods = 0;
foreach (get_declared_classes() as $cls) {
	if (is_subclass_of($cls, 'TestCase') && (new ReflectionClass($cls))->isInstantiable()) {
		$classes++;
		echo "Test: {$cls}\n";
		$obj = new $cls();
		$expected = $obj->runnableMethodCount();
		if ($expected === 0) {
			echo "Test {$cls} has no test methods\n";
			$failures++;
			continue;
		}
		$classReady = false;
		try {
			$obj->setUpClass();
			$classReady = true;
			$executed = $obj->run();
			$methods += $executed;
			if ($executed !== $expected) {
				throw new RuntimeException("ran {$executed} of {$expected} test methods");
			}
		} catch (Throwable $e) {
			echo 'Test ' . $cls . ' failed with error: ' . $e->getMessage() . "\n";
			$failures++;
		}
		if ($classReady) {
			try {
				$obj->tearDownClass();
			} catch (Throwable $e) {
				echo 'Test ' . $cls . ' tearDownClass failed with error: ' . $e->getMessage() . "\n";
				$failures++;
			}
		}
		$failures += $obj->failureCount();
	}
}
define('RUTORRENT_TESTCASE_RUNNER_FINISHED', true);
echo "TestCase runner finished: {$classes} classes, {$methods} methods\n";
if ($failures > 0) exit(1);
