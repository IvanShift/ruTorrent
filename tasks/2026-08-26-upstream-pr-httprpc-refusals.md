# Upstream PR — finish what #3192 started on the httprpc door

**Prepared:** 2026-08-26
**Base:** `upstream/master` @ `515a738e`
**Origin:** fell out of merging upstream into the fork (`f9c6dc19`). All three fixes
already exist in the fork and are covered by the merged test suite (PHP 2508/39, JS 238/20).
**Shape:** one PR, three commits. All three touch the same two files and the same idea —
a refusal is not an outage, and the two doors must answer it the same way.

> **2026-08-29 correction.** The implementation handoff in this draft is
> superseded by
> `tasks/2026-08-28-upstream-delivery/REVIEW-httprpc-refusals-2026-08-29.md`.
> Runtime probes disproved the claimed `post_max_size` explanation: PHP 7.4.33
> and 8.5.4 both left a 2049-byte `php://input` body readable with
> `post_max_size=1K`. The accepted contract treats a `false` read and an empty
> string as separate HTTP 400 cases and contains no `post_max_size` hint.

---

## Why this is a coherent PR

Upstream #3192 ("report a refused XMLRPC call as a refusal, not an outage") is right and
already merged. It just stops one step short in three places, all on the httprpc door,
while `rpc2.php` already does the right thing next to it. Every commit below closes a gap
between the two doors rather than introducing a new idea, so the PR argues itself.

---

## Commit 1 — a refusal must stop, not fall through to rtorrent

**File:** `plugins/httprpc/action.php`

At `upstream/master:plugins/httprpc/action.php:725-727` the refusal branch answers 403 and
then continues straight into `rXMLRPCRequest::send($decision['payload'], $decision['trusted'])`:

```php
    CachedEcho::send(XMLRPCProxy::rejectionFault($decision['method']), "text/xml");
}
$result = rXMLRPCRequest::send($decision['payload'], $decision['trusted']);
```

This is normally masked because `CachedEcho::send()` ends the request — but not on every
path. `php/utility/cachedecho.php:38-47` **returns instead of exiting** on the gzip branch:

```php
if($encoding && ($len>=2048)) { ... passthru($gzip." -".$phpGzipLevel." -c < ".$randName); unlink($randName); return; }
```

**Reachability, measured — state this honestly in the PR:**
- typical `rejectionFault()` body: **314 bytes** -> under 2048 -> `exit($content)` -> unreachable
- the method name is caller-controlled and is interpolated into the fault string, so a
  ~1800+ character `<methodName>` pushes the body over the threshold
  (measured: a 5000-char method name yields a **5302-byte** fault)
- also requires `$phpUseGzip = true` (default is `false` in `conf/config.php`) and a client
  sending `Accept-Encoding: gzip`

**Consequence when it does fire:** the refused payload is `''` (`reject()` returns an empty
payload), so no refused command executes — `rXMLRPCRequest::send('')` returns false. What
actually happens is response corruption: a second body ("Could not reach rTorrent over
XMLRPC. Is rTorrent running?") is appended after the gzipped fault, plus a `header()` call
after output has started. So: **not a vulnerability, a malformed answer under a
caller-influenced, operator-gated condition.** Do not oversell it — this maintainer
verifies "how to verify" sections and calls out overstatements (see
`2026-08-15-upstream-pr-plan.md`, #3164 feedback).

**Fix:** an explicit `exit;` after the refusal, and after the 500 branch, with a comment
naming why (`CachedEcho::send()` returns rather than exits on its gzip/passthru branch).
The invariant "a refused call must never reach rtorrent" should not depend on a size
threshold in an unrelated helper.

---

## Commit 2 — an empty request body is not an rtorrent outage

**File:** `plugins/httprpc/action.php`

`rpc2.php:139-146` already returns HTTP 400, but combines read failure and an
empty body and carries a diagnostic which later runtime probes disproved:

```php
if($raw === false || ($raw === '')) {
    rpc2_log('empty request body (check post_max_size against the largest torrent you add)');
    rpc2_fault('400 Bad Request', 'Empty XMLRPC request.');
}
```

The httprpc door has no equivalent. An empty body reaches `decide('')`, fails `parseXml()`,
is forwarded as `forward('', false, 'untrusted (invalid XML)')`, and `rXMLRPCRequest::send('')`
returns false because `php/xmlrpc.php:97-104` guards on `strlen((string)$data) > 0`. That
lands on the `$result === false` branch and answers **500 "Is rTorrent running?"** — the exact
misdiagnosis #3192 exists to remove, for a request rtorrent was never asked about.

An empty body is still a client-side malformed request rather than an rTorrent outage.
It must not be attributed to `post_max_size`: PHP 7.4.33 and 8.5.4 HTTP probes
with a 2049-byte XML body and `post_max_size=1K` returned the complete body from
`php://input` on both runtimes.

**Corrected fix:** add separate `false`-read and `''`-body HTTP 400 branches to
httprpc after `$proxyLog` is resolved, and split the corresponding rpc2 guard.
Use classified read-failure/empty-body logs without a `post_max_size` hint while
preserving rpc2's existing client-facing 400 XML fault.

---

## Commit 3 — one refusal, one sentence, both doors

**Files:** `php/xmlrpc_proxy.php`, `rpc2.php`

Today the two doors describe the same refusal differently:
- httprpc renders `XMLRPCProxy::rejectionFault()` -> "The command 'X' was rejected by this server."
- `rpc2.php:157` answers the generic 'This XMLRPC call is not allowed on this endpoint.'

**Fix:** extract `XMLRPCProxy::rejectionMessage($method)`, have `rejectionFault()` build its
fault from it, and have `rpc2.php` render the same sentence into its own envelope via
`rpc2_fault()`. Lowest value of the three, but it is the commit that makes commits 1 and 2
read as one idea rather than two patches.

---

## How to verify (for the PR description)

- `cd tests && bash php-test.sh` — must stay at zero failures; `XMLRPCProxyRejectionTest.php`
  (upstream's own #3192 test) still passes unchanged.
- Docker 8.1 half of the matrix once before submitting:
  `docker run --rm -v <checkout>:/w -w /w/tests php:8.1-cli bash php-test.sh`
  (containers write as root — `sudo chown -R dev:dev` afterwards).
- Commit 1 reachability demo: set `$phpUseGzip = true`, POST XML whose `<methodName>` is a
  denied command padded to ~2000 characters with `Accept-Encoding: gzip`, and observe the
  appended second body on `upstream/master` versus a clean single fault with the patch.
- Commit 2: POST an empty body to `plugins/httprpc/action.php` with no `mode` — 500 before,
  400 after.

## Do NOT include in this PR

- The fork's `$XMLRPCProxyAllowRootDirectory`, `XMLRPCPathResolver::deepestExistingAncestor`,
  `ErasedataManifestCodec` layer, the `$force` validation or the username shell-sanitisation.
  Upstream took an earlier twin of that work as #3187/#3188 and the fork's hardened version is
  a separate, much larger conversation. Keep this PR to the refusal-vs-outage idea.
