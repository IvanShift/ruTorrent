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
