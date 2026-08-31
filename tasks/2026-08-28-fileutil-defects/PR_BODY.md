# Title

Harden FileUtil log paths and permission handling

# Body

## Summary

- parse `RU_PROFILE_MASK` as validated octal at the configuration boundary;
- preserve existing FIFO and device permissions while using write-only append
  for regular logs;
- require stable absolute filesystem log paths while retaining `file://` and
  registered stream support;
- make `env_check.php` report invalid paths and unavailable stream wrappers
  correctly.

## Why

Official PHP FPM images populate `$_ENV`. Previously, an empty
`RU_PROFILE_MASK` reached filesystem operations as a string and could terminate
a real request with a `TypeError`; octal strings also produced incorrect
permission bits.

`FileUtil::toLog()` used `is_file()` as an existence check, so an existing FIFO
or device could be touched and re-chmodded on every write. Regular logs were
opened with `ab+`, unnecessarily requiring read access. Relative log paths could
also resolve to different files depending on the entry point's working
directory.

## Before / after

Before:

- an empty environment mask could return HTTP 500;
- string masks produced incorrect modes;
- existing non-regular log targets could have their permissions widened;
- write-only regular logs could not receive entries;
- one relative setting could create logs in multiple directories.

After:

- environment masks are validated and converted to octal integers;
- existing FIFO and device permissions are preserved;
- regular logs use write-only append while FIFO behavior remains non-blocking;
- filesystem logs must use stable absolute paths;
- stream and `file://` targets are validated according to their actual
  behavior.

## Tests

- full PHP suite on PHP 7.4, 8.1, and 8.5: 50 files, 318 methods, 1843 passing
  assertions and 127 TAP checks per runtime;
- PHPStan 2.2.9;
- syntax checks for all seven changed paths on all three PHP versions;
- focused RED/GREEN checks and ten targeted regression mutations;
- real PHP 8.1 FPM request with empty `RU_PROFILE_MASK`: current base returns
  HTTP 500, this branch returns HTTP 200;
- direct FIFO, write-only regular-file, and relative-path probes.

# Handoff

- upstream base: `f19c9d86df72ad6b1720f31252297340049e5eab`;
- local branch: `up/fileutil-defects-f19`;
- commit: `76485317b414b435a4cecb752fa6d769f67149b3`;
- exact diff: seven upstream-owned paths, `+514/-10`;
- upstream #3226 is the direct parent and is preserved, not duplicated;
- independent review: `APPROVED`, no findings.
- published upstream PR: `Novik/ruTorrent#3231`;
- GitHub checks: 8/8 GREEN.

Published by the owner with:

```sh
git push -u origin up/fileutil-defects-f19
```
