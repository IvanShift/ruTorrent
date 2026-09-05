# Make XMLRPC proxy decisions explicit and terminal

## Summary

Give the shared XMLRPC proxy a consistent request-validation and refusal contract.

Before, malformed input and command-bearing requests could take inconsistent paths; nested refusals could expose an inner command identity, and the httprpc directory boundary did not honor the explicit root-directory opt-in.

After, sanitize mode validates the complete request structure, rebuilds approved load/multicall forms, and refuses unsupported command carriers before any daemon send. Nested refusals identify the normalized outer method. Ordinary calls remain untrusted; explicitly configured unsafe passthrough remains a separate mode.

Both HTTP doors retain terminal refusal responses and neutral transport-failure messages. The environment check also detects an unavailable SimpleXML function rather than merely a loaded extension.

## Dependency

Prepared above the shared SCGI transport patch, which the real-entrypoint verification uses. Refresh against upstream master after that prerequisite lands.

## Verification

193 focused methods / 2,564 assertions pass on PHP 7.4, 8.1 and 8.5; full PHP harness and production PHPStan pass. Coverage includes real copied HTTP doors, byte/trust/send-count transcripts, root opt-in RED/GREEN, real symlink resolution, and all 70 preserved contract fixture keys.
