#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
scratch="$(mktemp -d "${TMPDIR:-/tmp}/rt-php-harness.XXXXXX")"
trap 'rm -rf -- "$scratch"' EXIT
mkdir -p "$scratch/tests/php" "$scratch/tests/plugins/a b"
cp "$root/tests/php-test.sh" "$root/tests/php-failure-pattern.sh" "$root/tests/php-test.ini" "$scratch/tests/"
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
echo 'php-harness-test.sh: root, path, empty, assertion, subclass, Throwable, and quoted-error gates passed'
