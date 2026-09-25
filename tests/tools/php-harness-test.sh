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
echo 'php-harness-test.sh: root invocation, CDPATH, spaced path, and empty-suite gate passed'
