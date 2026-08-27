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

### The plugin is multi-tracker despite its name

`registerTracker()` carries seven handlers (RuTracker, Kinozal, NNMClub, Toloka, tfile, AniDUB, TapochekNet), and `update.php` -> `RuTrackerUpdatePass::run()` is the scheduler's only route into `ruTrackerChecker::run()` for all of them. RuTracker-specific machinery — `RuTrackerDetector::classify()`, the announce fuse, the forum-dump layers — must never decide whether a *foreign*-tracker torrent gets checked.

`classify()` inspects only tracker rows matching `TRACKER_PATTERN`, so it answers `'none'` for a torrent that has no RuTracker row. That verdict means "not my jurisdiction", not "no signal worth a request". Gating dispatch on it once stopped every non-RuTracker handler for a full deploy: 211 of 211 torrents checked in a cycle were RuTracker, and 122 seeding Kinozal/NNMClub torrents were never dispatched, freezing their `chk-state` at whatever the previous release had left. `UpdatePassTest.php` pins this.

When adding a layer that reads RuTracker signals, ask what it returns for a torrent from another tracker, and make sure that answer cannot suppress the dispatch. Symptom to watch for on a live instance: `chk-time` on non-RuTracker torrents stops advancing while RuTracker ones keep updating.

Only the scheduler goes through that pass. The manual "check for update" button (`action.php` -> `batch_check.php`) calls `ruTrackerChecker::run($hash)` directly, so it keeps working even when the pass drops a tracker — which makes it the quickest way to tell a broken handler apart from a broken dispatch.

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

## Verify Against the Live Service, Not Against the Specification

This rule exists because ignoring it silently broke NNMClub checking for every torrent.

`parseScrapeResult()` required a scrape row to carry all three of `complete`, `downloaded` and
`incomplete`. That requirement was derived from BEP-48 and never compared with a real answer. The
live hosts answer in 67 bytes with `complete` and `incomplete` and no `downloaded` key at all;
opentracker-derived trackers commonly omit it. An unambiguous "159 seeders hold this" therefore
became `SCRAPE_RESULT_FAILED`, was reported as an unreachable tracker, and **no NNMClub torrent was
ever checked again** — with nothing visible in the panel. Worse, the shipped fixture pinned that
exact answer as `FAILED`, so the test confirmed the regression instead of catching it.

Rules that follow:

- **A validator of a third-party answer must be pinned by a captured real response.** Not a
  hand-written fixture that encodes what the specification says the service should send. Name such
  tests so the provenance is obvious, e.g. `the live NNMClub scrape answer is accepted verbatim`.
- **Relax which fields are mandatory; never relax the checks on the fields that are present.**
  Type, canonicality, sign and duplicate checks still apply to every counter that appears.
  `RuTrackerAnnounceProbe::hasValidSuccessSchema()` is the reference shape: require only what the
  protocol guarantees, and validate each optional field only when it is there.
- **Keep sibling validators consistent.** The announce layer and the scrape layer of the same
  plugin must not disagree about how strict to be.
- When a rule comes from a spec, say so in a comment and say whether it was checked against a
  capture. "Provenance, so this is not reinstated" is a comment worth writing.

### Capturing a real answer

Read-only, and the passkey never reaches a file or the terminal:

```sh
# 1. an announce URL from the running instance -- never type a passkey by hand
python3 - <<'PY'
import re, subprocess, xmlrpc.client
gw = re.search(r'via (\d+\.\d+\.\d+\.\d+)', subprocess.run(
    ['ip','route','show','default'], capture_output=True, text=True).stdout).group(1)
srv = xmlrpc.client.ServerProxy('http://%s:8080/plugins/httprpc/action.php' % gw)
for h, urls in ((r[0], r[1]) for r in srv.d.multicall2('', 'main', 'd.hash=', 't.multicall=,t.url=')):
    u = next((x[0] for x in (urls or []) if x and 't-ru.org' in x[0]), None)
    if u: print(h, re.sub(r'(?i)(pk=)[0-9a-f]+', r'\1<PK>', u)); break
PY
```

The probe itself must be exactly what the plugin sends — `event=stopped&numwant=0&compact=1&left=0`
— so the capture describes the request the code actually makes. That announce is idempotent: it
removes a peer record that is not there.

Two captures worth keeping current: a live announce, and a superseded/live hash **pair**. The pair
proves both branches of the verdict at once. `rutorrent-app-errors.log` records replacements as
`metafetch: begin <old> -> <new>`, which is where such a pair comes from.

### What a differential test can and cannot prove

A differential over old-vs-new behaviour proves the rewrite was **faithful**. It cannot prove the
behaviour was **correct**, because the baseline is the thing under suspicion. A rewrite of the NNM
validator once passed a 56,839-payload differential showing every verdict preserved — which was
precisely the proof that a production regression had been carried forward into cleaner code.

Use a differential to guard a refactor. Use a captured real answer to guard a rule.

## Diagnostics: Classified Reason, Raw Transcript On Demand

Routine plugin logs carry a **classified** reason, not third-party text: `rpc-unknown`,
`unreadable-manifest`, `generation-mismatch`. That keeps the log greppable, keeps arbitrary remote
strings out of it, and stops a raw fault message being mistaken for a verdict.

That is not a loss of diagnostic power. The full request/response transcript is available on
demand, in core:

- `$rpcLogCalls` — `php/xmlrpc.php:99` logs every request.
- `$rpcLogFaults` — `php/xmlrpc.php:235` logs the request **and** the raw answer whenever a call
  faults on an `important` request.

So when a cause has to be found: turn on `$rpcLogFaults`, reproduce, read the transcript. When
writing plugin code: log the classification, not the payload.

Any refusal must be **either self-healing or visible**. A guard that fails closed, writes nothing
and has no path back is not fail-closed, it is a silent permanent stall — and this fork has shipped
two of those. If a corrupt persisted value can only be repaired by hand, the refusal must name the
document, the key and the consequence.

## Test Hygiene Traps Found The Hard Way

- **Do not trust the inode allocator.** Simulating "this file was replaced" with `unlink()` then
  recreate frees the inode, and an idle filesystem hands the same number straight back — measured
  as 1 distinct identity out of 6 inside the shipped image, against 6 of 6 on a busy host. Allocate
  the replacement **while the victim is still alive** and `rename()` it over the name, then assert
  that the two identities really differ. Production is not exposed to this because the erasedata
  capture protocol renames the entry into its private root, so the captured inode stays alive.
- **Compare test-name SETS across a merge, and check the inputs are non-empty.** A merge deletes
  tests silently when one side moved them and the other added to the original file; neither the
  conflict count nor the diffstat shows it. A `comm` over two files that were not written yet
  returns an empty difference that looks exactly like success.
- **`git ls-files -- '*.png'` matches the whole repository**, not the artifacts you meant. Name
  artifacts explicitly in hygiene gates.
- **`perl -0pi -e 's/.../.../'` interpolates `@name` as a Perl array** and silently corrupts PHP
  source — `@intval(...)` becomes `(...)`. Use `python3` for source rewrites, and re-read the
  region afterwards to confirm the change is what you intended.
- **A mutation that makes the suite fatal before the named test runs proves nothing.** Check the
  output for `PHP Fatal error` and confirm the named test actually executed and failed.
- **Validate a name extractor by COUNT, not by non-emptiness.** These suites register tests in
  three different ways — `$suite->test('name', ...)`, a local helper such as
  `fiStateTest($suite, 'name', ...)`, and `$suite->addFromObject(new XTest())` reflecting over
  `test*` methods. An extractor that knows only the first pulled 6 names out of a 24-test suite,
  passed the non-empty guard, and would have compared almost nothing. Assert that the names
  extracted **equal the registrations the file performs**; abort on any registration whose name is
  a variable unless it sits inside a helper you discovered, and abort on a duplicate name, because
  one hides the other. Watch that the helper's own `function` definition is not counted as a call.
- **The union of both sides is not the merge target.** When one side deliberately deleted a test
  because the behaviour it pinned was wrong, "every test on either side must survive" resurrects
  it. On the `lane-rutracker` merge the union was 654 pairs and the correct target was 653 — the
  extra one was `testInvalidPayloadIsReportedAsDeletedTopic`, whose whole point was the deletion
  semantics that `S05` had just corrected. Decide the target per suite from what each side
  *intended*, and write the exact number down before merging so the check afterwards is arithmetic
  rather than judgement.

## The Shipped Image As A Test Runtime

`ivanshift/rutorrent:latest` can run the suite directly, with the tree bind-mounted. No build, no
pull, no deploy — which keeps it usable even when image builds are out of scope:

```sh
docker run --rm --user 1000:1000 --network none --entrypoint sh \
  -v /path/to/checkout:/w -w /w/tests ivanshift/rutorrent:latest -c 'bash php-test.sh'
```

Know before you interpret the result:

- The image is Alpine with PHP 8.5 and **does not load `posix`, `pcntl` or `tokenizer`**. So
  `PermissionTest` and one other test fatal there, and every static-structure test that reads the
  source through `token_get_all()` fails — 8 of them in `EntrypointsTest`, with
  `Error: Call to undefined function token_get_all()`. None of it is a code fault: production calls
  no tokenizer function anywhere. The base commit fails identically — **always run the same suite
  on the base before calling a failure a regression**. That comparison is the only thing separating
  an image gap from a real one.
- **The scratchpad is a 1.7 GB tmpfs.** Two `git archive` exports of this repository filled it, and
  a full `/tmp` does not announce itself: `Write` returned `EDQUOT` and then *every* shell command,
  down to `true`, exited 1 with no output. If the shell starts failing universally, check `df`
  before anything else. Export trees somewhere under `/home`, and delete a finished agent's scratch
  tree rather than leaving it for the next one to trip over.
- `/`, `/tmp`, `/config` and `/data` inside a bare container reuse inode numbers after an unlink.
- The Dockerfile pins `RUTORRENT_REF` to a commit, so the image contains that revision, not the
  working tree. Bind-mounting is what makes it a runtime for local code.

`tasks/rt-lab.sh` raises a full instance against local code. Two traps it exists to avoid: bind
mounting the repo over `/rutorrent/app` hides everything the image added at build time, and
`conf/config.php` is tracked but generated by the entrypoint, so overlaying the repository copy
points ruTorrent at the wrong SCGI endpoint and surfaces as an HTTP 500 that looks like a code bug.

## Running The Fork Against A Chosen rTorrent Version

`tasks/rt-lab.sh` is the whole workflow. `up <image> <port> [name]` starts a container and
overlays the local working tree onto it, `sync <name>` re-overlays after an edit, `down <name>`
removes it. It copies only what `git ls-files` reports, so uncommitted work reaches the container
and untracked scratch does not, and it waits for `/run/rtorrent/rtorrent.sock` before calling
`/php/getplugins.php` -- a container is "healthy" long before rtorrent is listening.

To build an image with a different rTorrent, nothing in `docker-rutorrent/Dockerfile` needs
editing; it is already parameterised:

```sh
LT=$(git ls-remote https://github.com/rakshasa/libtorrent.git 'refs/tags/vX.Y.Z^{}' | awk '{print $1}')
RT=$(git ls-remote https://github.com/rakshasa/rtorrent.git   'refs/tags/vX.Y.Z^{}' | awk '{print $1}')
# the tags are lightweight: if ^{} prints nothing, use the tag's own SHA
docker build --build-arg LIBTORRENT_BRANCH=vX.Y.Z --build-arg LIBTORRENT_VERSION=$LT \
             --build-arg RTORRENT_BRANCH=vX.Y.Z   --build-arg RTORRENT_VERSION=$RT \
             -t rutorrent-rtXYZ:test .
```

The Dockerfile asserts the resolved commit against `*_VERSION` and fails on a mismatch, so a
successful build is itself proof the intended tag was compiled.

**Do not background that build with `nohup ... &`.** The wrapper exits immediately and reports
success while docker is still compiling; this was misread twice in one session. Wait on the
artifact instead:

```sh
until docker images -q rutorrent-rtXYZ:test | grep -q . \
   || ! pgrep -f 'docker build.*rutorrent-rtXYZ' >/dev/null; do sleep 10; done
```

Mutating probes belong here, never on the live instance. The live endpoint is for reads.

## Reading rTorrent's Command Surface

Do not answer "was this command removed" from release notes. Extract the registrations from the
tagged source and diff two tags:

```sh
grep -rhoE '\bCMD2?_[A-Z_0-9]+[[:space:]]*\([[:space:]]*"[^"]+"' src/ | sed -E 's/.*"([^"]+)"/\1/' | LC_ALL=C sort -u
```

Both macro families matter. Newer rtorrent registers with `CMD_*` as well as `CMD2_*`, plus
`CMD_REDIRECT` for legacy aliases; a grep for `CMD2_` alone invents dozens of phantom removals.

The extraction is a floor, never a total: per-category `system.sockets.<cat>.*` names and the
`string.*` safe-list are composed at runtime, so no literal scan can see them. Cross-check against
`system.listMethods` on a running daemon. Note also that names registered inside
`if (rpc::call_command_value("method.use_deprecated") == 1)` -- `schedule2`, `execute2` and friends
-- exist only under the `-D` flag and are absent from a stock daemon; `php/methods-0.16.0.php`
already maps around that.

`rpc.mark_safe()` is the list that decides what an `UNTRUSTED_CONNECTION` may call. A command
missing from it answers `Fault -507`, which is a policy refusal, not an outage.

## Merging A Long-Lived Branch That Moved Tests

A branch that **moves** tests out of a file silently deletes any tests that file gained after the
branch was cut. Git reports no conflict: the deletion side simply wins everywhere the other side
did not edit the same lines, so conflict count and diffstat both look reassuring.

Compare test-name SETS across the two sides before trusting a clean merge:

```sh
git show <theirs>:<newfile> | grep -o 'function test[A-Za-z0-9_]*' | LC_ALL=C sort > /tmp/a
git show master:<oldfile>   | grep -o 'function test[A-Za-z0-9_]*' | LC_ALL=C sort > /tmp/b
LC_ALL=C comm -23 /tmp/b /tmp/a      # anything here is about to disappear
```

`git log -1 -S<name> -- <oldfile>` then names the commit whose work is being reverted. Run this for
every file the branch moves or splits. A moved test can also encode the OLD contract of code that
changed on the other side, so read the survivors, do not just count them.

Squashing compounds this. When both sides squash, the merge base falls back to their last common
ancestor and git replays changes both already contain. Preserve the relationship by building the
squashed commit with `git commit-tree` and passing the upstream tip as a second parent: the history
still shows one commit under `--first-parent`, and `git merge upstream/master` stays clean.

## When A Metric Starts Shaping The Code

A line-count ceiling on a plugin was met twice by changing code the task never asked about: once by
merging three unrelated functions, which cost a diagnostic and shipped a 32-bit availability stall,
and once by trimming comments. Work that **adds** fail-closed boundaries, canonical parsers and
diagnostics legitimately adds lines.

Prefer structural targets that measure the thing actually wanted: one bencode grammar, one metainfo
parse, one canonical integer parser with no copies, one owner per safety primitive. Those cannot be
satisfied by compacting something else. If a numeric ceiling forces a change outside the task's
mandate, the ceiling is measuring the wrong thing — say so rather than paying it.
