<?php

// Included by php-test.sh after each test file, so legacy TestCase classes
// still run even when a file only declares them.
$failures = 0;
foreach (get_declared_classes() as $cls) {
	if (is_subclass_of($cls, 'TestCase') && (new ReflectionClass($cls))->isInstantiable()) {
		echo "Test: {$cls}\n";
		$obj = new $cls();
		try {
			$obj->setUp();
			$obj->run();
		} catch (Throwable $e) {
			echo 'Test ' . $cls . ' failed with error: ' . $e->getMessage() . "\n";
			$failures++;
		}
		try {
			$obj->tearDown();
		} catch (Throwable $e) {
			echo 'Test ' . $cls . ' tearDown failed with error: ' . $e->getMessage() . "\n";
			$failures++;
		}
		$failures += $obj->failureCount();
	}
}
if ($failures > 0) exit(1);
