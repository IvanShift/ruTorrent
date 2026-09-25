#!/bin/bash

script_dir="$(CDPATH= cd -- "$(dirname "${BASH_SOURCE[0]}")" && pwd)" || exit 1
cd "$script_dir" || exit 1

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
# Several suites write large fixtures (SCGITransportTest streams 64 MiB, the
# retrackers bounded-reader cases write 64 MiB each) into the temporary
# filesystem. A small tmpfs shared with other processes can fill. Then
# the failure surfaces as a dozen unrelated suites failing on writes they never
# expected to fail. That reads exactly like a code regression and is not one.
#
# Warn rather than refuse: a CI runner with a small but sufficient /tmp is fine,
# and a suite gate that fails on a heuristic is worse than the confusion it
# prevents. Set TMPDIR to a disk-backed directory to avoid it entirely.
tmp_free_kb=$(df -Pk "${TMPDIR:-/tmp}" 2>/dev/null | awk 'NR==2 {print $4}')
if [ -n "$tmp_free_kb" ] && [ "$tmp_free_kb" -lt 524288 ]; then
	printf 'php-test.sh: only %s MiB free on %s; suites that write 64 MiB fixtures may fail on space rather than on behaviour. Set TMPDIR to a disk-backed directory.\n' \
		"$((tmp_free_kb / 1024))" "${TMPDIR:-/tmp}" >&2
fi

. "$script_dir/php-failure-pattern.sh"
status=0
failed_files=()
test_manifest="$(mktemp "${TMPDIR:-/tmp}/rutorrent-php-tests.XXXXXX")" || exit 1
trap 'rm -f -- "$test_manifest"' EXIT
if ! find php plugins -type f -name '*Test.php' -print0 > "$test_manifest"; then
	echo 'php-test.sh: cannot list PHP test files' >&2
	exit 1
fi
mapfile -d '' -t test_files < "$test_manifest"
if [ "${#test_files[@]}" -eq 0 ]; then
	echo 'php-test.sh: no PHP test files found' >&2
	exit 1
fi
for t in "${test_files[@]}"
do
	printf '> php %s\n' "$t"
	# Absolute: this stands in for __DIR__ below, and a relative path makes a
	# fixture symlink resolve against the wrong directory.
	DIR=$(CDPATH= cd -- "$(dirname "$t")" && pwd)
	out=$(php -c php-test.ini -f <(cat <(sed "s@__DIR__@\"$DIR\"@g" "$t") <(echo "$TEST_RUN")) 2>&1)
	code=$?
	printf '%s\n' "$out"
	# A present *Test.php is not proof that its tests ran. TestCase emits a
	# method marker and an assertion; self-running suites emit a case marker
	# and a positive zero-failure summary. Require one of those observed runs.
	checked=0
	if printf '%s\n' "$out" | grep -qE '^>>[^>].*>>$' \
		&& printf '%s\n' "$out" | grep -q '^Passed: '; then
		checked=1
	elif printf '%s\n' "$out" | grep -q '^ok - ' \
		&& printf '%s\n' "$out" | grep -qE '^[1-9][0-9]* tests?, 0 failures$'; then
		checked=1
	fi
	if [ "$checked" -eq 0 ]; then
		printf 'php-test.sh: no nonempty passing test result in %s\n' "$t"
	fi
	if [ "$code" -ne 0 ] || [ "$checked" -eq 0 ] \
		|| printf '%s\n' "$out" | grep -qE "$PHP_FAILURE_PATTERN"; then
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
