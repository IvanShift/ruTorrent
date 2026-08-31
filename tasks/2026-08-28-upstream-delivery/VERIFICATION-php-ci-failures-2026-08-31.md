# PHP CI failures — exact GitHub log verification

## Outcome

Run `33389898091` на fork `master` @ `558ced3b` падал из-за двух defects в
test harness. Production PHP/JS behavior причиной не было.

User-provided archive `logs_90471600543.zip` не коммитится. Его SHA-256:

```text
cf9852e0ff4db3043d9894d05f16e00b877b828cc923d6c7a4a5d420a37ea6f2
```

## Exact RED

PHP 7.4:

- `XMLRPCProxyTest::testEnvCheckRecommendsTheAvailableSimpleXMLFunction` —
  `env_check.php` returned non-zero;
- `SCGITransportTest::testCopiedRealRpc2ReturnsNeutral502AndOneClassifiedLog`
  — no complete HTTP response after an approximately 63-second stall.

PHP 8.1:

- `SCGITransportTest::testCopiedRealRpc2UsesBodyModeOverUnixWithLegacyGlobals`
  — the copied server's stderr contained a warning.

The archive's PHP step logs have SHA-256
`dc9304d43f59f6e2c2b1617bb496e9342251ae8aa45e8e839f09115eb9b24162`
for 7.4 and
`fdbef5e2fa76aab4833d64477b46f3e35cfa4fea8c927eb96d6e4c7e54ae455e`
for 8.1.

## Root causes and fixes

1. The SimpleXML diagnostic test used `php -n` and then disabled one function.
   On setup-php this also discarded php.ini-loaded required extensions, so the
   child tested a stripped runtime rather than only unavailable SimpleXML. The
   fix keeps the active production ini and passes only
   `-d disable_functions=simplexml_load_string`.
2. The copied PHP development-server readiness probe opened and immediately
   closed an empty TCP connection. PHP 7.4's single-threaded server could wait
   on that accepted client for about 60 seconds; PHP 8.1 could classify it as a
   malformed request and log a warning. The fix sends and drains one complete
   bounded HTTP/1.0 request before declaring the server ready.

The product tree is unchanged. Commit `1ca023ba` changes only
`tests/php/XMLRPCProxyTest.php` and `tests/php/SCGITransportTest.php`.

Commit `d8e48772` separately preserves future PHP failure diagnostics: both
matrix jobs remain red on a failure, identify failing files publicly, and upload
the complete combined output as a per-version artifact.

## GREEN

Clean checkout with the exact two-file fix:

```text
PHP 7.4: 65 files, 3430 Passed, 793 ok, failures 0
PHP 8.1: 65 files, 3430 Passed, 793 ok, failures 0
PHP 8.5: 65 files, 3430 Passed, 793 ok, failures 0
```

Focused full-file matrix was also green on all three versions:

```text
XMLRPCProxyTest: 84 methods / 205 assertions
SCGITransportTest: 34 methods / 129 assertions
```

Ten repeated affected-path cycles per PHP version completed with zero failures.
Both files lint cleanly on PHP 7.4/8.1/8.5; `git diff --check` is clean.
Independent review verdict: **APPROVED**, no findings.
