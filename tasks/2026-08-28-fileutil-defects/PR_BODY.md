## Summary

- parse `RU_PROFILE_MASK` as validated octal at the configuration boundary;
- preserve existing non-regular log targets and choose append mode by target type;
- require stable absolute filesystem log paths while retaining registered stream URI support;
- make `env_check.php` distinguish filesystem paths, `file://` targets, available streams, and invalid wrappers.

## Why

Official PHP FPM images populate `$_ENV` (`variables_order=EGPCS`). An empty
`RU_PROFILE_MASK` therefore reached permission expressions as a string and made a
real FastCGI request fail with `TypeError: Unsupported operand types: string & int`.
String octal values also produced incorrect permission bits.

`FileUtil::toLog()` used `is_file()` as an existence check, so each write touched
and re-chmodded an existing FIFO or device. It also opened every target with
`ab+`, unnecessarily requiring read access for regular append-only logs. Finally,
a relative `$log_file` resolved differently from web and scheduler entry points.

## Verification

- current-upstream full `tests/php-test.sh`: PHP 8.5.4 and PHP 8.1.34 root
  container, 48 files / 303 named tests / 1815 `Passed:` in each matrix;
- all seven changed paths lint cleanly on PHP 7.4.33; a full current-base 7.4
  run is blocked by upstream's unrelated `php/Torrent.php` native `mixed`
  properties, while the earlier pre-#3213 base completed the full 7.4 suite;
- regression mutations independently restore and detect each permission, mode, path, and wrapper defect;
- real PHP 8.1 FPM request with empty `RU_PROFILE_MASK`: base returns HTTP 500, this branch returns HTTP 200;
- real FIFO retains mode `0600`; write-only regular target receives the log line; relative target is not created;
- disposable `ivanshift/rutorrent:latest` lab with this working tree: `/php/getplugins.php` returns HTTP 200 with no PHP fatal/type error in the container log.

The lab smoke used a disposable container rather than a personal production torrent workload.

The final handoff commit is `79190927`, one commit directly on
`upstream/master` `755404f3`. Its exact scope remains seven files and
`+514/-10`; `range-diff` confirms that the rebase did not change the patch.
