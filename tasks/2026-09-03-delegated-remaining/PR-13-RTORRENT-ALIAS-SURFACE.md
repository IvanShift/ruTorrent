# Pin the rTorrent alias surface and version gates

## Summary

Add regression coverage for the existing PHP and browser command mappings, and clarify the socket-capability version comments.

Before, an alias change could leave a live caller using a missing or deprecated-only command without a focused regression test detecting the mismatch.

After, tests compare the mapping and caller surface against a captured stock rTorrent 0.16.20 command list and check the browser version gates. The comments distinguish the 0.16.19 write path, the 0.16.20 totals and the 0.16.21 category limits.

This PR does not change the production alias map or introduce new daemon commands.

## Verification

11 compatibility methods / 1,122 assertions and the socket-limit suite pass on PHP 7.4, 8.1 and 8.5. Full PHP harness and production PHPStan pass; all 22 Jest suites / 283 tests pass.
