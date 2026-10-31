#!/bin/bash

script_dir="$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)" || exit 1
cd -- "$script_dir" || exit 1

# Exit non-zero if any test file fails, so the suite can gate CI. Counted
# TestCase and self-running TestLib failures set a non-zero exit; the output
# matcher still catches diagnostics from other self-running fixtures.
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

# PHP_TEST_JOBS=N runs up to N test files at once; the default 1 keeps the
# sequential run. Every file is still reported whole and in the listing order
# below, so callers that parse this output see the same lines either way. A
# parallel run keeps per-file durations in PHP_TEST_TIMINGS, when that names
# a file, and starts the slowest files of the previous run first.
jobs="${PHP_TEST_JOBS:-1}"
if ! [[ "$jobs" =~ ^[1-9][0-9]?$ ]] || [ "$jobs" -gt 64 ]; then
	printf 'php-test.sh: PHP_TEST_JOBS must be an integer from 1 to 64, not %s\n' "$jobs" >&2
	exit 2
fi

. "$script_dir/php-failure-pattern.sh"
status=0
failed_files=()
results=''
test_manifest="$(mktemp "${TMPDIR:-/tmp}/rutorrent-php-tests.XXXXXX")" || exit 1
trap 'rm -f -- "$test_manifest"; [ -z "$results" ] || rm -rf -- "$results"' EXIT
if ! find php plugins -type f -name '*Test.php' -print0 > "$test_manifest"; then
	echo 'php-test.sh: cannot list PHP test files' >&2
	exit 1
fi
mapfile -d '' -t test_files < "$test_manifest"
if [ "${#test_files[@]}" -eq 0 ]; then
	echo 'php-test.sh: no PHP test files found' >&2
	exit 1
fi
# Execute the file itself so PHP resolves __FILE__ and __DIR__ from its
# real source path, including spaces and sed delimiter characters. exec keeps
# the caller's command substitution process as the PHP process.
run_test() {
	RUTORRENT_PHP_TEST_RUNNER=1 exec php -c php-test.ini \
		-d auto_append_file="$script_dir/php/TestCaseRunner.php" -f "$1" 2>&1
}

# Prints one file's output and judges it; $1 is the file, $2 its exit code
# and $3 its output.
report_test() {
	local t="$1" code="$2" out="$3" checked
	printf '%s\n' "$out"
	# A present *Test.php is not proof that its tests ran. Once TestCase starts,
	# require its final marker; child standalone summaries cannot replace it.
	# Standalone suites emit a case marker and a positive zero-failure summary.
	checked=0
	if printf '%s\n' "$out" | grep -qE '^(Test: |>>[^>].*>>$)'; then
		if printf '%s\n' "$out" | grep -q '^Passed: ' \
			&& printf '%s\n' "$out" | grep -qE '^TestCase runner finished: [1-9][0-9]* classes, [1-9][0-9]* methods$'; then
			checked=1
		elif ! printf '%s\n' "$out" | grep -q '^TestCase runner finished: '; then
			printf 'php-test.sh: TestCase runner did not finish in %s\n' "$t"
		fi
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
}

# Runs one file in the background. Its output goes to N.out and then
# "exit-code microseconds kept" to N.done, renamed into place last, so a
# present N.done means a finished file. kept is 1 only when N.out holds the
# whole output: a short write loses the end, where failures and the runner's
# final marker are. Capturing through a command substitution, as the
# sequential loop does, also waits for every process that inherited the
# output. N.pid names the PHP process for an interrupt to stop.
run_parallel_test() {
	local index="$1" t="${test_files[$1]}" started out code private kept=1
	# Bash makes background commands ignore SIGINT and SIGQUIT; a test gets
	# the dispositions of the sequential run back.
	trap - INT QUIT
	started="${EPOCHREALTIME//[!0-9]/}"
	# Each file gets a TMPDIR of its own: tests compare listings of their
	# temporary directory and use fixed names in it. The 4-byte suffix keeps
	# socket paths within the budget of tools/matrix.sh.
	if private="$(mktemp -d "${TMPDIR:-/tmp}/XXX")"; then
		printf '%s\n' "$private" > "$results/$index.tmpdir"
		out=$(printf '%s\n' "$BASHPID" > "$results/$index.pid"; TMPDIR="$private" run_test "$t")
		code=$?
		rm -f -- "$results/$index.pid"
		# An empty directory goes; one with leftovers stays for diagnosis.
		rmdir -- "$private" 2>/dev/null
	else
		out="php-test.sh: cannot create a temporary directory for $t"
		code=1
	fi
	printf '%s\n' "$out" > "$results/$index.out" || kept=0
	printf '%s %s %s\n' "$code" "$(( ${EPOCHREALTIME//[!0-9]/} - ${started:-0} ))" "$kept" \
		> "$results/$index.tmp" && mv -f -- "$results/$index.tmp" "$results/$index.done"
}

# The parallel mode runs these files alone, one at a time, before all others.
# Each matrix leg then starts with them, so they run beside the same files of
# the other legs only, as in the sequential matrix.
# - plugins/erasedata/RemoveWithDataTest.php: while other files create and
#   remove files, the ext4 allocator does not hand a freed inode straight back,
#   so testCleanupRetryPreservesNewParentEvenIfInodeIsReused takes its no-reuse
#   branch (256 more assertions, the reuse case unchecked). On 2026-10-29 that
#   happened in 3 of 9 leg runs beside the same leg's files and in 1 of 12 run
#   alone after them, beside the other legs' parallel files; and
#   testProducerAndWorkerRaceKeepOneFixedScheduleKey saw its worker exit
#   abnormally once beside other files.
alone_files=(plugins/erasedata/RemoveWithDataTest.php)

# Indexes of test_files, longest previous duration first, so the slowest
# files do not start last and set the total. A file without a previous
# duration may be the slowest, so files without one start first; ties keep
# the listing order.
start_order() {
	local record index
	local -A previous=()
	if [ -n "${PHP_TEST_TIMINGS:-}" ] && [ -f "$PHP_TEST_TIMINGS" ]; then
		while IFS= read -r -d '' record; do
			[[ "$record" =~ ^([0-9]+)$'\t'(.+)$ ]] && previous["${BASH_REMATCH[2]}"]="${BASH_REMATCH[1]}"
		done < "$PHP_TEST_TIMINGS"
	fi
	for index in "${!test_files[@]}"; do
		printf '%s %s\n' "${previous[${test_files[index]}]:-999999999999}" "$index"
	done | sort -k1,1nr -k2,2n | cut -d ' ' -f 2
}

# Starts the given indexes with at most $1 running at once, then waits for all.
run_parallel_tests() {
	local limit="$1" index running=0
	shift
	for index in "$@"; do
		if [ "$running" -ge "$limit" ]; then
			wait -n
			running=$((running - 1))
		fi
		run_parallel_test "$index" &
		worker_pid[index]=$!
		running=$((running + 1))
	done
	wait
}

# Reports every listed file in the listing order. A file without a finished
# result, whether it never started, its worker died or an interrupt stopped
# it, is reported as failed, and so is one whose output was not kept whole.
report_parallel_tests() {
	local index t code duration kept out
	for index in "${!test_files[@]}"; do
		t="${test_files[index]}" code='' duration='' kept='' out=''
		printf '> php %s\n' "$t"
		if [ -e "$results/$index.done" ]; then
			read -r code duration kept < "$results/$index.done"
			[ ! -e "$results/$index.out" ] || out="$(<"$results/$index.out")"
		fi
		if ! [[ "$code" =~ ^[0-9]+$ ]]; then
			printf 'php-test.sh: no result recorded for %s\n' "$t"
			code=1
		elif [ "$kept" != 1 ]; then
			printf 'php-test.sh: the output of %s was not kept whole\n' "$t"
			code=1
		fi
		[[ "$duration" =~ ^[0-9]+$ ]] && durations[index]="$duration"
		report_test "$t" "$code" "$out"
	done
}

# Keeps this run's durations for the next run's start order. Only the
# parallel mode reads or writes PHP_TEST_TIMINGS.
record_timings() {
	local index saved=''
	[ -n "${PHP_TEST_TIMINGS:-}" ] || return 0
	if saved="$(mktemp "$PHP_TEST_TIMINGS.XXXXXX")" \
		&& for index in "${!durations[@]}"; do
			printf '%s\t%s\0' "${durations[index]}" "${test_files[index]}"
		done > "$saved" \
		&& mv -f -- "$saved" "$PHP_TEST_TIMINGS"; then
		return 0
	fi
	[ -z "$saved" ] || rm -f -- "$saved"
	printf 'php-test.sh: cannot record test durations in %s\n' "$PHP_TEST_TIMINGS" >&2
}

report_failed_files() {
	if [ "${#failed_files[@]}" -ne 0 ]; then
		printf 'Failed PHP test files (%d):\n' "${#failed_files[@]}"
		printf ' - %s\n' "${failed_files[@]}"
	fi
}

# An interrupt stops the PHP processes, then the workers still without a
# result, removes the empty temporary directories they leave and reports
# every file: the finished ones whole, the others as without a result.
stop_parallel_tests() {
	local pidfile pid index
	trap - HUP INT TERM
	for pidfile in "$results"/*.pid; do
		read -r pid < "$pidfile" 2>/dev/null || continue
		[[ "$pid" =~ ^[1-9][0-9]*$ ]] && kill -TERM "$pid" 2>/dev/null
	done
	for index in "${!worker_pid[@]}"; do
		[ -e "$results/$index.done" ] || kill -TERM "${worker_pid[index]}" 2>/dev/null
	done
	# No worker may still write into the results directory once it is read.
	wait
	for index in "${!worker_pid[@]}"; do
		[ -e "$results/$index.done" ] || [ ! -f "$results/$index.tmpdir" ] \
			|| rmdir -- "$(<"$results/$index.tmpdir")" 2>/dev/null
	done
	report_parallel_tests
	report_failed_files
	exit "$1"
}

if [ "$jobs" -eq 1 ]; then
	for t in "${test_files[@]}"
	do
		printf '> php %s\n' "$t"
		out=$(run_test "$t")
		code=$?
		report_test "$t" "$code" "$out"
	done
else
	results="$(mktemp -d "${TMPDIR:-/tmp}/rutorrent-php-results.XXXXXX")" || exit 1
	worker_pid=()
	durations=()
	trap 'stop_parallel_tests 129' HUP
	trap 'stop_parallel_tests 130' INT
	trap 'stop_parallel_tests 143' TERM
	mapfile -t order <<< "$(start_order)"
	beside=()
	alone=()
	for index in "${order[@]}"; do
		if [[ " ${alone_files[*]} " == *" ${test_files[index]} "* ]]; then
			alone+=("$index")
		else
			beside+=("$index")
		fi
	done
	run_parallel_tests 1 "${alone[@]}"
	run_parallel_tests "$jobs" "${beside[@]}"
	trap - HUP INT TERM
	report_parallel_tests
	record_timings
fi

report_failed_files

exit $status
