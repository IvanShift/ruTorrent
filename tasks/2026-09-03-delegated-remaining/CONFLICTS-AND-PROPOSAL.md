# The two blocking conflicts, and one proposed way out

> **Resolved 2026-09-03.** Independent verification accepted a corrected form
> of option A for erasedata: keep upstream's local admission seam, but bind it
> and its acknowledgement to the exact generation, retain obligations without
> finite abandonment, and preserve the approved repeating wake plus real-child
> acknowledgement. Package 6 is now 10 production + 3 test paths. For XMLRPC,
> upstream #3251 is accepted as prerequisite; the shared safe list has one
> shipped owner while independent mode/log/local-path defaults remain unset-only.
> Package 15 is integrated as `b4d68005`, and upstream is merged as `4fd60d54`.
> See `../2026-08-28-upstream-delivery/VERIFICATION-upstream-sync-packages-2026-09-03.md`.

2026-09-03. Written because nine of the thirteen open packages are blocked on
exactly two rulings, and both rulings belong to the contract owner rather than
to an implementer.

The text below is the pre-decision proposal and is retained as provenance. It
was not applied by the delegated agent; the later Codex disposition above is
authoritative. Nothing was pushed.

---

## The situation in one paragraph

Both frozen contracts were written against an upstream that has since moved.
`upstream/master` went `495e2a54` → `cd814cb5` (18 commits) during this run, and
two of those commits land inside the exact path boundaries the contracts freeze.
The conflicts are not textual; a trial merge produces only **three** conflicting
files and loses **no** fork test. The conflicts are that upstream has
independently made design decisions the contracts had already made differently.

```text
master           ee260ac5      fork ahead by 164
upstream/master  cd814cb5      upstream ahead by 18
merge-base       495e2a54
merge conflicts  3   (plugin.info trivial; erase.php = F9; httprpc/conf.php = F1)
fork-only test files lost by the merge:  0 of 18
```

---

## Conflict 1 — erasedata (F9), blocks packages 6, 7, 8, 9, 10, 11, 12, 16, 17, 18

Upstream `a5509dc5` (#3240) and `dcf3fb96` (#3248) added
`plugins/erasedata/pending.php` and `tests/plugins/erasedata/PendingQueueTest.php`,
and rewrote `plugins/erasedata/erase.php` from a direct
`erasedataRemoveWithData()` call into record-then-drain.

Package 6's contract freezes **8 production + 2 test** paths and forbids new
scope without a separate current-base finding. `pending.php` would be a ninth
and `PendingQueueTest.php` a third, and `erase.php` — contract path 3 — no
longer resembles the file the approved carve targets.

The substance:

| Concern | Package 6, approved | Upstream `pending.php`, shipped |
|---|---|---|
| serialising the work | durable wake generation, one coalesced repeating `erasedata-drain` schedule | `drain.lock` + `flock(LOCK_EX\|LOCK_NB)`; the first firing drains |
| recording a request | generation-bound v2 staging | `<hash>.pending` marker via exclusive `fopen(..., "x")` |
| giving up | exact retention, retried every pass | `$erasePendingMaxAttempts`, default 10, then abandoned |
| acknowledgement | real PHP child acknowledgement | none |

The contract explicitly **rejected** a targeted one-shot and recorded that the
user approved the durable-wake variant instead. Upstream has since shipped
something close to the rejected shape.

### Options

**A. Compose.** Treat upstream's queue as the *admission* half and package 6's
design as the *drain* half. Keep `erasedataQueueRequest()` as the record step
(it is a local file write that cannot fail on a busy server, which is the
property #3240 was after), and let package 6 replace only `erasedataDrainQueue()`'s
scheduling with the durable generation, the repeating arm and the real child
acknowledgement. Re-scope the contract to 9 production + 3 test paths, and say
in one line why.

**B. Hold the line.** Keep the frozen 8+2 boundary and carry the fork past
upstream's queue. The fork diverges further on a file upstream is now actively
developing, and every later sync repeats this argument.

**C. Replace and propose.** Build package 6 as designed, delete upstream's
queue in the fork, and offer the result upstream as a better answer.

### What I would pick, and why

**Option A.** The two designs are not competing for the same job as closely as
they first look. `erasedataQueueRequest()` answers "how does a mass erase stop
flooding the RPC server", and it answers it with an exclusive create, which is
the same primitive package 6 uses for its own staging. `erasedataDrainQueue()`
answers "who does the work and when", and that is the half package 6 was
approved to make durable — a `flock` held by one process is exactly the guarantee
that disappears when that process is killed, which is the failure the durable
wake exists for.

Composing keeps upstream ancestry on a file upstream now maintains, keeps the
fork mergeable, and — the part I would not give up — keeps the acknowledgement
requirement, which upstream's version has no equivalent of. B pays a permanent
merge tax for a boundary number. C spends the same implementation effort and
then needs an upstream negotiation before it can land.

The cost of A is honest and should be stated: the contract's 8+2 boundary and
its "no new scope" rule both have to be reopened, and `$erasePendingMaxAttempts`
has to be reconciled with "exact retention, retried every pass" — those two
rules genuinely disagree, and one of them has to lose.

---

## Conflict 2 — XMLRPC proxy policy (F1), blocks packages 14 and 7

Upstream `62083b85` (#3251) makes `plugins/httprpc/action.php` load
`conf/xmlrpc_proxy.php` before the plugin conf, strips the duplicated
`$XMLRPCProxySafeParams` out of `plugins/httprpc/conf.php`, and adds
`tests/php/XMLRPCProxyPolicyParityTest.php`.

Package 14 §13 requires that same plugin conf to carry **unset-only defaults**.
The new parity test lists any conf file whose source text matches
`\$XMLRPCProxySafeParams\s*=`, re-evaluates it in isolation with the variable
pre-set to `null`, and requires the result to equal the shared list exactly.
`isset(null)` is false, so an unset-only default does assign and is then
compared. It is therefore either byte-identical to the shared list — carrying no
policy at all — or it fails a test package 14's seven-path scope forbids editing.

### Options

**A. Drop the defaults layer.** Re-freeze §13's order as
`conf/xmlrpc_proxy.php` → `plugins/httprpc/conf.php` *as an override only, with
no shipped defaults* → `conf.local.php` → per-user. The seven-path scope stays
intact and the parity test passes by construction, because the shipped plugin
conf then defines nothing.

**B. Widen the scope.** Keep §13 and add `XMLRPCProxyPolicyParityTest.php` as an
eighth path so the contract can adjust it.

**C. Stop package 14** until the proxy work is re-planned against `cd814cb5`.

### What I would pick, and why

**Option A.** It is the smallest change, it keeps the frozen seven-path boundary
that the rest of the contract depends on, and it costs nothing real: an
unset-only default that is required to equal the shared list carries no policy,
so the layer it defines is already vacuous. Upstream reached the same conclusion
from the other direction — its commit message is about two lists being free to
drift, and they did.

### A correction to my own earlier note

I first recorded (F2) that upstream's `testTheSharedPolicyCarriesTheViewActions`
also collides with §12, because it requires `d.open`, `d.close`, `d.start` and
`d.stop` in `$XMLRPCProxySafeParams` while §12 governs `d.open`, `d.start`,
`d.stop` and `d.delete_tied` as elevated exact shapes.

On re-reading both, that is **not** a conflict. `$XMLRPCProxySafeParams` names
commands allowed as trailing slots inside a rebuilt `load.*` or multicall —
precedence step 4, "configured safe names inside those owners". §12 governs
*direct* calls of those same methods. Two different doors, two different rules,
and neither deny set contains any of the four. F2 should be treated as resolved;
F1 is the real one.

---

## What unblocks on each ruling

```text
F1 ruling  ->  package 14  ->  package 7 (also needs 6)
F9 ruling  ->  package 6   ->  7, 8, 9  ->  10  ->  11, 12, 16, 17, 18
```

One ruling on F9 reopens nine packages. One ruling on F1 reopens two. After
both, the upstream sync stops being a judgement call and becomes mechanical: the
third conflict, `plugins/rutracker_check/plugin.info`, is a version number and a
`php.extensions.warning` line.

---

## What was finished around the conflicts

Work that needed neither ruling was completed and is listed in `PROGRESS.md`:

- **package 15** — full candidate, `5a1a0d97`, one non-merge commit on
  `cd814cb5`, exactly its six contract paths, 12 named natural REDs, 16/16
  sensitive mutations, green on PHP 7.4/8.1/8.5 and in the shipped image;
- **package 5, Task 5** — the canonical functional insert action, `0bdac05d` on
  the existing branch, 7/7 sensitive mutations, both frozen sequence invariants
  still byte-identical.
