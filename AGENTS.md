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

- **Ownership is declared, never inferred from the loose comment filter.** `run()` asks the
  handlers whose comment filter matches, in registration order, and moves on when one declines
  -- so the first filter that matches is who is *asked*, not who *owns*. Kinozal's `/kinozal\./`
  matches an NNMClub topic URL that carries `source=kinozal.tv`, Kinozal declines it, NNMClub
  takes it. A scheduler-side shortcut that cannot afford to ask (the announce free pass) must
  therefore use `ruTrackerChecker::ownerOf()`, which walks the same order but decides by each
  handler's declared `topicPattern` -- the exact test the handler applies before it does
  anything -- and answers null the moment it reaches a handler that declared none. A handler
  opts into the free pass with BOTH its authoritative host list and its topic pattern, and
  Kinozal keeps that pattern in one constant used by its handler and its registration. Measured
  2026-09-14: the first-match rule handed Kinozal's cross-seed announce the verdict on an NNMClub
  topic (`checked=0 uptodate=1`); the declared rule sends it to the dispatcher. This is the same
  class of defect upstream #3205 fixed in loginmgr -- an identity read off a substring of the URL
  string instead of its parsed host -- and #3206 is the reminder that a `(\.|\/)` anchor still
  admits a path segment: a topic pattern anchors at `^https?://<host>/`, so a host in userinfo
  (`kinozal.tv@evil.test`) or as a leading label (`kinozal.tv.evil.test`) does not match.
- **One host test: `php/urlhost.php`.** "Is this URL's host one of ours?" is answered by
  `UrlHost::of()` / `isOneOf()` / `urlIsOneOf()` and nowhere else: loginmgr's `urlAddresses()`
  delegates to it, `RuTrackerDetector::isTrackerHost()`/`isTrackerRow()` and
  `RuTrackerAnnounce::hostKey()` go through it, and a registry authority is a plain host list
  the helper matches whole. It parses the host out with `parse_url()` and folds case and the
  root dot before comparing, so the traps that each of those places had once fallen into --
  the name in the path or query, in the userinfo, as a leading label, or spelled with a
  trailing dot -- are pinned once, in `tests/php/UrlHostTest.php`. Do not write a fourth
  anchored regex; hand the helper a list. A predicate that needs more than the host (Kinozal's
  topic pattern wants the path and the id too) keeps its own anchored regex, anchored at
  `^https?://<host>/`.

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

Before pushing or opening the PR, inspect `git diff --stat upstream/master..HEAD` and `git diff --name-status upstream/master..HEAD`. The PR diff should contain only upstream-owned ruTorrent files and focused tests; exclude this fork's `AGENTS.md`, `.codex/`, Docker-specific notes, unrelated fork changes, and merge commits unless upstream explicitly asked for them.

`plugins/rutracker_check` also exists upstream. Self-contained fixes to it may be proposed separately when they are verified against the upstream base and do not depend on unfinished fork work. The full fork plugin is reserved for a separate handoff after it is complete; do not import it wholesale while preparing smaller PRs.

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
  `RuTrackerAnnounce::hasValidSuccessSchema()` (`plugins/rutracker_check/announce.php`) is the
  reference shape: require only what the protocol guarantees, and validate each optional field
  only when it is there. One grep finds its single definition; the line number this citation used
  to carry went stale in the very next commit, so do not add one back.
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

- `$rpcLogCalls` — `rXMLRPCRequest::send()` in `php/xmlrpc.php` logs every request it sends and
  the raw answer it came back with. Callers that reach `rSCGITransport::send()` directly, such
  as `rpc2.php`, do not pass through it.
- `$rpcLogFaults` — `rXMLRPCRequest::run()` in the same file logs the request **and** the raw
  answer whenever a call faults on an `important` request.

So when a cause has to be found: turn on `$rpcLogFaults`, reproduce, read the transcript. When
writing plugin code: log the classification, not the payload.

Any refusal must be **either self-healing or visible**. A guard that fails closed, writes nothing
and has no path back is not fail-closed, it is a silent permanent stall — and this fork has shipped
two of those. If a corrupt persisted value can only be repaired by hand, the refusal must name the
document, the key and the consequence.

## This Machine Will Lie To You About Test Results

Three separate review rounds on 2026-09-05 reported suite exit codes and failure counts that did
not reproduce. None was a code defect; all four causes are environmental and all four are still
here unless someone fixed them. **Re-run a number before you quote it.** Verdicts (accept /
changes-required) have held up well in review; measured numbers have not.

- **The test fixtures leak one server process per call, and it is invisible.** `proc_open()` given a
  STRING command runs it through `/bin/sh`, and this shell FORKS rather than execs (measured: 6 of 6
  trials, php as the shell's child), so `proc_terminate()` signals the shell and the real server
  keeps its port. `tests/php/SCGITransportTest.php` and `tests/php/SCGITransportFixture.php` prefix
  the command with `exec ` for exactly this reason -- if you write a new long-lived fixture process,
  do the same. One session accumulated 785 orphaned `php -S` processes this way and then every
  unrelated shell command started failing with no output.
  The leaked servers carry rutorrent-scgi-rpc2 in their command line -- spelled plainly here so
  that grepping this file for the name you just saw in `ps` lands on this paragraph.
  Check with `pgrep -cf '[r]utorrent-scgi-rpc2'`; a healthy idle machine answers 0, and `pgrep -c`
  exits 1 when it counts nothing, so do not chain it with `&&`. The brackets are what keep the
  checking command out of its own match: an agent's Bash tool passes the whole command as a
  single argv element, so the unbracketed pattern finds that wrapper and answers 1 on an idle
  machine (measured both ways here). Read a non-zero count with `pgrep -af` before killing
  anything, and kill by PID.
  **The fix must reach every branch.** It was made on one line while another had already branched
  from an older master, and the unfixed line leaked 186 servers over six hours before anyone noticed.

- **`TasksMax` is a percentage of `kernel.threads-max`, and both were low.** The systemd default for
  a user slice is `TasksMax=33%`. With `kernel.threads-max=12093` that is 3990 tasks -- and VS Code
  alone holds ~600 threads, Firefox ~350. A full `php-test.sh` run forks a process per test file, so
  a few concurrent runs hit the ceiling, `fork()` fails, and the shell dies with exit 1 and NO
  output. That is the signature: a command that returns nothing at all, not an error message.
  Diagnose with `systemctl show user-$(id -u).slice -p TasksMax -p TasksCurrent`.

- **`/tmp` is a variable-size tmpfs because this VM has Hyper-V Dynamic Memory.** `tmp.mount` ships
  `size=50%`, and a percentage is evaluated ONCE, at mount time. This VM boots with the balloon
  holding RAM down to about 3.3 GB, so 50% became 1736012k; the balloon then grows RAM to 38 GB and
  the tmpfs cap never moves. `hv_balloon` in `lsmod` and `auto_online_blocks=online` are the tell.
  Two reboots confirmed it on different numbers: a boot that started with ~3.3 GB produced
  `size=1736012k` (1.7 GiB), and the next one, which started with ~9 GB, produced `size=4701512k`
  (4.5 GiB) -- same rule, different starting RAM, and in both cases exactly half of it. So the size
  of `/tmp` varies from boot to boot and cannot be relied on.
  **On this host a percentage is always wrong -- use an absolute size.** Several suites write 64 MiB
  fixtures there
  (`SCGITransportTest`, the retrackers bounded-reader cases), and once it fills, a dozen unrelated
  suites fail on writes they never expected to fail. `tests/php-test.sh` warns when the temp
  filesystem has under 512 MiB free; `.git/hooks/pre-commit` points TMPDIR at `~/.cache/rutorrent-tmp`.
  **Always `export TMPDIR=~/.cache/rutorrent-tmp/<label>` before a suite run**, and never put two
  full `git archive` exports in `/tmp`.

- **Concurrent agents contaminate each other's runs.** Suites key scratch directories off `/tmp` and
  the PID, so two campaigns running at once collide. A reviewer measured a "5 failures in 8 runs"
  race that the next reviewer reproduced as 1 in 12. If a failure matters, reproduce it against a
  clean `git archive` export of the base commit under the SAME conditions before calling it a
  regression.

## PHP Suite Timing and the Matrix

On an idle host after the wait reductions (2026-09-15), the sequential PHP leg has
85 files and takes about 90 seconds. These are approximate timings; measure
again after changing a slow suite:

| file | seconds |
|---|---:|
| `tests/plugins/erasedata/RemoveWithDataTest.php` | ~29 |
| `tests/plugins/retrackers/UpdateTest.php` | ~36 |
| the other 83 files together | ~26 |
| **total** | **~90** |

The 2026-09-07 baseline was 201 seconds over 81 files; the erasedata and
retrackers files then took 117.6 and 48.7 seconds. The wait reductions below
explain why that historical table no longer describes the current gate.

Current guidance:

- **The hook is not ten minutes.** `.git/hooks/pre-commit` runs the suite and nothing
  else. Runs that took ten minutes here were contended -- agents, containers and a second
  suite over the same tree, all at once. Before optimising it, check what else is running:
  `uptime` and `pgrep -cf '[b]ash php-test.sh'`.
- **Parallelising files has a different ceiling now.** `tests/php-test.sh`
  runs 85 independent PHP files sequentially. With a ~90-second leg and a
  slowest file around 36 seconds, perfect file-level parallelism would be
  bounded by that file, before process startup and contention. The matrix
  below parallelises PHP versions, not files within a leg. `TaskTest.php`
  needs isolation before parallel file execution inside a container.
- **The lever inside the first file was one constant.** Per-method timing (2026-09-14) put
  110 of its 117 s in ten real producer children, each waiting the whole 11 s
  `ERASEDATA_DRAIN_ACK_TIMEOUT` for a scheduler the mirror never plays, as SETUP for a case
  about a held lock, a racing worker or a restart. `ErasedataProductionMirror::
  shortenAcknowledgementWait()` hands the children a shorter wait through `-d
  auto_prepend_file`: the plugin's constants are `if(!defined())`-guarded and the children
  are the real entry points, so nothing in production changes and the mirror still verifies
  it runs the shipped bytes. Nine setup-only cases use it; the file is about 29 s. The
  scheduler family keeps 11 s because the wait, and what answers inside it, is its subject --
  do not shorten those, and time the file per method before touching anything else in it (feed the
  file plus the runner to `php -c php-test.ini` on stdin and clock the `>>method>>` markers).
- **The second file's were two literals in the plugin.** `RetrackersLifecycleCoordinator::
  done()` gave active workers a hard-coded 5 s before `hook-teardown-pending`, and the
  recovery coordinator re-read a delayed receipt every hard-coded 250 ms; five in-process
  cases paid 26 s for outcomes that do not depend on either length. Both are now
  `if(!defined())` constants at the top of `plugins/retrackers/update.php`
  (`RETRACKERS_TEARDOWN_TIMEOUT` 5.0 s, `RETRACKERS_RECEIPT_POLL` 0.25 s, the shipped values),
  and `UpdateTest.php` declares them short before it loads the plugin. The file is 36 s; what
  is left is real work (128 MiB fixtures, a child PHP per case that loads the 670 KB test
  file) rather than waiting. `testTask5All165PreTaskPublicMethodsRemainOnePassRunnerReachable`
  freezes the public method list of that file: a new case there goes into its `$added` list
  and its count, or the guard fails with a message that does not name the new method.
- **Two more courtesies, 9 s.** toloka's handler slept a literal 5 s before each fetch for
  Cloudflare's sake, and `SiblingTrackersTest` paid it once; it is `TOLOKA_CLOUDFLARE_PAUSE`
  now, `if(!defined())` with the shipped 5, declared 0 by the suite before the require.
  `CheckerTest` evals `ruTrackerChecker` out of `check.php` and its two rollback cases exhaust
  `waitForLoad` on purpose, 40 polls 50 ms apart; the suite now edits that one constant to
  1 ms in the evaled text and asserts the edit landed, so a renamed constant fails loudly
  rather than restoring the wait. The rule behind all of these: a wait that is a courtesy to
  something outside the test (a daemon, a tracker) may be declared short; a wait that the
  case observes something inside of may not. `ManualEntrypointsTest` keeps its 1 s windows
  for that reason -- they are how it proves that no child was launched.

If someone does parallelise it, one file has to be handled first: `plugins/_task/TaskTest.php`
runs `kill -9` over every child of PID 1 (see the entry above). On this host that is harmless
-- 46 of 47 children answer EPERM -- but inside a container, where every process shares one
uid, it would kill the sibling test processes. Everything else is already safe for it: all
six suites that open sockets build unique paths from `uniqid()`/`getmypid()` and none binds a
fixed port, and `PermissionTest` stopped writing fixtures into the checkout on 2026-09-06.

The cheapest win is not speed at all: **skip the run when nothing it tests has changed.**
Four commits on 2026-09-06 touched only Markdown under `tasks/` and each paid the full suite.
An input digest compared against the last green run makes such Markdown-only
changes free.

Both of those exist now (2026-09-14), local to this checkout like the hook itself:

- **`tasks/matrix.sh`** runs four legs in parallel: local PHP, `php:8.1-cli`,
  `php:7.4-cli` (the CI version floor, with a different extension/configuration
  environment), and the shipped-image Kinozal suite without `iconv`. Each leg
  uses its own `git ls-files` export and `TMPDIR` under `~/.cache/rtm/<stamp>/`;
  containers run as this user. The full run is bounded by the slowest leg.
  `tasks/matrix.sh local 7.4` selects legs. Its digest hashes all exported
  non-Markdown working-tree files, tracked or new, and `last` shows the last
  full green run. A red repeat on the same digest revokes that marker.
  The local leg's `TMPDIR` must stay under 60 bytes: `SCGITransportFixture`
  binds a UNIX socket 49 bytes below it and `sun_path` holds 107. PHP
  truncates a longer path with a silenced Notice; bind then lands on the
  directory, and four tests fail with an empty "SCGI fixture peer exited:
  server:". The first trial died that way at a 68-byte `TMPDIR` (2026-09-14).
- **`.git/hooks/pre-commit` skips its run** when that digest equals the last green one
  recorded by `matrix.sh`: a matrix green on four legs is the stronger check.
  `PRECOMMIT_FORCE=1` runs it anyway. The hook calls the script's `digest` for the value, so
  there is one definition of "what the suite sees", not two.

## Match The Review Effort To The Change

Adversarial review found real defects here -- a blocking generation-wide freeze in the erasedata
drain, `Torrent::touch()` destroying real authorship, the fixture leak above. It is worth its cost
on behaviour changes. It is not worth its cost on a comment.

- A behaviour change earns the full treatment: RED-first test, independent reviewer, and a mutation
  proving the new test is load-bearing.
- A comment or docblock fix does not. Verify the sentence against the code, run the suite, commit.
  Spawning an implementer plus a reviewer for four words costs about an hour and finds nothing.
- The recurring defect class in review is **a replacement comment that is itself false** -- three
  rounds caught one each. So the check that pays is not "did a reviewer look at it" but "did anyone
  open the line the new sentence cites". Do that, always, and prefer a shorter true sentence.

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
  `PermissionTest` and one other test fatal there. Static-structure tests that read source
  through `token_get_all()` fail: 8 cases in `plugins/rutracker_check/EntrypointsTest`, plus
  `php/XMLRPCProxyTest`, `php/PluginInitPathsTest`, and
  `plugins/extsearch/ImmortalSeedCategoriesTest`. The failure may read
  `Error: Call to undefined function token_get_all()` or an explicit tokenizer guard.
  None of it is a code fault: production calls
  no tokenizer function anywhere. The base commit fails identically — **always run the same suite
  on the base before calling a failure a regression**. That comparison is the only thing separating
  an image gap from a real one.
- **`TaskTest` kills the container it runs in, and the symptom looks like an out-of-memory.**
  `rTask::kill()` reads a pid out of a file and runs ``kill -9 `pgrep -P $pid` ; kill -9 $pid`` --
  the pid *and every child of it*. `testKillRunsNothingFromANonNumericPidFile` deliberately feeds
  it a pid file reading `1$(touch ...)`; `intval()` correctly strips the injection the case is
  about, and leaves the pid **1**. Inside a container that is init, so every process in the
  container is a direct child of it and all of them are the same uid: they all die. Measured -- an
  unrelated `sleep` started beside the suite was killed, and a full run inside a live `rt-lab`
  container ended it (`Exited (0)`, mid-suite). What you see is `Killed` and `EXIT=137` at the line
  `> php plugins/_task/TaskTest.php`, which reads as an OOM kill and is not one; `free` will show
  the machine idle. Exclude that one file when running the suite in a container you care about.
  `php:7.4-cli` and `php:8.1-cli` do not ship `pgrep`, so the path silently does nothing there --
  which is why a version matrix does not show it. The plugin is untouched by any current work; the
  underlying defect is written up in `tasks/2026-09-05-consolidated-fixes/SIDE-FINDINGS.md`.
- **Suites that write fixtures into the checkout make two concurrent runs corrupt each other.**
  `PermissionTest` built its directories in `tests/php/fixtures` until this was fixed. Six parallel
  runs then passed 3 or 4 of 5 assertions instead of 5, and a run as root left the fixtures
  root-owned so the next ordinary-user run failed on `unlink: Permission denied` -- a wrong answer
  that reads exactly like a real one, and one that hides the `posix` gap above. Give every run its
  own tree, or its own `TMPDIR`, before comparing results across PHP versions or uids.
- **The scratchpad is a tmpfs whose size varies between boots.** Two `git archive` exports of
  this repository filled it, and
  a full `/tmp` does not announce itself: `Write` returned `EDQUOT` and then *every* shell command,
  down to `true`, exited 1 with no output. If the shell starts failing universally, check `df`
  before anything else. Export trees somewhere under `/home`, and delete a finished agent's scratch
  tree rather than leaving it for the next one to trip over.
- `/`, `/tmp`, `/config` and `/data` inside a bare container reuse inode numbers after an unlink.
- The Dockerfile pins `RUTORRENT_REF` to a commit, so the image contains that revision, not the
  working tree. Bind-mounting is what makes it a runtime for local code.
- **`TESTLIB_HANDLER_STUBS` does not define `iconv()`.** It loads the real
  Torrent and FileUtil classes for checker fixtures. Kinozal compares literal
  CP1251 and UTF-8 markers without conversion. The matrix's shipped-image
  Kinozal leg explicitly disables `iconv` before loading the test library,
  so this path is exercised on the production-style PHP runtime.
- **The entrypoint chowns the mounted tree to the image's uid (991).** `startup` runs
  `find <folder> ! -user $UID -exec chown` over the app directories, so a scratch copy mounted at
  `/rutorrent/app` stops being writable by the host user after the first start. Push later edits
  in with `docker cp`, or use `tasks/rt-lab.sh sync`, rather than editing the mounted copy.
- **Do not pass `-e UID=1000 -e GID=1000` to this image.** `torrent:991` is baked in at build
  time, and `startup` runs `addgroup -g $GID torrent` whenever no group has that gid -- which
  collides with the existing name and ends the container at "Create torrent user...". The
  README's "simple launch" example does exactly this; it is a docker-rutorrent defect, recorded
  in that repository's AGENTS.md. Leave the identity at the image default.
- **Public resolvers are only intermittently reachable from this network, and which one answers
  changes by the hour.** Measured 2026-09-14: from this VM at noon 1.1.1.1 was dead and 8.8.8.8
  answered; from the production host an hour later 8.8.8.8 was dead and 1.1.1.1 answered. A
  container that trusts one fixed public resolver -- the README's `--dns 1.1.1.1 --dns 8.8.8.8`,
  or Docker's own 8.8.8.8/8.8.4.4 default when the host runs a local stub -- loses ALL name
  resolution when that one is out, and every fetch fails with curl 6, which reads like a dead
  tracker (the production container lost NNMClub and Kinozal alike that afternoon). The LAN's
  `172.18.128.1` answered throughout. A local network condition, not something to fix in
  configuration; when a probe answers `curl: (6)`, check `nslookup <host>` against each resolver
  before reading anything into the tracker.

Driving a full instance from local code, once it is up (`tasks/rt-lab.sh`, or a scratch
`git archive` export mounted at `/rutorrent/app` with `-e ENABLE_RPC2=true` and a fresh `/config`
volume): POST raw XML to `/RPC2` and to `/plugins/httprpc/action.php` to exercise both proxy
doors against the real daemon, load a torrent through them with `load.raw_start` and a `<base64>`
param, and read `/tmp/errors.log` inside the container for both doors' decision lines. To run
`rutracker_check/update.php` against a handler without sending a real announce, give the test
torrent an announce on a look-alike host such as `tr2.torrent4me.com.invalid`: `update.php`
admits rows by the registry's ANNOUNCE filter (a substring test, so the look-alike is admitted)
and never by the comment, while the authority host list rejects it and DNS never resolves
it. A torrent announcing to `127.0.0.1` is not admitted to the cycle at all.

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
   || ! pgrep -f '[d]ocker build.*rutorrent-rtXYZ' >/dev/null; do sleep 10; done
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

**A multicall result slot has no zero-argument form.** `d.multicall` parses each slot with
`rpc::parse_command` (`src/rpc/parse_commands.cc`), which refuses a name without `=` ("Could not
find '=' in command") and then runs `parse_whole_list` on whatever follows -- on nothing, that is
one empty string object; on `key,fallback`, two. So `d.name=` and `d.name=""` are the same call,
`d.custom.if_z=key,fallback` really passes two arguments, and a command that checks for zero
arguments (`d.custom.keys`, `d.custom.items`, `src/command_download.cc`) cannot be carried by a
multicall in any spelling. Measured over SCGI on 0.16.22 (2026-09-14) before it was read in the
source; the proxy's read list leaves those two out for that reason, and splits `d.custom.if_z`
like the two-argument setter. `d.multicall2` is a `CMD_REDIRECT` to `d.multicall` on 0.16, and
`d.get_*` spellings are not registered there at all -- forwarded, they fault as unknown. A bare
local checkout of the daemon's source lives at `~/xmlrpc-audit.1Efjdk/rtorrent`; its HEAD is
master a few commits past v0.16.21 (`git describe` says so), not the v0.16.22 tag, so read it
with `git show HEAD:src/...` for the shape of the code and confirm behaviour against the running
0.16.22 daemon, as the measurements above were.

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
