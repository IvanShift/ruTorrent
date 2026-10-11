#!/usr/bin/env bash
set -euo pipefail

root="$(CDPATH= cd -- "$(dirname -- "$0")/../../.." && pwd)"
run="$(mktemp -d "${TMPDIR:-/tmp}/erasedata-noreplace.XXXXXX")"
probe_pid=''
concurrent_pids=()
trap 'for pid in "${concurrent_pids[@]}"; do kill -KILL "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; done; if [[ -n "$probe_pid" ]]; then kill -KILL "$probe_pid" 2>/dev/null || true; wait "$probe_pid" 2>/dev/null || true; fi; rm -rf -- "$run"' EXIT

compile_helper() {
  php -r '''require $argv[1]; $process = proc_open(NativeHelpers::compilerCommand("rutorrent-erasedata-rename-noreplace", $argv[2], array_slice($argv, 3)), array(0=>array("file","/dev/null","r"),1=>array("file","php://stdout","w"),2=>array("file","php://stderr","w")), $pipes); exit(is_resource($process) ? proc_close($process) : 1);''' \
    "$root/php/nativehelpers.php" "$@"
}
if [[ $# -gt 1 ]]; then echo 'usage: rename-noreplace-test.sh [packaged-binary]' >&2; exit 64; fi
if [[ $# == 1 ]]; then
  binary="$1"
  [[ -x "$binary" ]] || { echo 'packaged helper is not executable' >&2; exit 1; }
else
  binary="$run/rename-noreplace"
  compile_helper "$binary"
fi

# Runtime images have installed helpers and deliberately omit a compiler.
# Source gates must retain the real crash cuts and injected errno variants.
source_cuts=true
if [[ $# == 1 ]] && ! command -v cc >/dev/null 2>&1; then
  source_cuts=false
  echo 'scope - packaged helper probes run; compiler crash cuts and EINVAL classifier require the source gate'
fi

# Exit statuses of the helper. ErasedataFilesystemOps::RENAME_HELPER_STATUS in
# plugins/erasedata/filesystem.php is the table; RenameHelperStatusTest.php
# pins these names and values to it.
readonly STATUS_OK=0 STATUS_COLLISION=1 STATUS_INVALID_ARGUMENTS=2 STATUS_UNSUPPORTED=3 STATUS_ERROR=4 STATUS_FALLBACK=5

# A probe is usable natively or through the caller-locked fallback.
usable_status() {
  [[ "$1" == "$STATUS_OK" || "$1" == "$STATUS_FALLBACK" ]]
}

probe_usable() {
  local probe_status=0
  "$1" --probe "$2" || probe_status=$?
  usable_status "$probe_status"
}

# Run a helper invocation and require one exact exit status.
expect_status() {
  local expected=$1 actual=0
  shift
  "$@" 2>>"$run/expected-status.stderr" || actual=$?
  [[ "$actual" == "$expected" ]] || { echo "expected status $expected, got $actual: $*" >&2; exit 1; }
}

probe_usable "$binary" "$run"

# Eight helpers must recover the same 32 dead workspaces without reopening a
# removed lock name. The compiler gate also stops the first owner in that gap.
concurrent_parent="$run/concurrent"
mkdir -m 0700 "$concurrent_parent"
printf 'payload must survive' > "$concurrent_parent/payload"
for index in {0..31}; do
  printf -v workspace '%s/.rutorrent-rename-probe-999-%016x' "$concurrent_parent" "$index"
  mkdir -m 0700 "$workspace"
  for entry in .lock from-file to-file; do : > "$workspace/$entry"; chmod 0600 "$workspace/$entry"; done
  mkdir -m 0700 "$workspace/from-dir" "$workspace/to-dir"
done
first_binary="$binary"
if [[ "$source_cuts" == true ]]; then
  compile_helper "$run/recovery-cut" "$root/tests/plugins/erasedata/ProbeRecoveryCut.c" -Wl,--wrap=unlinkat
  first_binary="$run/recovery-cut"
fi
"$first_binary" --probe "$concurrent_parent" &
probe_pid=$!
if [[ "$source_cuts" == true ]]; then
  stopped=0
  for attempt in {1..100}; do
    if [[ -r "/proc/$probe_pid/status" ]] && grep -q '^State:.*T' "/proc/$probe_pid/status"; then stopped=1; break; fi
    sleep 0.01
  done
  [[ "$stopped" == 1 ]]
fi
concurrent_pids=("$probe_pid")
# Every waited status is the helper's own, so a fallback filesystem's 5 counts.
for index in {1..7}; do "$binary" --probe "$concurrent_parent" & concurrent_pids+=("$!"); done
if [[ "$source_cuts" == true ]]; then sleep 0.15; kill -CONT "$probe_pid"; fi
concurrent_failed=0
for pid in "${concurrent_pids[@]}"; do
  concurrent_status=0
  wait "$pid" || concurrent_status=$?
  usable_status "$concurrent_status" || concurrent_failed=1
done
concurrent_pids=()
probe_pid=''
[[ "$concurrent_failed" == 0 ]]
[[ -z "$(find "$concurrent_parent" -maxdepth 1 -name '.rutorrent-rename-probe-*' -print)" ]]
[[ "$(cat "$concurrent_parent/payload")" == 'payload must survive' ]]
echo 'ok - eight concurrent probes recover 32 attested workspaces and preserve payload'

# Model SIGKILL after each native side effect. The locked workspace is
# infrastructure, and the next probe recovers only its attested fixed entries.
probe_root="$run/.rutorrent-rename-probe-999-0123456789abcdef"
for cut in from-file to-file from-dir to-dir; do
  mkdir -m 0700 "$probe_root"
  if [[ "$cut" == *-file ]]; then
    printf 'interrupted probe' > "$probe_root/$cut"
    chmod 0600 "$probe_root/$cut"
  else
    mkdir -m 0700 "$probe_root/$cut"
  fi
  probe_usable "$binary" "$run"
  [[ ! -e "$probe_root" ]]
done
mkdir -m 0700 "$probe_root"
printf 'unrelated bytes' > "$probe_root/unexpected"
if probe_usable "$binary" "$run" 2>"$run/unsafe-probe.stderr"; then
  echo 'unexpected probe workspace content was accepted' >&2; exit 1
fi
[[ "$(cat "$probe_root/unexpected")" == 'unrelated bytes' ]]
rm -- "$probe_root/unexpected"
printf 'keep bytes' > "$run/keep-probe"
ln -s "$run/keep-probe" "$probe_root/from-file"
if probe_usable "$binary" "$run" 2>"$run/unsafe-probe.stderr"; then
  echo 'linked probe artifact was accepted' >&2; exit 1
fi
[[ -L "$probe_root/from-file" && "$(cat "$run/keep-probe")" == 'keep bytes' ]]
rm -- "$probe_root/from-file"
mkdir -m 0700 "$probe_root/from-dir"
printf 'unknown nested bytes' > "$probe_root/from-dir/unexpected"
printf 'attested probe bytes' > "$probe_root/from-file"
chmod 0600 "$probe_root/from-file"
if probe_usable "$binary" "$run" 2>"$run/unsafe-probe.stderr"; then
  echo 'nonempty probe directory was accepted' >&2; exit 1
fi
[[ "$(cat "$probe_root/from-dir/unexpected")" == 'unknown nested bytes' ]]
[[ "$(cat "$probe_root/from-file")" == 'attested probe bytes' ]]
rm -- "$probe_root/from-dir/unexpected"
probe_usable "$binary" "$run"
[[ ! -e "$probe_root" ]]

# Kill an actual helper while its private file/lock are live. The next real
# probe must recover those exact bytes, release the workspace and make progress.
if [[ "$source_cuts" == true ]]; then
compile_helper "$run/interrupted" -DRUTORRENT_RENAME_TEST_STOP_AFTER_CREATE
mkdir -m 0700 "$run/crash-parent"
"$run/interrupted" --probe "$run/crash-parent" &
probe_pid=$!
stopped=0
for attempt in {1..100}; do
  if [[ -r "/proc/$probe_pid/status" ]] && grep -q '^State:.*T' "/proc/$probe_pid/status"; then
    stopped=1; break
  fi
  sleep 0.01
done
[[ "$stopped" == 1 ]]
kill -KILL "$probe_pid"
wait "$probe_pid" 2>/dev/null || true
probe_pid=''
[[ -n "$(find "$run/crash-parent" -maxdepth 1 -name '.rutorrent-rename-probe-*' -print)" ]]
probe_usable "$binary" "$run/crash-parent"
[[ -z "$(find "$run/crash-parent" -maxdepth 1 -name '.rutorrent-rename-probe-*' -print)" ]]

fi

mkdir "$run/source-dir"
: > "$run/source-dir/child"
original="$(stat -c '%d:%i' "$run/source-dir")"
"$binary" "$run/source-dir" "$run/restored-dir"
[[ ! -e "$run/source-dir" ]]
[[ "$(stat -c '%d:%i' "$run/restored-dir")" == "$original" ]]
[[ -f "$run/restored-dir/child" ]]

mkdir "$run/source-occupied" "$run/occupied"
: > "$run/source-occupied/from"
: > "$run/occupied/keep"
expect_status "$STATUS_COLLISION" "$binary" "$run/source-occupied" "$run/occupied"
[[ -f "$run/source-occupied/from" && -f "$run/occupied/keep" ]]

# An ordinary rename would replace both of these destinations.
printf 'source' > "$run/file-collision-source"
printf 'keep' > "$run/file-collision-target"
expect_status "$STATUS_COLLISION" "$binary" "$run/file-collision-source" "$run/file-collision-target"
[[ "$(cat "$run/file-collision-target")" == keep ]]
[[ "$(cat "$run/file-collision-source")" == source ]]
mkdir "$run/empty-dir-source" "$run/empty-dir-target"
empty_identity="$(stat -c '%d:%i' "$run/empty-dir-target")"
expect_status "$STATUS_COLLISION" "$binary" "$run/empty-dir-source" "$run/empty-dir-target"
[[ -d "$run/empty-dir-source" && "$(stat -c '%d:%i' "$run/empty-dir-target")" == "$empty_identity" ]]

: > "$run/source-file"
file_identity="$(stat -c '%d:%i' "$run/source-file")"
"$binary" "$run/source-file" "$run/restored-file"
[[ ! -e "$run/source-file" ]]
[[ "$(stat -c '%d:%i' "$run/restored-file")" == "$file_identity" ]]

ln -s missing-target "$run/source-link"
"$binary" "$run/source-link" "$run/restored-link"
[[ ! -L "$run/source-link" ]]
[[ "$(readlink "$run/restored-link")" == missing-target ]]

# Status 3 survives the fallback only as plain renameat's own refusal: a
# directory cannot move into its own subtree on any filesystem.
mkdir "$run/self-parent"
: > "$run/self-parent/keep"
expect_status "$STATUS_UNSUPPORTED" "$binary" "$run/self-parent" "$run/self-parent/child"
[[ -f "$run/self-parent/keep" && ! -e "$run/self-parent/child" ]]
expect_status "$STATUS_INVALID_ARGUMENTS" "$binary" "$run/self-parent" "$run/self-parent"
expect_status "$STATUS_INVALID_ARGUMENTS" "$binary" relative "$run/self-parent/other"
[[ -d "$run/self-parent" && ! -e "$run/self-parent/other" ]]

# Inject only renameat2's errno: unsupported flags must use the shared
# caller-locked fallback, while real filesystem errors remain refusals.
if [[ "$source_cuts" == true ]]; then
for injected_errno in 22 38 95; do
  echo "test - errno $injected_errno fallback probe and collision preservation"
  fallback="$run/fallback-$injected_errno"
  compile_helper "$fallback" "-DRUTORRENT_RENAME_TEST_ERRNO=$injected_errno"
  expect_status "$STATUS_FALLBACK" "$fallback" --probe "$run"
  [[ -z "$(find "$run" -maxdepth 1 -name '.rutorrent-rename-probe-*' -print)" ]]
  printf source > "$run/fallback-source"
  "$fallback" "$run/fallback-source" "$run/fallback-target"
  [[ ! -e "$run/fallback-source" && "$(cat "$run/fallback-target")" == source ]]
  printf keep > "$run/fallback-source"
  expect_status "$STATUS_COLLISION" "$fallback" "$run/fallback-source" "$run/fallback-target"
  [[ "$(cat "$run/fallback-source")" == keep && "$(cat "$run/fallback-target")" == source ]]
  rm -- "$run/fallback-source" "$run/fallback-target"
  mkdir "$run/fallback-source" "$run/fallback-target"
  target_identity="$(stat -c '%d:%i' "$run/fallback-target")"
  expect_status "$STATUS_COLLISION" "$fallback" "$run/fallback-source" "$run/fallback-target"
  [[ -d "$run/fallback-source" && "$(stat -c '%d:%i' "$run/fallback-target")" == "$target_identity" ]]
  rmdir -- "$run/fallback-target"
  "$fallback" "$run/fallback-source" "$run/fallback-target"
  [[ ! -e "$run/fallback-source" && -d "$run/fallback-target" ]]
  rmdir -- "$run/fallback-target"
  printf source > "$run/fallback-source"
  ln -s absent-target "$run/fallback-target"
  expect_status "$STATUS_COLLISION" "$fallback" "$run/fallback-source" "$run/fallback-target"
  [[ "$(cat "$run/fallback-source")" == source && "$(readlink "$run/fallback-target")" == absent-target ]]
  rm -- "$run/fallback-source" "$run/fallback-target"
  echo "ok - errno $injected_errno fallback probe, rename and nofollow collisions"
done
for injected_errno in 13 18 30; do
  refused="$run/refused-$injected_errno"
  compile_helper "$refused" "-DRUTORRENT_RENAME_TEST_ERRNO=$injected_errno"
  printf preserve > "$run/refused-source"
  # A real filesystem error never uses the fallback: both refuse as errors.
  expect_status "$STATUS_ERROR" "$refused" --probe "$run"
  expect_status "$STATUS_ERROR" "$refused" "$run/refused-source" "$run/refused-target"
  [[ "$(cat "$run/refused-source")" == preserve && ! -e "$run/refused-target" ]]
  rm -- "$run/refused-source"
  echo "ok - errno $injected_errno refuses without fallback"
done
fi

if [[ "$source_cuts" == true ]]; then
  # btrfs and WSL2 drvfs over 9p report one link for every directory. The probe
  # must still remove its own fixed entries and recover the residue it left.
  onelink_check="$run/onelink-check.c"
  printf '%s\n' '#include <fcntl.h>' '#include <stdio.h>' '#include <sys/stat.h>' \
    'int main(void) { struct stat entry; if (fstatat(AT_FDCWD, ".", &entry, 0) != 0) return 2;' \
    'printf("%lu\n", (unsigned long) entry.st_nlink); return 0; }' > "$onelink_check"
  cc -o "$run/onelink-check" "$onelink_check" "$root/tests/plugins/erasedata/OneLinkDirectoryCut.c" \
    -Wl,--wrap=fstatat,--wrap=fstat
  if [[ "$(cd "$run" && "$run/onelink-check")" != 1 ]]; then
    echo 'scope - this libc inlines fstatat, so the one-link directory cut cannot be linked here'
  else
    onelink_helper="$run/onelink"
    compile_helper "$onelink_helper" "$root/tests/plugins/erasedata/OneLinkDirectoryCut.c" \
      -Wl,--wrap=fstatat,--wrap=fstat
    onelink_parent="$run/onelink-parent"
    mkdir -m 0700 "$onelink_parent"
    probe_usable "$onelink_helper" "$onelink_parent" \
      || { echo 'one-link directories made the probe unusable' >&2; exit 1; }
    [[ -z "$(ls -A "$onelink_parent")" ]] || { echo 'the one-link probe left its workspace' >&2; exit 1; }
    residue="$onelink_parent/.rutorrent-rename-probe-11682-06ade481acbaf577"
    make_onelink_residue() {
      mkdir -m 0700 "$residue" "$residue/from-dir" "$residue/to-dir"
      (umask 077; : > "$residue/.lock"; : > "$residue/from-file"; : > "$residue/to-file")
    }
    # The residue captured on the owner's host after the old nlink=2 check refused.
    make_onelink_residue
    probe_usable "$onelink_helper" "$onelink_parent" \
      || { echo 'one-link probe residue blocked the next probe' >&2; exit 1; }
    [[ ! -e "$residue" ]] || { echo 'one-link probe residue was not recovered' >&2; exit 1; }
    # One link no longer proves emptiness: a subdirectory still refuses recovery.
    make_onelink_residue
    mkdir -m 0700 "$residue/from-dir/payload"
    expect_status "$STATUS_ERROR" "$onelink_helper" --probe "$onelink_parent"
    [[ -d "$residue/from-dir/payload" ]] || { echo 'recovery removed a subdirectory' >&2; exit 1; }
    rm -rf -- "$onelink_parent"
    echo 'ok - one-link directories: the probe cleans up, recovers its residue and refuses contents'
  fi
fi

if [[ $# == 0 ]]; then
  echo 'erasedata rename-noreplace: probes, usable fallback, files, directories, links and collisions passed'
else
  echo 'erasedata rename-noreplace: packaged probes, files, directories, links and collisions passed'
fi
