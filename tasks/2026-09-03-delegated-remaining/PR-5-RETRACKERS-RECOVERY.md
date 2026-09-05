# Recover retracker updates after partial failures

## Summary

Make the retrackers replacement workflow recoverable across partial daemon writes, lost replies and restarts.

Before, a multi-step replacement could leave an intermediate state, and an uncertain reply did not reliably distinguish an applied mutation from a failed one.

After, generation-bound ownership, durable recovery state and observed postconditions govern retries, rollback and cleanup. Stale callbacks cannot modify a replacement generation. Tracker edits preserve the torrent's metainfo bytes and use bounded file reads.

This is a substantial retrackers-only change. It does not include the separate erasedata workflow or rutracker_check replacement orchestration.

## Dependency

Requires the shared SCGI transport change. This branch is stacked on that patch; target upstream master only after that prerequisite is merged and the branch is refreshed.

## Verification

221 worker methods / 1,097 assertions and the preserved 12-method / 40-assertion sequence suite pass on PHP 7.4, 8.1 and 8.5. Full PHP harness and production PHPStan pass. Disposable rTorrent 0.9.8 and 0.16.21 labs cover partial failure, rollback, six discarded mutation replies, twelve delayed reads and eleven harmless late callbacks per daemon.
