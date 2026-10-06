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
# Source gates must retain the real crash cuts and EINVAL classifier variant.
source_cuts=true
if [[ $# == 1 ]] && ! command -v cc >/dev/null 2>&1; then
  source_cuts=false
  echo 'scope - packaged helper probes run; compiler crash cuts and EINVAL classifier require the source gate'
fi

"$binary" --probe "$run"

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
for index in {1..7}; do "$binary" --probe "$concurrent_parent" & concurrent_pids+=("$!"); done
if [[ "$source_cuts" == true ]]; then sleep 0.15; kill -CONT "$probe_pid"; fi
concurrent_failed=0
for pid in "${concurrent_pids[@]}"; do wait "$pid" || concurrent_failed=1; done
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
  "$binary" --probe "$run"
  [[ ! -e "$probe_root" ]]
done
mkdir -m 0700 "$probe_root"
printf 'unrelated bytes' > "$probe_root/unexpected"
if "$binary" --probe "$run" 2>"$run/unsafe-probe.stderr"; then
  echo 'unexpected probe workspace content was accepted' >&2; exit 1
fi
[[ "$(cat "$probe_root/unexpected")" == 'unrelated bytes' ]]
rm -- "$probe_root/unexpected"
printf 'keep bytes' > "$run/keep-probe"
ln -s "$run/keep-probe" "$probe_root/from-file"
if "$binary" --probe "$run" 2>"$run/unsafe-probe.stderr"; then
  echo 'linked probe artifact was accepted' >&2; exit 1
fi
[[ -L "$probe_root/from-file" && "$(cat "$run/keep-probe")" == 'keep bytes' ]]
rm -- "$probe_root/from-file"
mkdir -m 0700 "$probe_root/from-dir"
printf 'unknown nested bytes' > "$probe_root/from-dir/unexpected"
printf 'attested probe bytes' > "$probe_root/from-file"
chmod 0600 "$probe_root/from-file"
if "$binary" --probe "$run" 2>"$run/unsafe-probe.stderr"; then
  echo 'nonempty probe directory was accepted' >&2; exit 1
fi
[[ "$(cat "$probe_root/from-dir/unexpected")" == 'unknown nested bytes' ]]
[[ "$(cat "$probe_root/from-file")" == 'attested probe bytes' ]]
rm -- "$probe_root/from-dir/unexpected"
"$binary" --probe "$run"
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
"$binary" --probe "$run/crash-parent"
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
if "$binary" "$run/source-occupied" "$run/occupied"; then
  echo 'occupied destination was overwritten' >&2
  exit 1
fi
[[ -f "$run/source-occupied/from" && -f "$run/occupied/keep" ]]

# An ordinary rename would replace both of these destinations.
printf 'source' > "$run/file-collision-source"
printf 'keep' > "$run/file-collision-target"
if "$binary" "$run/file-collision-source" "$run/file-collision-target"; then
  echo 'occupied file was overwritten' >&2
  exit 1
fi
[[ "$(cat "$run/file-collision-target")" == keep ]]
[[ "$(cat "$run/file-collision-source")" == source ]]
mkdir "$run/empty-dir-source" "$run/empty-dir-target"
empty_identity="$(stat -c '%d:%i' "$run/empty-dir-target")"
if "$binary" "$run/empty-dir-source" "$run/empty-dir-target"; then
  echo 'empty destination directory was overwritten' >&2
  exit 1
fi
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

# Inject only the syscall refusal, keeping the production error classifier real.
if [[ "$source_cuts" == true ]]; then
compile_helper "$run/unsupported" -DRUTORRENT_RENAME_TEST_ERRNO=22
set +e
"$run/unsupported" --probe "$run" 2>"$run/unsupported.stderr"
unsupported_status=$?
set -e
[[ "$unsupported_status" == 3 ]]
[[ "$(cat "$run/unsupported.stderr")" == *rename-noreplace-unsupported* ]]
[[ -z "$(find "$run" -maxdepth 1 -name '.rutorrent-rename-probe-*' -print)" ]]
  echo 'ok - source compiler crash cuts and EINVAL refusal classifier'
fi

if [[ $# == 0 ]]; then
  echo 'erasedata rename-noreplace: probes, typed unsupported, files, directories, links and collisions passed'
else
  echo 'erasedata rename-noreplace: packaged probes, files, directories, links and collisions passed'
fi
