#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
scratch="$(mktemp -d "${TMPDIR:-/tmp}/rt-php-harness.XXXXXX")"
trap 'rm -rf -- "$scratch"' EXIT
mkdir -p "$scratch/tests/php" "$scratch/tests/plugins/a b"
cp "$root/tests/php-test.sh" "$root/tests/php-failure-pattern.sh" "$root/tests/php-test.ini" "$scratch/tests/"
cp "$root/tests/php/TestCaseRunner.php" "$scratch/tests/php/"
cat > "$scratch/tests/plugins/a b/One Test.php" <<'PHP'
<?php
echo "ok - fixture executed\n1 tests, 0 failures\n";
PHP

# Running from the repository root must execute the test even though the
# fixture path contains a space and the caller supplied CDPATH.
output="$(cd "$scratch" && CDPATH=. bash tests/php-test.sh)"
grep -q 'ok - fixture executed' <<< "$output" || {
    echo 'root invocation skipped its PHP tests' >&2
    exit 1
}

# PHP must execute the source file itself: __FILE__ is used by fixtures
# that reopen their own source, and __DIR__ must survive special path bytes.
cat > "$scratch/tests/plugins/a b/FileIdentityTest.php" <<'PHP'
<?php
if (!is_file(__FILE__) || dirname(__FILE__) !== __DIR__) {
    echo "not ok - source file identity was replaced by a pipe\n1 tests, 1 failures\n";
    exit(1);
}
echo "ok - source file identity survived\n1 tests, 0 failures\n";
PHP
if ! output="$(cd "$scratch" && bash tests/php-test.sh)"; then
    printf '%s\n' "$output" >&2
    echo 'the PHP runner did not preserve __FILE__ and __DIR__' >&2
    exit 1
fi
grep -q 'ok - source file identity survived' <<< "$output" || {
    echo 'the source identity fixture did not run' >&2
    exit 1
}
rm "$scratch/tests/plugins/a b/FileIdentityTest.php"
mkdir -p "$scratch/tests/plugins/a@b"
cat > "$scratch/tests/plugins/a@b/AtPathTest.php" <<'PHP'
<?php
echo "ok - delimiter in source path survived\n1 tests, 0 failures\n";
PHP
if ! output="$(cd "$scratch" && bash tests/php-test.sh)"; then
    printf '%s\n' "$output" >&2
    echo 'the PHP runner could not execute a path containing @' >&2
    exit 1
fi
grep -q 'ok - delimiter in source path survived' <<< "$output" || {
    echo 'the @ path fixture did not run' >&2
    exit 1
}
rm "$scratch/tests/plugins/a@b/AtPathTest.php"
rm "$scratch/tests/plugins/a b/One Test.php"
if (cd "$scratch" && bash tests/php-test.sh) > "$scratch/empty.log" 2>&1; then
    echo 'empty PHP suite reported success' >&2
    exit 1
fi
grep -q 'no PHP test files' "$scratch/empty.log" || {
    cat "$scratch/empty.log" >&2
    echo 'empty PHP suite had no actionable diagnostic' >&2
    exit 1
}
# A failed assertion remains a failure even when output from the method did
# not end with a newline before TestCase printed its diagnostic.
cp "$root/tests/php/TestCase.php" "$scratch/tests/php/TestCase.php"
cat > "$scratch/tests/plugins/a b/MidlineFailureTest.php" <<'PHP'
<?php
require_once(__DIR__.'/../../php/TestCase.php');
class MidlineFailureTest extends TestCase {
    public function testMidlineFailure() {
        echo 'output without a newline';
        $this->assertTrue(false, 'this assertion must fail the file');
        $this->assertTrue(true, 'the file also has a passing assertion');
    }
}
PHP
if (cd "$scratch" && bash tests/php-test.sh) > "$scratch/midline.log" 2>&1; then
    echo 'a midline failed assertion reported success' >&2
    exit 1
fi
grep -q 'Failed: this assertion must fail the file' "$scratch/midline.log" || {
    cat "$scratch/midline.log" >&2
    echo 'the failed assertion was not reported' >&2
    exit 1
}
rm "$scratch/tests/plugins/a b/MidlineFailureTest.php"

# The runner must discover indirect subclasses and use the same case-insensitive
# test prefix rule when selecting their methods.
cat > "$scratch/tests/plugins/a b/NestedFixtureTest.php" <<'PHP'
<?php
require_once(__DIR__.'/../../php/TestCase.php');
abstract class BaseFixture extends TestCase {}
class NestedFixtureTest extends BaseFixture {
    public function TestInheritedClassIsDiscovered() {
        $this->assertTrue(true, 'indirect subclass and capitalised Test method ran');
    }
}
PHP
if output="$(cd "$scratch" && bash tests/php-test.sh)"; then
    :
else
    printf '%s\n' "$output" >&2
    echo 'the indirect TestCase subclass fixture failed' >&2
    exit 1
fi
grep -q 'Passed: indirect subclass and capitalised Test method ran' <<< "$output" || {
    echo 'the indirect TestCase subclass or Test method was skipped' >&2
    exit 1
}
rm "$scratch/tests/plugins/a b/NestedFixtureTest.php"

# A PHP Error in a method is a counted case failure with its closing marker,
# not an uncaught fatal that prevents the remaining tests from running.
cat > "$scratch/tests/plugins/a b/ThrowableFixtureTest.php" <<'PHP'
<?php
require_once(__DIR__.'/../../php/TestCase.php');
class ThrowableFixtureTest extends TestCase {
    public function testThrowsError() { throw new Error('deliberate test error'); }
    public function testLaterCaseStillRuns() { $this->assertTrue(true, 'later method ran'); }
}
PHP
if (cd "$scratch" && bash tests/php-test.sh) > "$scratch/throwable.log" 2>&1; then
    echo 'a method Error reported success' >&2
    exit 1
fi
grep -q '<<testThrowsError<<' "$scratch/throwable.log" || {
    cat "$scratch/throwable.log" >&2
    echo 'the method Error escaped TestCase instead of being counted' >&2
    exit 1
}
grep -q 'Passed: later method ran' "$scratch/throwable.log" || {
    cat "$scratch/throwable.log" >&2
    echo 'a method Error skipped later cases' >&2
    exit 1
}
rm "$scratch/tests/plugins/a b/ThrowableFixtureTest.php"

# setUp/tearDown errors are caught by the outer runner, which must continue
# to the other TestCase classes declared in the same file.
cat > "$scratch/tests/plugins/a b/LifecycleThrowableTest.php" <<'PHP'
<?php
require_once(__DIR__.'/../../php/TestCase.php');
class SetupThrowableTest extends TestCase {
    public function setUp() { throw new Error('deliberate setup error'); }
    public function testUnreachable() { $this->assertTrue(false, 'setup must prevent this'); }
}
class TeardownThrowableTest extends TestCase {
    public function testPasses() { $this->assertTrue(true, 'teardown fixture ran'); }
    public function tearDown() { throw new Error('deliberate teardown error'); }
}
class AfterLifecycleErrorsTest extends TestCase {
    public function testStillRuns() { $this->assertTrue(true, 'later class ran'); }
}
PHP
if (cd "$scratch" && bash tests/php-test.sh) > "$scratch/lifecycle.log" 2>&1; then
    echo 'a lifecycle Error reported success' >&2
    exit 1
fi
for expected in 'Test SetupThrowableTest failed with error: deliberate setup error' \
    'Test TeardownThrowableTest tearDown failed with error: deliberate teardown error' \
    'Passed: later class ran'; do
    grep -q "$expected" "$scratch/lifecycle.log" || {
        cat "$scratch/lifecycle.log" >&2
        echo 'a lifecycle Error escaped the runner or skipped a later class' >&2
        exit 1
    }
done
rm "$scratch/tests/plugins/a b/LifecycleThrowableTest.php"

# A passing assertion may discuss a remote error named "Failed:". The
# fallback output matcher must not turn that ordinary passing text red.
cat > "$scratch/tests/plugins/a b/QuotedFailureTest.php" <<'PHP'
<?php
require_once(__DIR__.'/../../php/TestCase.php');
class QuotedFailureTest extends TestCase {
    public function testExpectedErrorString() {
        $this->assertEquals('Failed: remote lookup', 'Failed: remote lookup');
    }
}
PHP
if ! (cd "$scratch" && bash tests/php-test.sh) > "$scratch/quoted.log" 2>&1; then
    cat "$scratch/quoted.log" >&2
    echo 'a passing assertion that quotes Failed: was marked red' >&2
    exit 1
fi
rm "$scratch/tests/plugins/a b/QuotedFailureTest.php"
expect_rejected_test() {
    local name="$1" expected="$2" false_green="$3"
    local log="$scratch/$name.log"
    if (cd "$scratch" && bash tests/php-test.sh) > "$log" 2>&1; then
        printf '%s\n' "$false_green" >&2
        exit 1
    fi
    if [[ -n "$expected" ]] && ! grep -Fq "$expected" "$log"; then
        cat "$log" >&2
        printf '%s: expected rejection was not reported\n' "$name" >&2
        exit 1
    fi
    rm -- "$scratch/tests/plugins/a b/$name.php"
}

# A passing method marker must not hide a second concrete class with no
# runnable test methods in the same file.
cat > "$scratch/tests/plugins/a b/EmptySecondClassTest.php" <<'PHP'
<?php
require_once(__DIR__.'/../../php/TestCase.php');
class FirstRunnableCase extends TestCase {
    public function testPasses() { $this->assertTrue(true, 'the first class ran'); }
}
class EmptySecondCase extends TestCase {}
PHP
expect_rejected_test EmptySecondClassTest 'EmptySecondCase has no test methods' 'a second TestCase class with no methods was silently accepted'

# exit() inside the first method skips both a later method and the runner's
# final count. A single earlier Passed line is not proof of file completion.
cat > "$scratch/tests/plugins/a b/EarlyExitTest.php" <<'PHP'
<?php
require_once(__DIR__.'/../../php/TestCase.php');
class EarlyExitCase extends TestCase {
    public function testExitsEarly() {
        $this->assertTrue(true, 'the first assertion ran');
        exit(0);
    }
    public function testNeverReached() { $this->assertTrue(false, 'this method must run'); }
}
PHP
expect_rejected_test EarlyExitTest 'TestCase runner did not finish' 'exit in a test method silently skipped the rest of the file'

# A TestCase method can print standalone-style output before exiting. Its
# partial run must not use the standalone summary as a fallback success.
cat > "$scratch/tests/plugins/a b/ChildSummaryExitTest.php" <<'PHP'
<?php
require_once(__DIR__.'/../../php/TestCase.php');
class ChildSummaryExitCase extends TestCase {
    public function testExitsAfterChildSummary() {
        echo "ok - child\n1 tests, 0 failures\n";
        exit(0);
    }
    public function testNeverReached() { $this->assertTrue(false, 'this method must run'); }
}
PHP
expect_rejected_test ChildSummaryExitTest 'TestCase runner did not finish' 'an incomplete TestCase used a child summary to pass'

# A top-level exit can happen after declaring a TestCase but before the
# appended runner starts, leaving only a counterfeit standalone summary.
cat > "$scratch/tests/plugins/a b/TopLevelExitTest.php" <<'PHP'
<?php
require_once(__DIR__.'/../../php/TestCase.php');
class TopLevelExitCase extends TestCase {
    public function testNeverReached() { $this->assertTrue(false, 'this method must run'); }
}
echo "ok - child\n1 tests, 0 failures\n";
echo 'partial';
exit(0);
PHP
expect_rejected_test TopLevelExitTest 'TestCase runner did not finish' 'a top-level exit bypassed the declared TestCase'

# A test may discard shutdown output. Its exit status must still be red.
cat > "$scratch/tests/plugins/a b/BufferedExitTest.php" <<'PHP'
<?php
require_once(__DIR__.'/../../php/TestCase.php');
class BufferedExitCase extends TestCase {
    public function testNeverReached() { $this->assertTrue(false, 'this method must run'); }
}
echo "ok - child\n1 tests, 0 failures\n";
ob_start(function ($bytes) { return ''; });
exit(0);
PHP
expect_rejected_test BufferedExitTest '' 'an output buffer hid the unfinished TestCase'

# Failing an incomplete runner must still allow later cleanup callbacks.
cat > "$scratch/tests/plugins/a b/CleanupExitTest.php" <<'PHP'
<?php
require_once(__DIR__.'/../../php/TestCase.php');
class CleanupExitCase extends TestCase {
    public function testNeverReached() { $this->assertTrue(false, 'this method must run'); }
}
register_shutdown_function(function () { file_put_contents(__DIR__.'/cleanup-witness', 'done'); });
echo "ok - child\n1 tests, 0 failures\n";
ob_start(function ($bytes) { return ''; });
exit(0);
PHP
expect_rejected_test CleanupExitTest '' 'a cleanup fixture hid the unfinished TestCase'
if [[ ! -f "$scratch/tests/plugins/a b/cleanup-witness" ]]; then
    echo 'an unfinished TestCase skipped a later cleanup callback' >&2
    exit 1
fi
rm "$scratch/tests/plugins/a b/cleanup-witness"

# A custom run() that skips a declared method must not be treated as a
# complete TestCase merely because it reports one passing method.
cat > "$scratch/tests/plugins/a b/SkippingMethodTest.php" <<'PHP'
<?php
require_once(__DIR__.'/../../php/TestCase.php');
class SkippingMethodCase extends TestCase {
    public function testFirst() { $this->assertTrue(true, 'the first method ran'); }
    public function testSecond() { $this->assertTrue(false, 'this method must run'); }
    public function run(): int {
        echo ">>testFirst>>\n";
        $this->testFirst();
        return 1;
    }
}
PHP
expect_rejected_test SkippingMethodTest 'ran 1 of 2 test methods' 'a declared TestCase method was silently skipped'
# A child PHP diagnostic can be printed by a passing parent test. The output
# matcher must catch it even when php-test.ini omits the CLI's "PHP " prefix.
for diagnostic in Fatal Parse; do
    cat > "$scratch/tests/plugins/a b/ChildDiagnosticTest.php" <<'PHP'
<?php
$code = getenv('O15_DIAGNOSTIC') === 'Parse'
    ? 'this is invalid PHP syntax ;'
    : 'trigger_error("O15 child failure", E_USER_ERROR);';
$cmd = escapeshellarg(PHP_BINARY) . ' -c ' . escapeshellarg(__DIR__ . '/../../php-test.ini')
    . ' -r ' . escapeshellarg($code) . ' 2>&1';
echo shell_exec($cmd);
echo "ok - parent process reached its passing summary\n1 tests, 0 failures\n";
PHP
    if (cd "$scratch" && O15_DIAGNOSTIC="$diagnostic" bash tests/php-test.sh) > "$scratch/child-$diagnostic.log" 2>&1; then
        cat "$scratch/child-$diagnostic.log" >&2
        echo "child $diagnostic error was accepted as a passing test" >&2
        exit 1
    fi
    grep -q "$diagnostic error:" "$scratch/child-$diagnostic.log" \
        && grep -q 'Command line code on line 1' "$scratch/child-$diagnostic.log" \
        && grep -q 'ok - parent process reached its passing summary' "$scratch/child-$diagnostic.log" || {
        cat "$scratch/child-$diagnostic.log" >&2
        echo "child $diagnostic diagnostic or parent summary was not emitted" >&2
        exit 1
    }
    rm "$scratch/tests/plugins/a b/ChildDiagnosticTest.php"
done

# PHP_TEST_JOBS: the parallel mode reports the same bytes and verdict as the
# sequential run. The sleeps make later files finish before earlier ones.
# Each parallel run is bounded: a runner that loses track of a worker would
# otherwise hang this script instead of failing it.
fixture_dir="$scratch/tests/plugins/a b"
cat > "$fixture_dir/SlowPassTest.php" <<'PHP'
<?php
usleep(900000);
echo "ok - slow standalone file\n1 tests, 0 failures\n";
PHP
cat > "$fixture_dir/FailingCaseTest.php" <<'PHP'
<?php
require_once(__DIR__.'/../../php/TestCase.php');
class FailingParallelCase extends TestCase {
    public function testFails() { usleep(300000); $this->assertTrue(false, 'parallel failure is reported'); }
}
PHP
cat > "$fixture_dir/PassingCaseTest.php" <<'PHP'
<?php
require_once(__DIR__.'/../../php/TestCase.php');
class PassingParallelCase extends TestCase {
    public function testPasses() { usleep(600000); $this->assertTrue(true, 'parallel pass is reported'); }
}
PHP
cat > "$fixture_dir/WarningTest.php" <<'PHP'
<?php
trigger_error('parallel warning', E_USER_WARNING);
echo "ok - warning file\n1 tests, 0 failures\n";
PHP
cat > "$fixture_dir/ExitCodeTest.php" <<'PHP'
<?php
echo "ok - nonzero exit file\n1 tests, 0 failures\n";
exit(3);
PHP
# A failure printed only to stderr fails the file: stderr is judged too.
cat > "$fixture_dir/StderrFailureTest.php" <<'PHP'
<?php
fwrite(STDERR, "Failed: reported on stderr only\n");
echo "ok - stdout looks fine\n1 tests, 0 failures\n";
PHP
mkdir -m 0700 "$scratch/parallel-tmp"
seq_code=0
(cd "$scratch" && bash tests/php-test.sh) > "$scratch/sequential.log" 2>&1 || seq_code=$?
par_code=0
(cd "$scratch" && TMPDIR="$scratch/parallel-tmp" PHP_TEST_JOBS=3 PHP_TEST_TIMINGS="$scratch/timings" \
    timeout 60 bash tests/php-test.sh) > "$scratch/parallel.log" 2>&1 || par_code=$?
if [ "$seq_code" -ne 1 ] || [ "$par_code" -ne 1 ] || ! cmp -s "$scratch/sequential.log" "$scratch/parallel.log"; then
    diff "$scratch/sequential.log" "$scratch/parallel.log" >&2 || :
    echo "parallel run differs from the sequential run (exit $seq_code, $par_code)" >&2
    exit 1
fi
for expected in 'Passed: parallel pass is reported' 'Failed: parallel failure is reported' \
    'parallel warning' 'Failed PHP test files (4):' '::error file=tests/plugins/a b/StderrFailureTest.php::'; do
    grep -q "$expected" "$scratch/parallel.log" || {
        cat "$scratch/parallel.log" >&2
        echo "parallel run did not report: $expected" >&2
        exit 1
    }
done
# The per-file temporary directories were empty and are gone.
[ -z "$(ls -A "$scratch/parallel-tmp")" ] || {
    ls -A "$scratch/parallel-tmp" >&2
    echo 'parallel run left temporary directories behind' >&2
    exit 1
}
# Every finished file is kept, NUL-terminated, for the next start order.
records="$(tr -cd '\0' < "$scratch/timings" | wc -c)"
[ "$records" -eq 6 ] && tr '\0' '\n' < "$scratch/timings" | grep -q $'^[0-9]\+\tplugins/a b/SlowPassTest.php$' || {
    tr '\0' '\n' < "$scratch/timings" >&2
    echo "parallel run kept $records durations instead of 6" >&2
    exit 1
}
# The sequential run neither reads nor writes durations.
(cd "$scratch" && PHP_TEST_TIMINGS="$scratch/sequential-timings" bash tests/php-test.sh) > /dev/null 2>&1 || :
[ ! -e "$scratch/sequential-timings" ] || { echo 'the sequential run wrote durations' >&2; exit 1; }
rm -- "$fixture_dir"/{SlowPass,FailingCase,PassingCase,Warning,ExitCode,StderrFailure}Test.php "$scratch/timings"

# Tests list and name files in their temporary directory. Beside other
# files, each starts in an empty TMPDIR of its own below the run's TMPDIR,
# which holds the runner's own files; the empty directory goes afterwards.
cat > "$fixture_dir/PrivateTmpTest.php" <<'PHP'
<?php
$tmp = sys_get_temp_dir();
$entries = array_diff(scandir($tmp), array('.', '..'));
// tools/matrix.sh budgets exactly 4 bytes for this directory.
if (dirname($tmp) !== getenv('RUN_TMPDIR') || strlen($tmp) !== strlen(getenv('RUN_TMPDIR')) + 4
    || $entries !== array()) {
    echo "not ok - shared or used TMPDIR $tmp: " . implode(' ', $entries) . "\n1 tests, 1 failures\n";
    exit(1);
}
echo "ok - private temporary directory\n1 tests, 0 failures\n";
PHP
mkdir -m 0700 "$scratch/private-tmp"
(cd "$scratch" && TMPDIR="$scratch/private-tmp" RUN_TMPDIR="$scratch/private-tmp" PHP_TEST_JOBS=2 \
    timeout 60 bash tests/php-test.sh) > "$scratch/private.log" 2>&1 || {
    cat "$scratch/private.log" >&2
    echo 'a parallel file did not get an empty TMPDIR of its own' >&2
    exit 1
}
[ -z "$(ls -A "$scratch/private-tmp")" ] || {
    ls -A "$scratch/private-tmp" >&2
    echo 'parallel run left temporary directories behind' >&2
    exit 1
}
rm -rf -- "$fixture_dir/PrivateTmpTest.php" "$scratch/private-tmp"

# A file on the run-alone list runs before any other file starts, whatever
# its listing position or previous duration.
for name in One Two Three; do
    cat > "$fixture_dir/Beside${name}Test.php" <<'PHP'
<?php
touch(getenv('ALONE_DIR') . '/' . basename(__FILE__));
echo "ok - beside\n1 tests, 0 failures\n";
PHP
done
mkdir -p "$scratch/tests/plugins/erasedata" "$scratch/alone"
cat > "$scratch/tests/plugins/erasedata/RemoveWithDataTest.php" <<'PHP'
<?php
usleep(500000);
$started = count(glob(getenv('ALONE_DIR') . '/Beside*Test.php'));
echo $started === 0 ? "ok - ran alone\n1 tests, 0 failures\n"
    : "not ok - ran beside $started other files\n1 tests, 1 failures\n";
PHP
printf '1\tplugins/erasedata/RemoveWithDataTest.php\000' > "$scratch/alone-timings"
(cd "$scratch" && ALONE_DIR="$scratch/alone" PHP_TEST_JOBS=4 PHP_TEST_TIMINGS="$scratch/alone-timings" \
    timeout 60 bash tests/php-test.sh) > "$scratch/alone.log" 2>&1 && grep -q '^ok - ran alone$' "$scratch/alone.log" \
    && [ "$(grep -c '^ok - beside$' "$scratch/alone.log")" -eq 3 ] || {
    cat "$scratch/alone.log" >&2
    echo 'a run-alone file ran beside other files' >&2
    exit 1
}
rm -rf -- "$fixture_dir"/Beside{One,Two,Three}Test.php "$scratch/tests/plugins/erasedata" "$scratch/alone" "$scratch/alone-timings"

# Bash makes background commands ignore SIGINT. A test interrupted by SIGINT
# must stop, as it does in the sequential run, rather than carry on; the run
# starts with default dispositions whatever this script's caller ignores.
cat > "$fixture_dir/SignalsTest.php" <<'PHP'
<?php
echo "ok - SIGINT reached the test\n1 tests, 0 failures\n";
exec('kill -INT ' . getmypid());
usleep(200000);
echo "SIGINT was ignored\n";
PHP
if (cd "$scratch" && PHP_TEST_JOBS=2 timeout 60 env --default-signal=INT,QUIT bash tests/php-test.sh) > "$scratch/signals.log" 2>&1 \
    || grep -q 'SIGINT was ignored' "$scratch/signals.log" \
    || ! grep -qF '::error file=tests/plugins/a b/SignalsTest.php::' "$scratch/signals.log"; then
    cat "$scratch/signals.log" >&2
    echo 'a test interrupted by SIGINT carried on in the parallel run' >&2
    exit 1
fi
rm -- "$fixture_dir/SignalsTest.php"

for jobs in 0 -2 two 1.5 65; do
    if (cd "$scratch" && PHP_TEST_JOBS="$jobs" timeout 60 bash tests/php-test.sh) > "$scratch/jobs.log" 2>&1; then
        echo "PHP_TEST_JOBS=$jobs was accepted" >&2
        exit 1
    fi
    grep -q 'PHP_TEST_JOBS must be an integer from 1 to 64' "$scratch/jobs.log" || {
        cat "$scratch/jobs.log" >&2
        echo "PHP_TEST_JOBS=$jobs had no actionable diagnostic" >&2
        exit 1
    }
done

# Start order: with two slots, the last-listed file must start in the first
# pair when the previous run measured it longest, or did not measure it. It
# then has started before the first-listed file, which starts only after a
# one-second file of the first pair has finished.
for name in One Two Three; do
    cat > "$fixture_dir/Order${name}Test.php" <<'PHP'
<?php
touch(getenv('ORDER_DIR') . '/' . basename(__FILE__));
if (basename(__FILE__) === getenv('ORDER_LATE') && !is_file(getenv('ORDER_DIR') . '/' . getenv('ORDER_EARLY'))) {
    echo "not ok - " . getenv('ORDER_EARLY') . " had not started\n1 tests, 1 failures\n";
    exit(1);
}
sleep(1);
echo "ok - start order\n1 tests, 0 failures\n";
PHP
done
mapfile -d '' -t listed < <(cd "$scratch/tests" && find php plugins -type f -name '*Test.php' -print0)
[ "${#listed[@]}" -eq 3 ] || { printf '%s\n' "${listed[@]}" >&2; echo 'unexpected order fixtures' >&2; exit 1; }
run_order_case() {
    rm -rf -- "$scratch/order" && mkdir "$scratch/order"
    (cd "$scratch" && PHP_TEST_JOBS=2 PHP_TEST_TIMINGS="$scratch/order-timings" ORDER_DIR="$scratch/order" \
        ORDER_EARLY="$(basename "${listed[2]}")" ORDER_LATE="$(basename "${listed[0]}")" \
        timeout 60 bash tests/php-test.sh) > "$scratch/order.log" 2>&1
}
printf '1000\t%s\0002000\t%s\0003000\t%s\000' "${listed[0]}" "${listed[1]}" "${listed[2]}" > "$scratch/order-timings"
run_order_case || { cat "$scratch/order.log" >&2; echo 'the longest previous file did not start first' >&2; exit 1; }
printf '1000\t%s\0002000\t%s\000' "${listed[0]}" "${listed[1]}" > "$scratch/order-timings"
run_order_case || { cat "$scratch/order.log" >&2; echo 'a file without a previous duration did not start first' >&2; exit 1; }
# Without durations the files start in listing order, which this fixture
# detects: it would otherwise prove nothing about the two cases above.
rm -f -- "$scratch/order-timings"
if run_order_case; then
    echo 'the start-order fixture passed in listing order' >&2
    exit 1
fi
rm -rf -- "$fixture_dir"/Order{One,Two,Three}Test.php "$scratch/order" "$scratch/order-timings"

# A file whose temporary directory cannot be created fails visibly. The
# first file locks the run's TMPDIR; the last starts in its slot afterwards.
if [ "$(id -u)" -ne 0 ]; then
    cat > "$fixture_dir/LockTmpTest.php" <<'PHP'
<?php
chmod(getenv('LOCK_DIR'), 0500);
echo "ok - locked the run TMPDIR\n1 tests, 0 failures\n";
PHP
    cat > "$fixture_dir/HoldSlotTest.php" <<'PHP'
<?php
sleep(1);
echo "ok - held a slot\n1 tests, 0 failures\n";
PHP
    cat > "$fixture_dir/AfterLockTest.php" <<'PHP'
<?php
echo "ok - ran without a temporary directory\n1 tests, 0 failures\n";
PHP
    printf '3\t%s\0002\t%s\0001\t%s\000' "plugins/a b/LockTmpTest.php" "plugins/a b/HoldSlotTest.php" \
        "plugins/a b/AfterLockTest.php" > "$scratch/lock-timings"
    mkdir -m 0700 "$scratch/lock-tmp"
    lock_code=0
    (cd "$scratch" && TMPDIR="$scratch/lock-tmp" LOCK_DIR="$scratch/lock-tmp" PHP_TEST_JOBS=2 PHP_TEST_TIMINGS="$scratch/lock-timings" \
        timeout 60 bash tests/php-test.sh) > "$scratch/lock.log" 2>&1 || lock_code=$?
    chmod 0700 "$scratch/lock-tmp"
    if [ "$lock_code" -ne 1 ] || grep -q 'ran without a temporary directory' "$scratch/lock.log" \
        || ! grep -qF 'php-test.sh: cannot create a temporary directory for plugins/a b/AfterLockTest.php' "$scratch/lock.log" \
        || ! grep -qF '::error file=tests/plugins/a b/AfterLockTest.php::' "$scratch/lock.log"; then
        cat "$scratch/lock.log" >&2
        echo "a file without a temporary directory was not failed visibly (exit $lock_code)" >&2
        exit 1
    fi
    rm -rf -- "$fixture_dir"/{LockTmp,HoldSlot,AfterLock}Test.php "$scratch/lock-tmp" "$scratch/lock-timings"
fi

# A file whose output cannot be kept whole fails, although the part that was
# kept looks like a pass: the failure is at the end. The runner's own output
# goes through a pipe, which the file size limit does not cover.
if command -v prlimit > /dev/null; then
    cat > "$fixture_dir/TailTest.php" <<'PHP'
<?php
echo "ok - head\n1 tests, 0 failures\n";
for ($i = 0; $i < 6000; $i++) echo str_repeat('.', 50), "\n";
echo "not ok - tail failure the gate must see\n";
PHP
    partial_code=0
    (cd "$scratch" && PHP_TEST_JOBS=3 timeout 60 prlimit --fsize=204800 env --ignore-signal=XFSZ \
        bash tests/php-test.sh) 2>&1 | cat > "$scratch/partial.log" || partial_code=$?
    if [ "$partial_code" -ne 1 ] \
        || ! grep -qF 'php-test.sh: the output of plugins/a b/TailTest.php was not kept whole' "$scratch/partial.log" \
        || ! grep -qF '::error file=tests/plugins/a b/TailTest.php::' "$scratch/partial.log"; then
        tail -5 "$scratch/partial.log" >&2
        echo "a truncated output copy was judged as the file's result (exit $partial_code)" >&2
        exit 1
    fi
    rm -- "$fixture_dir/TailTest.php"
else
    echo 'php-harness-test.sh: prlimit unavailable; the truncated-output case did not run' >&2
fi

# A file the start order never launched is reported as failed, not skipped.
cat > "$fixture_dir/FirstLaunchedTest.php" <<'PHP'
<?php
echo "ok - launched\n1 tests, 0 failures\n";
PHP
cp "$fixture_dir/FirstLaunchedTest.php" "$fixture_dir/SecondLaunchedTest.php"
mkdir -p "$scratch/shim"
printf '#!/bin/sh\n%s "$@" | head -n 1\n' "$(command -v cut)" > "$scratch/shim/cut"
chmod +x "$scratch/shim/cut"
unlaunched_code=0
(cd "$scratch" && PATH="$scratch/shim:$PATH" PHP_TEST_JOBS=2 timeout 60 bash tests/php-test.sh) \
    > "$scratch/unlaunched.log" 2>&1 || unlaunched_code=$?
if [ "$unlaunched_code" -ne 1 ] \
    || ! grep -q '^php-test.sh: no result recorded for plugins/a b/[A-Za-z]*LaunchedTest.php$' "$scratch/unlaunched.log" \
    || ! grep -q '^Failed PHP test files (1):$' "$scratch/unlaunched.log"; then
    cat "$scratch/unlaunched.log" >&2
    echo "a file that was never started was not reported as failed (exit $unlaunched_code)" >&2
    exit 1
fi
rm -rf -- "$fixture_dir"/{First,Second}LaunchedTest.php "$scratch/shim"

# A worker that dies without a result fails its file visibly; the other file
# of the run is still reported.
cat > "$fixture_dir/LostWorkerTest.php" <<'PHP'
<?php
// In the parallel mode this PHP process is the child of its worker shell.
$stat = file_get_contents('/proc/self/stat');
$fields = preg_split('/\s+/', substr($stat, strrpos($stat, ')') + 2));
exec('kill -KILL ' . (int) $fields[1]);
echo "ok - orphaned output\n1 tests, 0 failures\n";
PHP
cat > "$fixture_dir/SurvivorTest.php" <<'PHP'
<?php
echo "ok - survivor\n1 tests, 0 failures\n";
PHP
if (cd "$scratch" && PHP_TEST_JOBS=2 timeout 60 bash tests/php-test.sh) > "$scratch/lost.log" 2>&1; then
    cat "$scratch/lost.log" >&2
    echo 'a lost worker was reported as a pass' >&2
    exit 1
fi
for expected in 'php-test.sh: no result recorded for plugins/a b/LostWorkerTest.php' \
    '::error file=tests/plugins/a b/LostWorkerTest.php::' 'ok - survivor' 'Failed PHP test files (1):'; do
    grep -qF "$expected" "$scratch/lost.log" || {
        cat "$scratch/lost.log" >&2
        echo "lost worker run did not report: $expected" >&2
        exit 1
    }
done
rm -- "$fixture_dir/LostWorkerTest.php" "$fixture_dir/SurvivorTest.php"

# TERM stops the running PHP processes and removes the results directory,
# even when a leaked child still holds a test's output open.
cat > "$fixture_dir/SleeperTest.php" <<'PHP'
<?php
exec('sleep 30 >&2 & echo $!', $holder);
file_put_contents(getenv('SLEEPER_DIR') . '/holders', $holder[0] . "\n", FILE_APPEND | LOCK_EX);
file_put_contents(getenv('SLEEPER_DIR') . '/' . getmypid(), '');
sleep(60);
PHP
cp "$fixture_dir/SleeperTest.php" "$fixture_dir/SecondSleeperTest.php"
# A file that finished before the interrupt is still reported whole.
cat > "$fixture_dir/FinishedBeforeTermTest.php" <<'PHP'
<?php
echo "ok - finished before TERM\n1 tests, 0 failures\n";
PHP
mkdir "$scratch/sleepers" "$scratch/interrupt-tmp"
(cd "$scratch" && SLEEPER_DIR="$scratch/sleepers" TMPDIR="$scratch/interrupt-tmp" PHP_TEST_JOBS=3 \
    exec bash tests/php-test.sh) > "$scratch/interrupt.log" 2>&1 &
runner=$!
for _ in $(seq 100); do
    [ "$(find "$scratch/sleepers" -type f -name '[0-9]*' | wc -l)" -eq 2 ] \
        && [ -n "$(find "$scratch/interrupt-tmp" -name '*.done')" ] && break
    sleep 0.1
done
sleepers=($(ls "$scratch/sleepers" | grep -v holders))
kill -TERM "$runner"
for _ in $(seq 100); do
    kill -0 "$runner" 2>/dev/null || break
    sleep 0.1
done
runner_code=0
if kill -0 "$runner" 2>/dev/null; then
    runner_code=hung
    kill -KILL "$runner" 2>/dev/null || :
    wait "$runner" || :
else
    wait "$runner" || runner_code=$?
fi
xargs -r kill -KILL < "$scratch/sleepers/holders" 2>/dev/null || :
alive=''
for _ in $(seq 50); do
    alive=''
    for pid in "${sleepers[@]}"; do
        if kill -0 "$pid" 2>/dev/null; then alive+=" $pid"; fi
    done
    [ -n "$alive" ] || break
    sleep 0.1
done
if [ "${#sleepers[@]}" -ne 2 ] || [ "$runner_code" != 143 ] || [ -n "$alive" ] \
    || [ -n "$(ls -A "$scratch/interrupt-tmp")" ] \
    || ! grep -q '^ok - finished before TERM$' "$scratch/interrupt.log" \
    || ! grep -qF 'php-test.sh: no result recorded for plugins/a b/SleeperTest.php' "$scratch/interrupt.log" \
    || ! grep -qF 'php-test.sh: no result recorded for plugins/a b/SecondSleeperTest.php' "$scratch/interrupt.log" \
    || ! grep -q '^Failed PHP test files (2):$' "$scratch/interrupt.log"; then
    cat "$scratch/interrupt.log" >&2
    for pid in $alive; do kill -KILL "$pid" 2>/dev/null || :; done
    ls -A "$scratch/interrupt-tmp" >&2
    echo "TERM left PHP processes ($alive) or results (exit $runner_code, ${#sleepers[@]} started)" >&2
    exit 1
fi
rm -rf -- "$fixture_dir"/{Sleeper,SecondSleeper,FinishedBeforeTerm}Test.php "$scratch/sleepers" "$scratch/interrupt-tmp"
echo 'php-harness-test.sh: source paths, empty classes, early exits, skipped TestCase methods, and parallel jobs passed'
