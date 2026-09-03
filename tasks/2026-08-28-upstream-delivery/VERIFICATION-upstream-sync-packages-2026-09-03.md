# Verification: upstream sync and package corrections — 2026-09-03

## Outcome

- **Upstream sync: APPROVED and integrated locally.** `upstream/master` was
  fetched at `cd814cb58e260dc08a3894d3fbfd4407e966b031`; local merge commit is
  `4fd60d544b1b9604b6500fc437c6c33bf3a04d40`.
- **Package 15: APPROVED and integrated locally.** Clean candidate
  `5a1a0d9798a76bff06a07c230eaaae941b1aef49` was independently remeasured;
  fork integration is `b4d68005828c965b69c69969e835d36208c99ebb`.
- **Package 14: DESIGN APPROVED, implementation pending.** Upstream #3251 is a
  prerequisite and removes its config-design blocker.
- **Package 6: CORRECTED DESIGN APPROVED, implementation pending.** Upstream's
  queue is retained as input, but its hash-only acknowledgement and finite
  abandonment semantics are not accepted. Scope is corrected from 8+2 to
  **10 production + 3 test paths**.
- **Package 5 Task 5 increment: APPROVED, not integrated.** Commit `0bdac05d`
  provides only the canonical insert-action builder. Package 5 remains partial
  until the builder is wired and Tasks 6–8 are complete.
- No push or deployment was performed. The four user diagnostic files in the
  repository root were not modified or committed.

## Refs and measured upstream delta

```text
previous shared base       495e2a54a657efcc132dc1456db8d7e680304a8a
upstream/master            cd814cb58e260dc08a3894d3fbfd4407e966b031
local upstream merge       4fd60d544b1b9604b6500fc437c6c33bf3a04d40
package 15 integration     b4d68005828c965b69c69969e835d36208c99ebb
origin/master              2d2710eb51a35695040d11dc2f18735a6aa5cce1
```

The 18-commit upstream delta includes the three changes that materially touch
the remaining contracts:

| Commit | Upstream change | Package consequence |
|---|---|---|
| `62083b85` / #3251 | one shared XMLRPC proxy policy at both doors | package 14 config layer partly implemented; parity test becomes preservation gate |
| `a5509dc5` / #3240 | local erasedata admission marker and single drainer | useful overload-control primitive, but not generation-safe in the fork |
| `dcf3fb96` / #3248 | pending-queue coverage and explicit util dependency | adds one owned test path; finite give-up expectation conflicts with the durable contract |

The merge had three textual conflicts. `plugins/rutracker_check/plugin.info`
kept fork version/description and combined extension warnings.
`plugins/httprpc/conf.php` kept the shared safe-list owner while preserving
unset-only fallbacks for independent settings. `plugins/erasedata/erase.php`
kept the direct generation-aware path; upstream `pending.php`, its config and
tests remain in the tree for package 6 to integrate safely.

## Package 6 reproduction and corrected contract

Wiring upstream `erase.php` unchanged produced a named regression in the
fork's copied-production entrypoint test:

```text
testLegacyManifestDoesNotBlockAReaddedSameHash  RED
```

The cause is exact. `erasedataQueueRequest()` treats `<hash>.list` as proof that
the hash was already collected. In this fork that file may belong to an older
torrent generation. Re-adding a torrent with the same infohash and asking to
erase it returns success without creating a pending request or invoking
`erasedataRemoveWithData()`. Restoring the direct generation-aware entrypoint
made the test GREEN.

The accepted composition keeps upstream's exclusive local admission and
single-drainer concept, but package 6 must make the marker and acknowledgement
generation-bound, keep obligations without a finite give-up, and retain the
previously approved repeating schedule plus real PHP-child acknowledgement
before erase. The revised scope and exact requirements are in
`REVIEW-erasedata-remove-payload-2026-08-29.md`.

## Package 14 correction

The delegated report originally described two conflicts. Independent review
reduced them to none:

1. The duplicate safe-parameter default is intentionally gone. The shared
   `conf/xmlrpc_proxy.php` list is the only shipped owner; plugin/local/user
   files are override layers.
2. A method may be a safe command slot inside a rebuilt multicall and still
   require exact-shape elevation when called directly. Therefore the upstream
   view-action list does not compete with the contract's direct-call matrix.

The plugin keeps unset-only defaults for mode/log/local-path settings because
those are independent of the shared list. `XMLRPCProxyPolicyParityTest.php`
must remain unchanged and green. Package 14 is ready for RED-first
implementation on the current base.

## Package 15 independent review and integration

The candidate had one commit directly on current upstream and its six intended
paths. Exact-parent execution gave 12 named natural REDs; the candidate gave
13/13 GREEN. Production review confirmed exclusive handover creation, complete
write/flush checking, quoted executable/script/arguments, bounded and validated
request parsing, per-hash worker isolation, classified logging and distinct
`queued`/`refused`/`rejected` body outcomes.

Fork integration deliberately adds one existing aggregate test path and keeps
one fork-only production behavior:

- `RuTrackerForumIndex::spawnCrawl()` still runs after manual checks, because
  the scheduler may be disabled and non-RuTracker handlers share this route;
- the old aggregate assertions for HTTP 400/413/503 were replaced by the
  approved handled-2xx/body-status contract;
- a bounded body is the single request limit; the earlier arbitrary 4096-item
  limit is not reintroduced.

Recorded focused verification:

| Runtime | Manual real-entrypoint | Fork aggregate entrypoints | JS integration |
|---|---:|---:|---:|
| PHP 7.4 container | 13/13 | 22/22 | n/a |
| PHP 8.1 container | 13/13 | 22/22 | n/a |
| PHP 8.5 host | 13/13 | 22/22 | 49/49 |

The full PHP pre-commit suite also passed before `b4d68005` was created.

## Resume point

1. Finish package 5 from Task 5 wiring through Tasks 6–8; do not integrate the
   builder-only commit by itself.
2. Package 14 and corrected package 6 are independently ready for RED-first
   implementation from local `master=b4d68005`.
3. After package 6, unblock packages 8 and 9; package 7 waits for both 6 and 14.
4. Package 15 is closed locally; upstream handoff is a separate delivery task.
