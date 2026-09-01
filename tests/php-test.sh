#!/bin/bash

TEST_RUN='
foreach(get_declared_classes() as $cls) {
	if (get_parent_class($cls) == "TestCase") {
		echo "Test: {$cls}\n";
		$obj = new $cls();
		try {
			$obj->setUp();
			$obj->run();
		} catch (Exception $e) {
			echo $e->getMessage()."\n";
			echo $e->getTraceAsString()."\n";
		}
		$obj->tearDown();
	}
}'

# Exit non-zero if any test file fails, so the suite can gate CI. Two failure
# signals are honoured: a non-zero exit (the self-running TestLib suites end
# with exit($failures)) and failure output (the TestCase runner only prints).
status=0
failed_files=()
for t in $(find php plugins -type f -name '*Test.php')
do
	echo '> php' $t
	# Absolute: this stands in for __DIR__ below, and a relative path makes a
	# fixture symlink resolve against the wrong directory.
	DIR=$(cd "$(dirname "$t")" && pwd)
	out=$(php -c php-test.ini -f <(cat <(sed "s@__DIR__@\"$DIR\"@g" "$t") <(echo "$TEST_RUN")) 2>&1)
	code=$?
	printf '%s\n' "$out"
	if [ "$code" -ne 0 ] || printf '%s\n' "$out" | grep -qE '^Failed:|^not ok|failed with error|PHP (Fatal|Parse) error|Uncaught'; then
		status=1
		failed_files+=("$t")
		# Public job annotations identify the failing file even when GitHub hides
		# the full Actions log from unauthenticated readers.
		printf '::error file=tests/%s::PHP test file failed; inspect the PHP failure-log artifact for its complete output.\n' "$t"
	fi
done

if [ "${#failed_files[@]}" -ne 0 ]; then
	printf 'Failed PHP test files (%d):\n' "${#failed_files[@]}"
	printf ' - %s\n' "${failed_files[@]}"
fi

exit $status
