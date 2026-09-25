#!/usr/bin/env bash
# Exercise the shipped overlay against a disposable Git tree and mock container.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
runner="${RT_LAB_RUNNER:-$root/tasks/rt-lab.sh}"
scratch="$(mktemp -d "${TMPDIR:-/tmp}/rt-lab-test.XXXXXX")"
trap 'rm -rf -- "$scratch"' EXIT
repo="$scratch/repo"
app="$scratch/app"
mkdir -p "$repo" "$app" "$scratch/bin" "$scratch/tmp" "$scratch/container-tmp"
git -C "$repo" init -q
git -C "$repo" config user.name 'rt-lab fixture'
git -C "$repo" config user.email 'fixture@example.invalid'
printf 'old\n' > "$repo/old.php"
printf 'keep\n' > "$repo/keep.php"
git -C "$repo" add old.php keep.php
git -C "$repo" commit -qm baseline
cp "$repo/old.php" "$app/old.php"
printf 'image extra\n' > "$app/image-extra.php"
printf 'updated\n' > "$repo/keep.php"
printf 'untracked\n' > "$repo/secret.php"
git -C "$repo" rm -q old.php
cat > "$scratch/bin/docker" <<'MOCK_DOCKER'
#!/usr/bin/env bash
set -euo pipefail
case "$1" in
    cp)
        source_path="${2#*:}"
        target_path="${3#*:}"
        if [[ "$source_path" == /tmp/rt-lab-managed-* && "${RT_LAB_TEST_FAIL_MANIFEST_CP:-}" == 1 ]]; then
            exit 17
        fi
        if [[ "$target_path" == /tmp/rt-lab-managed-* && "${RT_LAB_TEST_FAIL_MANIFEST_WRITE:-}" == 1 ]]; then
            exit 17
        fi
        # Map the container's /tmp into this fixture instead of writing to host /tmp.
        if [[ "$source_path" == /tmp/* ]]; then source_path="$RT_LAB_TEST_CONTAINER_TMP/${source_path#/tmp/}"; fi
        if [[ "$target_path" == /tmp/* ]]; then target_path="$RT_LAB_TEST_CONTAINER_TMP/${target_path#/tmp/}"; fi
        cp "$source_path" "$target_path"
        ;;
    exec)
        shift
        if [ "$1" = -u ]; then shift 2; fi
        shift # container name
        [ "$1" = sh ] && [ "$2" = -c ]
        command_text="$(printf '%s' "$3" | sed "s#/tmp/#$RT_LAB_TEST_CONTAINER_TMP/#g")"
        command_text="${command_text//\/rutorrent\/app/$RT_LAB_TEST_APP}"
        args=("${@:4}")
        for i in "${!args[@]}"; do
            args[$i]="${args[$i]//\/rutorrent\/app/$RT_LAB_TEST_APP}"
            if [[ "${args[$i]}" == /tmp/* ]]; then args[$i]="$RT_LAB_TEST_CONTAINER_TMP/${args[$i]#/tmp/}"; fi
        done
        if [[ "$command_text" == *'tar -C "$1" -xf "$2"'* && "${RT_LAB_TEST_FAIL_AFTER_EXTRACT:-}" == 1 ]]; then
            tar -C "${args[1]}" -xf "${args[2]}"
            exit 17
        fi
        sh -c "$command_text" "${args[@]}"
        ;;
    *) echo "unexpected docker command: $*" >&2; exit 1 ;;
esac
MOCK_DOCKER
chmod +x "$scratch/bin/docker"
lab_name="lab-$(basename "$scratch")"
run_lab() {
    REPO="$repo" RT_LAB_TEST_APP="$app" RT_LAB_TEST_CONTAINER_TMP="$scratch/container-tmp" \
        TMPDIR="$scratch/tmp" PATH="$scratch/bin:$PATH" "$runner" sync "$lab_name"
}
run_lab > "$scratch/first.log"
[ ! -e "$app/old.php" ] || { echo 'staged deletion survived first overlay' >&2; exit 1; }
[ "$(cat "$app/keep.php")" = updated ]
[ -e "$app/image-extra.php" ]
[ ! -e "$app/secret.php" ]
printf 'new\n' > "$repo/new.php"
git -C "$repo" add new.php
run_lab > "$scratch/second.log"
[ -e "$app/new.php" ]
git -C "$repo" rm -fq new.php
if RT_LAB_TEST_FAIL_MANIFEST_CP=1 run_lab > "$scratch/failed-manifest.log" 2>&1; then
    echo 'manifest read failure was silently accepted' >&2; exit 1
fi
[ -e "$app/new.php" ] || { echo 'failed sync changed the app before validating manifest' >&2; exit 1; }
run_lab > "$scratch/third.log"
[ ! -e "$app/new.php" ] || { echo 'previously overlaid staged addition survived deletion' >&2; exit 1; }
[ -e "$app/image-extra.php" ]
printf 'new2\n' > "$repo/new2.php"
git -C "$repo" add new2.php
if RT_LAB_TEST_FAIL_MANIFEST_WRITE=1 run_lab > "$scratch/failed-write.log" 2>&1; then
    echo 'manifest write failure was silently accepted' >&2; exit 1
fi
[ ! -e "$app/new2.php" ] || { echo 'failed manifest write still overlaid a file' >&2; exit 1; }
[ -n "$(find "$scratch/container-tmp" -maxdepth 1 -name 'rt-lab.*.tar' -print -quit)" ] || { echo 'fixture did not retain failed container archive in isolated tmp' >&2; exit 1; }
run_lab > "$scratch/fourth.log"
[ -e "$app/new2.php" ]
git -C "$repo" rm -fq new2.php
# Put old.php back in the index without restoring its working-tree file:
# git ls-files must still report it when the directory replaces that file.
git -C "$repo" reset -q HEAD -- old.php
[ "$(git -C "$repo" ls-files -- old.php)" = old.php ]
# A tracked file replaced by a directory must not recursively archive its untracked contents.
mkdir "$repo/old.php"
printf 'private\n' > "$repo/old.php/untracked-secret"
run_lab > "$scratch/fifth.log"
[ ! -e "$app/new2.php" ]
[ ! -e "$app/old.php/untracked-secret" ] || { echo 'tracked file type change archived an untracked directory' >&2; exit 1; }
[ -e "$app/image-extra.php" ]
printf 'new3\n' > "$repo/new3.php"
git -C "$repo" add new3.php
if RT_LAB_TEST_FAIL_AFTER_EXTRACT=1 run_lab > "$scratch/partial-extract.log" 2>&1; then
    echo 'failed extraction was silently accepted' >&2; exit 1
fi
[ -e "$app/new3.php" ] || { echo 'partial extraction fixture did not overlay the new file' >&2; exit 1; }
git -C "$repo" rm -fq new3.php
run_lab > "$scratch/recovery.log"
[ ! -e "$app/new3.php" ] || { echo 'pending inventory failed to remove an orphan overlay' >&2; exit 1; }
[ -e "$app/image-extra.php" ]
echo 'rt-lab-test.sh: staged and prior-overlay deletions, failed manifest publish, partial extraction recovery, file-to-directory type change, and image extras passed'
