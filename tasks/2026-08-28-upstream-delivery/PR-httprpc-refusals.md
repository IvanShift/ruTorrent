# Distinguish XMLRPC input failures and make proxy refusals terminal

## Summary

- distinguish an unreadable request body from an empty XMLRPC request at both
  `httprpc` and `/RPC2`;
- return terminal, correctly classified 400/403/500 responses without sending
  a refused request or falling through after a response helper returns;
- share the named `-501` refusal message between both XMLRPC doors and use
  neutral wording for transport failures;
- add copied-entrypoint regression tests that execute the real HTTP control
  flow, status codes, bodies, Content-Types and logging behavior.

## Problem

The standalone `/RPC2` entrypoint treated a failed `php://input` read like an
empty request and reported a speculative `post_max_size` explanation. The
`httprpc` entrypoint had no input guard at that boundary, so unreadable and
empty input continued into proxy policy instead of receiving distinct 400
responses.

At `httprpc`, refusals and transport failures also relied on
`CachedEcho::send()` terminating the process, although its gzip path can
return. That allowed later code to run after a response had already been
emitted. The standalone `/RPC2` refusal used a generic message while `httprpc`
could identify the rejected command.

Finally, the old transport error asked whether rTorrent was running. A failed
connection setup can also come from endpoint configuration, socket path or
listener state, and socket permissions, so daemon availability was not proven
by that result.

## Behavior after this change

- unreadable input returns HTTP 400 with `Could not read XMLRPC request.`;
- empty input returns its own HTTP 400 with `Empty XMLRPC request.`;
- optional server logs classify those two causes separately, and logging-off
  behavior stays silent;
- policy refusals return terminal HTTP 403 XML faults naming the rejected
  command;
- an admitted `httprpc` call whose transport fails returns terminal HTTP 500
  with `Could not complete the rTorrent XMLRPC request.`;
- `/RPC2` renders the same shared named refusal sentence;
- existing proxy parsing and policy behavior is unchanged.

## Verification

- focused `XMLRPCProxyRejectionTest` and copied real-entrypoint suite:
  17 test methods / 71 assertions on PHP 7.4, PHP 8.1 and PHP 8.5;
- lint: all five changed PHP files on the supported PHP matrix;
- PHPStan 2.2.9: no errors;
- full JavaScript suite on the clean upstream branch: 22 suites / 229 tests;
- exact five-path scope, one commit directly on the current upstream base,
  `git diff --check`, and independent whole-branch review: pass.

The copied-entrypoint tests hash-check the production files they execute. Test
doubles replace only external dependencies below the endpoint boundary; status,
body, Content-Type and terminal control flow come from the real entrypoints.
