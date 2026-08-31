# Verification: setsettings/socket allocation package

Date: 2026-08-30

## Verdict

**APPROVED — implemented and locally integrated.** Package #2 is closed as an
implementation package. Push and deployment were not performed.

Current upstream was verified read-only both locally and with `git ls-remote`:

```text
f19c9d86df72ad6b1720f31252297340049e5eab refs/heads/master
```

## Upstream-clean branch

- branch: `up/setsettings-socket-alloc`
- parent: `f19c9d86df72ad6b1720f31252297340049e5eab`
- head: `d548016babea5ba557fad2c13afc0234a335a420`
- topology: one commit, no merge
- scope: exactly four paths, `+1229/-19`

```text
js/options.js
js/rtorrent.js
js/webui.js
tests/js/setsettings.spec.js
```

Explicit exclusions stayed absent: `tests/js/rtorrent.spec.js`, tracker and
stale-details hunks, `titled: true`, and the fork-only `chkmsg` documentation.

## Closed defects

The final package:

1. serializes all five Other Limiting numeric inputs as numeric settings and
   maps an empty numeric value to `i8:0`;
2. snapshots socket allocation bounds before direct writes, restores every
   affected category once after any member fault, and performs one final
   allocator adjustment;
3. parses XML faults before diagnostics, aggregates setsettings member faults,
   and reconciles the WebUI model after definitive failures;
4. holds the physical Save barrier through write, restore, read-back and the
   deferred `setuisettings` terminal result;
5. executes success/reload while the lock is held and releases afterward even
   when the callback throws;
6. releases without reload for deferred UI error, timeout, status zero, early
   no-op and HTTP 401;
7. routes only the lock-owning UI request's 401 through its scoped error
   callback; unrelated 401 still performs normal global authentication reload;
8. leaves initial direct write/restore timeout or status zero fail-closed and
   locked because remote completion cannot be proven;
9. preserves upstream #3196 socket-budget preflight, including immediate
   same-press WebUI-only save when allocation is rejected.

RED-first tests reproduced the early unlock and the 401 interception. Named
mutations independently killed numeric typing, empty-to-zero, snapshot/restore,
fault parsing, reconciliation, repeated-Save guarding, terminal ordering,
callback-finally release, every UI failure release, failure no-reload, early
no-op, non-reload success and scoped 401. Every mutation was restored.

## Candidate verification and reviews

Fresh exact-head results on `d548016b`:

- focused `setsettings.spec.js` + `settingsbudget.spec.js`: 59/59;
- full Jest: 22 suites, 263/263;
- `node --check`: all three changed production JS files;
- host PHP 8.5 full runner: exit 0;
- root `php:8.1-cli` full runner: exit 0;
- exact four-path scope, direct parent, one commit, no merge and
  `git diff --check`: pass.

`d548016b` is the patch-identical rebase of independently approved
`b3e36835` from `eeae9f3a` to `f19c9d86`: `range-diff` marks the commit equal,
and the four-path binary patch hash is unchanged.

Independent reports:

- fix-round-5 scoped re-review: **APPROVED**, no Critical/Important;
- final whole-branch review: **APPROVED**, no
  Critical/Important/Minor;
- request-local production probe measured one 401 diagnostic, zero navigation,
  terminal unlock and no extra request; ordinary 401 retained global
  navigation.

The ignored SDD evidence remains under
`.worktrees/up-setsettings-socket-alloc/.superpowers/sdd/PLAN-setsettings-socket-alloc/`.

## Local master integration

The approved branch assumed upstream #3196, but fork `master` content was still
synchronized only through `52903333`. A first cherry-pick correctly failed its
focused test with missing `socketAllocationFits`. The package scope was not
expanded to hide this prerequisite.

Instead, integration produced three separate linear commits:

1. `ed71bee5fba4a51903bbf05aa0ab6bfb6202045a` — exact current-upstream
   baseline `52903333..eeae9f3a`, comprising #3196/#3222/#3223, exactly 11
   paths and `+461/-10`;
2. `f547b2f31da57dc77013fa972faae1d34038de35` — the socket package, exactly
   four paths relative to the sync parent;
3. `7a78c606107b9520454a62edcc522d9a4e487ae8` — exact accepted
   #3224/#3225/#3226 refresh, five paths and `+163/-2`; already-local #3224 was
   deduplicated rather than replayed.

`backup/master-before-setsettings-integration-20260830` points to
`acbf569152e30b4ef75babcea61a31219c6a4ebc`; backup
`backup/master-before-upstream-f19-sync-20260830` points to `f547b2f3`. The
range contains three ordinary product commits and no merge. The local product
tip is `7a78c606`, while `origin/master` remains `acbf5691` (three product
commits behind, before this task-document closure).

Conflict resolution was independently checked:

- final `js/options.js` and `tests/js/setsettings.spec.js` are byte-identical to
  the final candidate;
- `js/rtorrent.js` differs only by retained fork `chkmsg` documentation;
- `js/webui.js` differs only by retained titled tracker status and stale-details
  race guard;
- the final ordered 38-test set equals the candidate; eleven obsolete tests for
  the superseded synchronous contract were not resurrected.

Independent master-integration review: **APPROVED**, with no finding at any
severity.

All seven upstream-owned paths in `eeae9f3a..f19c9d86` are byte-identical
between local `master` and `f19c9d86`. The package files remain identical to
`d548016b` except for the same three approved fork-only documentation/UI hunks.

## Integrated-tree verification

Fresh master results:

- focused package/upstream suites: 139/139;
- preserved WebUI suite: 7/7;
- full Jest: 23 suites, 310/310;
- three Node syntax checks, affected PHP lints, focused `MakeDirectoryTest` and
  `TorrentMetaTest` on host and root PHP 8.1: pass;
- exact sync/package scopes, topology, conflict-marker scan and diff-check:
  pass.

The broad PHP runner on fork `master` is deliberately **not called green**:

- clean host HEAD has the pre-existing missing `rRetrackers` fatal, reproduced
  unchanged on clean pre-refresh base `f547b2f3`;
- archive-based root PHP 8.1 HEAD has the same fatal, eight pre-existing
  root/permission-model filesystem assertions and the pre-existing ForumIndex
  ETag failure; the exact failure set reproduces on clean `f547b2f3`, with
  identical relevant file blobs;
- the main checkout's ignored runtime profile additionally changes schedule
  aliases, so verification used clean detached trees and did not delete or edit
  that user state.

This is baseline-equal failure evidence, not a green full-PHP claim and not a
package regression.

## Residuals and handoff

### Upstream PR CI follow-up — 2026-08-31

The owner subsequently published `d548016b` as upstream PR #3227. GitHub's
exact ESLint 9 job found one package-owned `no-redeclare`: `getResponse()` had
an existing function-scoped `var i`, while the new member-fault loop declared
`var i` again. This was a real PR/master defect, not workflow drift.

Branch follow-up `a8b60beaf67e4a09599461f979463b3e01c1cbac`
renames only that inner loop index and its lookup to `j`. The owner published
it; remote/local are equal, and all eight GitHub checks are green. The same
patch is integrated and published in fork `master` as `fe5313fa`. Fresh exact
ESLint, `node --check js/rtorrent.js`, focused `rtorrent.spec.js` plus
`setsettings.spec.js` (66/66), scope and whitespace checks are green. The
untracked user log remains untouched.

- Same-browser Saves are serialized; another rTorrent client remains outside
  this browser transaction boundary.
- No live daemon `/RPC2` or deployed enabled-httprpc smoke was run. A disposable
  `tasks/rt-lab.sh` smoke can add deployment confidence later but is not a
  merge blocker for this package.
- The unrelated `rutorrent-app-errors.log` was preserved byte-for-byte and was
  not staged.
- No push, PR creation, deploy or Docker image build was performed.

After packages #1 and #2, the current queue is **16 open implementation
packages / 0 pending audits / 5 ready or locally integrated owner handoffs + 1
accepted upstream closure**. Package #1 was accepted as #3224; #3225 is its
upstream follow-up.
