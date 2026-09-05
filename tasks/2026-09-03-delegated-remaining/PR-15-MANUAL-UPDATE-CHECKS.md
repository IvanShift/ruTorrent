# Report manual update-check failures and finish each batch

## Summary

Make rutracker_check's manual “check for update” action reliably hand the selected torrents to its background worker.

Before, two requests in the same second could share a temporary filename. Failed or partial writes and failed launches still looked successful; one exception could abandon the rest of the selection and leave the handover file behind.

After, the handover is created exclusively, requests and writes are validated, the PHP path is quoted, launch refusals are reported, and each torrent is checked independently with cleanup afterwards. The UI distinguishes queued, rejected and refused requests.

Handled outcomes intentionally retain HTTP 2xx: the shared UI error callback otherwise reports rTorrent as down even when it never received a request. “Queued” means dispatched, not that every tracker check has finished.

Only the manual route is changed; the scheduler and tracker-specific checking logic are unchanged.

## Verification

13 real-entrypoint scenarios pass on PHP 7.4, 8.1 and 8.5. Full PHP harness and production PHPStan pass; all 23 Jest suites / 284 tests pass. Tests cover filename collisions, incomplete writes, interpreter paths with spaces, worker exceptions and UI outcomes.
