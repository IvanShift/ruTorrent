#!/usr/bin/env bash
# Isolated behavior checks for the local matrix runner; no Docker needed.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
runner="${MATRIX_RUNNER:-$root/tasks/matrix.sh}"
scratch="$(mktemp -d /tmp/rtmatrix.XXXXXX)"
short_home="$(mktemp -d /tmp/mx.XXXXXX)"
long_home="$(mktemp -d /tmp/rtmlonghome.XXXXXX)"
matrix_pid=''
child_pid=''
cleanup() {
    if [ -n "$matrix_pid" ]; then kill -TERM "$matrix_pid" 2>/dev/null || :; fi
    if [ -n "$child_pid" ]; then kill -TERM "$child_pid" 2>/dev/null || :; fi
    rm -rf -- "$scratch" "$short_home" "$long_home"
}
trap cleanup EXIT

mkdir -p "$scratch/tasks" "$scratch/tests/php" "$scratch/tests/plugins" "$scratch/bin"
cp "$runner" "$scratch/tasks/matrix.sh"
cp "$root/tests/php-failure-pattern.sh" "$scratch/tests/php-failure-pattern.sh"
printf 'fixture\n' > "$scratch/tests/php/aTest.php"
printf 'fixture\n' > "$scratch/tests/plugins/aux"
printf 'one\n' > "$scratch/env_check.php"
printf 'docs\n' > "$scratch/README.md"
cat > "$scratch/tests/php-test.sh" <<'TEST'
#!/bin/bash
echo '> php php/aTest.php'
echo '1 tests, 0 failures'
TEST
git -C "$scratch" init -q
git -C "$scratch" add .

before="$(HOME="$short_home" "$scratch/tasks/matrix.sh" digest)"
printf 'two\n' > "$scratch/env_check.php"
after="$(HOME="$short_home" "$scratch/tasks/matrix.sh" digest)"
[ "$before" != "$after" ] || { echo 'digest missed env_check.php' >&2; exit 1; }
printf 'new docs\n' > "$scratch/README.md"
docs="$(HOME="$short_home" "$scratch/tasks/matrix.sh" digest)"
[ "$after" = "$docs" ] || { echo 'Markdown changed the digest' >&2; exit 1; }
chmod 0755 "$scratch/tests/php/aTest.php"
mode_digest="$(HOME="$short_home" "$scratch/tasks/matrix.sh" digest)"
[ "$mode_digest" != "$docs" ] || { echo 'chmod missed the digest' >&2; exit 1; }
chmod 0644 "$scratch/tests/php/aTest.php"
HOME="$short_home" "$scratch/tasks/matrix.sh" local > "$short_home/green.log"
cat > "$scratch/tests/php-test.sh" <<'TEST'
#!/bin/bash
printf 'leg edit\n' >> ../env_check.php
echo '> php php/aTest.php'
echo '1 tests, 0 failures'
TEST
HOME="$short_home" "$scratch/tasks/matrix.sh" local > "$short_home/isolation.log"
isolation_log="$(awk '$1 == "local" { print $5 }' "$short_home/isolation.log")"
isolation_run="$(dirname "$(dirname "$isolation_log")")"
cmp -s "$scratch/env_check.php" "$isolation_run/export/env_check.php" || {
    echo 'a leg edited the shared export inode' >&2; exit 1;
}
cat > "$scratch/tests/php-test.sh" <<'TEST'
#!/bin/bash
echo '> php php/aTest.php'
echo '1 tests, 0 failures'
TEST
rm "$scratch/tests/php/aTest.php"
HOME="$short_home" "$scratch/tasks/matrix.sh" digest >/dev/null
printf 'fixture\n' > "$scratch/tests/php/aTest.php"

cat > "$scratch/bin/tar" <<'TAR'
#!/bin/bash
if [ "$1" = -xf ]; then
    /usr/bin/tar "$@" || exit $?
    dest="${@: -1}"
    rm -f "$dest/tests/php/aTest.php"
    exit 2
fi
exec /usr/bin/tar "$@"
TAR
chmod +x "$scratch/bin/tar"
if HOME="$short_home" PATH="$scratch/bin:$PATH" "$scratch/tasks/matrix.sh" local > "$short_home/export.log" 2>&1; then
    echo 'partial archive was accepted' >&2; exit 1
fi
grep -q 'archive export failed' "$short_home/export.log"
rm "$scratch/bin/tar"

cat > "$scratch/tests/php-test.sh" <<'TEST'
#!/bin/bash
echo '> php php/aTest.php'
echo 'Failed: synthetic'
TEST
red_digest="$(HOME="$short_home" "$scratch/tasks/matrix.sh" digest)"
printf '%s\n' "$red_digest" > "$short_home/.cache/rtm/last-green"
if HOME="$short_home" "$scratch/tasks/matrix.sh" local > "$short_home/red.log" 2>&1; then
    echo 'red run was accepted' >&2; exit 1
fi
[ ! -e "$short_home/.cache/rtm/last-green" ] || { cat "$short_home/red.log" >&2; echo 'red marker survived' >&2; exit 1; }

cat > "$scratch/tests/php-test.sh" <<'TEST'
#!/bin/bash
echo '> php php/aTest.php'
echo 'Warning: synthetic warning that TestCase did not count'
echo '1 tests, 0 failures'
TEST
if HOME="$short_home" "$scratch/tasks/matrix.sh" local > "$short_home/warning.log" 2>&1; then
    echo 'warning-only run was accepted' >&2; exit 1
fi

# The actual PHP harness must not count a present but empty *Test.php as a
# passing file. Keep the runner real; the disposable repository has one file.
cp "$root/tests/php-test.sh" "$scratch/tests/php-test.sh"
cp "$root/tests/php-test.ini" "$scratch/tests/php-test.ini"
: > "$scratch/tests/php/aTest.php"
if HOME="$short_home" "$scratch/tasks/matrix.sh" local > "$short_home/empty-php.log" 2>&1; then
    echo 'empty PHP test file was accepted' >&2; exit 1
fi
empty_php_log="$(awk '$1 == "local" { print $5 }' "$short_home/empty-php.log")"
grep -q 'no nonempty passing test result in php/aTest.php' "$empty_php_log"

# Both runner styles must still be accepted after the empty-file guard.
cp "$root/tests/php/TestCase.php" "$scratch/tests/php/TestCase.php"
cat > "$scratch/tests/php/aTest.php" <<'TESTCASE'
<?php
require_once __DIR__ . '/TestCase.php';
class MatrixSmokeTest extends TestCase {
    public function testRunsAssertion() {
        $this->assertTrue(true, 'one TestCase assertion ran');
    }
}
TESTCASE
HOME="$short_home" "$scratch/tasks/matrix.sh" local > "$short_home/testcase-php.log"
cat > "$scratch/tests/php/aTest.php" <<'SELF_RUNNING'
<?php
if (2 + 2 !== 4) throw new Exception('self-running assertion failed');
echo "ok - one self-running case\n1 test, 0 failures\n";
SELF_RUNNING
HOME="$short_home" "$scratch/tasks/matrix.sh" local > "$short_home/self-running-php.log"
printf 'fixture\n' > "$scratch/tests/php/aTest.php"

cat > "$scratch/tests/php-test.sh" <<'TEST'
#!/bin/bash
echo '> php php/aTest.php'
printf 'changed during red run\n' > "$MATRIX_TEST_SOURCE"
echo 'Failed: synthetic failure with source drift'
TEST
drift_digest="$(HOME="$short_home" "$scratch/tasks/matrix.sh" digest)"
printf '%s\n' "$drift_digest" > "$short_home/.cache/rtm/last-green"
if HOME="$short_home" MATRIX_TEST_SOURCE="$scratch/env_check.php" \
    "$scratch/tasks/matrix.sh" local > "$short_home/drift.log" 2>&1; then
    echo 'red run with source drift was accepted' >&2; exit 1
fi
[ ! -e "$short_home/.cache/rtm/last-green" ] || {
    echo 'red marker survived a source drift' >&2; exit 1;
}

cat > "$scratch/tests/php-test.sh" <<'TEST'
#!/bin/bash
echo '> php php/aTest.php'
sleep 20 &
child=$!
printf '%s\n' "$child" > "$TMPDIR/child"
wait "$child"
TEST
HOME="$short_home" "$scratch/tasks/matrix.sh" local > "$short_home/term.log" 2>&1 &
matrix_pid=$!
child_file=''
for ((i=0; i<100; i++)); do
    child_file="$(find "$short_home/.cache/rtm" -name child -print -quit)"
    [ -z "$child_file" ] || break
    sleep 0.05
done
[ -n "$child_file" ] || { echo 'signal fixture did not start' >&2; exit 1; }
child_pid="$(cat "$child_file")"
kill -TERM "$matrix_pid"
wait "$matrix_pid" 2>/dev/null || :
matrix_pid=''
for ((i=0; i<30; i++)); do
    kill -0 "$child_pid" 2>/dev/null || break
    sleep 0.1
done
if kill -0 "$child_pid" 2>/dev/null; then
    echo 'child survived TERM' >&2; exit 1
fi
child_pid=''
grep -q 'interrupted' "$short_home/term.log"
rm -f "$child_file"
python3 - "$scratch/tasks/matrix.sh" "$short_home" <<'PYINT'
import glob
import os
import signal
import subprocess
import sys
import time

script, home = sys.argv[1:]
log_path = os.path.join(home, "int.log")
with open(log_path, "w") as log:
    process = subprocess.Popen(
        [script, "local"], stdout=log, stderr=subprocess.STDOUT,
        env={**os.environ, "HOME": home}, start_new_session=True,
        preexec_fn=lambda: signal.signal(signal.SIGINT, signal.SIG_DFL),
    )
    child_pid = None
    try:
        for _ in range(100):
            files = glob.glob(os.path.join(home, ".cache/rtm", "*", "local", "tmp", "child"))
            if files:
                child_pid = int(open(files[-1]).read())
                break
            time.sleep(0.05)
        assert child_pid is not None, "INT fixture did not start"
        os.killpg(process.pid, signal.SIGINT)
        assert process.wait(timeout=5) == 130, "matrix did not handle SIGINT"
        for _ in range(30):
            try:
                os.kill(child_pid, 0)
            except ProcessLookupError:
                break
            time.sleep(0.1)
        else:
            raise AssertionError("child survived INT")
    finally:
        if process.poll() is None:
            os.killpg(process.pid, signal.SIGTERM)
            process.wait(timeout=5)
assert "interrupted" in open(log_path).read(), "matrix did not log SIGINT"
PYINT

cat > "$scratch/tests/php-test.sh" <<'TEST'
#!/bin/bash
echo '> php php/aTest.php'
if [ -n "${MATRIX_TEST_DELAY:-}" ]; then
    touch "$TMPDIR/started"
    sleep "$MATRIX_TEST_DELAY"
fi
echo '1 tests, 0 failures'
TEST
HOME="$short_home" MATRIX_TEST_DELAY=3 "$scratch/tasks/matrix.sh" local > "$short_home/slow.log" 2>&1 &
matrix_pid=$!
started_file=''
for ((i=0; i<100; i++)); do
    started_file="$(find "$short_home/.cache/rtm" -name started -print -quit)"
    [ -z "$started_file" ] || break
    sleep 0.05
done
[ -n "$started_file" ] || { echo 'retention fixture did not start' >&2; exit 1; }
active_run="$(dirname "$(dirname "$(dirname "$started_file")")")"
for ((i=0; i<3; i++)); do
    HOME="$short_home" "$scratch/tasks/matrix.sh" local > "$short_home/quick-$i.log"
done
[ -d "$active_run" ] || { echo 'cleanup deleted an active run' >&2; exit 1; }
wait "$matrix_pid"
matrix_pid=''

cat > "$scratch/bin/docker" <<'DOCKER'
#!/bin/bash
echo '1 tests, 0 failures'
DOCKER
chmod +x "$scratch/bin/docker"
HOME="$long_home" PATH="$scratch/bin:$PATH" "$scratch/tasks/matrix.sh" prod-kinozal > "$short_home/prod.log"
grep -q 'green on prod-kinozal' "$short_home/prod.log"
cat > "$scratch/bin/docker" <<'DOCKER'
#!/bin/bash
case "$1" in
    run)
        echo run >> "$MATRIX_TEST_DOCKER_LOG"
        # Model a daemon completing create after the client sees TERM.
        setsid sh -c 'sleep 0.15; : > "$MATRIX_TEST_DOCKER_CONTAINER"' \
            </dev/null >/dev/null 2>&1 &
        sleep 20
        ;;
    rm)
        if [ -e "$MATRIX_TEST_DOCKER_CONTAINER" ]; then
            rm -f "$MATRIX_TEST_DOCKER_CONTAINER"
            echo removed >> "$MATRIX_TEST_DOCKER_LOG"
        else
            echo missing >> "$MATRIX_TEST_DOCKER_LOG"
        fi
        ;;
esac
DOCKER
chmod +x "$scratch/bin/docker"
HOME="$long_home" PATH="$scratch/bin:$PATH" MATRIX_TEST_DOCKER_LOG="$long_home/docker.log" \
    MATRIX_TEST_DOCKER_CONTAINER="$long_home/container" \
    "$scratch/tasks/matrix.sh" prod-kinozal > "$long_home/docker-run.log" 2>&1 &
matrix_pid=$!
for ((i=0; i<100; i++)); do
    [ -f "$long_home/docker.log" ] && grep -q '^run$' "$long_home/docker.log" && break
    sleep 0.05
done
[ -f "$long_home/docker.log" ] || { echo 'Docker fixture did not start' >&2; exit 1; }
kill -TERM "$matrix_pid"
wait "$matrix_pid" 2>/dev/null || :
matrix_pid=''
sleep 0.2
[ ! -e "$long_home/container" ] || { echo 'late-created container survived' >&2; exit 1; }
grep -q '^removed$' "$long_home/docker.log" || { echo 'named container was not removed' >&2; exit 1; }
echo 'matrix-test.sh: digest/mode, deletion, export, leg isolation, warning, empty PHP test, marker drift, TERM, INT, retention, long HOME, and delayed Docker cleanup passed'
