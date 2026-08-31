## Summary

- add one shared SCGI transport for core XMLRPC calls and `rpc2.php`;
- retry partial writes and keep connect, write, and read budgets separate;
- stop reading at the declared `Content-Length` and enforce a configurable response cap;
- preserve raw-response mode for core XMLRPC parsing and body-only mode for `rpc2.php`.

## Dependency

This branch is stacked on top of #3228 because both changes touch `rpc2.php`.
It should be reviewed and merged after #3228. Once that predecessor lands,
this pull request has an exact seven-file diff.

## Why this is needed

The current two SCGI clients assume that one `fwrite()` sends the complete
request and then read until the peer closes the socket. PHP may return a
positive short write, which can truncate the request. Waiting for EOF can also
keep a PHP worker occupied after rTorrent has already returned the complete
declared body.

| Before | After |
|---|---|
| duplicated SCGI implementations | one shared transport |
| one write assumed complete | bounded loop handles short writes |
| response read until EOF | completion at exact `Content-Length` |
| one timeout covers different phases | independent connect and transfer budgets |
| response growth is not centrally bounded | configurable 64 MiB default cap |

The transport validates byte framing only. XML and XMLRPC fault handling remain
with the existing consumers, and legacy configurations use safe defaults
without warnings.

## Compatibility

- PHP 7.4 and later;
- TCP SCGI and UNIX-domain sockets using port `0`;
- runtime checked against rTorrent 0.9.8 and 0.16.21.

## Tests

- focused transport suite on PHP 7.4, 8.1, and 8.5: 34 methods, 129 assertions;
- fresh full PHP suite on all three versions: 50 files, 1,990 assertions and
  127 TAP checks, with no failures;
- GitHub-exposed copied-server readiness regressions fixed by a complete HTTP
  probe against a real static resource with an exact `200 OK` gate; full PHP
  7.4/8.1/8.5 reruns remain green;
- changed-file lint and PHPStan 2.2.9 level 0: clean;
- disposable rTorrent 0.9.8 and 0.16.21 UNIX-socket probes: passed.
