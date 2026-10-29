#!/usr/bin/env bash
# Isolated behavior checks for the local matrix runner; no Docker needed.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
runner="${MATRIX_RUNNER:-$root/tools/matrix.sh}"
scratch="$(mktemp -d "${TMPDIR:-/tmp}/rtmatrix.XXXXXX")"
short_home_real=''
short_home_root=''
short_home=''
long_home=''
matrix_pid=''
child_pid=''
cleanup() {
    local path
    if [ -n "$matrix_pid" ]; then kill -TERM "$matrix_pid" 2>/dev/null || :; fi
    if [ -n "$child_pid" ]; then kill -TERM "$child_pid" 2>/dev/null || :; fi
    for path in "$short_home_root" "$scratch" "$short_home_real" "$long_home"; do
        [ -z "$path" ] || rm -rf -- "$path"
    done
}
trap cleanup EXIT
short_home_real="$(mktemp -d /tmp/mxXXX)"
long_home="$(mktemp -d "${TMPDIR:-/tmp}/rtmlonghome.XXXXXX")"
# The fixture needs a short HOME for the UNIX-socket path budget; only this
# directory and symlink live in /tmp. Run data stays under TMPDIR.
short_home_root="$(mktemp -d /tmp/m.XXXXXX)"
short_home="$short_home_root/h"
ln -s -- "$short_home_real" "$short_home"

mkdir -p "$scratch/tools" "$scratch/tests/tools" "$scratch/tests/php" "$scratch/tests/plugins" "$scratch/bin"
cp "$runner" "$scratch/tools/matrix.sh"
cp "$root/tests/php-failure-pattern.sh" "$scratch/tests/php-failure-pattern.sh"
# Matrix accounting tests use a synthetic suite. Native-leg tests separately
# verify the actual manifest helper build and fixed-path runtime exposure.
cat > "$scratch/tests/tools/matrix-native-leg.py" <<'NATIVE_LEG_FIXTURE'
import argparse, os
from pathlib import Path
p = argparse.ArgumentParser()
for argument in ('directory', 'leg', 'uid', 'gid', 'name', 'owner', 'jobs', 'timings'):
    p.add_argument('--' + argument, required=True)
a = p.parse_args()
d = Path(a.directory)
if 'MATRIX_TEST_JOBS_LOG' in os.environ:
    with open(os.environ['MATRIX_TEST_JOBS_LOG'], 'a') as log:
        log.write(a.leg + ' ' + a.jobs + ' ' + os.path.basename(a.timings) + '\n')
if a.leg == 'local':
    os.chdir(d / 'tree/tests')
    os.environ['TMPDIR'] = str(d / 'tmp')
    os.environ['PHP_TEST_JOBS'] = a.jobs
    os.execvp('bash', ['bash', 'php-test.sh'])
os.execvp('docker', ['docker', 'run', '--rm', '--pull=never', '--name', a.name,
                    '--label', 'io.rutorrent.matrix.owner=' + a.owner,
                    '--user', a.uid + ':' + a.gid, '-v', str(d / 'tree') + ':/w',
                    '-v', str(d / 'tmp') + ':/mtmp', '-w', '/w/tests',
                    'php:' + a.leg + '-cli', 'bash', 'php-test.sh'])
NATIVE_LEG_FIXTURE
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

before="$(HOME="$short_home" "$scratch/tools/matrix.sh" digest)"
printf 'two\n' > "$scratch/env_check.php"
after="$(HOME="$short_home" "$scratch/tools/matrix.sh" digest)"
[ "$before" != "$after" ] || { echo 'digest missed env_check.php' >&2; exit 1; }
printf 'new docs\n' > "$scratch/README.md"
docs="$(HOME="$short_home" "$scratch/tools/matrix.sh" digest)"
[ "$after" = "$docs" ] || { echo 'Markdown changed the digest' >&2; exit 1; }
chmod 0755 "$scratch/tests/php/aTest.php"
mode_digest="$(HOME="$short_home" "$scratch/tools/matrix.sh" digest)"
[ "$mode_digest" != "$docs" ] || { echo 'chmod missed the digest' >&2; exit 1; }
chmod 0644 "$scratch/tests/php/aTest.php"
# Equal-content targets isolate the link target itself in the digest.
printf 'same\n' > "$scratch/same-a.txt"
printf 'same\n' > "$scratch/same-b.txt"
ln -s same-a.txt "$scratch/suite-link"
git -C "$scratch" add same-a.txt same-b.txt suite-link
link_before="$(HOME="$short_home" "$scratch/tools/matrix.sh" digest)"
ln -sfn same-b.txt "$scratch/suite-link"
link_after="$(HOME="$short_home" "$scratch/tools/matrix.sh" digest)"
[ "$link_before" != "$link_after" ] || { echo 'symlink target change missed the digest' >&2; exit 1; }
# Ignored files are outside the export and must not inflate the file count.
printf 'tests/php/ignoredTest.php\n' > "$scratch/.gitignore"
git -C "$scratch" add .gitignore
printf 'ignored\n' > "$scratch/tests/php/ignoredTest.php"
HOME="$short_home" "$scratch/tools/matrix.sh" local > "$short_home/green.log" || {
    cat "$short_home/green.log" >&2
    echo 'ignored PHP file made the exported suite red' >&2; exit 1
}
# A partial harness run must still fail against the exported manifest.
printf 'second\n' > "$scratch/tests/php/secondTest.php"
git -C "$scratch" add tests/php/secondTest.php
if HOME="$short_home" "$scratch/tools/matrix.sh" local > "$short_home/missing-file.log" 2>&1; then
    echo 'harness skipped a tracked exported PHP file' >&2; exit 1
fi
missing_file_log="$(awk '$1 == "local" { print $5 }' "$short_home/missing-file.log")"
grep -q 'ran 1 of 2 PHP files' "$missing_file_log" || {
    cat "$short_home/missing-file.log" "$missing_file_log" >&2
    echo 'missing exported PHP file was not counted' >&2; exit 1
}
git -C "$scratch" rm -fq tests/php/secondTest.php
chmod 0664 "$scratch/tests/php/aTest.php"
( umask 022; HOME="$short_home" "$scratch/tools/matrix.sh" local > "$short_home/umask.log" ) || {
    cat "$short_home/umask.log" >&2
    echo 'archive export lost source mode under umask 022' >&2; exit 1
}
chmod 0644 "$scratch/tests/php/aTest.php"
if HOME="$short_home" "$scratch/tools/matrix.sh" local local > "$short_home/duplicate.log" 2>&1; then
    echo 'duplicate leg was accepted' >&2; exit 1
fi
grep -q 'duplicate leg: local' "$short_home/duplicate.log" || {
    cat "$short_home/duplicate.log" >&2
    echo 'duplicate leg did not produce a clear error' >&2; exit 1
}
# A parallel leg gives each file TMPDIR/XXX, so the local TMPDIR budget is
# 4 bytes smaller: a 14-byte HOME makes it 55 bytes, which only the
# sequential run accepts.
budget_home="$short_home_real/pad"
mkdir "$budget_home"
if HOME="$budget_home" "$scratch/tools/matrix.sh" local > "$short_home/budget.log" 2>&1; then
    echo 'a 55-byte TMPDIR was accepted for parallel files' >&2; exit 1
fi
grep -q 'is 55 bytes; UNIX socket fixtures need it <= 54 with MATRIX_PHP_JOBS=6' "$short_home/budget.log" || {
    cat "$short_home/budget.log" >&2
    echo 'the parallel TMPDIR budget had no actionable diagnostic' >&2; exit 1
}
HOME="$budget_home" MATRIX_PHP_JOBS=1 "$scratch/tools/matrix.sh" local > "$short_home/budget-one.log" || {
    cat "$short_home/budget-one.log" >&2
    echo 'a 55-byte TMPDIR was refused for the sequential run' >&2; exit 1
}
# Each suite leg gets the per-leg file count and its own durations file.
HOME="$short_home" MATRIX_TEST_JOBS_LOG="$short_home/jobs.log" "$scratch/tools/matrix.sh" local > "$short_home/jobs-default.log"
HOME="$short_home" MATRIX_TEST_JOBS_LOG="$short_home/jobs.log" MATRIX_PHP_JOBS=1 "$scratch/tools/matrix.sh" local > "$short_home/jobs-one.log"
[ "$(cat "$short_home/jobs.log")" = "$(printf 'local 6 php-timings-local\nlocal 1 php-timings-local')" ] || {
    cat "$short_home/jobs.log" >&2
    echo 'MATRIX_PHP_JOBS did not reach the suite leg' >&2; exit 1
}
# A green full run names its mode in the marker, and `last` shows it. The
# Docker stand-in answers every leg's container with one passing file.
mkdir "$scratch/bin-green"
cat > "$scratch/bin-green/docker" <<'DOCKER_GREEN'
#!/bin/sh
case "$1" in
    image) echo sha256:green ;;
    run)
        case "$*" in *php-test.sh*) printf '> php php/aTest.php\n' ;; esac
        printf '1 tests, 0 failures\n' ;;
esac
exit 0
DOCKER_GREEN
chmod +x "$scratch/bin-green/docker"
HOME="$short_home" PATH="$scratch/bin-green:$PATH" MATRIX_PHP_JOBS=64 "$scratch/tools/matrix.sh" > "$short_home/jobs-full.log" 2>&1 || {
    cat "$short_home/jobs-full.log" >&2
    echo 'the stand-in full run was not green' >&2; exit 1
}
[ "$(HOME="$short_home" "$scratch/tools/matrix.sh" last | sed -n 4p)" = jobs=64 ] \
    && grep -q '^green on local 8.1 7.4 prod-kinozal with jobs=64 in ' "$short_home/jobs-full.log" || {
    cat "$short_home/jobs-full.log" >&2
    HOME="$short_home" "$scratch/tools/matrix.sh" last >&2
    echo 'the green marker or summary did not name jobs=64' >&2; exit 1
}
rm -rf -- "$scratch/bin-green" "$short_home/.cache/rtm/last-green"
for jobs in 0 -1 two 65; do
    if HOME="$short_home" MATRIX_PHP_JOBS="$jobs" "$scratch/tools/matrix.sh" local > "$short_home/jobs-bad.log" 2>&1; then
        echo "MATRIX_PHP_JOBS='$jobs' was accepted" >&2; exit 1
    fi
    grep -q 'MATRIX_PHP_JOBS must be an integer from 1 to 64' "$short_home/jobs-bad.log" || {
        cat "$short_home/jobs-bad.log" >&2
        echo 'an invalid MATRIX_PHP_JOBS did not produce a clear error' >&2; exit 1
    }
done
cat > "$scratch/tests/php-test.sh" <<'TEST'
#!/bin/bash
printf 'leg edit\n' >> ../env_check.php
echo '> php php/aTest.php'
echo '1 tests, 0 failures'
TEST
HOME="$short_home" "$scratch/tools/matrix.sh" local > "$short_home/isolation.log"
isolation_log="$(awk '$1 == "local" { print $5 }' "$short_home/isolation.log")"
isolation_run="$(dirname "$(dirname "$isolation_log")")"
cmp -s "$scratch/env_check.php" "$isolation_run/export/env_check.php" || {
    echo 'a leg edited the shared export inode' >&2; exit 1;
}
cat > "$scratch/tests/php-test.sh" <<'TEST'
#!/bin/bash
printf 'source edit during leg\n' > "$MATRIX_TEST_SOURCE"
echo '> php php/aTest.php'
echo '1 tests, 0 failures'
TEST
HOME="$short_home" MATRIX_TEST_SOURCE="$scratch/env_check.php" \
    "$scratch/tools/matrix.sh" local > "$short_home/source-drift.log"
grep -q 'source changed while it ran' "$short_home/source-drift.log" || {
    cat "$short_home/source-drift.log" >&2
    echo 'source drift was not identified' >&2; exit 1
}
cat > "$scratch/tests/php-test.sh" <<'TEST'
#!/bin/bash
echo '> php php/aTest.php'
echo '1 tests, 0 failures'
TEST
rm "$scratch/tests/php/aTest.php"
HOME="$short_home" "$scratch/tools/matrix.sh" digest >/dev/null
printf 'fixture\n' > "$scratch/tests/php/aTest.php"

cat > "$scratch/bin/tar" <<'TAR'
#!/bin/bash
if [ "$1" = -xf ] || [ "$1" = -xpf ]; then
    /usr/bin/tar "$@" || exit $?
    dest="${@: -1}"
    rm -f "$dest/tests/php/aTest.php"
    exit 2
fi
exec /usr/bin/tar "$@"
TAR
chmod +x "$scratch/bin/tar"
if HOME="$short_home" PATH="$scratch/bin:$PATH" "$scratch/tools/matrix.sh" local > "$short_home/export.log" 2>&1; then
    echo 'partial archive was accepted' >&2; exit 1
fi
grep -q 'archive export failed' "$short_home/export.log"
rm "$scratch/bin/tar"
cat > "$scratch/bin/tar" <<'TAR'
#!/bin/bash
if [ "$1" = -xpf ]; then
    /usr/bin/tar "$@" || exit $?
    dest="${@: -1}"
    printf 'wrong export\n' > "$dest/tests/php/aTest.php"
    exit 0
fi
exec /usr/bin/tar "$@"
TAR
chmod +x "$scratch/bin/tar"
if HOME="$short_home" PATH="$scratch/bin:$PATH" "$scratch/tools/matrix.sh" local > "$short_home/export-drift.log" 2>&1; then
    echo 'changed archive was accepted' >&2; exit 1
fi
grep -Eq 'archive export.*source digest.*export: ' "$short_home/export-drift.log" || {
    cat "$short_home/export-drift.log" >&2
    echo 'archive mismatch did not name its path' >&2; exit 1
}
rm "$scratch/bin/tar"

cat > "$scratch/tests/php-test.sh" <<'TEST'
#!/bin/bash
echo '> php php/aTest.php'
echo 'Failed: synthetic'
TEST
red_digest="$(HOME="$short_home" "$scratch/tools/matrix.sh" digest)"
printf '%s\n' "$red_digest" > "$short_home/.cache/rtm/last-green"
if HOME="$short_home" "$scratch/tools/matrix.sh" local > "$short_home/red.log" 2>&1; then
    echo 'red run was accepted' >&2; exit 1
fi
[ ! -e "$short_home/.cache/rtm/last-green" ] || { cat "$short_home/red.log" >&2; echo 'red marker survived' >&2; exit 1; }

cat > "$scratch/tests/php-test.sh" <<'TEST'
#!/bin/bash
echo '> php php/aTest.php'
echo 'Warning: synthetic warning that TestCase did not count'
echo '1 tests, 0 failures'
TEST
if HOME="$short_home" "$scratch/tools/matrix.sh" local > "$short_home/warning.log" 2>&1; then
    echo 'warning-only run was accepted' >&2; exit 1
fi

# The actual PHP harness must not count a present but empty *Test.php as a
# passing file. Keep the runner real; the disposable repository has one file.
cp "$root/tests/php-test.sh" "$scratch/tests/php-test.sh"
cp "$root/tests/php-test.ini" "$scratch/tests/php-test.ini"
: > "$scratch/tests/php/aTest.php"
if HOME="$short_home" "$scratch/tools/matrix.sh" local > "$short_home/empty-php.log" 2>&1; then
    echo 'empty PHP test file was accepted' >&2; exit 1
fi
empty_php_log="$(awk '$1 == "local" { print $5 }' "$short_home/empty-php.log")"
grep -q 'no nonempty passing test result in php/aTest.php' "$empty_php_log"

# Both runner styles must still be accepted after the empty-file guard.
cp "$root/tests/php/TestCase.php" "$scratch/tests/php/TestCase.php"
cp "$root/tests/php/TestCaseRunner.php" "$scratch/tests/php/TestCaseRunner.php"
cat > "$scratch/tests/php/aTest.php" <<'TESTCASE'
<?php
require_once __DIR__ . '/TestCase.php';
class MatrixSmokeTest extends TestCase {
    public function testRunsAssertion() {
        $this->assertTrue(true, 'one TestCase assertion ran');
    }
}
TESTCASE
HOME="$short_home" "$scratch/tools/matrix.sh" local > "$short_home/testcase-php.log"
cat > "$scratch/tests/php/aTest.php" <<'SELF_RUNNING'
<?php
if (2 + 2 !== 4) throw new Exception('self-running assertion failed');
echo "ok - one self-running case\n1 test, 0 failures\n";
SELF_RUNNING
HOME="$short_home" "$scratch/tools/matrix.sh" local > "$short_home/self-running-php.log"
printf 'fixture\n' > "$scratch/tests/php/aTest.php"

cat > "$scratch/tests/php-test.sh" <<'TEST'
#!/bin/bash
echo '> php php/aTest.php'
printf 'changed during red run\n' > "$MATRIX_TEST_SOURCE"
echo 'Failed: synthetic failure with source drift'
TEST
drift_digest="$(HOME="$short_home" "$scratch/tools/matrix.sh" digest)"
printf '%s\n' "$drift_digest" > "$short_home/.cache/rtm/last-green"
if HOME="$short_home" MATRIX_TEST_SOURCE="$scratch/env_check.php" \
    "$scratch/tools/matrix.sh" local > "$short_home/drift.log" 2>&1; then
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
HOME="$short_home" "$scratch/tools/matrix.sh" local > "$short_home/term.log" 2>&1 &
matrix_pid=$!
child_file=''
for ((i=0; i<100; i++)); do
    child_file="$(find "$short_home/.cache/rtm" -name child -print -quit)"
    [ -z "$child_file" ] || break
    sleep 0.05
done
[ -n "$child_file" ] || { echo 'signal fixture did not start' >&2; exit 1; }
child_pid="$(cat "$child_file")"
term_active_run="$(dirname "$(dirname "$(dirname "$child_file")")")"
touch -d '3 days ago' "$term_active_run"
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
term_run="$(sed -n 's/^matrix.sh: interrupted; logs under //p' "$short_home/term.log" | tail -1)"
[ -d "$term_run" ] || { echo 'TERM log path is missing' >&2; exit 1; }
rm -f "$child_file"
python3 - "$scratch/tools/matrix.sh" "$short_home" <<'PYINT'
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
find "$short_home/.cache/rtm" -name child -delete
python3 - "$scratch/tools/matrix.sh" "$short_home" <<'PYHUP'
import glob
import os
import signal
import subprocess
import sys
import time

script, home = sys.argv[1:]
log_path = os.path.join(home, "hup.log")
with open(log_path, "w") as log:
    process = subprocess.Popen(
        [script, "local"], stdout=log, stderr=subprocess.STDOUT,
        env={**os.environ, "HOME": home}, start_new_session=True,
    )
    child_pid = None
    try:
        for _ in range(100):
            files = glob.glob(os.path.join(home, ".cache/rtm", "*", "local", "tmp", "child"))
            if files:
                child_pid = int(open(files[-1]).read())
                break
            time.sleep(0.05)
        assert child_pid is not None, "HUP fixture did not start"
        os.kill(process.pid, signal.SIGHUP)
        assert process.wait(timeout=5) == 129, "matrix did not handle SIGHUP"
        for _ in range(30):
            try:
                os.kill(child_pid, 0)
            except ProcessLookupError:
                break
            time.sleep(0.1)
        else:
            raise AssertionError("child survived HUP")
    finally:
        if process.poll() is None:
            os.killpg(process.pid, signal.SIGTERM)
            process.wait(timeout=5)
assert "interrupted" in open(log_path).read(), "matrix did not log SIGHUP"
PYHUP

cat > "$scratch/tests/php-test.sh" <<'TEST'
#!/bin/bash
echo '> php php/aTest.php'
if [ -n "${MATRIX_TEST_RELEASE:-}" ]; then
    touch "$TMPDIR/started"
    while [ ! -e "$MATRIX_TEST_RELEASE" ]; do sleep 0.05; done
fi
echo '1 tests, 0 failures'
TEST
HOME="$short_home" MATRIX_TEST_RELEASE="$short_home/release-slow" "$scratch/tools/matrix.sh" local > "$short_home/slow.log" 2>&1 &
matrix_pid=$!
started_file=''
for ((i=0; i<100; i++)); do
    started_file="$(find "$short_home/.cache/rtm" -name started -print -quit)"
    [ -z "$started_file" ] || break
    sleep 0.05
done
[ -n "$started_file" ] || { echo 'retention fixture did not start' >&2; exit 1; }
active_run="$(dirname "$(dirname "$(dirname "$started_file")")")"
# Force the active directory past the stale cutoff: quick runs must respect
# its inherited flock, not just the ordinary one-day grace period.
touch -d '3 days ago' "$active_run"
for ((i=0; i<3; i++)); do
    HOME="$short_home" "$scratch/tools/matrix.sh" local > "$short_home/quick-$i.log"
done
[ -d "$term_run" ] || { echo 'interrupted logs disappeared before the operator could read them' >&2; exit 1; }
[ -d "$active_run" ] || { echo 'cleanup deleted an active run' >&2; exit 1; }
touch "$short_home/release-slow"
wait "$matrix_pid"
matrix_pid=''
[ -d "$active_run" ] || { echo 'a late-finishing run erased its own logs' >&2; exit 1; }
python3 - "$short_home/.cache/rtm" "$active_run" <<'PYRETENTION'
from pathlib import Path
import re
import sys
base, active = map(Path, sys.argv[1:])
finished = [p for p in base.iterdir() if re.fullmatch(r'[0-9]{12}\.[A-Za-z0-9]{6}', p.name) and (p / '.finished').exists()]
if len(finished) != 3:
    raise SystemExit('retention kept %d completed runs, expected exactly three' % len(finished))
if active not in finished:
    raise SystemExit('late-finishing active run was not retained')
PYRETENTION
old_unfinished="$short_home/.cache/rtm/000101000000.stale0"
mkdir -p "$old_unfinished"
touch -d '3 days ago' "$old_unfinished"
HOME="$short_home" "$scratch/tools/matrix.sh" local > "$short_home/stale-cleanup.log"
[ ! -e "$old_unfinished" ] || { echo 'stale unfinished run was never removed' >&2; exit 1; }

cat > "$scratch/bin/docker" <<'DOCKER'
#!/bin/bash
if [ "${1:-}" = run ] && [ -n "${MATRIX_TEST_PROD_ARG_LOG:-}" ]; then
    printf '%s\n' "$@" >> "$MATRIX_TEST_PROD_ARG_LOG"
fi
for arg in "$@"; do
    case "$arg" in
        RT_SUITE=*) printf '%s\n' "${arg#RT_SUITE=}" >> "$MATRIX_TEST_DOCKER_SUITE_LOG" ;;
    esac
done
echo '1 tests, 0 failures'
DOCKER
chmod +x "$scratch/bin/docker"
HOME="$long_home" PATH="$scratch/bin:$PATH" \
    MATRIX_TEST_DOCKER_SUITE_LOG="$short_home/prod-suites.log" \
    "$scratch/tools/matrix.sh" prod-kinozal > "$short_home/prod.log"
grep -q 'green on prod-kinozal' "$short_home/prod.log"
printf '%s\n' tests/plugins/rutracker_check/KinozalHandlerTest.php \
    tests/plugins/rutracker_check/SiblingTrackersTest.php \
    tests/plugins/create/MktorrentDefaultImageSmoke.php > "$short_home/expected-prod-suites.log"
cmp -s "$short_home/expected-prod-suites.log" "$short_home/prod-suites.log" || {
    echo 'image leg did not run both tracker suites and creator smoke in order' >&2; exit 1;
}
# A local candidate image must be selectable without retagging the shipped image,
# and its selection must invalidate the runtime digest used by the green marker.
default_image_digest="$(HOME="$long_home" PATH="$scratch/bin:$PATH" "$scratch/tools/matrix.sh" digest)"
candidate_image_digest="$(HOME="$long_home" PATH="$scratch/bin:$PATH" RUTORRENT_MATRIX_PROD_IMAGE=rutorrent-candidate:review "$scratch/tools/matrix.sh" digest)"
[ "$default_image_digest" != "$candidate_image_digest" ] || {
    echo 'selected production image did not invalidate runtime digest' >&2; exit 1;
}
HOME="$long_home" PATH="$scratch/bin:$PATH" \
    RUTORRENT_MATRIX_PROD_IMAGE=rutorrent-candidate:review \
    MATRIX_TEST_DOCKER_SUITE_LOG="$short_home/candidate-suites.log" \
    MATRIX_TEST_PROD_ARG_LOG="$short_home/candidate-args.log" \
    "$scratch/tools/matrix.sh" prod-kinozal > "$short_home/candidate-prod.log"
[ "$(grep -cx 'rutorrent-candidate:review' "$short_home/candidate-args.log")" = 3 ] || {
    echo 'image leg did not run all three suites on the selected candidate' >&2; exit 1;
}
! grep -qx 'ivanshift/rutorrent:latest' "$short_home/candidate-args.log" || {
    echo 'candidate image selection silently used the shipped image' >&2; exit 1;
}
cmp -s "$short_home/expected-prod-suites.log" "$short_home/candidate-suites.log" || {
    echo 'candidate image leg changed its suite selection' >&2; exit 1;
}
# A stalled packaged binary must fail this leg rather than hold the whole matrix.
# Shorten only the runner's image-suite bound; fingerprint timeouts are unchanged.
cat > "$scratch/bin/timeout" <<'TIMEOUT'
#!/bin/bash
if [ "${1:-}" = --kill-after=5s ] && [ "${2:-}" = 60s ]; then
    shift 2
    exec /usr/bin/timeout --kill-after=1s 1s "$@"
fi
exec /usr/bin/timeout "$@"
TIMEOUT
cat > "$scratch/bin/docker" <<'DOCKER'
#!/bin/bash
case "${1:-}" in
    run) exec sleep 30 ;;
    image) echo 'sha256:timeout-fixture'; exit 0 ;;
    *) exit 1 ;;
esac
DOCKER
chmod +x "$scratch/bin/timeout" "$scratch/bin/docker"
timeout_code=0
HOME="$long_home" PATH="$scratch/bin:$PATH" \
    /usr/bin/timeout --kill-after=1s 5s "$scratch/tools/matrix.sh" prod-kinozal \
    > "$short_home/prod-timeout.log" 2>&1 || timeout_code=$?
[ "$timeout_code" = 1 ] && grep -Eq '^prod-kinozal +124 ' "$short_home/prod-timeout.log" || {
    cat "$short_home/prod-timeout.log" >&2
    echo 'stalled packaged suite did not produce a bounded failing leg' >&2; exit 1;
}
rm "$scratch/bin/timeout"
# Two summaries from one suite cannot stand in for an unrun second suite.
cat > "$scratch/bin/docker" <<'DOCKER'
#!/bin/bash
for arg in "$@"; do
    case "$arg" in
        RT_SUITE=*KinozalHandlerTest.php)
            echo '1 tests, 0 failures'
            echo '1 tests, 0 failures'
            exit 0 ;;
        RT_SUITE=*SiblingTrackersTest.php) exit 0 ;;
    esac
done
exit 1
DOCKER
chmod +x "$scratch/bin/docker"
if HOME="$long_home" PATH="$scratch/bin:$PATH" \
    "$scratch/tools/matrix.sh" prod-kinozal > "$short_home/prod-false-green.log" 2>&1; then
    echo 'no-iconv leg accepted summaries from the wrong suite' >&2; exit 1
fi
cat > "$scratch/bin/docker" <<'DOCKER'
#!/bin/bash
case "$1" in
    run)
        echo run >> "$MATRIX_TEST_DOCKER_LOG"
        for argument in "$@"; do
            case "$argument" in
                io.rutorrent.matrix.owner=*) owner="${argument#*=}" ;;
            esac
        done
        export MATRIX_TEST_DOCKER_OWNER="$owner"
        # Model a daemon completing create after the client sees TERM.
        setsid sh -c 'sleep 0.15; printf "%s\n" "$MATRIX_TEST_DOCKER_OWNER" > "$MATRIX_TEST_DOCKER_CONTAINER"' \
            </dev/null >/dev/null 2>&1 &
        sleep 20
        ;;
    container)
        [ ! -e "$MATRIX_TEST_DOCKER_CONTAINER" ] || printf 'owned-delayed-id %s\n' "$(cat "$MATRIX_TEST_DOCKER_CONTAINER")"
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
    "$scratch/tools/matrix.sh" prod-kinozal > "$long_home/docker-run.log" 2>&1 &
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
# A green marker belongs to the exact runtime images, not only to source bytes.
cat > "$scratch/bin/docker" <<'DOCKER_ID'
#!/bin/sh
if [ "$1" = image ] && [ "$2" = inspect ]; then
    cat "$MATRIX_TEST_IMAGE_ID"
    exit 0
fi
exit 1
DOCKER_ID
chmod +x "$scratch/bin/docker"
printf 'sha256:before\n' > "$short_home/image-id"
image_before="$(HOME="$short_home" PATH="$scratch/bin:$PATH" MATRIX_TEST_IMAGE_ID="$short_home/image-id" "$scratch/tools/matrix.sh" digest)"
printf 'sha256:after\n' > "$short_home/image-id"
image_after="$(HOME="$short_home" PATH="$scratch/bin:$PATH" MATRIX_TEST_IMAGE_ID="$short_home/image-id" "$scratch/tools/matrix.sh" digest)"
[ "$image_before" != "$image_after" ] || {
    echo 'runtime image replacement did not invalidate digest' >&2; exit 1
}
# The full marker must change when local PHP bytes or reported runtime inputs change.
# Keep the fake Docker image ID fixed so each comparison isolates host PHP.
mkdir -p "$short_home/php-bin"
cat > "$short_home/php-bin/php" <<'FINGERPRINT_PHP'
#!/bin/sh
case "$1" in
    -v) printf 'PHP fixture 8.5\n' ;;
    -m) cat "$MATRIX_TEST_PHP_MODULES" ;;
    -r) cat "$MATRIX_TEST_PHP_INI" "$MATRIX_TEST_PHP_EXTENSION" ;;
    *) exit 99 ;;
esac
FINGERPRINT_PHP
chmod +x "$short_home/php-bin/php"
printf 'ini:before\n' > "$short_home/php-ini"
printf 'extension:before\n' > "$short_home/php-extension"
printf 'module:before\n' > "$short_home/php-modules"
fingerprint_digest() {
    HOME="$short_home" PATH="$short_home/php-bin:$scratch/bin:$PATH" MATRIX_TEST_IMAGE_ID="$short_home/image-id" \
        MATRIX_TEST_PHP_INI="$short_home/php-ini" MATRIX_TEST_PHP_EXTENSION="$short_home/php-extension" \
        MATRIX_TEST_PHP_MODULES="$short_home/php-modules" "$scratch/tools/matrix.sh" digest
}
fingerprint_before="$(fingerprint_digest)"
printf 'ini:after\n' > "$short_home/php-ini"
fingerprint_ini="$(fingerprint_digest)"
[ "$fingerprint_before" != "$fingerprint_ini" ] || { echo 'PHP ini change did not invalidate digest' >&2; exit 1; }
printf 'extension:after\n' > "$short_home/php-extension"
fingerprint_extension="$(fingerprint_digest)"
[ "$fingerprint_ini" != "$fingerprint_extension" ] || { echo 'PHP extension change did not invalidate digest' >&2; exit 1; }
printf 'module:after\n' > "$short_home/php-modules"
fingerprint_modules="$(fingerprint_digest)"
[ "$fingerprint_extension" != "$fingerprint_modules" ] || { echo 'PHP module change did not invalidate digest' >&2; exit 1; }
printf '# changed binary bytes\n' >> "$short_home/php-bin/php"
fingerprint_binary="$(fingerprint_digest)"
[ "$fingerprint_modules" != "$fingerprint_binary" ] || { echo 'PHP binary change did not invalidate digest' >&2; exit 1; }

# A failed selected Docker leg invalidates a full-matrix marker for this source.
# Image IDs and fake PHP inputs remain fixed throughout the run.
cat > "$scratch/bin/docker" <<'DOCKER_RED'
#!/bin/sh
if [ "$1" = image ] && [ "$2" = inspect ]; then
    cat "$MATRIX_TEST_IMAGE_ID"
    exit 0
fi
if [ "$1" = run ]; then
    printf '> php php/aTest.php\nFailed: synthetic Docker leg\n'
    exit 1
fi
exit 0
DOCKER_RED
chmod +x "$scratch/bin/docker"
full_marker="$(fingerprint_digest)"
printf '%s\n2026-09-25T00:00:00Z\nlocal 8.1 7.4 prod-kinozal\n' "$full_marker" \
    > "$short_home/.cache/rtm/last-green"
if HOME="$short_home" PATH="$short_home/php-bin:$scratch/bin:$PATH" MATRIX_TEST_IMAGE_ID="$short_home/image-id" \
    MATRIX_TEST_PHP_INI="$short_home/php-ini" MATRIX_TEST_PHP_EXTENSION="$short_home/php-extension" \
    MATRIX_TEST_PHP_MODULES="$short_home/php-modules" \
    "$scratch/tools/matrix.sh" 7.4 > "$short_home/docker-red.log" 2>&1; then
    echo 'red Docker leg was accepted' >&2; exit 1
fi
[ ! -e "$short_home/.cache/rtm/last-green" ] || {
    cat "$short_home/docker-red.log" >&2
    echo 'red Docker leg left the full marker in place' >&2; exit 1
}
# A Docker-only leg must remain usable when host PHP is absent or broken.
cat > "$scratch/bin/php" <<'NO_HOST_PHP'
#!/bin/sh
printf '%s\n' "$*" >> "$MATRIX_TEST_PHP_LOG"
exit 127
NO_HOST_PHP
cat > "$scratch/bin/docker" <<'DOCKER_ONLY'
#!/bin/sh
if [ "$1" = image ] && [ "$2" = inspect ]; then
    printf 'sha256:fixture\n'
    exit 0
fi
if [ "$1" = run ]; then
    printf '> php php/aTest.php\n1 tests, 0 failures\n'
    exit 0
fi
exit 0
DOCKER_ONLY
chmod +x "$scratch/bin/php" "$scratch/bin/docker"
if ! HOME="$short_home" PATH="$scratch/bin:$PATH" MATRIX_TEST_PHP_LOG="$short_home/host-php.log" "$scratch/tools/matrix.sh" 7.4 > "$short_home/no-host-php.log" 2>&1; then
    cat "$short_home/no-host-php.log" >&2
    echo 'Docker-only leg incorrectly requires host PHP' >&2; exit 1
fi
[ ! -s "$short_home/host-php.log" ] || { echo 'Docker-only fingerprint invoked host PHP' >&2; exit 1; }
if HOME="$short_home" PATH="$scratch/bin:$PATH" MATRIX_TEST_PHP_LOG="$short_home/host-php.log" "$scratch/tools/matrix.sh" digest > "$short_home/no-host-digest.log" 2>&1; then
    echo 'full digest silently omitted unavailable host PHP' >&2; exit 1
fi
cat > "$scratch/bin/docker" <<'DOCKER_DRIFT'
#!/bin/sh
if [ "$1" = image ] && [ "$2" = inspect ]; then
    cat "$MATRIX_TEST_IMAGE_ID"
    exit 0
fi
if [ "$1" = run ]; then
    printf '%s\n' "$*" > "$MATRIX_TEST_DOCKER_ARGS"
    printf 'sha256:after\n' > "$MATRIX_TEST_IMAGE_ID"
    printf '> php php/aTest.php\n1 tests, 0 failures\n'
    exit 0
fi
exit 0
DOCKER_DRIFT
chmod +x "$scratch/bin/docker"
printf 'sha256:before\n' > "$short_home/image-id"
HOME="$short_home" PATH="$scratch/bin:$PATH" MATRIX_TEST_IMAGE_ID="$short_home/image-id" \
    MATRIX_TEST_DOCKER_ARGS="$short_home/docker-args" "$scratch/tools/matrix.sh" 7.4 > "$short_home/runtime-drift.log" 2>&1
grep -q -- '--pull=never' "$short_home/docker-args" || { echo 'PHP container can pull an image during the run' >&2; exit 1; }
grep -q 'runtime changed while it ran' "$short_home/runtime-drift.log" || {
    cat "$short_home/runtime-drift.log" >&2
    echo 'runtime drift was reported as source drift' >&2; exit 1
}
echo 'matrix-test.sh: digest/mode, deletion, export, leg isolation, PHP jobs, warning, empty PHP test, marker drift, TERM, INT, HUP, retention, umask, stale-run cleanup, long HOME, and delayed Docker cleanup, source/runtime drift, local PHP fingerprint, selected-leg marker revocation, Docker-only without host PHP passed'
