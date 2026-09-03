# Findings discovered while implementing the remaining packages

Opened 2026-09-03. Append-only. This file collects problems noticed **along the
way** that are not themselves one of the 18 implementation packages.

Each entry says whether it was fixed here, and if not, what it blocks.

Status vocabulary:

- `FIXED IN CANDIDATE` — corrected inside a package candidate on this run;
- `OPEN` — real, reproduced, nobody owns it yet;
- `CONTRACT` — a frozen contract document disagrees with the current tree;
- `OBSERVATION` — latent fragility, not currently reachable in production.

---

## F1 — `CONTRACT` — upstream #3251 landed part of package 14 and now conflicts with its §13

Upstream `62083b85` ("Read one XMLRPC proxy policy at both entry points") makes
`plugins/httprpc/action.php` load `conf/xmlrpc_proxy.php` before the plugin conf,
and strips the duplicated `$XMLRPCProxySafeParams` out of
`plugins/httprpc/conf.php`.

The package 14 contract §13 requires that same file to carry **unset-only
defaults**. The new upstream test `tests/php/XMLRPCProxyPolicyParityTest.php`
lists any conf file whose *source text* matches `\$XMLRPCProxySafeParams\s*=`,
re-evaluates it in isolation with the variable pre-set to `null`, and requires
the result to equal the shared list exactly. `isset(null)` is false, so an
unset-only default does assign, and the file is then compared. The default is
therefore either byte-identical to the shared list — carrying no policy, making
the §13 layer vacuous — or it fails a test package 14's seven-path scope forbids
editing.

Blocks: package 14. Recorded in full in `PROGRESS.md`.

---

## F2 — `CONTRACT` — #3251 also crosses package 14's §12 elevated set

`testTheSharedPolicyCarriesTheViewActions` requires `conf/xmlrpc_proxy.php` to
list `d.open`, `d.close`, `d.start` and `d.stop` as safe parameters. Package 14
§12 instead governs `d.open`, `d.start`, `d.stop` and `d.delete_tied` as
*elevated exact shapes*, and never mentions `d.close`.

Upstream's commit message carries live measurement for this — a `d.multicall2`
carrying `d.stop=` refused to an untrusted caller on 0.16.21 and 0.16.20 — so
the two mechanisms are competing over commands that demonstrably matter.

Blocks: package 14, together with F1.

---

## F3 — `FIXED IN CANDIDATE` — seven reachable defects in the upstream manual check route

Reproduced against exact upstream `cd814cb5`, each by a named failing test that
drives the real entrypoint (no reimplementation, no fatal before the failure).
Fixed by the package 15 candidate.

| # | Defect in `plugins/rutracker_check/action.php` / `batch_check.php` | Evidence |
|---|---|---|
| 1 | Handover named `rutorrent-prm-<user><time()>`: two checks in one second collide, the second write lands on the first, one selection is lost | `testTwoChecksInOneSecondGetSeparateHandovers` — 2 launches, 1 distinct path |
| 2 | `file_put_contents()` result ignored: an unwritable or partial handover is still reported as accepted | `testAnUnwritableHandoverIsRefusedRatherThanQueued`, `testAnIncompleteHandoverWriteIsRefusedRatherThanQueued` |
| 3 | `Utility::getPHP()` interpolated into the shell unquoted, and `shell_exec` result discarded: an installed PHP path containing a space silently never starts the worker | `testAPhpPathContainingASpaceStillLaunchesTheWorker` — 0 launches |
| 4 | Request body read unbounded, and whatever follows `hash=` is passed to the checker unvalidated | `testAnOversizedBodyIsRefusedWithoutLaunchingAWorker`, `testOnlyRealHashesReachTheWorker` (`'not-a-hash'` reached the worker) |
| 5 | One `Throwable` ends the whole batch **and** skips `@unlink`, leaking the handover | `testOneFailingHashDoesNotCancelTheRestOfTheBatch` (1 of 3 checked), `testTheHandoverIsRemovedEvenWhenAHashThrows` |
| 6 | No classified lifecycle for a refused handover, refused launch or failed check | `testAWorkerFailureIsLoggedAsAClassifiedEnglishReason` |
| 7 | `{}` is the answer to every outcome, so the UI cannot tell a queued batch from a refused one | `testAQueuedBatchAndARefusedBatchAnswerDifferently` |

These are upstream defects, present in `Novik/ruTorrent` at `cd814cb5`, not
fork-only.

---

## F4 — `OPEN` — a non-2xx answer from any plugin action endpoint reports rTorrent as down

Not a new defect, but the constraint that decided package 15's response design,
and it applies to **every** plugin that answers `theWebUI.perform()`.

`js/webui.js` `getTorrents()` passes an error callback that runs
`theWebUI.systemInfo.rTorrent.started = false` before `theWebUI.error(...)`. The
manual check reaches this endpoint through exactly that path, so a 400/503 from
a *plugin* — over a request rTorrent never saw — makes the UI announce the
daemon as stopped, and skips the plugin's own response handler, which is the
only thing that could have explained the refusal.

Package 15 therefore keeps every handled outcome at 2xx and puts the outcome in
the body. Whether other plugin endpoints have the same mismatch was not audited.

---

## F5 — `CONTRACT` — the disposition audit's `cantFetchInfo` claim is stale

`REVIEW-disposition-wave-2026-08-29.md` §4 rejects the old manual-entrypoint
snapshot partly because "JS использовал несуществующий `cantFetchInfo`". That key
does exist in the fork, at `plugins/rutracker_check/lang/en.js:10`
(`"Failed to queue the update check."`).

The real constraint is different and still binding: `lang/en.js` is **not** among
package 15's six paths, and a key added to `en.js` alone would render as
`undefined` in every other shipped language. The package 15 candidate therefore
uses only keys that already exist in every language file (`checkTorrent`,
`Queued`, `Error`), and pins that with a test that fails if any rendered message
contains `undefined`.

---

## F6 — `OPEN` — `ScheduleTest` reads a runtime cache from the primary checkout

Already noted in `STATUS-18-PACKAGES-2026-09-01.md` as a test-hygiene follow-up;
re-confirmed on this run and still unowned.

`tests/php/ScheduleTest.php` reads the real `share/settings/rtorrent.dat`, which
in the primary checkout is a root-owned 24 KB runtime cache from 2026-08-29. It
shifts the `schedule` parameter indices, so three cases fail there and the
pre-commit hook refuses every commit, including documentation-only ones.

Measured this run: 3 failures in the primary checkout, `11 tests, 0 failures` in
a clean worktree at the same SHA. The cache was not deleted or edited.

---

## F7 — `OBSERVATION` — `addTorrents()` would throw on a response carrying no `torrents`

`theWebUI.addTorrents()` does `Object.entries(data.torrents)`, which throws
`TypeError` when the key is absent.

Not currently reachable: `perform()` always appends `list=1`, so `listRequired`
makes the core discard the action response and follow up with its own list
request, whose answer always carries `torrents`. An action endpoint invoked
without `list=1` whose JSON lacked the key would take the throw. Recorded rather
than changed — `js/webui.js` is outside every package scope on this run.

---

## F8 — `OPEN` — the shipped entrypoint tests leak one `php -S` server per case

Found by running out of them: after a few full `php-test.sh` passes plus a
mutation campaign, 30 abandoned development servers were still listening, the
oldest for ~54 minutes. The symptom is not a clear error — it is later runs
failing with `could not start the copied entrypoint server`, and `jest` dying
with `Aborted (core dumped)`. Both look like product regressions and are not.

Cause: `proc_open()` given a **command string** runs it through `/bin/sh`, so
the PID it returns is the shell's. `proc_terminate()` then signals the shell;
the `php -S` it spawned is reparented to init and keeps its port, its temp tree
and its memory. Passing the command as an array, or prefixing it with `exec `,
makes the shell replace itself so the returned PID is the server's.

Affected files, both outside every package scope on this run:

```text
tests/php/XMLRPCProxyEntrypointTest.php    -t /tmp/rutorrent-entrypoint-*
tests/php/... SCGI rpc2 cases              -t /tmp/rutorrent-scgi-rpc2-*
```

The package 15 test hit the same trap and was corrected inside its own file
(`exec ` prefix, with the reason in a comment). Measured after the fix: a full
13-case run leaves **0** servers behind.

Worth fixing in the shipped tests, but it is a test-harness change that belongs
with `2026-08-28-harness-defects.md` rather than inside an implementation
package.

---

## F9 — `CONTRACT` — upstream shipped an erasedata drain queue that collides with package 6's approved design

`a5509dc5` (#3240) and `dcf3fb96` (#3248) added `plugins/erasedata/pending.php`
and `tests/plugins/erasedata/PendingQueueTest.php`, and rewrote
`plugins/erasedata/erase.php` from a direct `erasedataRemoveWithData()` call to
record-then-drain.

Package 6's contract freezes eight production and two test paths and forbids
new scope without a separate current-base finding. `pending.php` would be a
ninth production path and `PendingQueueTest.php` a third test path, and
`erase.php` — contract path 3 — no longer resembles the file the approved carve
targets.

The collision is substantive: the contract records that a targeted one-shot
drain was **rejected**, and a durable wake generation with one coalesced
repeating `erasedata-drain` schedule was approved in its place, with the user's
explicit agreement. Upstream has since shipped a `drain.lock` + attempt-counter
design closer to the rejected shape, and with no acknowledgement step at all.

Blocks: package 6, and through it packages 7, 8, 9, 10, 11, 12, 16, 17, 18.

This is the single highest-value unblocking decision in the remaining queue:
one contract ruling reopens nine of the thirteen open packages.

---

## F10 — `OPEN` — the upstream sync is small, and blocked on exactly two contract rulings

Measured, not estimated: a trial `git merge upstream/master` was run in a
throwaway detached worktree and aborted; `master` was never touched.

```text
master           ee260ac5   (fork ahead by 164)
upstream/master  cd814cb5   (upstream ahead by 18)
merge-base       495e2a54
conflicts        3
```

| Conflicting path | What the conflict actually is |
|---|---|
| `plugins/rutracker_check/plugin.info` | trivial: `plugin.version` 6.0 vs 5.1, plus upstream's new `php.extensions.warning` line |
| `plugins/httprpc/conf.php` | the package 14 §13 decision (F1/F2) |
| `plugins/erasedata/erase.php` | the package 6 design decision (F9) |

Test-file survival was checked explicitly, because a merge that moves tests
deletes them without a conflict:

```text
fork-only test files      18
missing after the merge    0
new upstream test files    6
```

So the mechanical risk is low. The blocker is not mechanical: two of the three
conflicts *are* the two frozen-contract decisions this run stopped on. Resolving
them inside a merge would settle both contracts by hand, silently, which is
exactly what the delegation brief forbids.

Recommendation: rule on F9 (whose erasedata drain design wins) and on F1/F2 (how
package 14 §13 coexists with upstream's `XMLRPCProxyPolicyParityTest`). With
those two answers the sync becomes mechanical, and packages 6-12 and 16-18
unblock at the same time.
