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
echo 'php-harness-test.sh: source paths, empty classes, early exits, and skipped TestCase methods passed'
