# Avoid probing binary torrent data as filesystem paths

## Summary

- Treat already-loaded `.torrent` contents containing NUL bytes as data, not
  as a possible filesystem path.
- Skip `is_dir()` and `is_file()` probes for such binary input.
- Add a regression test covering this behavior on PHP 7.4.

## Why this is needed

`Torrent::__construct()` accepts both a filesystem path and the bencoded
contents of a `.torrent` file.

The `pieces` field contains binary SHA-1 data and may legally include NUL
bytes. On PHP 7.4, passing that string to `is_dir()` or `is_file()` emits
invalid-path warnings. With a strict error handler, a valid torrent may fail
before decoding; displayed warnings may also corrupt the response.

## Before

```text
loaded .torrent contents
        ↓
is_dir() / is_file()
        ↓
PHP 7.4 warnings
        ↓
decoding may be interrupted
```

## After

```text
loaded .torrent contents
        ↓
contains NUL → cannot be a path
        ↓
skip filesystem probes
        ↓
decode normally
```

Ordinary filesystem-path handling remains unchanged.

## Tests

- PHP lint passed on PHP 7.4, 8.1, and 8.5.
- Focused torrent tests passed on all three versions: 32 methods, 145 assertions.
- Full PHP suite passed on all three versions: 48 files, 1,810 tests.

## Handoff

- branch: `IvanShift:up/php74-binary-metainfo` at `a1e60e69`;
- upstream pull request: #3229;
- status on 2026-08-31: open against `Novik/ruTorrent:master`.
