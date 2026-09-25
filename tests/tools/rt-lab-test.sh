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
printf 'committed obsolete\n' > "$repo/committed-obsolete.php"
printf 'renamed\n' > "$repo/rename-old.php"
printf 'committed rename\n' > "$repo/committed-rename-old.php"
printf 'revived in image\n' > "$repo/revived.php"
printf 'old file shape\n' > "$repo/dir-shape"
mkdir -p "$repo/conf/users" "$app/conf/users" "$app/dir-shape"
printf 'image child\n' > "$app/dir-shape/image-extra.txt"
printf 'runtime config\n' > "$repo/conf/config.php"
printf 'runtime user\n' > "$repo/conf/users/user.php"
git -C "$repo" add old.php keep.php committed-obsolete.php rename-old.php committed-rename-old.php revived.php dir-shape conf
git -C "$repo" commit -qm baseline
cp "$repo/old.php" "$app/old.php"
cp "$repo/committed-obsolete.php" "$app/committed-obsolete.php"
cp "$repo/rename-old.php" "$app/rename-old.php"
cp "$repo/committed-rename-old.php" "$app/committed-rename-old.php"
cp "$repo/revived.php" "$app/revived.php"
cp "$repo/conf/config.php" "$app/conf/config.php"
cp "$repo/conf/users/user.php" "$app/conf/users/user.php"
git -C "$repo" rm -q committed-obsolete.php revived.php dir-shape conf/config.php conf/users/user.php
git -C "$repo" mv committed-rename-old.php committed-rename-new.php
git -C "$repo" commit -qm 'remove obsolete files after image baseline'
git -c diff.renames=true -C "$repo" show --format= --name-status HEAD | grep -q $'R100\tcommitted-rename-old.php\tcommitted-rename-new.php' || {
    echo 'committed rename fixture was not detected as a rename' >&2; exit 1
}
printf 'revived in checkout\n' > "$repo/revived.php"
mkdir "$repo/dir-shape"
printf 'new tracked child\n' > "$repo/dir-shape/new.php"
git -C "$repo" add revived.php dir-shape/new.php
git -C "$repo" mv rename-old.php rename-new.php
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
run_lab_at() {
    REPO="$1" RT_LAB_TEST_APP="$app" RT_LAB_TEST_CONTAINER_TMP="$scratch/container-tmp" \
        TMPDIR="$scratch/tmp" PATH="$scratch/bin:$PATH" "$runner" sync "$lab_name"
}
run_lab() { run_lab_at "$repo"; }
run_lab > "$scratch/first.log"
[ ! -e "$app/old.php" ] || { echo 'staged deletion survived first overlay' >&2; exit 1; }
[ ! -e "$app/rename-old.php" ] || { echo 'staged rename left the old path in the overlay' >&2; exit 1; }
[ ! -e "$app/committed-obsolete.php" ] || { echo 'committed deletion survived first overlay' >&2; exit 1; }
[ ! -e "$app/committed-rename-old.php" ] || { echo 'committed rename left the old path in the overlay' >&2; exit 1; }
[ "$(cat "$app/committed-rename-new.php")" = 'committed rename' ] || { echo 'committed rename did not overlay the new path' >&2; exit 1; }
[ -e "$app/rename-new.php" ] || { echo 'staged rename did not overlay the new path' >&2; exit 1; }
[ "$(cat "$app/revived.php")" = 'revived in checkout' ] || { echo 'reintroduced tracked path was removed' >&2; exit 1; }
[ "$(cat "$app/dir-shape/image-extra.txt")" = 'image child' ] || { echo 'historical deletion removed an image-only directory child' >&2; exit 1; }
[ "$(cat "$app/dir-shape/new.php")" = 'new tracked child' ] || { echo 'file-to-directory replacement failed' >&2; exit 1; }
[ "$(cat "$app/conf/config.php")" = 'runtime config' ] || { echo 'runtime config was removed' >&2; exit 1; }
[ "$(cat "$app/conf/users/user.php")" = 'runtime user' ] || { echo 'runtime user was removed' >&2; exit 1; }
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
# A failed rm in an xargs batch must not be hidden by a later successful rm.
printf 'first delete\n' > "$repo/a-denied.php"
printf 'later delete\n' > "$repo/z-removed.php"
git -C "$repo" add a-denied.php z-removed.php
run_lab > "$scratch/before-delete-error.log"
git -C "$repo" rm -fq a-denied.php z-removed.php
cat > "$scratch/bin/rm" <<'MOCK_RM'
#!/usr/bin/env bash
for arg in "$@"; do
    if [ "$arg" = ./a-denied.php ]; then exit 17; fi
done
exec "$RT_LAB_TEST_REAL_RM" "$@"
MOCK_RM
chmod +x "$scratch/bin/rm"
if RT_LAB_TEST_REAL_RM="$(command -v rm)" run_lab > "$scratch/delete-error.log" 2>&1; then
    echo 'first deletion failure was masked by a later successful rm' >&2; exit 1
fi
[ -e "$app/a-denied.php" ] && [ -e "$app/z-removed.php" ] || {
    echo 'failed delete published or continued the overlay' >&2; exit 1
}
rm "$scratch/bin/rm"
run_lab > "$scratch/delete-recovery.log"
[ ! -e "$app/a-denied.php" ] && [ ! -e "$app/z-removed.php" ] || {
    echo 'failed deletion did not recover on next sync' >&2; exit 1
}
# A shallow checkout cannot establish what an older image still contains.
git clone -q --depth=1 "file://$repo" "$scratch/shallow"
[ "$(git -C "$scratch/shallow" rev-parse --is-shallow-repository)" = true ] || {
    echo 'shallow fixture unexpectedly has full history' >&2; exit 1
}
keep_before="$(cat "$app/keep.php")"
if run_lab_at "$scratch/shallow" > "$scratch/shallow.log" 2>&1; then
    echo 'shallow history was accepted for a first overlay' >&2; exit 1
fi
grep -q 'rt-lab: shallow Git history' "$scratch/shallow.log" || {
    cat "$scratch/shallow.log" >&2
    echo 'shallow refusal lacked an actionable reason' >&2; exit 1
}
[ "$(cat "$app/keep.php")" = "$keep_before" ] || {
    echo 'shallow refusal changed the app' >&2; exit 1
}
# A failed history lookup must stop before destructive overlay operations.
cat > "$scratch/bin/git" <<'MOCK_GIT'
#!/usr/bin/env bash
if [[ " $* " == *' log '* ]]; then
    : > "$RT_LAB_TEST_HISTORY_MARKER"
    exit 17
fi
exec "$RT_LAB_TEST_REAL_GIT" "$@"
MOCK_GIT
chmod +x "$scratch/bin/git"
if RT_LAB_TEST_REAL_GIT="$(command -v git)" RT_LAB_TEST_HISTORY_MARKER="$scratch/history-attempted" \
    run_lab > "$scratch/history-error.log" 2>&1; then
    echo 'failed Git history lookup was silently accepted' >&2; exit 1
fi
[ -e "$scratch/history-attempted" ] || { echo 'history failure fixture did not reach git log' >&2; exit 1; }
[ -e "$app/image-extra.php" ] && [ -e "$app/keep.php" ] || {
    echo 'failed history lookup changed the app' >&2; exit 1
}
echo 'rt-lab-test.sh: staged and committed deletions, staged and committed renames, prior-overlay deletions, failed manifest publish, partial extraction recovery, file-to-directory type change, and image extras, runtime conf, revived files, and deletion/history failures passed'
