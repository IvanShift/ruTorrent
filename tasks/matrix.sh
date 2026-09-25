#!/usr/bin/env bash
# matrix.sh -- run the PHP suite on every supported PHP at once, each leg on
# its own copy of the tree, and remember the tree that came out green so the
# pre-commit hook can skip a run it has already seen.
#
#   tasks/matrix.sh                 all legs: local PHP, php:8.1-cli, php:7.4-cli,
#                                   plus Kinozal and sibling trackers in the shipped no-iconv image
#   tasks/matrix.sh local 7.4       only these legs
#   tasks/matrix.sh prod-kinozal    only the no-iconv tracker handler suites
# Only the default run with no leg arguments records the pre-commit marker.
#   tasks/matrix.sh digest          print the digest of what the suite tests
#   tasks/matrix.sh last            show the last green run
#
# Why legs run in parallel on separate exports: the local PHP leg was about
# 90 s on 2026-09-15, mostly in two files (AGENTS.md, "PHP Suite Timing and the
# Matrix"). Four legs run side by side, but two runs
# over ONE tree corrupt each other (AGENTS.md, "This Machine Will Lie To You"),
# and a default container could write as root. So every leg gets `git ls-files` of the
# working tree (staged and unstaged edits, untracked new files) exported to a
# disk-backed directory of its own, plus its own TMPDIR, and containers run as
# this user. Never under /tmp: it is a small tmpfs here.
#
# Why the run directory is ~/.cache/rtm and not something readable: a leg's
# TMPDIR must stay short. The unique run suffix still fits the budget.
# SCGITransportFixture binds a UNIX socket at
# TMPDIR/rutorrent-scgi-<23-char uniqid>/scgi.sock, 49 bytes below TMPDIR,
# and Linux sun_path holds 107. PHP does not refuse a longer path -- it
# truncates it (a Notice the fixture silences with @), bind() then lands on
# the directory itself, and the test reports "SCGI fixture peer exited:
# server:" with nothing after the colon. The first trial of this script died
# exactly like that on a 68-byte TMPDIR. The guard below refuses to start
# rather than fail four tests 90 seconds in.
#
# The source digest covers every exported non-Markdown file and its mode/type.
# The marker also includes the runtime fingerprint below. Markdown-only edits
# do not change the source inputs; editing this runner does.
set -u -o pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
base="${HOME}/.cache/rtm"
marker="${base}/last-green"
tmpdir_budget=58  # 58 + the 49-byte socket suffix fits the 107-byte sun_path limit.

# A tracked path removed in the working tree is deliberately absent. A file
# vanishing while it is hashed or archived remains an error.
suite_files() (
    cd "$root" || exit 1
    set -o pipefail
    git ls-files -z --cached --others --exclude-standard \
        | LC_ALL=C sort -zu \
        | while IFS= read -r -d '' path; do
            if [ -e "$path" ] || [ -L "$path" ]; then
                printf '%s\0' "$path"
            fi
        done
)

hash_files() (
    cd "$1" || exit 1
    python3 - "$2" <<'PYHASH'
import hashlib
import os
import stat
import sys

digest = hashlib.sha256()
with open(sys.argv[1], 'rb') as manifest:
    paths = manifest.read().split(b'\0')
for path in paths:
    if not path:
        continue
    info = os.lstat(path)
    if not (stat.S_ISREG(info.st_mode) or stat.S_ISLNK(info.st_mode)):
        raise ValueError('matrix input is not a file or symlink: ' + os.fsdecode(path))
    digest.update(len(path).to_bytes(8, 'big'))
    digest.update(path)
    # A chmod or file-to-symlink replacement can change test execution while
    # leaving the bytes identical. Include type, permissions and link target.
    digest.update(info.st_mode.to_bytes(4, 'big'))
    target = os.readlink(path) if stat.S_ISLNK(info.st_mode) else b''
    digest.update(len(target).to_bytes(8, 'big'))
    digest.update(target)
    content = hashlib.sha256()
    with open(path, 'rb') as source:
        for chunk in iter(lambda: source.read(1024 * 1024), b''):
            content.update(chunk)
    digest.update(content.digest())
print(digest.hexdigest())
PYHASH
)

source_digest() (
    files=$(mktemp "${TMPDIR:-/tmp}/rutorrent-matrix-files.XXXXXX") || exit 1
    trap 'rm -f "$files"' EXIT
    suite_files | grep -zvE '\.md$' > "$files" || exit 1
    if [ ! -s "$files" ]; then
        echo "matrix.sh: no suite inputs found for digest" >&2
        exit 1
    fi
    hash_files "$root" "$files"
)

# The pre-commit marker also names the runtimes that executed the suite. Docker
# image IDs change on a tag refresh; PHP bytes, ini files, extension binaries
# and loaded modules cover local package/configuration changes without a remote query.
runtime_fingerprint() (
    set -o pipefail
    include_local=0
    if [ "$#" -eq 0 ]; then
        # `digest` without legs describes the full pre-commit matrix.
        include_local=1
    else
        for leg in "$@"; do
            [ "$leg" = local ] && include_local=1
        done
    fi
    {
        if [ "$include_local" -eq 1 ]; then
            php_binary="$(command -v php)" || {
                echo "matrix.sh: local PHP unavailable for runtime fingerprint" >&2
                return 1
            }
            sha256sum "$php_binary" || return 1
            php -v || return 1
            php -m || return 1
            php -r '$files = array_merge(array(php_ini_loaded_file()), explode(",", (string) php_ini_scanned_files())); foreach ($files as $file) { $file = trim($file); if ($file !== "") { $hash = hash_file("sha256", $file); if ($hash === false) exit(1); echo $file, ":", $hash, "\n"; } } foreach (get_loaded_extensions() as $name) echo $name, ":", phpversion($name), "\n"; $dir = rtrim((string) ini_get("extension_dir"), DIRECTORY_SEPARATOR); foreach (glob($dir . DIRECTORY_SEPARATOR . "*.so") ?: array() as $file) { $hash = hash_file("sha256", $file); if ($hash === false) exit(1); echo $file, ":", $hash, "\n"; }' || return 1
        else
            printf 'local PHP: not selected\n'
        fi
        for image in php:8.1-cli php:7.4-cli ivanshift/rutorrent:latest; do
            printf '%s ' "$image"
            timeout 5s docker image inspect --format '{{.Id}}' "$image" 2>/dev/null || printf 'unavailable\n'
        done
    } | sha256sum | cut -d ' ' -f 1
)

combine_digest() {
    printf '%s\n%s\n' "$1" "$2" | sha256sum | cut -d ' ' -f 1
}

digest() {
    local source runtime
    source="$(source_digest)" || return 1
    runtime="$(runtime_fingerprint "$@")" || return 1
    combine_digest "$source" "$runtime"
}

case "${1:-}" in
	digest) digest; exit $? ;;
	last)   cat "$marker" 2>/dev/null || echo "no green run recorded"; exit 0 ;;
esac

legs=("$@")
record_green=0
if [ "${#legs[@]}" -eq 0 ]; then
	legs=(local 8.1 7.4 prod-kinozal)
	record_green=1
fi

stamp="$(date -u +%y%m%d%H%M%S)"
declare -A seen_legs=()
for leg in "${legs[@]}"; do
    case "$leg" in
        local|7.4|8.1|prod-kinozal) ;;
        *) echo "matrix.sh: unknown leg: $leg" >&2; exit 2 ;;
    esac
    if [ -n "${seen_legs[$leg]+set}" ]; then
        echo "matrix.sh: duplicate leg: $leg" >&2
        exit 2
    fi
    seen_legs[$leg]=1
done
if [[ " ${legs[*]} " == *" local "* ]]; then
    # The template and mktemp result have the same number of bytes.
    local_tmp="${base}/${stamp}.XXXXXX/local/tmp"
    local_tmp_bytes="$(printf '%s' "$local_tmp" | wc -c)"
    if [ "$local_tmp_bytes" -gt "$tmpdir_budget" ]; then
        echo "matrix.sh: TMPDIR '$local_tmp' is $local_tmp_bytes bytes; UNIX socket fixtures need it <= $tmpdir_budget" >&2
        exit 2
    fi
fi

source_before="$(source_digest)" || exit 1
runtime_before="$(runtime_fingerprint "${legs[@]}")" || exit 1
before="$(combine_digest "$source_before" "$runtime_before")" || exit 1
mkdir -p "$base" || exit 1
run="$(mktemp -d "${base}/${stamp}.XXXXXX")" || exit 1
# Descendant legs inherit this lock. A SIGKILL of the runner cannot make an
# active export eligible for retention cleanup while a leg still uses it.
exec {run_fd}>"$run/.lock" || exit 1
flock -x "$run_fd" || exit 1
started=$(date +%s)
declare -A pid=()
declare -A container_name=()

marker_lock() {
    exec {marker_fd}>"${marker}.lock"
    flock -x "$marker_fd"
}
marker_unlock() {
    flock -u "$marker_fd"
    exec {marker_fd}>&-
}
revoke_marker() {
    local observed="$1" recorded full_runtime full_observed
    [ -n "$observed" ] || return 0
    marker_lock
    recorded="$(sed -n 1p "$marker" 2>/dev/null)"
    if [ "$recorded" = "$observed" ]; then
        rm -f "$marker"
    elif [ -n "$recorded" ]; then
        # A selected Docker leg omits local PHP from its digest. Reconstruct
        # the full marker for the source snapshot that the failed leg tested.
        # Keep the lock while checking so another run cannot publish between
        # the comparison and removal.
        if full_runtime="$(runtime_fingerprint 2>/dev/null)"; then
            full_observed="$(combine_digest "$source_before" "$full_runtime")"
            [ "$recorded" != "$full_observed" ] || rm -f "$marker"
        fi
    fi
    marker_unlock
}
stop_legs() {
    local leg round
    for leg in "${!pid[@]}"; do
        kill -TERM -- "-${pid[$leg]}" 2>/dev/null || :
    done
    # Let an in-flight Docker create register its named container before rm.
    # The grace period is bounded, then every remaining process group is killed.
    sleep 0.25
    for leg in "${!pid[@]}"; do
        kill -KILL -- "-${pid[$leg]}" 2>/dev/null || :
    done
    for leg in "${!pid[@]}"; do
        wait "${pid[$leg]}" 2>/dev/null || :
    done
    # A daemon can finish create after its CLI has gone. Retry by unique name
    # so an interrupt does not leave that late-created container behind.
    for round in 1 2 3; do
        for leg in "${!container_name[@]}"; do
            docker rm -f "${container_name[$leg]}" >/dev/null 2>&1 || :
        done
        [ "$round" -eq 3 ] || sleep 0.15
    done
}
fail_setup() {
    echo "matrix.sh: $*" >&2
    stop_legs
    revoke_marker "$before"
    rm -rf -- "$run"
    exit 1
}
on_signal() {
    local code="$1"
    trap - HUP INT TERM
    # Start the diagnostic grace period when the signal arrived, even if a
    # very long run created its root directory more than a day earlier.
    touch "$run" 2>/dev/null || :
    stop_legs
    echo "matrix.sh: interrupted; logs under $run" >&2
    exit "$code"
}
trap 'on_signal 129' HUP
trap 'on_signal 130' INT
trap 'on_signal 143' TERM

# Export once, then give each leg its own copy. A failed or incomplete archive must
# never turn into a successful test run with fewer files.
export_dir="${run}/export"
mkdir -p "$export_dir" || fail_setup "cannot create export directory"
suite_files > "$run/manifest" || fail_setup "cannot list suite inputs"
grep -zvE '\.md$' "$run/manifest" > "$run/digest-manifest" \
    || fail_setup "no non-Markdown suite inputs"
(cd "$root" && tar --null -T "$run/manifest" -cf -) \
    | tar -xpf - -C "$export_dir" || fail_setup "archive export failed"
export_digest="$(hash_files "$export_dir" "$run/digest-manifest")" \
    || fail_setup "archive export is incomplete"
if [ "$export_digest" != "$source_before" ]; then
    source_at_export="$(source_digest)" || fail_setup "archive export differs from source digest; cannot re-read source at $root (export: $export_dir)"
    if [ "$source_at_export" != "$source_before" ]; then
        fail_setup "archive export differs from source digest because source changed during export (source: $root; export: $export_dir)"
    fi
    fail_setup "archive export differs from source digest; exported content or mode changed (source: $root; export: $export_dir)"
fi
# Count exactly the regular test files in this verified export. The source
# tree may also contain ignored *Test.php files that were never archived.
expected_files="$(python3 - "$run/manifest" "$export_dir" <<'PYCOUNT'
import os
import stat
import sys

manifest, export = sys.argv[1:]
with open(manifest, 'rb') as stream:
    paths = stream.read().split(b'\0')
root = os.fsencode(export)
count = 0
for path in paths:
    if not path.startswith((b'tests/php/', b'tests/plugins/')) or not path.endswith(b'Test.php'):
        continue
    if stat.S_ISREG(os.lstat(os.path.join(root, path)).st_mode):
        count += 1
print(count)
PYCOUNT
)" || fail_setup "cannot count exported tests"
[ "$expected_files" -gt 0 ] || fail_setup "export has no PHP test files"

. "$root/tests/php-failure-pattern.sh"
uid="$(id -u)"; gid="$(id -g)"
for leg in "${legs[@]}"; do
    dir="${run}/${leg}"
    mkdir -p "$dir/tmp" || fail_setup "cannot create $leg directory"
    cp -a --reflink=auto "$export_dir" "$dir/tree" || fail_setup "cannot copy export for $leg"
    case "$leg" in
        local)
            setsid bash -c '
                dir="$1"
                cd "$dir/tree/tests" &&
                    TMPDIR="$dir/tmp" bash php-test.sh > "$dir/php-test.log" 2>&1
                printf "%s\n" "$?" > "$dir/exit"
            ' _ "$dir" &
            ;;
        prod-kinozal)
            container_name[$leg]="rtm-$(basename "$run")-$leg"
            setsid bash -c '
                dir="$1"; uid="$2"; gid="$3"; name="$4"; status=0
                : > "$dir/php-test.log"
                for suite in KinozalHandlerTest SiblingTrackersTest; do
                    test_file="tests/plugins/rutracker_check/${suite}.php"
                    suite_log="$dir/${suite}.log"
                    printf "> php %s\n" "$test_file" >> "$dir/php-test.log"
                    docker run --rm --pull=never --network none --name "$name" --user "${uid}:${gid}" \
                        -e HOME=/mtmp -e TMPDIR=/mtmp -e RT_SUITE="$test_file" --entrypoint php85 \
                        -v "$dir/tree:/w:ro" -v "$dir/tmp:/mtmp" -w /w \
                        ivanshift/rutorrent:latest -c tests/php-test.ini \
                        -d disable_functions=iconv -r \
                        '"'"'if (function_exists("iconv")) { fwrite(STDERR, "prod-kinozal: iconv available\n"); exit(1); } require getenv("RT_SUITE");'"'"' \
                        > "$suite_log" 2>&1
                    status=$?
                    cat "$suite_log" >> "$dir/php-test.log"
                    if [ "$status" -ne 0 ] \
                        || ! grep -Eq '"'"'^[1-9][0-9]* tests, 0 failures$'"'"' "$suite_log" \
                        || grep -q '"'"'NOT CHECKED'"'"' "$suite_log"; then
                        [ "$status" -ne 0 ] || status=1
                        break
                    fi
                done
                printf "%s\n" "$status" > "$dir/exit"
            ' _ "$dir" "$uid" "$gid" "${container_name[$leg]}" &
            ;;
        *)
            container_name[$leg]="rtm-$(basename "$run")-$leg"
            setsid bash -c '
                dir="$1"; leg="$2"; uid="$3"; gid="$4"; name="$5"
                docker run --rm --pull=never --name "$name" --user "${uid}:${gid}" \
                    -e HOME=/mtmp -e TMPDIR=/mtmp \
                    -v "$dir/tree:/w" -v "$dir/tmp:/mtmp" -w /w/tests "php:${leg}-cli" \
                    bash php-test.sh > "$dir/php-test.log" 2>&1
                printf "%s\n" "$?" > "$dir/exit"
            ' _ "$dir" "$leg" "$uid" "$gid" "${container_name[$leg]}" &
            ;;
    esac
    pid[$leg]=$!
done

status=0
printf '%-13s %-6s %-9s %-6s %s\n' leg exit failures files log
for leg in "${legs[@]}"; do
    wait "${pid[$leg]}" || status=1
    unset "pid[$leg]"
    dir="${run}/${leg}"
    code="$(cat "$dir/exit" 2>/dev/null || echo 99)"
    fails="$(grep -cE "$PHP_FAILURE_PATTERN" "$dir/php-test.log" 2>/dev/null || :)"
    files="$(grep -c '^> php' "$dir/php-test.log" 2>/dev/null || :)"
    fails="${fails:-0}"
    files="${files:-0}"
    if [ "$leg" = prod-kinozal ]; then
        if [ "$files" -ne 2 ] \
            || [ "$(grep -cE '^[1-9][0-9]* tests, 0 failures$' "$dir/php-test.log")" -ne 2 ] \
            || grep -q 'NOT CHECKED' "$dir/php-test.log"; then
            echo 'matrix.sh: prod-kinozal did not execute both no-iconv suites' >> "$dir/php-test.log"
            fails=$((fails + 1))
        fi
    elif [ "$files" -ne "$expected_files" ]; then
        echo "matrix.sh: $leg ran $files of $expected_files PHP files" >> "$dir/php-test.log"
        fails=$((fails + 1))
    fi
    printf '%-13s %-6s %-9s %-6s %s\n' "$leg" "$code" "$fails" "$files" "$dir/php-test.log"
    [ "$code" = 0 ] && [ "$fails" = 0 ] || status=1
done
elapsed=$(( $(date +%s) - started ))
source_after="$(source_digest)" || status=1
runtime_after="$(runtime_fingerprint "${legs[@]}")" || status=1
after="$(combine_digest "$source_after" "$runtime_after")" || status=1

if [ "$status" = 0 ] && [ "$before" = "$after" ]; then
    if [ "$record_green" = 1 ]; then
        marker_lock
        tmp_marker="$(mktemp "${marker}.XXXXXX")" || exit 1
        printf '%s\n%s\n%s\n' "$after" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "${legs[*]}" > "$tmp_marker"
        mv -f "$tmp_marker" "$marker"
        marker_unlock
        echo "green on ${legs[*]} in ${elapsed}s; recorded ${after:0:12} for the pre-commit hook"
    else
        echo "green on ${legs[*]} in ${elapsed}s; focused run does not update last-green"
    fi
elif [ "$status" = 0 ]; then
    if [ "$source_before" != "$source_after" ] && [ "$runtime_before" != "$runtime_after" ]; then
        change="source and runtime changed"
    elif [ "$source_before" != "$source_after" ]; then
        change="source changed"
    else
        change="runtime changed"
    fi
    echo "green on ${legs[*]} in ${elapsed}s, but $change while it ran: nothing recorded"
else
    # The export was verified against before; a failed leg invalidates that digest
    # even if the source changed while the leg ran or the final digest failed.
    revoke_marker "$before"
    echo "NOT green (${elapsed}s); logs under $run"
fi

# Serialise cleanup, then keep the three most recently *finished* runs.
# Keep incomplete logs for a day so an interrupted run can be diagnosed.
# After that, remove them only when the inherited lock is free; this also
# handles old-format directories that had no lock. The current log is retained.
touch "$run/.finished"
exec {cleanup_fd}>"$base/.cleanup.lock"
flock -x "$cleanup_fd"
python3 - "$base" "$run" <<'PYCLEAN' || echo "matrix.sh: retention cleanup failed; logs under $run" >&2
import fcntl
import re
import shutil
import sys
import time
from pathlib import Path

base, current = map(Path, sys.argv[1:])
now = time.time()
completed = []
locks = []
for candidate in base.iterdir():
    if not re.fullmatch(r'[0-9]{12}\.[A-Za-z0-9]{6}', candidate.name):
        continue
    if not candidate.is_dir() or candidate.is_symlink():
        continue
    finished = candidate / '.finished'
    if candidate == current:
        completed.append((finished.stat().st_mtime_ns, candidate))
        continue
    lock_path = candidate / '.lock'
    # A recent interrupted run keeps its logs; old unlocked runs are stale.
    if not finished.exists() and now - candidate.stat().st_mtime < 86400:
        continue
    lock = lock_path.open('a')
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        lock.close()
        continue
    locks.append(lock)
    if finished.exists():
        completed.append((finished.stat().st_mtime_ns, candidate))
    else:
        shutil.rmtree(candidate)

completed.sort(key=lambda item: item[0], reverse=True)
for _, candidate in completed[3:]:
    if candidate != current:
        shutil.rmtree(candidate)
for lock in locks:
    lock.close()
PYCLEAN
flock -u "$cleanup_fd"
exit "$status"
