## Summary

- replace the duplicated SCGI clients with one shared framed transport
- retry bounded partial writes and keep connect/write/read time budgets separate
- parse one exact `Content-Length`, stop at the declared body, and enforce a configurable body cap
- preserve raw responses for core XMLRPC parsing and return body-only responses from `rpc2.php`
- classify routine transport failures without adding request or remote response text to their logs

## Dependency

This commit is currently stacked on the httprpc refusal-response change because
both packages modify `rpc2.php`. It must be rebased onto `Novik/ruTorrent:master`
after that predecessor lands. Opening the current branch against `master` before
then would include both packages in one pull request.

## Problem

The current core XMLRPC path and `rpc2.php` each assume that one `fwrite()` sends
the whole SCGI request and then read the reply until the peer closes the socket.
PHP permits a positive short write, so a request can be truncated. rTorrent
already declares the response length, so waiting for EOF can also keep a PHP
worker occupied after the complete response has arrived. The duplicated paths
also conflate connect and transfer timeouts and do not share one response-size
policy.

## Behavior after this change

`rSCGITransport` owns the byte protocol only:

- exact SCGI netstring framing and trust bit;
- complete writes in slices no larger than 64 KiB;
- one monotonic request-write deadline plus an independent response idle timeout;
- strict header grammar and one case-insensitive `Content-Length`;
- exact-body completion without waiting for EOF or accumulating a coalesced suffix;
- raw and body response modes;
- a 64 MiB default declared-body limit configurable up to rTorrent's 100 MiB
  wire ceiling, plus a separate 64 KiB header limit;
- stable failure codes returned to the caller, with no transport-level logging.

XML and XMLRPC fault validation remain with the consumers. Existing
configurations that do not yet define the new transfer-timeout or response-limit
variables use safe defaults without warnings. Existing opt-in core call/fault
logging remains unchanged.

## Compatibility

- PHP 7.4 and later
- runtime verified with rTorrent 0.9.8 and 0.16.21
- TCP SCGI and UNIX-domain sockets using port `0`

## Verification

- focused transport suite on PHP 7.4, 8.1 and 8.5: 34 methods, 129 assertions, no failures on each runtime
- one recorded sequential full-harness matrix on PHP 7.4, 8.1 and 8.5: 50 files,
  344 methods, 1990 assertions plus 127 TAP checks, no failures; a later
  independent repeat hit the unrelated pre-existing `_task` process-exit race
  after all SCGI tests passed
- PHPStan 2.2.9 level 0 and changed-file lint: clean
- real UNIX-socket calls against disposable rTorrent 0.9.8 and 0.16.21 daemons: exact successful responses
- deterministic mutations cover short writes, deadline resets, trust framing, response limits/modes, malformed and duplicate lengths, EOF/timeout classification, and accidental XML validation in the transport
