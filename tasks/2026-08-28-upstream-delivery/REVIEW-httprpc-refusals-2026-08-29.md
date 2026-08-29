# `up/httprpc-refusals` — final independent design review

Дата: 2026-08-29. База: `upstream/master=755404f3`.

## Verdict: APPROVED after required corrections

Exact five-path carve, endpoint ownership, status codes, explicit exits, shared
`rejectionMessage()` и copied-entrypoint strategy подтверждены. Independent
review вернул первоначальный brief в `CHANGES REQUIRED` из-за двух ложных
diagnostics; этот документ уже включает обе поправки:

1. `false` read failure и `''` empty body — разные HTTP 400 branches без
   недоказанного `post_max_size` объяснения;
2. httprpc transport failure отвечает
   `Could not complete the rTorrent XMLRPC request.` без ложного обещания
   `see server log`.

После этих corrections design implementation-ready; ветки/кода ещё нет.

## Frozen five-path scope

Production:

1. `plugins/httprpc/action.php`;
2. `php/xmlrpc_proxy.php`;
3. `rpc2.php`.

Tests:

4. новый `tests/php/XMLRPCProxyEntrypointTest.php`;
5. расширенный `tests/php/XMLRPCProxyRejectionTest.php`.

Exclude: `conf/xmlrpc_proxy.php`, `plugins/httprpc/conf.php`, `php/xmlrpc.php`,
`php/xmlrpc_path.php`, SCGI transport, proxy-policy, erasedata и task files.
Test-harness `8eafb529` может быть evidence parent, но его четыре paths не входят
в commit.

Estimate `+190..230/-3` не freeze. Corrected production prototype около
`+40/-6`; final numstat определяется copied endpoint tests, а не compacting
fixture под старое число.

## Endpoint contracts

### httprpc action

- `file_get_contents()` вернул `false`: HTTP 400, один response, no policy/no
  send, optional classified read-failure log;
- body `''`: HTTP 400, один response, no policy/no send, отдельный optional
  empty-body log;
- policy refusal: HTTP 403, `text/xml`, fault `-501`, named command, один
  response и explicit exit;
- admitted call, transport `send() === false`: HTTP 500, `text/html`, exact
  neutral text, один send/response и explicit exit;
- successful admitted response остаётся existing body-extraction owner.

Exits load-bearing: `CachedEcho::send()` обычно exits, но gzip path возвращает
после `passthru()`. Endpoint correctness не может зависеть от helper branch или
returning test double.

### standalone rpc2

- empty body сохраняет existing HTTP 400 XML fault;
- policy refusal сохраняет 403/XML envelope, но sentence получает через
  `XMLRPCProxy::rejectionMessage($method)`;
- false/empty guard разделяет server-log classification без `post_max_size`
  prose, сохраняя прежний client fault contract;
- transport HTTP 502/body остаётся successor SCGI owner, который заменяет
  `rpc2_send()`.

## False read не равен empty body

Реальные HTTP probes на PHP 8.5.4 и official PHP 7.4.33 с
`post_max_size=1K`, `Content-Type: text/xml` и POST 2049 bytes получили
`php://input` длиной 2049 на обоих runtime. PHP warning был, raw XML остался.

Поэтому `post_max_size` parenthetical удаляется из comment/log/PR prose.
Corrected exact wording:

```text
false log: xmlrpc-proxy: could not read request body
false body: Could not read XMLRPC request.

empty log: xmlrpc-proxy: empty request body
empty body: Empty XMLRPC request.
```

Logging остаётся под `$XMLRPCProxyLog`; при off обе ветви всё равно дают
correct status/body и no send.

## Neutral transport failure

`Could not reach rTorrent ... Is rTorrent running?` неверен: false позже может
означать connect/write/timeout/framing/truncation/peer close.

Но `see server log` также неверно до SCGI: `$rpcLogCalls=false` по default, а
current `rXMLRPCRequest::send()` не гарантирует transport-failure log. Probe
видел только optional policy-forward line, при logging off — ничего.

Поэтому exact client response этого пакета:

```text
Could not complete the rTorrent XMLRPC request.
```

HTTP 500 и `text/html` сохраняются. SCGI successor добавляет classified server
log, но не меняет эту нейтральную client sentence.

## Shared refusal rendering

- public `XMLRPCProxy::rejectionMessage($method)` возвращает одну raw sentence;
- `rejectionFault()` XML-escapes её в `-501` envelope;
- `rpc2_fault()` отдельно XML-escapes ту же sentence;
- named и generic variants unit-tested;
- copied action/rpc2 endpoints доказывают один named result через реальные
  двери, не source grep.

Proxy policy/parser/config в пакет не входят.

## Required copied-entrypoint REDs

Test копирует real production entrypoints в isolated include tree; stubs меняют
dependencies/transport, но не воспроизводят control flow.

1. httprpc `false`: 400, read-failure text/log, zero sends, one response;
2. httprpc `''`: 400, empty text/log, zero sends, one response;
3. denial с returning `CachedEcho`: 403 XML `-501`, named method, zero sends,
   one response;
4. admitted `send()===false`: 500, exact neutral text, one send/response;
5. copied rpc2 denial по HTTP: 403 `text/xml`, та же named sentence;
6. copied rpc2 empty POST: existing 400 XML fault;
7. logging-off variants;
8. exact content type/status каждого response;
9. missing copied source/child result/HTTP response — explicit test failure.

## Fresh prototype и mutations

Disposable prototype на 755 + test harness:

- natural current-base RED: wrong status/send/fallthrough/wording/generic rpc2;
- structural prototype **до финальных diagnostic wording corrections** дал
  focused entrypoint/helper GREEN на PHP 8.5.4 и 7.4.33;
- все пять paths lint clean на PHP 7.4.

Measured mutation RED counts без unrelated fatal:

- убрать `false` guard: 2 named failures;
- убрать empty branch: 3;
- убрать refusal exit: 3;
- убрать transport-failure exit: 1;
- вернуть daemon-down wording: 1;
- вернуть rpc2 generic refusal: 1.

Corrected exact texts пока являются design, не готовым GREEN commit. Final
branch дополнительно мутирует status/content-type/logging gates и запускает
corrected diagnostic assertions. Named test обязан реально
выполниться; после restore — свежий GREEN.

## Dependencies и handoff

- evidence parent: ready `up/test-harness`, production dependency отсутствует;
- successor `up/scgi-transport` следует после этого package из-за `rpc2.php`;
- successor `up/xmlrpc-proxy-policy` следует после него из-за action/entrypoint;
- whole-file fork copy запрещён: сохранить #3209/#3211 quoted/escaped-comma
  parser behavior.

Перед handoff: focused copied-entrypoint tests и changed-path runtime/lint на
PHP 7.4; full harness на PHP 8.1/8.5; PHPStan; exact five paths/final numstat;
whitespace и independent whole-file review. Full harness PHP 7.4 запускается
только после prerequisite `up/php74-torrent-properties`; без него handoff обязан
показать byte-identical fatal базового `Torrent.php`, а не назвать его регрессией
этого пакета. Implementation начинается после explicit user approval; agent не
push.
