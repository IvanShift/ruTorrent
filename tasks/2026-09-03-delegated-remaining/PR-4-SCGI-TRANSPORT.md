# Bound SCGI transfers and reject incomplete response frames

## Summary

Use one bounded SCGI transport for the PHP RPC client and `rpc2.php`.

Before, a short socket write could leave a request incomplete; reply handling could accept truncated data or wait without a useful bound. A client could receive a misleading daemon-outage message after an indeterminate transfer.

After, writes are completed within an absolute budget, reads have an idle timeout and response-size cap, and incomplete or inconsistent frames fail explicitly. The two callers retain their existing raw-response/body-only interfaces. Transport failures do not claim that a request was never executed.

The new limits are documented and configurable; no automatic mutation retry is added.

## Verification

36 focused methods / 134 assertions on PHP 7.4, 8.1 and 8.5; full PHP harness and production PHPStan pass. Tests exercise fragmented writes/replies, framing, limits, timeouts and the real copied `rpc2.php`.
