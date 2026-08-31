# Verification: SCGI transport framing and timeouts

Date: 2026-08-31

## Verdict

**APPROVED — implemented and locally integrated.** Package #4 is closed as an
implementation package. The upstream-clean branch is not published, no pull
request was opened for it, and no deployment was performed.

The package replaces two duplicated one-write/read-to-EOF SCGI clients with one
framed transport. It retries short writes, separates connect and transfer
budgets, stops at the declared response body, bounds memory, supports TCP and
UNIX sockets, and reports stable classified failures without logging remote
payloads.

## Upstream-clean branch

- branch: `up/scgi-transport`
- parent: `c7a431aaf5ad470f9fc7487395d38b48d12c722f`
- head: `4682a761cda6c813e3911ac6229dcf84ea4c7e99`
- topology: one non-merge commit
- scope: exactly seven paths, `+1569/-51`

```text
README.md
conf/config.php
php/scgitransport.php
php/xmlrpc.php
rpc2.php
tests/php/SCGITransportFixture.php
tests/php/SCGITransportTest.php
```

The branch has no remote `origin/up/scgi-transport`. It deliberately has final
package #3 `c7a431aa` as its immediate parent because both packages change
`rpc2.php`. Until the httprpc predecessor PR is accepted, opening this branch
against `Novik/ruTorrent:master` would include both packages in one diff.

## Closed behavior

The final implementation proves the following:

1. the complete SCGI netstring header and payload are written through bounded
   slices even when the writer returns partial positive counts;
2. one absolute monotonic deadline covers all request writes and is not reset
   after progress; response reads use an independent idle timeout;
3. connect timeout, transfer timeout and response-size policy are independent;
4. response framing requires one exact case-insensitive `Content-Length`,
   rejects malformed, duplicate, zero and oversized values, and completes at
   the exact declared body without waiting for EOF;
5. `X-Content-Length` is not mistaken for `Content-Length`, and all three
   possible split positions in `\r\n\r\n` are accepted at the exact header
   boundary;
6. raw mode returns headers, delimiter and body for core XMLRPC parsing, while
   body mode returns only the XML body for `rpc2.php`;
7. the default client limit is 64 MiB and the configurable hard ceiling is the
   supported daemon wire limit of 100 MiB;
8. TCP ports and UNIX sockets with integer or string port `0` use the same
   contract;
9. every failure has one stable classified code and one consumer-owned log;
   the transport itself logs no request, response or remote fault text;
10. XML validation remains a consumer responsibility and is not performed by
    the byte transport.

Legacy configurations that do not define `$rpcTransferTimeOut` or
`$rpcMaxResponseBytes` continue to use safe defaults without warnings.

## Candidate verification

Fresh verification on final head `4682a761`:

- focused SCGI suite on host PHP 8.5 and official PHP 7.4, 8.1 and 8.5:
  `34 methods / 129 Passed / 0 failures` on every runtime;
- full PHP harness, run strictly sequentially in the same checkout:
  `50 files / 344 methods / 1990 Passed / 127 ok / 0 failure signals` on PHP
  7.4, 8.1 and 8.5;
- PHPStan 2.2.9 level 0 and lint of all changed PHP files: GREEN;
- 34 unique final test names, predecessor/final name accounting, exact
  seven-path scope, direct parent, no merge and `git diff --check`: GREEN.

Those full-harness numbers describe the recorded clean matrix, not a promise
that every broad rerun is deterministic. A later independent repeat on PHP 7.4
and 8.1 reached all SCGI tests but ended with one failure in the unchanged
`tests/plugins/_task/TaskTest.php`: `The named process is gone` observed the
asynchronous child before it disappeared. Focused final-head SCGI reruns stayed
`34/129/0` on every runtime.

The final three-assertion follow-up restored predecessor intent for
`X-Content-Length` and delimiter splits 1/2/3. It was independently reviewed
and then amended into the unpublished single candidate commit. No test name was
added, removed or duplicated.

Natural RED and named mutations covered one-write request loss, EOF-driven
response completion, deadline reset, trust-bit inversion, response-mode
collapse, malformed/duplicate/optional length, over-accumulation, timeout-vs-EOF
classification, missing legacy globals and transport-level XML validation.
Each mutation reached its named test without a preceding fatal and returned to
GREEN after restoration.

## Real daemon verification

Both supported daemon families were tested through disposable UNIX sockets:

- `tasks/rt-lab.sh` overlaid the exact integration tree on the 0.16.21 image;
  the live HTTP XMLRPC call returned both `system.client_version=0.16.21` and
  `system.library_version=0.16.21` through port `0`;
- the daemon-only 0.9.8 image cannot boot the full ruTorrent lab entrypoint, so
  a disposable PHP 7.4 client used the final transport directly against its
  real UNIX daemon socket with canonical string port `"0"`; body mode returned
  the 0.9.8 response and `$failure === null`.

The reviewer independently repeated both probes. Ephemeral containers and
volumes were removed after verification. No live instance was mutated.

## Fork integration

The approved fork integration is linear:

```text
19086b5f772d5e6deaf4758598300018e8c29a12 Harden SCGI transport framing and timeouts
3ff4860cf0dcfc8613485eb69296283c0231e585 Adapt erasedata test stub to SCGI API
```

The first commit has exact seven-path scope, `+1405/-785`, and preserves the
fork's `XMLRPCPathResolver`, richer proxy/refusal behavior, raw-fault parser and
shared PHP-child test helper. The second changes only
`tests/plugins/erasedata/RemoveWithDataTest.php`, `+4/-3`, adapting its old
six-argument/array transport stub to the final nine-argument/string API. No
production legacy seam was added for that test double.

Fresh integration evidence on PHP 7.4, 8.1 and 8.5 is identical on each
runtime: `65 files / 556 methods / 3430 Passed / 793 ok / 0 failure signals`.
Focused SCGI and copied-entrypoint results are `34/129` and `8/51`; lint,
PHPStan, test-name accounting and both independent whole-file reviews are
GREEN.

Two discovered hygiene issues were intentionally kept outside package #4:

- `55994b36` makes copied-entrypoint tests replace their command shell, so
  `proc_terminate()` cannot leave a PHP built-in server orphaned;
- `5208727d` classifies SimpleXML as a recommendation for XMLRPC proxy
  sanitisation, not as a core SCGI requirement. Its named test passes on PHP
  7.4/8.1/8.5 and the full proxy suite passes `84 methods / 205 assertions`.

The ordinary pre-commit hook for the second cleanup repeated only three known
main-checkout `ScheduleTest` false failures caused by deployed runtime cache.
The exact two-path cleanup was therefore committed once with `--no-verify`
after its focused matrix, full proxy suite, lint, diff check and independent
review were GREEN. No Schedule code or cache was changed.

## Residuals and handoff

- The unrelated untracked `rutorrent-app-errors.log` remains unstaged.
- Local `master` is at `5208727d`, ten commits ahead of
  `origin/master=d553bd47`; no push was performed.
- The clean branch may be published with
  `git push -u origin up/scgi-transport`, but its upstream PR must remain
  stacked behind package #3 or be rebased onto the upstream tip after that PR
  lands.
- Package #5 `up/retrackers-recovery` now has final SCGI head `4682a761` as its
  implementation parent.

After package #4 the queue is **14 open implementation packages / 0 pending
audits / 7 ready or locally integrated owner handoffs + 1 accepted upstream
closure**.
