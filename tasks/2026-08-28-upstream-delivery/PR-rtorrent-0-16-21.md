# Title

tests: cover rTorrent 0.16.21 compatibility

# Body

## Problem

ruTorrent's production compatibility gates already cover the rTorrent 0.16.x
series, but the explicit tests stopped at 0.16.19 or 0.16.20. That left the
current 0.16.21 release implicit: a later alias or version-gate refactor could
misclassify it without a release-specific failure.

## Change

Add rTorrent 0.16.21 to the existing characterization rows for:

- the declared 0.16.x support policy;
- canonical port and socket command aliases;
- direct `d.is_partially_done` use instead of the pre-0.9 no-op fallback.

`0x1015` is the packed form of `0.16.21` produced by the existing version parser.
This is a three-file test-only change; it does not add a production gate or
claim to introduce runtime support.

## Verification

```text
Jest:          20 suites, 196 tests passed
PHP 8.5:      46 files, 285 tests, 1790 assertions passed
PHP 8.1:      46 files, 285 tests, 1790 assertions passed
```

Mutation checks excluded 0.16.21 from the relevant production gates. The new
JavaScript and PHP rows then failed while the earlier-version rows still passed;
the named tests executed without fatal, parse, or uncaught errors.

An independent disposable local smoke against rTorrent 0.16.21/API 26 also
confirmed all seven command-surface entries asserted by the compatibility tests.
That smoke is supplemental and is not presented as a full UI or production
certification.
