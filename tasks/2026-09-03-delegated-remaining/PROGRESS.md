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
