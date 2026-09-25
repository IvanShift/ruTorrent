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
    archive="$scratch/overlay.tar"
    container_archive="/tmp/$(basename "$scratch").tar"
    # git-tracked files only, plus anything modified but not yet committed. Untracked
    # scratch (task notes, logs, images) must not reach the container.
    # conf/config.php is TRACKED in the repo but the entrypoint GENERATES its own on
    # boot, pointing at the unix socket the image runs rtorrent on. Overlaying the
    # repo copy replaces that with the repo default (TCP 127.0.0.1:5000) and every
    # RPC call then fails with "cannot reach rtorrent ... Connection refused".
    # Same for conf/users/, which holds per-profile state the container owns.
    ( cd "$REPO" && git ls-files -z ':!conf/config.php' ':!conf/users' ) > "$files"
    tar -C "$REPO" --null -T "$files" -cf "$archive"
    docker cp "$archive" "$name":"$container_archive"
    docker exec -u root "$name" sh -c "tar -C $APP -xf $container_archive && rm -f $container_archive"
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
