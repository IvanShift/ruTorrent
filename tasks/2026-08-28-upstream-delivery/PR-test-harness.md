# Title

tests: report comparisons independently of assertions

# Body

## Problem

`TestCase::assertEquals()` and `assertTrue()` routed their comparisons through
PHP's `assert()`. With `zend.assertions=-1`, as shipped by
`php.ini-production`, PHP compiles the expression out and a deliberately false
check is reported as `Passed:`.

The suite itself enabled assertions by loading `tests/php-test.ini` with `-c`.
Deleting that file without replacing the runner contract would also load the
host's normal php.ini; on the measured host that changed `error_reporting()`
from 30719 to 22527 and `display_errors` from on to off.

## Change

- Use ordinary boolean comparisons, with `assertEquals()` delegating to
  `assertTrue()`. The existing `Passed:`/`Failed:` output stays unchanged and
  no longer depends on ini configuration.
- Keep the obsolete three-line `php-test.ini` deleted, but invoke each test
  file with explicit `zend.assertions=1`, `error_reporting=-1`, and
  `display_errors=1` settings.
- Add `TestCaseTest`: one case launches a child with assertions disabled and
  requires a false value to print `Failed:`; the other verifies the effective
  runner settings.

The runner's established failure policy is unchanged: visible warnings and
deprecations remain diagnostics, while non-zero children, `Failed:`, `not ok`,
fatal/parse errors, and uncaught exceptions fail the suite.

## Verification

```sh
cd tests && bash php-test.sh
```

- PHP 8.5.4 on current upstream: 47 test files, 287 named tests,
  1781 `Passed:`, 0 failure markers, exit 0.
- PHP 8.1.34 as root with a read-write bind mount and no `--user`: the same
  47 files, 287/287 start/end markers, and 1781 `Passed:`, exit 0.
- Returning `TestCase` to the legacy `assert()` implementation fails the new
  child-process test while the runner flags remain unchanged.
- Removing or weakening any one of the three command-line settings fails the
  runner-contract test.
- All seven full-suite mutations reached every named end marker without a
  fatal or parse error.

The branch is one commit, `8eafb529`, directly on current upstream
`755404f3`; its exact diff is four test-harness paths and `+49/-17`.

The direct regression probe is now executable on its own:

```sh
php -d zend.assertions=-1 -r 'require "tests/php/TestCase.php"; (new TestCase())->assertTrue(false, "ONE IS NOT TWO");'
```

It prints `Failed: ONE IS NOT TWO`.
