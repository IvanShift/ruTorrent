# Package 5 `retrackers-recovery` Remediation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> `superpowers:subagent-driven-development` or `superpowers:executing-plans` to
> implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for
> tracking.

**Goal:** Replace the unsafe Tasks 5-7 implementation on
`up/retrackers-recovery=4795cdd1` with the approved six-path recovery protocol
and complete the two-family runtime acceptance gate.

**Architecture:** Keep the already approved Tasks 1-4B scanner, projector,
bounded reader and restricted RAW codec. Replace the lifecycle and recovery
coordinators with daemon-side conditional callbacks: every mutating phase is
armed once, guarded by `wa:<tx>`, and reconciled only through surviving
receipts. Candidate and original bytes are staged as anonymous procfd
capabilities before the old object is touched; candidate, cleanup and rollback
are generation-bound and scheduler-fenced.

**Tech Stack:** PHP 7.4-compatible PHP, rTorrent XMLRPC command grammar,
`rSCGITransport::send(..., RESPONSE_RAW)`, POSIX shell, disposable Docker labs.

**Spec:**
`tasks/2026-08-28-upstream-delivery/REVIEW-retrackers-recovery-2026-08-29.md`
with
`tasks/2026-08-28-upstream-delivery/VERIFICATION-retrackers-recovery-precode-2026-08-31.md`.

**Final checkpoint 2026-09-05:** this remediation is complete. Independent
review corrections are committed in `fde65fe5`, final donor is `1c810568`,
master integration is `66571370`, and verified upstream sync is `ee96fab1`.
The single authorized final reviewer finished APPROVED. The original Task 1-5
checkboxes below are historical execution notes, not current remaining work.
Their literal direct-class PHP commands are not a valid runner: use the real
TestCase runner with assertions enabled or the complete harness from tests/.
Final evidence/stop point:
[PACKAGE-5-14-INTEGRATION-2026-09-05.md](PACKAGE-5-14-INTEGRATION-2026-09-05.md).

## Global constraints

- Work only in `.worktrees/up-retrackers-recovery`.
- The package owns exactly the six paths listed in `NEXT-AGENT-PACKAGE-5.md`.
- Do not rebase, merge, push, deploy, rewrite existing commits, or touch the
  main checkout's diagnostic archives.
- Every production change follows named natural RED, GREEN, adversarial RED,
  byte-identical restoration and fresh GREEN.
- No mutating probe may target a live service; use `tasks/rt-lab.sh` only.
- Preserve the 12 sequence methods and the frozen class-through-EOF hash.
- PHP 7.4 acceptance runs with an effective `memory_limit=128M`; a test may not
  widen it globally.

---

### Task 1: Restore the executable CLI and closed RPC adapter

**Files:**

- Modify: `plugins/retrackers/update.php`
- Test: `tests/plugins/retrackers/UpdateTest.php`

**Interfaces:**

- `retrackersCliMain(array $argv): int` loads `retrackers.php`,
  `php/xmlrpc.php` and `php/rtorrent.php` only after pure argv validation.
- `RetrackersLifecycleRpcAdapter::setFamily(int $family): void` is called from
  an accepted historical sample before family-dependent mutations.
- `executeDirectMutation()` and `executeMulticall()` distinguish transport,
  malformed response, XMLRPC fault and member fault.
- Scheduler command names come from `getCmd('schedule')` and
  `getCmd('schedule_remove')`; production never emits deprecated `schedule2`.

- [ ] Add `testValidCliLoadsRuntimeDependenciesBeforeTheFirstRpc` using a real
  child process with a valid handoff. Assert that it exits normally with a
  classified failure and never contains `Class "rSCGITransport" not found`.
- [ ] Run `php tests/plugins/retrackers/UpdateTest.php`; verify the named test
  fails on `4795cdd1` with the missing-class fatal.
- [ ] Add adapter cases for family propagation, canonical scheduler names,
  direct XMLRPC fault and a partial member-fault batch. Each case must assert
  `false` plus its classified failure, not merely inspect a fake's call list.
- [ ] Run the suite again and preserve the named RED outputs.
- [ ] Load runtime dependencies after argv validation, propagate the accepted
  family into the lifecycle adapter, normalize global `method.set_key` target
  arguments, resolve scheduler aliases through the version map and reject any
  fault slot from `system.multicall`.
- [ ] Obtain GREEN, mutate each boundary independently, restore exact bytes and
  obtain fresh GREEN.
- [ ] Commit only `update.php` and `UpdateTest.php`.

### Task 2: Make lifecycle ownership and deferred replay atomic

**Files:**

- Modify: `plugins/retrackers/update.php`
- Test: `tests/plugins/retrackers/UpdateTest.php`
- Verify: `plugins/retrackers/init.php`, `plugins/retrackers/done.php`,
  `plugins/retrackers/run.sh`

**Interfaces:**

- Lifecycle mutations are one unsplit daemon callback returning a typed
  sentinel, never a PHP sequence of unconditional `set_key` calls.
- `replayDeferredInserts()` performs `dq` clear, fresh direct
  `d.multicall2` local-id resolution, ownership checks and launch/cancel
  callbacks without dropping a `di:*` obligation.
- Failed ledger reads remain unknown; they are never converted to an empty set.

- [ ] Add named REDs for accepted-response loss, per-slot fault, `dq/di` ABA,
  unknown ledger reads, `wp` cancel/adopt races and every INIT/DONE/CONTAIN
  owner transition.
- [ ] Prove current code drops `di:*`, tears down on unknown reads and can
  expose partially written profile state.
- [ ] Introduce narrowly named callback builders for bootstrap/current acquire,
  init finalization, containment, done acquire/finalization, deferred replay
  and pending cancellation. Each builder returns one XMLRPC method plus params
  and an exact sentinel plan.
- [ ] Require coherent readback of epoch, owner, claims, actions and dirty/
  active receipts after every known mutation; unknown replies leave the safety
  gate and owner sticky.
- [ ] Re-run focused tests and the 12-method sequence suite; perform required
  order/removal/unknown mutations and restore GREEN.
- [ ] Commit the lifecycle/deferred correction separately.

### Task 3: Stage immutable anonymous load capabilities before erase

**Files:**

- Modify: `plugins/retrackers/update.php`
- Test: `tests/plugins/retrackers/UpdateTest.php`

**Interfaces:**

- A staging object owns two complete-write `0600` regular files, unlinks their
  names, locates exact `/proc/<pid>/fd/<fd>` capabilities and keeps the handles
  alive through candidate/rollback fences.
- A daemon-side `execute.capture` preflight must independently read and hash
  each capability before `ea` can be armed.

- [ ] Add REDs for short write, flush failure, inode/link/mode/owner mismatch,
  missing procfd, daemon-side hash mismatch and handle closed before `lf/rf`.
- [ ] Implement complete-write loops and descriptor/lstat invariants without a
  second pathname read or unbounded copy.
- [ ] Implement the no-shell nonce/hash preflight and prove all failures occur
  before arm, temp exposure or old-object mutation.
- [ ] Run PHP 7.4/8.1/8.5 focused tests, mutation checks and syntax checks.
- [ ] Commit staging as a self-contained safety layer.

### Task 4: Replace old-generation erase with one conditional commit

**Files:**

- Modify: `plugins/retrackers/update.php`
- Test: `tests/plugins/retrackers/UpdateTest.php`

**Interfaces:**

- `armPhase('ea', ...)` is one-shot and requires live `wa`.
- One direct daemon command owns outer CAS, begin receipt, stop/close,
  post-quiesce inner CAS, erase, `ed` and terminal `ex`.
- Reconciliation reads `eb/ed/ex` plus a fresh full snapshot and never repeats
  a state-changing request.

- [ ] Add REDs for local-id, source, generic-map, tracker topology, enabled
  state and lifecycle drift in both CAS layers.
- [ ] Add REDs proving `d.erase=false`, missing `ex`, begin-only and transport
  unknown never authorize candidate load.
- [ ] Build the exact nested `branch/and/cat` command using recursive
  `rTorrent::quoteCommandArg()` boundaries and canonical ledger calls.
- [ ] Implement the response-lost table from the contract, including sticky
  `commit-dispatch-pending`, `commit-completion-pending` and
  `partial-quiesce-ambiguous` outcomes.
- [ ] Obtain RED/GREEN/mutation evidence and commit the atomic erase phase.

### Task 5: Implement fenced candidate, owned cleanup and rollback

**Files:**

- Modify: `plugins/retrackers/update.php`
- Test: `tests/plugins/retrackers/UpdateTest.php`

**Interfaces:**

- Candidate and rollback creation lists restore generic custom values,
  directory, cleared tied/loaded fields, custom1-5, priority, throttle and
  URL-keyed tracker state before the ready marker is written last.
- Armed wrappers schedule a canonical one-shot fence before begin/load.
- Cleanup matches exact transaction, local id, marker/ack and the complete
  captured prefix tuple; only `cd+cx+absence` permits rollback.

- [ ] Add named REDs for omitted baseline fields, wrong quoting, wrong tracker
  projection, deprecated schedule names, ignored fence failure, wrong/empty
  ack, hashing failure, foreign transaction cleanup and second/third load.
- [ ] Build and size-check the complete load command before old mutation.
- [ ] Implement one-shot `la/lb/lf`, `ca/cb/cd/cx` and `ra/rb/rf` callbacks and
  the exact terminal outcome tables.
- [ ] Implement capability-first terminal cleanup and one-shot conditional
  ack-then-marker release; unknown cleanup/release keeps `wa` sticky.
- [ ] Obtain focused GREEN and mutation-sensitive REDs, then commit.

### Task 6: Complete worker orchestration and bounded-memory acceptance

**Files:**

- Modify: `plugins/retrackers/update.php`
- Modify: `tests/plugins/retrackers/UpdateTest.php`

- [x] Wire `RetrackersRecoveryCoordinator::run()` in the exact order: adopt,
  stable snapshot, metainfo prepare, staging/preflight, arm+commit reconcile,
  candidate reconcile, optional owned cleanup+rollback, conditional release,
  terminal cleanup.
- [x] Remove the global `@ini_set('memory_limit', '512M')`.
- [x] Run CAP-1/CAP/CAP+1 and maximum retained-state cases under literal PHP
  7.4 `-d memory_limit=128M`; record peak/outcome and reject OOM masking.
- [x] Run all focused tests, sequence preservation, lint, `sh -n`, PHPStan and
  `git diff --check`; fix only package-owned failures.
- [x] Commit the complete code candidate (`153f8e45`, normal hook passed).

### Task 7: Two-family runtime, adversarial review and handoff

**Files:**

- Test/runtime changes, if required, remain inside the six-path scope.
- Update after acceptance in the main checkout:
  `PACKAGE-5-IMPLEMENTATION-REPORT.md` and package status documents.

- [x] In disposable rTorrent 0.9.8 and 0.16.21 labs, run production callbacks
  for all eight manifest states. Capture two independent byte-identical reads,
  request/BODY hashes, image/source provenance and exact command families.
- [x] Exercise old-object stop/erase, candidate, partial cleanup, rollback,
  response-loss and late-callback races only in the disposable labs.
- [x] Run the mandatory mutation matrix and verify every named test executes
  before failing; restore bytes and re-run GREEN.
- [x] Perform independent whole-file reviews of all six paths.
  Final reviewer approved the corrected production SHA-256 `c6992d5a02f342943cc7cf076301bccf989660d02ebc8c8677158b775cf45231`.
  The complete reports and 42-scenario crosswalk are retained in
  [PACKAGE-5-FINAL-REVIEW-2026-09-05.md](PACKAGE-5-FINAL-REVIEW-2026-09-05.md).
- [x] Verify final worktree cleanliness, exact six-path diff and no loss of the
  12 sequence methods.
- [x] Update the report with exact commands/counts, unchecked items and the
  truthful final verdict. Do not merge or push without separate authority.
