---
name: rutorrent-fork
description: Use when changing or reviewing the IvanShift ruTorrent fork in /home/dev/Documents/my_projects/ruTorrent, especially ruTorrent PHP, JavaScript, CSS, bundled plugins, rutracker_check, httprpc/xmlrpc compatibility, or behavior that the docker-rutorrent image consumes from this fork.
---

# IvanShift ruTorrent Fork

## Boundary

This repository is the active ruTorrent fork consumed by `/home/dev/Documents/my_projects/docker-rutorrent`.

Make ruTorrent behavior changes here:

- core PHP, JavaScript, CSS, and UI behavior
- bundled plugin fixes
- `plugins/rutracker_check`
- `plugins/httprpc`
- `php/xmlrpc.php`
- `php/Snoopy.class.inc`
- regression tests under `tests/`

Do not move active behavior fixes into `docker-rutorrent/overrides/rutorrent`; that overlay has been removed from the Docker build.

## rutracker_check

`plugins/rutracker_check/check.php` owns checker orchestration, state, and torrent replacement. Missing hashes during replacement are expected races. Handle them as early exits and suppress noisy non-critical XMLRPC faults where polling can race with erase/reload.

NNMClub behavior lives in `plugins/rutracker_check/trackers/nnmclub.php`; RuTracker behavior lives in `plugins/rutracker_check/trackers/rutracker.php`.

## Verification

Use focused checks first:

```sh
cd /home/dev/Documents/my_projects/ruTorrent/tests
npm test -- --runInBand tests/js/webui-stale-details.spec.js

cd /home/dev/Documents/my_projects/ruTorrent
node --check js/webui.js
php -l plugins/rutracker_check/check.php
php -l plugins/httprpc/action.php
```

If host PHP is missing, lint through the Docker image:

```sh
docker run --rm --entrypoint php85 \
  -v /home/dev/Documents/my_projects/ruTorrent:/src \
  -w /src ivanshift/rutorrent:latest \
  -l plugins/rutracker_check/check.php
```

The full Jest suite has unrelated existing failures in legacy specs. Do not claim it passes unless it has been run fresh and returns exit 0.

## Docker Handoff

The default `docker-rutorrent` build fetches `IvanShift/ruTorrent` from `refs/heads/master`. Push fork commits before expecting a normal Docker build to include them.
