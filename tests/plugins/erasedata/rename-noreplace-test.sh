#!/usr/bin/env bash
set -euo pipefail

root="$(CDPATH= cd -- "$(dirname -- "$0")/../../.." && pwd)"
run="$(mktemp -d "${TMPDIR:-/tmp}/erasedata-noreplace.XXXXXX")"
trap 'rm -rf -- "$run"' EXIT

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

"$binary" --probe "$run"

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
if [[ $# == 0 ]]; then
compile_helper "$run/unsupported" -DRUTORRENT_RENAME_TEST_ERRNO=22
set +e
"$run/unsupported" --probe "$run" 2>"$run/unsupported.stderr"
unsupported_status=$?
set -e
[[ "$unsupported_status" == 3 ]]
[[ "$(cat "$run/unsupported.stderr")" == *rename-noreplace-unsupported* ]]
[[ -z "$(find "$run" -maxdepth 1 -name '.rutorrent-rename-probe-*' -print)" ]]
fi
if [[ $# == 0 ]]; then
  echo 'erasedata rename-noreplace: probes, typed unsupported, files, directories, links and collisions passed'
else
  echo 'erasedata rename-noreplace: packaged probes, files, directories, links and collisions passed'
fi
