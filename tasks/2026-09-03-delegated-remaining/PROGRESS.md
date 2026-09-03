# Delegated remaining packages — append-only ledger

Started 2026-09-03. This ledger is **not** the official status document.
`tasks/2026-08-28-upstream-delivery/STATUS-18-PACKAGES-2026-09-01.md` remains
authoritative and still records **5 of 18 independently approved**.

Two counters are tracked separately here:

- independently approved: **5** (packages 1-4 and 13) — unchanged by this work;
- candidate ready for Codex review: see the running total at the end.

No verdict in this ledger is `APPROVED` or `CLOSED`. The best outcome an entry
may carry is `READY_FOR_CODEX_REVIEW`.

## Session starting refs

```text
repository      /home/dev/Documents/my_projects/ruTorrent
branch          master
HEAD            2d2710eb51a35695040d11dc2f18735a6aa5cce1
origin/master   2d2710eb51a35695040d11dc2f18735a6aa5cce1
upstream/master 495e2a54a657efcc132dc1456db8d7e680304a8a   (before fetch)
ancestry        git merge-base --is-ancestor 2d2710eb HEAD -> OK
```

Handoff recorded `origin/master=774a6bf2`. Observed `origin/master=2d2710eb`:
the three handoff commits are already published. Nothing was lost, no reset was
performed. The four user diagnostic files in the repository root were never
staged, moved or edited.

Host tooling: PHP 8.5.4 (cli), node v26.7.0, docker as uid 1000.
Images used: `php:7.4-cli` `7bbbb12d1498`, `php:8.1-cli` `7699e39d88f6`,
`ivanshift/rutorrent:latest` `7cea0d172586`.

---

## Package 14 — `xmlrpc-proxy-policy`

**Verdict: `BASE_DRIFT`.** No branch, no worktree, no code, no commit.

### Contract files read in full

```text
tasks/2026-09-01-gemini-xmlrpc-proxy-policy/README.md
tasks/2026-08-28-upstream-delivery/REVIEW-xmlrpc-proxy-policy-2026-08-29.md
```

### Frozen base vs observed base

The brief §2 fixes the base and states the stop condition literally:

```text
expected upstream/master  495e2a54a657efcc132dc1456db8d7e680304a8a
observed  upstream/master cd814cb58e260dc08a3894d3fbfd4407e966b031
required ancestor 7e77ebf0 (#3228)  -> still an ancestor: YES
donor 4d779ff9 object                -> present: YES
```

`git fetch upstream master` (the brief's own first command) advanced
`upstream/master` by 18 commits. Per brief §2 — "If upstream moved, return
`BASE_DRIFT` with the new SHA and the seven-path diff from `495e2a54`; do not
silently rebase the contract" — this is terminal for the package.

### Seven-path diff `495e2a54..cd814cb5`

Three of the seven contract paths moved upstream:

```text
M	plugins/httprpc/action.php                   13	0
M	plugins/httprpc/conf.php                      8	24
M	tests/php/XMLRPCProxyEntrypointTest.php      64	7
```

Untouched upstream and therefore still contract-current: `conf/xmlrpc_proxy.php`,
`php/xmlrpc_proxy.php`, `tests/php/XMLRPCProxyTest.php`,
`tests/php/XMLRPCProxyContractFixture.php`.

Excluded paths (`rpc2.php`, `php/xmlrpc.php`, `php/xmlrpc_path.php`,
`php/scgitransport.php`, `XMLRPCProxyContractTest.php`,
`XMLRPCProxyRejectionTest.php`) are unchanged in the drift range.

### Why the drift is material, not cosmetic

`62083b85` — *"Read one XMLRPC proxy policy at both entry points (#3251)"* —
independently implements part of the package-14 contract's §13 config layer:

- `plugins/httprpc/action.php` now `require_once`s `conf/xmlrpc_proxy.php`
  before `eval(FileUtil::getPluginConf('httprpc'))`;
- the shipped `plugins/httprpc/conf.php` no longer restates
  `$XMLRPCProxySafeParams` at all — it is now a comment block;
- a new file `tests/php/XMLRPCProxyPolicyParityTest.php` (98 lines) asserts that
  no other shipped `conf.php` / `conf.local.php` / `xmlrpc_proxy.php` defines a
  *different* `$XMLRPCProxySafeParams`.

The contract §13 requires the layer order
`conf/xmlrpc_proxy.php -> unset-only defaults in plugins/httprpc/conf.php ->
conf.local.php -> conf/users/<user>/...`. An unset-only default written into
`plugins/httprpc/conf.php` is read by the new parity test through
`$XMLRPCProxySafeParams = null; require($f);` — `isset(null)` is false, so an
unset-only guard *does* assign, the file then counts as a definer, and the
parity assertion compares it against the reference list. The contract's §4
seven-path scope forbids editing `XMLRPCProxyPolicyParityTest.php`.

Verified by reading the whole test, not inferred: `otherDefiners()` lists a file
by a *source-text* match on `\$XMLRPCProxySafeParams\s*=`, and
`testEveryOtherDefinitionOfThePolicyAgreesWithIt` then requires the value that
file produces in isolation to equal the reference list exactly. An unset-only
default is therefore either byte-identical to the shared list — in which case it
carries no policy and the §13 layer is vacuous — or it fails an upstream test the
contract forbids editing.

A second, independent tension in the same commit:
`testTheSharedPolicyCarriesTheViewActions` requires `conf/xmlrpc_proxy.php` to
list `d.open`, `d.close`, `d.start` and `d.stop` in `$XMLRPCProxySafeParams`.
The frozen contract §12 instead governs `d.open`, `d.start`, `d.stop` and
`d.delete_tied` as *elevated exact shapes* with a `hash` canonical form, and
mentions `d.close` nowhere at all. Upstream's stated measurement — a
`d.multicall2` carrying `d.stop=` refused to an untrusted caller on 0.16.21 and
0.16.20 — is live evidence about the same commands the contract routes through a
different mechanism.

So the frozen §13 layer design, the frozen §12 elevated set, and two upstream
tests that did not exist at the frozen base are now in direct tension. Resolving
that is a contract decision, not an implementation decision. Brief §2 also lists
"a frozen policy decision is ambiguous" as an independent stop condition, and §5
forbids expanding safe-command config merely to pass tests.

### Additional drift relevant to later packages

Not package 14 scope, recorded because packages 6-9 own these files:

```text
a5509dc5  erasedata: queue erase requests so mass erases cannot flood rtorrent (#3240)
          M plugins/erasedata/conf.php  M plugins/erasedata/erase.php  A plugins/erasedata/pending.php
dcf3fb96  Cover the erasedata queue, and let it require what it uses (#3248)
          M plugins/erasedata/pending.php  A tests/plugins/erasedata/PendingQueueTest.php
```

Neither is an ancestor of fork `master` (`2d2710eb`).

### State

```text
branch          none created
worktree        none created
candidate       none
push            no
master product integration   no
```

Proceeding to the next independent package per the delegation brief §6.

---

## Package 15 — `rutracker-manual-entrypoints`

**Verdict: `READY_FOR_CODEX_REVIEW`.** Not approved, not closed.

### Contract files read in full

```text
tasks/2026-08-28-upstream-delivery/REVIEW-disposition-wave-2026-08-29.md  (§4)
tasks/2026-08-28-upstream-delivery/PLAN-remaining-queue-2026-08-29.md     (row 15)
tasks/2026-08-28-upstream-delivery/STATUS-18-PACKAGES-2026-09-01.md       (row 15)
```

### Base

Package 15 has no frozen base SHA in its contract — unlike package 14 — and the
registry marks it independent. It was therefore built on the current
`upstream/master`.

```text
BASE / PACKAGE_BASE   cd814cb58e260dc08a3894d3fbfd4407e966b031
branch                agent/package-15-rutracker-manual-entrypoints
worktree              .worktrees/agent-package-15-rutracker-manual-entrypoints
candidate commit      5a1a0d9798a76bff06a07c230eaaae941b1aef49
parent                cd814cb5  (exact; verified non-merge, rev-list count 1)
```

The base is fully green before any edit: 62 files, 2075 `Passed:` + 148 `ok -`,
0 failures, exit 0 (host PHP 8.5); Jest 22 suites / 279 tests.

The registry describes `launcher.php` and `init.spec.js` as *new*. They are new
relative to upstream, which is the base; the fork already carries variants of
both, and the fork was used only as evidence and a hunk source. The excluded
items (`MAX_HASHES=4096`, raw exception text, `spawnCrawl()`, HTTP 503, the
aggregate test, the run registry, `init.php`) are absent from the candidate.

### Exact changed paths — the six the contract names, no seventh

```text
M  plugins/rutracker_check/action.php                    42  18
M  plugins/rutracker_check/batch_check.php               96   5
M  plugins/rutracker_check/init.js                       18   0
A  plugins/rutracker_check/launcher.php                 312   0
A  tests/plugins/rutracker_check/ManualEntrypointsTest.php  817  0
A  tests/plugins/rutracker_check/init.spec.js            111   0
```

### Natural RED, before any production edit

Both new test files run against production held at exact base (`git status`
reported 0 modified production files). They drive the copied real entrypoints —
`action.php` over a real `php -S`, `batch_check.php` as a real child — and name
no class the base does not define, so nothing fatals before a named failure.

```text
PHP   12 named RED, 1 preservation GREEN, 0 fatal/parse/uncaught, exit 1
JS     5 named RED, suite loads cleanly
```

| Named RED | Base behaviour observed |
|---|---|
| `testTwoChecksInOneSecondGetSeparateHandovers` | 2 launches, **1** distinct handover path |
| `testAnUnwritableHandoverIsRefusedRatherThanQueued` | answer carries no status at all |
| `testAPhpPathContainingASpaceStillLaunchesTheWorker` | **0** launches, still answered as success |
| `testALaunchTheShellRefusedIsNotReportedAsQueued` | answer carries no status at all |
| `testAnOversizedBodyIsRefusedWithoutLaunchingAWorker` | oversized body accepted |
| `testOnlyRealHashesReachTheWorker` | `'not-a-hash'` reached the worker |
| `testOneFailingHashDoesNotCancelTheRestOfTheBatch` | **1 of 3** torrents checked |
| `testTheHandoverIsRemovedEvenWhenAHashThrows` | handover left behind |
| `testAWorkerFailureIsLoggedAsAClassifiedEnglishReason` | nothing logged |
| `testAQueuedBatchAndARefusedBatchAnswerDifferently` | status `NULL`, both answers `{}` |
| `testAnEmptySelectionIsAnsweredWithoutClaimingRtorrentIsDown` | not distinguishable |
| `testAnIncompleteHandoverWriteIsRefusedRatherThanQueued` | no dispatch helper exists (named failure, not a fatal) |
| `testEveryHandledOutcomeKeepsA2xxStatus` | **preservation GREEN** — pins that the fix must not introduce 403/503 |

### Test-name SET preservation

Execution-derived (the names the runner actually emitted), `LC_ALL=C`, unique,
non-empty guard on both sides:

```text
base unique       543   SHA-256 4e9dc223281bda5c55b5935c80be3091a4d6b8f1fdbe6c8c1f37e790e7e51ea7
candidate unique  556
duplicates        0 on both sides
LOST              0
ADDED             13   (exactly the new file's cases)
arithmetic        543 + 13 = 556 = observed
```

Jest: 22 suites / 279 tests → 23 suites / 284 tests, +1 suite and +5 tests, none
lost.

### Full test matrix — all GREEN

| Runtime | Result |
|---|---|
| host PHP 8.5.4 `bash php-test.sh` | 63 files, 2075 `Passed:` + 161 `ok -`, 0 failures, exit 0 |
| `php:7.4-cli` `7bbbb12d1498`, `--user 1000:1000 --network none` | identical: 63 / 2075 / 161 / 0 |
| `php:8.1-cli` `7699e39d88f6`, `--user 1000:1000 --network none` | identical: 63 / 2075 / 161 / 0 |
| `ivanshift/rutorrent:latest` `7cea0d172586`, bind-mounted | base 12 failures → candidate 0 failures |
| full Jest `--runInBand` | 23 suites / 284 tests, 0 failures |
| `php -l` on all 4 changed PHP files, `node --check` on `init.js` | clean |
| `git diff --check`, `git diff --cached --check` | clean |

### Mutation campaign — 16 of 16 sensitive

Each row: one semantic changed by exact-string replacement (python3, never
perl), the owning suite run, the named test proved RED, the output scanned for
a preceding fatal, the exact bytes restored and `cmp`-verified, then GREEN
re-proved.

```text
sensitive            16 / 16
not sensitive         0
fatal before named RED 0
restore byte diffs     0
RED after restore      0
```

| # | Mutation | Named RED |
|---|---|---|
| M01 | handover name back to `<user><time()>` | `testTwoChecksInOneSecondGetSeparateHandovers` |
| M02 | short write no longer distinguished from a full one | `testAnIncompleteHandoverWriteIsRefusedRatherThanQueued` |
| M03 | uncreatable handover reported as dispatched | `testAnUnwritableHandoverIsRefusedRatherThanQueued` |
| M04 | interpreter interpolated unquoted | `testAPhpPathContainingASpaceStillLaunchesTheWorker` |
| M05 | detached launch stops being observed | `testALaunchTheShellRefusedIsNotReportedAsQueued` |
| M06 | request body bound removed | `testAnOversizedBodyIsRefusedWithoutLaunchingAWorker` |
| M07 | hash shape no longer validated | `testOnlyRealHashesReachTheWorker` |
| M08 | duplicate hashes no longer collapsed | `testOnlyRealHashesReachTheWorker` |
| M09 | per-torrent isolation removed | `testOneFailingHashDoesNotCancelTheRestOfTheBatch` |
| M10 | failed check no longer reported | `testAWorkerFailureIsLoggedAsAClassifiedEnglishReason` |
| M11 | classified reason replaced by raw exception text | `testAWorkerFailureIsLoggedAsAClassifiedEnglishReason` |
| M12 | refusal answers exactly like an accepted batch | `testAQueuedBatchAndARefusedBatchAnswerDifferently` |
| M13 | handled refusal answers 503 again | `testEveryHandledOutcomeKeepsA2xxStatus` |
| M14 | every answer treated as queued (JS) | `surfaces a refused batch instead of leaving it silent` |
| M15 | message built from a key no shipped file defines (JS) | `never renders a missing language key` |
| M16 | visible refusal downgraded to a silent log (JS) | `surfaces a refused batch instead of leaving it silent` |

### Runtime

No rTorrent/SCGI/XMLRPC runtime gate applies: this route never speaks to the
daemon. `ruTrackerChecker::run()` is the boundary and is stubbed, which is what
lets the worker's failure path be exercised at all. No live service was touched
and no mutating probe was run anywhere.

### Unresolved risks, stated plainly

1. `testAnIncompleteHandoverWriteIsRefusedRatherThanQueued` reaches the short-write
   branch through the dispatch helper's `$writer` seam, because a filesystem that
   fills up mid-write cannot be arranged here without root. Its RED at base is
   **structural** (the helper does not exist) rather than behavioural. Every other
   case is behavioural.
2. The two same-second requests are aligned to a second boundary and the test
   asserts its own precondition (`$startSecond === $endSecond`) with bounded
   retries, so it cannot pass vacuously — but it is the one timing-dependent case.
3. `theUILang.Error` is a blunt message for a refusal. A specific string would
   need a new key in every shipped `lang/*.js`, which is outside the six paths.
   See `FINDINGS.md` F5.
4. The candidate is built on upstream, where this plugin is far smaller than the
   fork's. Carrying it into fork `master` is a separate integration with its own
   conflict surface and was **not** performed.

### State

```text
push                        no
PR                          no
master product integration  no
user diagnostic files       untouched
```

---

## Package 6 — `erasedata-remove-payload` (A)

**Verdict: `CONTRACT_CONFLICT`,** triggered by `BASE_DRIFT`. No branch, no
worktree, no code, no commit.

### Contract files read

```text
tasks/2026-08-28-upstream-delivery/REVIEW-erasedata-remove-payload-2026-08-29.md
tasks/2026-08-28-upstream-delivery/VERIFICATION-erasedata-contracts-2026-08-29.md
```

### Frozen base vs observed base

```text
contract base   upstream/master = 755404f3   (stated in the review's header)
observed base   upstream/master = cd814cb5
```

### The frozen 8+2 boundary can no longer describe the tree

The contract freezes exactly eight production paths and two test paths, and
states that any new scope may come only from a separate current-base finding.
Three of its production paths (`collector.php`, `filesystem.php`,
`manifest.php`) and its `CollectorFixture.php` exist only in the fork, so the
approved work is a carve of fork hunks onto an upstream base.

Upstream `a5509dc5` (#3240) and `dcf3fb96` (#3248) changed exactly that base:

```text
M  plugins/erasedata/conf.php     + $erasePendingMaxAttempts
M  plugins/erasedata/erase.php    rewritten: record-then-drain
A  plugins/erasedata/pending.php  182 lines, a ninth production file
A  tests/plugins/erasedata/PendingQueueTest.php   309 lines, a third test file
```

`erase.php` is contract path 3. At the frozen base it called
`erasedataRemoveWithData()` directly; it now records a marker and calls
`erasedataDrainQueue()`. The fork's copy still has the old call, so the carve
that was reviewed no longer applies to the file it targets.

### The conflict is a design collision, not just a moved line

Upstream's `pending.php` solves an overlapping problem with a **different**
mechanism from the one package 6 had approved:

| Concern | Package 6 approved design | Upstream `pending.php` |
|---|---|---|
| serialising erase work | durable wake generation, one coalesced repeating `erasedata-drain` schedule | `drain.lock` + `flock(LOCK_EX\|LOCK_NB)`, first firing drains |
| request record | generation-bound v2 staging | `<hash>.pending` marker, exclusive `fopen(..., "x")` |
| giving up | exact retention/retry, retried every pass | `$erasePendingMaxAttempts`, default 10, then abandoned |
| acknowledgement | real PHP child acknowledgement | none |

Choosing between them — or reconciling them — changes what package 6 *is*. The
contract explicitly rejected a targeted one-shot in favour of the durable-wake
variant, recording that the user approved that choice; upstream has since
shipped something closer to the rejected shape. That is a contract decision.

Per the delegation brief §7, a later document supersedes a frozen contract only
with an explicit supersession explanation. Upstream's commits carry none — they
are not aware of this contract. So the package stops here rather than being
silently rebased onto a boundary nobody approved.

### Cascade

Package 6 is the prerequisite for 7, 8 and 9; 9 for 10; 10 for 11, 12, 16, 17
and 18. All of them are therefore `BLOCKED` on this same decision, and none was
started. Package 12 additionally needs a fully finished package 5.

```text
6  CONTRACT_CONFLICT
   ├── 7  BLOCKED (also needs package 14, itself BASE_DRIFT)
   ├── 8  BLOCKED
   └── 9  BLOCKED
        └── 10 BLOCKED
             ├── 11 BLOCKED (also needs event-order evidence)
             ├── 12 BLOCKED (also needs package 5 complete)
             ├── 16 BLOCKED
             ├── 17 BLOCKED
             └── 18 BLOCKED
```

No dependent package was built on an unproven result.

---

## Package 5 — `retrackers-recovery`, Task 5 (partial)

**Verdict: `READY_FOR_CODEX_REVIEW` for the Task 5 increment only.**
Package 5 as a whole remains **incomplete**: Tasks 6, 7 and 8 are untouched, and
Task 5's own `init.php` wiring is deliberately not done (reason below).

### Contract files read

```text
REVIEW-retrackers-recovery-2026-08-29.md            (§ hook and shell contract, § entrypoint gates)
VERIFICATION-retrackers-recovery-precode-2026-08-31.md
PLAN-remaining-queue-2026-08-29.md                  (row 5)
```

### Base and candidate

```text
PACKAGE_BASE   9fef4d667a722331b3c72e673e3e6a0db0564246   (Task 4B, APPROVED)
branch         up/retrackers-recovery                     (existing, continued)
worktree       .worktrees/up-retrackers-recovery
candidate      0bdac05d  parent 9fef4d66, non-merge, tree clean
paths          plugins/retrackers/update.php        +60  -0
               tests/plugins/retrackers/UpdateTest.php +155 -0
```

Both are inside the corrected six-path scope. `4682a761` is an ancestor.

### Frozen predecessor invariants — re-verified, not assumed

```text
sequence class-body-through-EOF sha256
  f0dac045fa3b9e98172132977e05fa14b7f091d1b9779a989d8b1d047fecc8f3   MATCHES contract
sequence 12 sorted method names sha256
  0ee7b35f9cda898d00e963b7e23aff02351e3653db21bbf2e99e31a34d5c7044   MATCHES contract
  normalization recovered by search: LF-joined WITH a final LF
sequence run: 12 methods / 40 assertions / 0 failures   MATCHES contract
```

The existing `retrackersBuildSafetyOnlyInsertAction()` hash reproduced as
`c1ec79a6767399434309847606d9dda5f5d5eaf5d3d849e42c72b32a9dae83bb`, identical to
the value already frozen in the suite — which is what shows the probe harness
reproduces the established quoting layer exactly, rather than a lookalike.

### What was built

`retrackersBuildInsertAction($script, $php, $user)` in the side-effect-free
importable part of `update.php`, composed **exactly** from the contract's frozen
expression list, completing the trio of canonical hook shapes.

```text
action bytes   2386
sha256         ed597fcf31a63256e78346d442c4ca28bbd854a5ab34a00f69beb3131a8db706
shares a 456-byte marker/ack head with both existing variants
contains no d.stop, d.close or d.erase in any branch
```

### Natural RED

Production held at exact `9fef4d66` (`git status` reported 0 modified production
files; the builder is absent there):

```text
5 named RED, 0 fatal/parse errors
  update.php declares the canonical functional insert action builder
  the functional insert action builder exists   (x4, one per dependent case)
```

### Tests

```text
UpdateTest   base 75 methods / 402 assertions / 0 failures
             candidate 80 methods / 424 assertions / 0 failures
             +5 methods, 0 lost
```

| Runtime | Result |
|---|---|
| host PHP 8.5.4 `bash php-test.sh` | 51 files, 2416 `Passed:` + 127 `ok -`, 0 failures |
| `php:7.4-cli` `7bbbb12d1498` non-root, no network | identical: 51 / 2416 / 127 / 0 |
| `php:8.1-cli` `7699e39d88f6` non-root, no network | identical: 51 / 2416 / 127 / 0 |
| `php -l`, `git diff --check` | clean |

### Mutations — 7 of 7 sensitive

```text
sensitive 7/7   fatal before named RED 0   restore byte diffs 0   RED after restore 0
```

| # | Mutation | Named RED |
|---|---|---|
| P01 | receipt cleared before the launch instead of after | `the hook-active receipt is cleared only after the launch returns` |
| P02 | ack and marker written in the wrong order | `the pending lease, the ack and the marker are all written before the launch` |
| P03 | a stop reintroduced into the owner branch | `the functional insert action carries no d.stop in any branch` |
| P04 | canonical user interpolated into the launch unquoted | `the canonical user is not one layer short` |
| P05 | script path interpolated into the launch unquoted | `the script path is not one layer short...` |
| P06 | handoff stops binding to the canonical user | `the handoff carries the exact SHA-256 of the canonical user` |
| P07 | hook-active key stops being per-download | `the hook-active receipt is written once and cleared once` |

The first campaign found **P04, P05 and P06 caught only by the frozen-bytes
pin**, not by the behavioural cases. That was a real weakness in the tests, not
in the code: the launch sits five nesting layers deep, so the outer layers escape
a value even when its own quote is dropped, and "the raw value is absent" is
true either way. The cases were rewritten to measure escaping **depth** — a
value quoted properly appears at depth 6 and must not appear at depth 5 — and
re-run; all seven are sensitive now.

### Runtime evidence, and its exact limit

Disposable lab, `ivanshift/rutorrent:latest` `7cea0d172586`, rTorrent
**0.16.21**, torn down afterwards with no container left.

The fork's own XMLRPC proxy refuses `method.*` through httprpc with HTTP 403 —
correct behaviour from package 3 — so the probe went to the daemon's SCGI socket
directly.

```text
system.client_version                                  0.16.21
method.insert  "", rr.receipts.v1, multi|private       0  (and "Invalid key." when repeated,
                                                          exactly as the contract records)
method.set_key "", event.download.inserted_new, ...    0        the 2386 bytes were accepted
method.has_key installed key                           1
method.has_key never-installed key                     0
```

**This does not prove the grammar parses.** A deliberately broken variant
(`branch=` → `branch`) was **also accepted** with result 0, so `method.set_key`
stores the string without parsing it. The check therefore proves installation
and retrieval, and nothing about validity. Real grammar validation needs the
event to fire against an inserted download, which is Task 8's runtime acceptance
and needs the worker protocol Tasks 6-7 build. rTorrent 0.9.8 was not exercised
at all: no such image is present and building one is a from-source compile.

### Why `init.php` was not wired

The contract gives `init.php` the *ledger and profile install protocol* — an
idempotent `method.insert = "", rr.receipts.v1, multi|private`, coherent ordered
`system.multicall(list_keys, get, required has_key)` validation, lifecycle
acquire, generation handoff and loop suppression — not merely two `set_key`
calls. Installing the functional hook without that protocol arms worker launches
against a transaction protocol Tasks 6 and 7 have not built, and the profile
classifier would read the resulting pair as `invalid` because no `pf:` claim
exists. Building half of it would be inventing the missing half.

So the builder ships with no production caller yet. That is visible and
intentional rather than dead code: the contract assigns all three canonical
builders to `update.php`, the other two already sit there consumed only by the
profile classifier, and the `init.php` protocol is the named next step.

### Unresolved risks

1. Task 5 is not finished — only its `update.php` half is.
2. The action's grammar is unvalidated against a firing event on either daemon.
3. rTorrent 0.9.8 has no runtime evidence in this run.
4. The frozen action bytes were derived from the contract's own expression list;
   the daemon confirmed storage, not meaning.

### State

```text
push  no        PR  no        master product integration  no
user diagnostic files  untouched
```
