# Avoid probing binary torrent metadata as filesystem paths

## Summary

- Treat strings containing NUL bytes as binary bencoded data rather than
  filesystem path candidates.
- Apply the same path-candidate check to the directory/file probes in
  `Torrent::build()` and the file probe in `Torrent::decode()`.
- Add a regression test using raw metainfo whose binary `pieces` field contains
  NUL bytes.

## Problem

`Torrent::__construct()` tries to interpret its input as a source directory or
file before decoding it as bencoded metainfo.

A torrent's `pieces` field contains concatenated binary SHA-1 hashes and may
contain NUL bytes. Passing the complete raw metainfo string to `is_dir()` or
`is_file()` produces an invalid-path warning on PHP 7.4:

```text
is_dir() expects parameter 1 to be a valid path, string given
```

A strict error handler can turn that warning into an exception, leaving
otherwise valid torrent metadata unavailable. Even where warnings are not
promoted, probing binary payloads as filesystem paths is unnecessary.

Filesystem paths cannot contain NUL bytes, so such strings can safely bypass
every path probe and proceed directly to bencode decoding.

## Before and after

| Scenario | Before | After |
|---|---|---|
| Raw metainfo contains a NUL byte | Passed to `is_dir()` and both `is_file()` probes; PHP 7.4 emits warnings | Filesystem probes are skipped and the payload is decoded directly |
| A strict error handler is active | The warning can abort parsing | Valid binary metainfo parses normally |
| Input is a valid file or directory path | Handled as a path | Behavior remains unchanged |

## Tests

- The regression test was confirmed RED on the current upstream base under
  PHP 7.4.
- Focused regression passed on PHP 7.4, 8.1, and 8.5.
- Full PHP harness passed sequentially on each runtime: 48 files and 1,810
  passed assertions per runtime.
- PHP lint passed for both changed files on all three runtimes.
- PHPStan 2.2.9 completed with no errors.
- Removing any one of the three path guards makes the regression test fail.
