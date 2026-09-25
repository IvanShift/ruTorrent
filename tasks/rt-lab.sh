#!/bin/sh
# rt-lab.sh -- run a ruTorrent container and overlay the LOCAL working tree onto it,
# so the daemon under test runs the code being edited right now (uncommitted included).
#
#   rt-lab.sh up   <image> <port> [name]   start, overlay, wait for rtorrent
#   rt-lab.sh sync <name>                  re-overlay after editing files locally
#   rt-lab.sh down <name>                  remove the container
#
# Why copy instead of `-v repo:/rutorrent/app`: a bind mount hides everything the image
# adds to that directory -- the plugins baked in at build time (geoip2, ratiocolor) and
# the conf/ the entrypoint generates on boot. Overlaying only the files git tracks keeps
# both: image extras survive, our edits win.
set -eu

# Default to the checkout this script is tracked in, so a fresh clone works without
# editing the file; REPO= still overrides it for an out-of-tree checkout.
REPO="${REPO:-$(cd "$(dirname "$0")/.." && pwd)}"
APP=/rutorrent/app

overlay() (
    name="$1"
    scratch=$(mktemp -d "${TMPDIR:-/tmp}/rt-lab.XXXXXX")
    trap 'rm -rf "$scratch"' EXIT HUP INT TERM
    files="$scratch/files.z"
    deleted="$scratch/deleted.z"
    previous="$scratch/previous.z"
    archive="$scratch/overlay.tar"
    container_archive="/tmp/$(basename "$scratch").tar"
    container_deleted="/tmp/$(basename "$scratch").deleted.z"
    container_files="/tmp/rt-lab-managed-$name.z"
    container_pending="$container_files.pending"
    # The pending inventory is written before extraction. If extraction or the
    # final rename fails, the next sync can still remove a staged addition.
    : > "$previous"
    for inventory in "$container_files" "$container_pending"; do
        # A missing manifest is normal on the first sync. A failed read of an
        # existing one must stop before changing the app.
        manifest_state=$(docker exec "$name" sh -c \
            'if [ -f "$1" ]; then printf present; elif [ -e "$1" ]; then printf invalid; else printf absent; fi' \
            _ "$inventory") || { echo "rt-lab: cannot check overlay manifest in $name" >&2; exit 1; }
        case "$manifest_state" in
            present)
                inventory_copy="$scratch/$(basename "$inventory")"
                docker cp "$name:$inventory" "$inventory_copy" || {
                    echo "rt-lab: cannot read overlay manifest in $name" >&2
                    exit 1
                }
                cat "$inventory_copy" >> "$previous"
                ;;
            absent) ;;
            *) echo "rt-lab: invalid overlay manifest in $name" >&2; exit 1 ;;
        esac
    done
    # conf/config.php and conf/users belong to the image at runtime. Track the
    # files we overlaid so a later staged or unstaged deletion removes only our
    # own former files, while image-added plugins and configuration survive.
    python3 - "$REPO" "$files" "$previous" "$deleted" <<'MANIFEST'
import os
import stat
import subprocess
import sys

repo, current_file, previous_file, deleted_file = sys.argv[1:]
excluded = [':!conf/config.php', ':!conf/users']
def git(*args):
    return subprocess.check_output(['git', '-C', repo, *args])
def paths(data):
    return set(filter(None, data.split(b'\0')))
def valid(path):
    return not path.startswith(b'/') and all(part not in (b'', b'.', b'..') for part in path.split(b'/'))

history_state = git('rev-parse', '--is-shallow-repository').strip()
if history_state == b'true':
    sys.exit('rt-lab: shallow Git history cannot identify stale image files; run git fetch --unshallow before sync')
if history_state != b'false':
    sys.exit('rt-lab: cannot verify complete Git history before sync')
tracked = paths(git('ls-files', '-z', *excluded))
def exportable(path):
    source = os.path.join(os.fsencode(repo), path)
    try:
        mode = os.lstat(source).st_mode
    except FileNotFoundError:
        return False
    return stat.S_ISREG(mode) or stat.S_ISLNK(mode)
current = {path for path in tracked if exportable(path)}
previous = paths(open(previous_file, 'rb').read()) if os.path.exists(previous_file) else set()
# A fresh container has no previous inventory, and the image does not expose a
# reliable ruTorrent commit SHA. Historical Git deletions cover older images;
# image-only paths survive unless they reuse a formerly Git-owned name. The
# current export wins for reintroduced paths. --no-renames makes the old side
# of both committed and staged renames a deletion.
removed_from_history = paths(git('log', '-m', '--format=', '--name-only',
                                 '--no-renames', '--diff-filter=D', '-z',
                                 'HEAD', '--', *excluded))
removed_from_head = paths(git('diff', '--no-renames', '--name-only',
                              '--diff-filter=D', '-z', 'HEAD', '--', *excluded))
removed = (previous | removed_from_history | removed_from_head) - current
if any(not valid(path) for path in current | removed):
    raise ValueError('rt-lab: unsafe tracked path in overlay manifest')
with open(current_file, 'wb') as stream:
    stream.write(b''.join(path + b'\0' for path in sorted(current)))
with open(deleted_file, 'wb') as stream:
    stream.write(b''.join(b'./' + path + b'\0' for path in sorted(removed)))
MANIFEST
    tar -C "$REPO" --null -T "$files" -cf "$archive"
    if [ -s "$deleted" ]; then
        docker cp "$deleted" "$name:$container_deleted"
        # A historical file path may be a directory in a newer image. Keep
        # that directory (and image-only children), while removing old files.
        docker exec -u root "$name" sh -c "cd $APP && xargs -0 -r -n 100 sh -ec 'for path do
            if [ -L \"\$path\" ] || [ ! -d \"\$path\" ]; then rm -f -- \"\$path\"; fi
        done' _ < $container_deleted && rm -f $container_deleted"
    fi
    docker cp "$archive" "$name":"$container_archive"
    # A failed manifest upload cannot leave an unrecorded newly overlaid file.
    docker cp "$files" "$name:$container_pending"
    docker exec -u root "$name" sh -c \
        'tar -C "$1" -xf "$2" && mv -f "$3" "$4" && rm -f "$2"' \
        _ "$APP" "$container_archive" "$container_pending" "$container_files"
    # The entrypoint runs everything as `torrent`; files arrive owned by root.
    docker exec -u root "$name" sh -c "chown -R torrent:torrent $APP" 2>/dev/null || true
    n=$(tr -cd '\0' < "$files" | wc -c)
    echo "overlaid $n tracked files from $REPO"
    ( cd "$REPO" && git status --porcelain | grep -v '^??' | sed 's/^/    uncommitted: /' ) || true
)

case "${1:-}" in
up)
    img="${2:?image}"; port="${3:?port}"; name="${4:-rt-lab}"
    docker rm -f "$name" >/dev/null 2>&1 || true
    docker run -d --name "$name" -p "$port":8080 "$img" >/dev/null
    # Wait for the daemon's socket, not just for the container: the entrypoint
    # generates config and starts rtorrent well after the container is "up".
    i=0
    while [ $i -lt 60 ]; do
        docker exec "$name" test -S /run/rtorrent/rtorrent.sock 2>/dev/null && break
        i=$((i+1)); sleep 1
    done
    overlay "$name"
    # A full page load is what registers the plugin schedules -- an already-running
    # daemon does not re-create them by itself.
    curl -fsS -o /dev/null "http://localhost:$port/php/getplugins.php" 2>/dev/null || true
    echo "ready: http://localhost:$port   rpc: http://localhost:$port/plugins/httprpc/action.php"
    docker exec "$name" rtorrent -h 2>&1 | head -1
    ;;
sync)
    overlay "${2:-rt-lab}"
    ;;
down)
    docker rm -f "${2:-rt-lab}" >/dev/null 2>&1 || true
    echo "removed ${2:-rt-lab}"
    ;;
*)
    sed -n '2,12p' "$0"; exit 1 ;;
esac
