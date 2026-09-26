#!/usr/bin/env bash
set -euo pipefail

root="$(CDPATH= cd -- "$(dirname -- "$0")/../../.." && pwd)"
run="$(mktemp -d "${TMPDIR:-/tmp}/erasedata-noreplace.XXXXXX")"
trap 'rm -rf -- "$run"' EXIT

cc -std=c11 -O2 -Wall -Wextra -Werror \
  -o "$run/rename-noreplace" "$root/plugins/erasedata/rename-noreplace.c"

mkdir "$run/source-dir"
: > "$run/source-dir/child"
original="$(stat -c '%d:%i' "$run/source-dir")"
"$run/rename-noreplace" "$run/source-dir" "$run/restored-dir"
[[ ! -e "$run/source-dir" ]]
[[ "$(stat -c '%d:%i' "$run/restored-dir")" == "$original" ]]
[[ -f "$run/restored-dir/child" ]]

mkdir "$run/source-occupied" "$run/occupied"
: > "$run/source-occupied/from"
: > "$run/occupied/keep"
if "$run/rename-noreplace" "$run/source-occupied" "$run/occupied"; then
  echo 'occupied destination was overwritten' >&2
  exit 1
fi
[[ -f "$run/source-occupied/from" && -f "$run/occupied/keep" ]]

: > "$run/source-file"
file_identity="$(stat -c '%d:%i' "$run/source-file")"
"$run/rename-noreplace" "$run/source-file" "$run/restored-file"
[[ ! -e "$run/source-file" ]]
[[ "$(stat -c '%d:%i' "$run/restored-file")" == "$file_identity" ]]

ln -s missing-target "$run/source-link"
"$run/rename-noreplace" "$run/source-link" "$run/restored-link"
[[ ! -L "$run/source-link" ]]
[[ "$(readlink "$run/restored-link")" == missing-target ]]

echo 'erasedata rename-noreplace: directory, file, link and occupied destination passed'
