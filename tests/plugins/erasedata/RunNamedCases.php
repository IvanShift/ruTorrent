<?php

// Test-only runner for named TestCase methods.
//
// It exists because `php SomeTest.php` runs NOTHING: these files declare a
// TestCase subclass and the shipped php-test.sh appends the loop that
// instantiates it. A case run that way is silently vacuous, and TestCase itself
// only PRINTS 'Failed:' -- it does not raise and it does not change the exit
// code -- so an exit status alone is not evidence of anything either.
//
// This runner therefore checks, for every named method:
//   * the method really started and really finished (START/END markers);
//   * it made a non-zero number of assertions;
//   * none of them failed, and the output carries none of the shipped
//     failure signals php-test.sh greps for.
// It exits non-zero if any of that does not hold, including when a fatal error
// ends the process before a method could finish.
//
// Usage: php -c tests/php-test.ini tests/plugins/erasedata/RunNamedCases.php \
//            <path to a *Test.php file> <testMethod> [<testMethod> ...]

if(PHP_SAPI !== 'cli')
{
	fwrite(STDERR, "RunNamedCases: CLI only\n");
	exit(2);
}
if(!isset($argv) || count($argv) < 3)
{
	fwrite(STDERR, "RunNamedCases: usage: <TestFile.php> <method> [<method> ...]\n");
	exit(2);
}

$runnerFile = $argv[1];
$runnerNames = array_slice($argv, 2);
if(!is_string($runnerFile) || !is_file($runnerFile))
{
	fwrite(STDERR, "RunNamedCases: no such test file: ".$runnerFile."\n");
	exit(2);
}

$runnerBefore = get_declared_classes();
require_once($runnerFile);
$runnerClass = null;
foreach(array_diff(get_declared_classes(), $runnerBefore) as $candidate)
	if(get_parent_class($candidate) === 'TestCase')
		$runnerClass = $candidate;
if($runnerClass === null)
{
	fwrite(STDERR, "RunNamedCases: ".$runnerFile." declares no TestCase subclass\n");
	exit(2);
}

$runnerFailures = 0;
$runnerFinished = array();
$runnerPatterns = '/^Failed:|^not ok|failed with error|PHP (Fatal|Parse) error|Uncaught/m';

// A fatal error kills the process before the loop below can report, so the
// verdict is printed from a shutdown handler that can see it happened.
register_shutdown_function(function() use (&$runnerFinished, $runnerNames) {
	$last = error_get_last();
	$fatal = is_array($last)
		&& in_array($last['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR,
			E_COMPILE_ERROR, E_USER_ERROR), true);
	$missing = array_values(array_diff($runnerNames, $runnerFinished));
	if(!$fatal && !count($missing))
		return;
	echo "RunNamedCases: INCOMPLETE";
	if($fatal)
		echo " (fatal: ".$last['message'].")";
	if(count($missing))
		echo " (never finished: ".implode(', ', $missing).")";
	echo "\n";
	// exit() inside a shutdown function still sets the process status.
	exit(1);
});

$runner = new $runnerClass();
$runner->setUp();
try
{
	foreach($runnerNames as $name)
	{
		if(!method_exists($runner, $name))
		{
			echo "RunNamedCases: ".$runnerClass." has no method ".$name."\n";
			$runnerFailures++;
			continue;
		}
		echo "START ".$name."\n";
		ob_start();
		try
		{
			$runner->$name();
		}
		catch(Exception $e)
		{
			echo "Test ".$name." failed with error: ".$e->getMessage()."\n";
		}
		catch(Error $e)
		{
			echo "Test ".$name." failed with error: ".$e->getMessage()."\n";
		}
		$output = ob_get_clean();
		echo $output;
		$passed = preg_match_all('/^Passed:/m', $output);
		$failed = preg_match_all('/^Failed:/m', $output);
		$signals = preg_match($runnerPatterns, $output);
		echo "END ".$name." assertions=".($passed + $failed)
			." passed=".$passed." failed=".$failed."\n";
		$runnerFinished[] = $name;
		if(($passed + $failed) === 0)
		{
			echo "RunNamedCases: ".$name." made no assertions at all\n";
			$runnerFailures++;
		}
		if($failed || $signals)
			$runnerFailures++;
	}
}
finally
{
	$runner->tearDown();
}

echo "RunNamedCases: ".count($runnerFinished)."/".count($runnerNames)
	." cases finished, ".$runnerFailures." with failures\n";
exit($runnerFailures ? 1 : 0);
