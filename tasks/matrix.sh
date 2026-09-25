#!/usr/bin/env bash
# matrix.sh -- run the PHP suite on every supported PHP at once, each leg on
# its own copy of the tree, and remember the tree that came out green so the
# pre-commit hook can skip a run it has already seen.
#
#   tasks/matrix.sh                 all legs: local PHP, php:8.1-cli, php:7.4-cli,
#                                   plus Kinozal in the shipped no-iconv image
#   tasks/matrix.sh local 7.4       only these legs
#   tasks/matrix.sh prod-kinozal    only the no-iconv Kinozal handler suite
# Only the default run with no leg arguments records the pre-commit marker.
#   tasks/matrix.sh digest          print the digest of what the suite tests
#   tasks/matrix.sh last            show the last green run
#
# Why legs run in parallel on separate exports: one leg is ~100 s and most of
# it is two single-threaded files (AGENTS.md, "The Pre-Commit Suite Is 200
# Seconds"), so three legs side by side cost about one leg -- but two runs
# over ONE tree corrupt each other (AGENTS.md, "This Machine Will Lie To You"),
# and a container writes as root. So every leg gets `git ls-files` of the
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
# The digest covers every exported non-Markdown file and its mode/type. Markdown-only edits do
# not change the suite inputs. A changed runner invalidates its old green
# marker because tasks/matrix.sh is part of the export.
set -u -o pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
base="${HOME}/.cache/rtm"
marker="${base}/last-green"
tmpdir_budget=59  # Verified with SCGITransportTest; the socket suffix is 49 bytes.

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

digest() (
    files=$(mktemp "${TMPDIR:-/tmp}/rutorrent-matrix-files.XXXXXX") || exit 1
    trap 'rm -f "$files"' EXIT
    suite_files | grep -zvE '\.md$' > "$files" || exit 1
    if [ ! -s "$files" ]; then
        echo "matrix.sh: no suite inputs found for digest" >&2
        exit 1
    fi
    hash_files "$root" "$files"
)

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
for leg in "${legs[@]}"; do
    case "$leg" in
        local|7.4|8.1|prod-kinozal) ;;
        *) echo "matrix.sh: unknown leg: $leg" >&2; exit 2 ;;
    esac
done
if [[ " ${legs[*]} " == *" local "* ]]; then
    # The template and mktemp result have the same number of bytes.
    local_tmp="${base}/${stamp}.XXXXXX/local/tmp"
    if [ "${#local_tmp}" -gt "$tmpdir_budget" ]; then
        echo "matrix.sh: TMPDIR '$local_tmp' is ${#local_tmp} bytes; UNIX socket fixtures need it <= $tmpdir_budget" >&2
        exit 2
    fi
fi

before="$(digest)" || exit 1
mkdir -p "$base" || exit 1
run="$(mktemp -d "${base}/${stamp}.XXXXXX")" || exit 1
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
    local observed="$1"
    [ -n "$observed" ] || return 0
    marker_lock
    if [ "$(sed -n 1p "$marker" 2>/dev/null)" = "$observed" ]; then
        rm -f "$marker"
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
    trap - INT TERM
    stop_legs
    echo "matrix.sh: interrupted; logs under $run" >&2
    exit "$code"
}
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
    | tar -xf - -C "$export_dir" || fail_setup "archive export failed"
export_digest="$(hash_files "$export_dir" "$run/digest-manifest")" \
    || fail_setup "archive export is incomplete"
[ "$export_digest" = "$before" ] || fail_setup "archive export differs from the source digest"
expected_files="$(find "$root/tests/php" "$root/tests/plugins" -type f -name '*Test.php' | wc -l)" \
    || fail_setup "cannot count source tests"
[ "$expected_files" -gt 0 ] || fail_setup "source has no PHP test files"

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
                dir="$1"; uid="$2"; gid="$3"; name="$4"
                printf "> php plugins/rutracker_check/KinozalHandlerTest.php\n" > "$dir/php-test.log"
                docker run --rm --pull=never --network none --name "$name" --user "${uid}:${gid}" \
                    -e HOME=/mtmp -e TMPDIR=/mtmp --entrypoint php85 \
                    -v "$dir/tree:/w:ro" -v "$dir/tmp:/mtmp" -w /w \
                    ivanshift/rutorrent:latest -c tests/php-test.ini \
                    -d disable_functions=iconv -r \
                    '"'"'if (function_exists("iconv")) { fwrite(STDERR, "prod-kinozal: iconv available\n"); exit(1); } require "tests/plugins/rutracker_check/KinozalHandlerTest.php";'"'"' \
                    >> "$dir/php-test.log" 2>&1
                printf "%s\n" "$?" > "$dir/exit"
            ' _ "$dir" "$uid" "$gid" "${container_name[$leg]}" &
            ;;
        *)
            container_name[$leg]="rtm-$(basename "$run")-$leg"
            setsid bash -c '
                dir="$1"; leg="$2"; uid="$3"; gid="$4"; name="$5"
                docker run --rm --name "$name" --user "${uid}:${gid}" \
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
        if [ "$files" -ne 1 ] \
            || ! grep -Eq '^[1-9][0-9]* tests, 0 failures$' "$dir/php-test.log" \
            || grep -q 'NOT CHECKED' "$dir/php-test.log"; then
            echo 'matrix.sh: prod-kinozal did not execute its no-iconv cases' >> "$dir/php-test.log"
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
after="$(digest)" || status=1

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
    echo "green on ${legs[*]} in ${elapsed}s, but the tree changed while it ran: nothing recorded"
else
    # The export was verified against before; a failed leg invalidates that digest
    # even if the source changed while the leg ran or the final digest failed.
    revoke_marker "$before"
    echo "NOT green (${elapsed}s); logs under $run"
fi

# Other matrix invocations can be running in older directories. Retain the
# three newest completed runs and never delete a directory still in use.
touch "$run/.finished"
completed=()
for candidate in "$base"/[0-9]*; do
    [ -d "$candidate" ] && [ -f "$candidate/.finished" ] && completed+=("$candidate")
done
if [ "${#completed[@]}" -gt 3 ]; then
    mapfile -d '' -t completed < <(printf '%s\0' "${completed[@]}" | LC_ALL=C sort -zr)
    for ((i=3; i<${#completed[@]}; i++)); do
        rm -rf -- "${completed[$i]}"
    done
fi
exit "$status"
