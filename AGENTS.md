# Agent Context - IvanShift ruTorrent Fork

## Project Overview

This repository is the active IvanShift ruTorrent fork used by the Docker image in `/home/dev/Documents/my_projects/docker-rutorrent`.

Implement ruTorrent PHP, JavaScript, CSS, and bundled plugin behavior changes here. Do not put active ruTorrent behavior patches in `docker-rutorrent/overrides/rutorrent`; that overlay has been removed from the Docker build.

The local Codex skill for this repository is `.codex/skills/rutorrent-fork/SKILL.md`. Use it together with this file when working on fork behavior or deciding whether Docker image checks are needed.

## Repository Boundary

This repository owns:

- ruTorrent core PHP, JavaScript, CSS, and UI behavior
- bundled ruTorrent plugins and plugin fixes
- `plugins/rutracker_check`, including RuTracker and NNMClub update detection
- rTorrent/httprpc/xmlrpc compatibility code that belongs inside ruTorrent
- regression tests for ruTorrent behavior under `tests/`

`/home/dev/Documents/my_projects/docker-rutorrent` owns:

- Dockerfile dependency pins and image build stages
- rTorrent/libtorrent/PHP/nginx/s6 runtime configuration
- `rootfs/` startup scripts and `/config` migration behavior
- build-time fetching of third-party plugins such as `geoip2` and `ratiocolor`
- image-level smoke tests after this fork has the intended ruTorrent change

## rutracker_check Notes

The active tracker checker lives in `plugins/rutracker_check/`.

- `check.php` owns checker orchestration, state handling, and torrent replacement through `createTorrent()`.
- `trackers/nnmclub.php` implements NNMClub direct scrape, guest `.torrent` download, and passkey patching.
- `trackers/rutracker.php` implements RuTracker update detection and download fallback handling.

Stale hash races are normal during torrent replacement: the old hash can disappear while UI or plugin polling is still in flight. Treat missing hashes as an early-exit condition, not as an exceptional XMLRPC failure.

## Build & Test

Useful focused checks:

```sh
cd /home/dev/Documents/my_projects/ruTorrent/tests
npm test -- --runInBand tests/js/webui-stale-details.spec.js

cd /home/dev/Documents/my_projects/ruTorrent
node --check js/webui.js
php -l plugins/rutracker_check/check.php
php -l plugins/httprpc/action.php
```

Host-side PHP may be unavailable in this environment. In that case, lint changed PHP files through the Docker image:

```sh
docker run --rm --entrypoint php85 \
  -v /home/dev/Documents/my_projects/ruTorrent:/src \
  -w /src ivanshift/rutorrent:latest \
  -l plugins/rutracker_check/check.php
```

The full Jest suite currently has unrelated existing failures in some legacy specs. Prefer focused tests plus syntax checks unless the task is specifically to repair the test suite.

## Upstream Sync

Inspect the dirty tree before merging, but do not require a stash merely because unrelated user changes exist. Preserve dirty files in place when upstream does not touch those paths; stop and protect the changes only when the merge overlaps them.

When the user explicitly prefers upstream conflict resolution, `git merge -X theirs upstream/master` may encode that textual policy. Always inspect the resulting shared files and run focused tests afterward: valid fork-only compatibility and race handling can live in the same file as a correct upstream fix.

## Upstream PR Handoff

When a ruTorrent fix should be proposed to upstream `Novik/ruTorrent`, do not open the PR from `IvanShift/master` or from a local merge commit. This fork carries Docker handoff, `rutracker_check`, and other fork-specific history that should not leak into upstream PRs.

Create a clean upstream branch from the intended upstream base, usually `upstream/master`, then apply only the upstreamable patch:

```sh
git fetch upstream master
git switch -c upstream-<short-fix-name> upstream/master
```

Before pushing or opening the PR, inspect `git diff --stat upstream/master..HEAD` and `git diff --name-status upstream/master..HEAD`. The PR diff should contain only upstream-owned ruTorrent files and focused tests; exclude this fork's `AGENTS.md`, `.codex/`, `plugins/rutracker_check`, Docker-specific notes, and merge commits unless upstream explicitly asked for them.

Push the clean branch to `IvanShift/ruTorrent` and open the compare against `Novik/ruTorrent:<base>`. Keep the fork's `master` push/merge workflow separate from upstream PR preparation.

## Code Documentation

Keep new and updated code comments, inline documentation, and test assertion messages in English. Add concise comments for non-obvious compatibility gates, locking/race handling, XMLRPC quirks, or cross-repository handoff assumptions; avoid comments that merely restate straightforward code.

## Change Workflow

1. Make ruTorrent behavior changes in this repository.
2. Add or update the smallest focused regression test where practical.
3. Run focused JS/PHP checks here.
4. Commit and push this fork before relying on the default Docker build, because `docker-rutorrent` fetches `IvanShift/ruTorrent` from `refs/heads/master`.
5. Run Docker image checks from `/home/dev/Documents/my_projects/docker-rutorrent` only after the fork contains the intended change.
