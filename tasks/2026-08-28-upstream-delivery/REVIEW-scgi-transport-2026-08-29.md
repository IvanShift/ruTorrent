# `up/scgi-transport` — закрытый технический контракт

Дата: 2026-08-29. База исследования: `upstream/master=755404f3`; immediate
parent будущей ветки — final `up/httprpc-refusals`. Fork использован только как
donor гипотез. Поведение и protocol limits независимо перепроверены по rTorrent
0.9.8 и 0.16.21, а PHP-совместимость — в immutable containers.

## Verdict: IMPLEMENTED / APPROVED — locally integrated

Один shared transport владеет SCGI framing, complete writes, response framing,
connect/write/read budgets и memory bounds. Открытых design-решений больше нет:

- client protection default — 67,108,864 bytes;
- operator ceiling — 104,857,600 bytes, равный supported daemon wire ceiling;
- core consumer получает raw headers + delimiter + body;
- `rpc2.php` получает body only;
- XML validation остаётся consumer responsibility;
- transport failures имеют stable classified codes, а endpoints — neutral text.

Final implementation закрыта clean branch `4682a761` от exact parent
`c7a431aa`; fork integration — `19086b5f` + separate test adaptation
`3ff4860c`. Полный evidence находится в
`VERIFICATION-scgi-transport-2026-08-31.md`.

## Exact seven-path scope

От final `up/httprpc-refusals` пакет меняет ровно семь путей:

1. `conf/config.php`;
2. новый `php/scgitransport.php`;
3. `php/xmlrpc.php`;
4. `rpc2.php`;
5. новый `tests/php/SCGITransportFixture.php`;
6. новый `tests/php/SCGITransportTest.php`;
7. `README.md`.

Final package delta от exact final httprpc tip равен `+1569/-51`. Historical
raw fork/upstream aggregates не используются как package scope.

Не входят: `php/xmlrpc_path.php`, `php/rtorrent.php`, proxy policy, erasedata,
trackers, `env_check.php`, SimpleXML requirement, Docker/runtime config и task
artifacts. #3209/#3211 proxy parser tests не меняются.

## Production reachability and independent verdicts

Current `rXMLRPCRequest::send()` делает один unchecked blocking `fwrite()`,
читает до EOF и может принять partial reply. Это core path settings,
startup/plugin discovery, httprpc и scheduled plugins — defect
**ПОДТВЕРЖДЁН, production-reachable**.

Optional `rpc2.php::rpc2_send()` дублирует one-write/EOF assumptions и смешивает
connect/read timeout. При опубликованном `/RPC2` defect также
**ПОДТВЕРЖДЁН, production-reachable**.

| Гипотеза | Вердикт | Основание |
|---|---|---|
| один `fwrite()` гарантирует весь SCGI request | **ОПРОВЕРГНУТО** | PHP возвращает byte count; short write разрешён API. Конкретный blocking repro не детерминирован и не годится как RED. |
| EOF нужен для конца ответа | **ОПРОВЕРГНУТО** | Supported daemon всегда даёт `Content-Length`; exact count завершает frame. |
| missing `Content-Length` — compatibility case | **ОПРОВЕРГНУТО** | 0.9.8 и 0.16.21 всегда emit-ят его. |
| 64 MiB — daemon protocol limit | **ОПРОВЕРГНУТО** | Обе версии reject-ят только output `> 100 << 20`. |
| 64 MiB полезен как PHP client cap | **ПОДТВЕРЖДЕНО** | Это явная worker-protection policy, configurable до daemon ceiling. |
| SimpleXML обязан быть в transport | **ОПРОВЕРГНУТО** | Framing не зависит от XML parser; XML валидирует consumer. |
| headerless EOF точно означает daemon deadline | **ОПРОВЕРГНУТО** | Причина также может быть restart/close; transport знает только framing outcome. |
| blocking large-write experiment доказывает defect | **ОПРОВЕРГНУТО** | 4/16/32 MiB writes на measured host завершались целиком; нужен deterministic seam. |
| UNIX SCGI path можно считать эквивалентным без runtime | **ОПРОВЕРГНУТО** | Нужен отдельный real UNIX-socket success gate с port `0`. |

Source ground truth:

- rTorrent 0.9.8 и 0.16.21 отвечают `Status`, `Content-Type`,
  `Content-Length`, затем `\r\n\r\n` и body;
- wire body длиной ровно 104,857,600 допустим; больше — daemon reject;
- 0.16.21 ставит absolute 60-second deadline при open и один раз re-arm-ит его
  после полного чтения request для processing/response phase. Это две
  phase-local границы, не idle timeout; registered command surface их не
  настраивает. Client contract не обещает превысить вторую границу.

## Public API and configuration

```php
rSCGITransport::send(
    $host,
    $port,
    $payload,
    $trusted = true,
    $connectTimeout = 30,
    &$failure = null,
    $transferTimeout = null,
    $maxResponseBytes = null,
    $responseMode = rSCGITransport::RESPONSE_RAW
): ?string
```

PHP 7.4-compatible implementation объявляет:

```php
const RESPONSE_RAW = 'raw';
const RESPONSE_BODY = 'body';
const MAX_HEADER_BYTES = 65536;
const MAX_WRITE_BYTES = 65536;
const DEFAULT_MAX_RESPONSE_BYTES = 67108864;
const MAX_WIRE_RESPONSE_BYTES = 104857600;
```

`conf/config.php` сохраняет `$rpcTimeOut` как connect budget и добавляет:

```php
$rpcTransferTimeOut = null;
$rpcMaxResponseBytes = 67108864;
```

`null` transfer timeout наследует positive `default_socket_timeout`; если ini
не даёт positive value, fallback — 60 seconds. Fractional positive seconds не
округляются до integer. Connect и transfer budget независимы:

- один absolute transfer deadline покрывает все request writes;
- response read использует transfer value как idle timeout;
- tight connect timeout не сокращает slow but progressing reply.

Response limit принимает только integer или decimal-string в диапазоне
`1..104857600`. Missing/null использует `DEFAULT_MAX_RESPONSE_BYTES`. Bool,
float, sign, whitespace, exponent, empty/non-digit и out-of-range дают
`invalid-response-limit` **до socket connect**. Decimal сравнивается с ceiling
по normalized string length/lexicographic value до integer cast, поэтому
32-bit PHP не переполняется.

Response mode принимает только exact `raw`/`body`; иное значение даёт
`invalid-response-mode` до connect. Empty request аналогично даёт
`empty-request`.

## Request framing and write contract

SCGI request fields сохраняются exactly:

```text
CONTENT_LENGTH\0<decimal payload bytes>\0
CONTENT_TYPE\0text/xml\0
SCGI\0 + ASCII `1` + \0
UNTRUSTED_CONNECTION\0<0 trusted | 1 untrusted>\0
```

То есть bytes поля `SCGI` — exact hex `53 43 47 49 00 31 00`; запись не должна
превратиться в octal escape `\01`.

Wire request — netstring header length, `:`, header bytes, `,`, payload. Header
и payload отправляются segmented; второй concatenated full request запрещён.
Каждый `fwrite()` получает slice не больше `MAX_WRITE_BYTES` (65,536 bytes),
включая первый payload offer. Нельзя передавать `substr($payload, $offset)` без
length и тем самым копировать почти весь remaining payload. Partial positive
count продвигает offset; false/zero не считается success.

Socket переводится в режим, подходящий для `stream_select`; failure настройки —
`socket-config-failed`. Один monotonic absolute write deadline не
перезапускается после progress. Select false/expiry и write false/zero получают
разные codes. После полной отправки socket получает blocking idle read timeout.

## Response framing and memory contract

Transport ищет только exact `\r\n\r\n`. До delimiter разрешено максимум 65,536
header bytes; partial delimiter на boundary учитывается отдельно. Каждый header
line имеет exact grammar `field-name ":" field-value`: `field-name` — один или
больше RFC tchar bytes ``[!#$%&'*+.^_`|~0-9A-Za-z-]``; bare LF, obs-fold и line
без colon отвергаются.

Captured verbatim на disposable rTorrent 0.16.21 для
`system.client_version`:

```text
Status: 200 OK\r\n
Content-Type: text/xml\r\n
Content-Length: 125\r\n
\r\n
```

`Content-Length` contract:

- ровно один case-insensitive field;
- после colon снимаются только outer OWS bytes SP/HTAB;
- оставшееся value — non-empty `[0-9]+` без sign/internal whitespace;
- leading zeros допустимы и удаляются только при numeric normalization;
- normalized value strictly positive;
- value не превышает selected client limit;
- duplicate/missing/malformed/zero/overflow fail closed до body accumulation.

После parse каждый следующий `fread()` запрашивает не больше remaining declared
bytes. Tail, уже coalesced с header delimiter в одном предыдущем read,
разделяется **до append**: только первые remaining bytes входят в response,
излишек не накапливается и не возвращается. EOF раньше exact count —
`truncated-body`; exact count немедленно завершает frame без ожидания EOF и без
нового `fread()`.

Memory ownership различается по mode:

- `RESPONSE_RAW` возвращает exact received header bytes + delimiter + body;
- `RESPONSE_BODY` возвращает только body;
- transport не хранит одновременно две полные response representations;
- rejected chunk не изменяет accumulator;
- XML parse/`methodResponse` validation выполняет consumer после успешного
  framing и не является transport failure.

## Stable failures and diagnostics

`$failure` содержит ровно один stable code или `null` при success:

```text
empty-request
invalid-response-mode
invalid-response-limit
connect-failed
socket-config-failed
write-wait-failed
write-timeout
write-failed
read-timeout
read-failed
closed-before-headers
truncated-headers
headers-too-large
malformed-header
missing-content-length
duplicate-content-length
malformed-content-length
zero-content-length
response-too-large
truncated-body
```

Transport сам не пишет log и возвращает code owner-у. На каждый failure consumer
обязан записать ровно одну строку: core — `rXMLRPCRequest: <code>`, `rpc2.php` —
`rpc2: <code>`. Никаких raw response, XML payload, request arguments, remote
fault text или guessed daemon outcome. Consumer XML/fault logging остаётся у
`rXMLRPCRequest` и существующих `$rpcLogCalls`/`$rpcLogFaults`.

Consumer adaptation фиксирована:

- `rXMLRPCRequest::send()` вызывает `RESPONSE_RAW`, передаёт connect,
  transfer и response-limit globals через legacy-safe `isset` defaults;
  transport `null` отображает строго в прежний `false`;
- `rpc2_send()` вызывает `RESPONSE_BODY`, передаёт те же три policy values;
  transport `null` отображает в `null` для endpoint failure branch;
- успешный core response сохраняет exact raw headers+delimiter+body, успешный
  rpc2 response — exact body only;
- один failure нельзя залогировать повторно transport и consumer слоями.

Endpoint contract:

- `plugins/httprpc/action.php` остаётся owner predecessor и при transport
  failure сохраняет HTTP 500 + exact neutral
  `Could not complete the rTorrent XMLRPC request.`;
- `rpc2.php` отвечает HTTP 502 с той же exact sentence;
- никакой endpoint не утверждает, что rTorrent «unreachable», потому что
  framing/timeout/config failure не доказывает outage.

## RED, GREEN and mutations

Natural/current characterization на exact predecessor фиксирует два
duplicated one-write/EOF callers. Protocol RED обязан быть детерминирован:

1. fixture отправляет complete declared frame и держит peer open; corrected
   client возвращает exact frame до release signal, EOF-driven baseline — нет;
2. injectable writer/nonblocking socket-pair принудительно возвращает short
   counts; one-write mutation теряет bytes, loop даёт exact captured netstring;
3. parsed request fixture проверяет payload и `UNTRUSTED_CONNECTION` для обоих
   trust values, не только boolean `complete`;
4. slow first/progressing reply переживает tight connect budget, но idle read
   получает `read-timeout`;
5. TCP и real UNIX-domain socket (`port=0`) возвращают exact raw/body outputs;
6. missing/duplicate/case-varied/malformed/zero/oversize/truncated framing
   покрыты отдельными named cases;
7. legacy config без новых globals работает без warning с defaults;
8. invalid response mode/limit/empty payload доказывают zero connect attempts.
9. captured `Content-Length: 125` принимается verbatim; OWS/leading-zero matrix
   отделена от sign/internal-whitespace/obs-fold rejects;
10. coalesced header + exact body + suffix при held-open peer возвращает только
    framed response и не делает второго read;
11. injected writer доказывает, что каждый offered slice `<= 65,536` bytes;
12. core `null -> false` и rpc2 `null -> null`, mode/config forwarding и ровно
    одна exact classified log line закреплены consumer tests.

Mandatory mutations, каждая с named executed RED, no preceding fatal и fresh
GREEN after restore:

- single write вместо partial-write loop;
- reset write deadline после progress;
- transfer budget заменить connect budget;
- убрать/invert trust bit;
- сделать `Content-Length` case-sensitive, optional или first-wins duplicate;
- разрешить malformed/zero/out-of-range length;
- append chunk до remaining/cap check;
- ждать EOF после exact count;
- перепутать/collapse raw и body mode;
- перепутать timeout metadata с EOF/read error;
- обратиться к отсутствующему legacy global без `isset`/default;
- вернуть XML/SimpleXML validation в transport.

## Verification gate

На exact implementation tip обязательны:

- focused transport и оба consumer contracts runtime на PHP 7.4/8.1/8.5;
- full harness 8.1/8.5; full 7.4 после `up/php74-torrent-properties`, иначе
  identical prerequisite fatal фиксируется отдельно;
- PHPStan 2.2.9 level 0 и lint exact changed PHP;
- exact seven-path diff, `git diff --check`, test-name/count guard;
- disposable real UNIX SCGI call через `tasks/rt-lab.sh` на supported oldest и
  0.16.21;
- whole-file independent review; никакого mutating live probe и 65-second CI
  sleep.

Container characterization уже показала на PHP 7.4 и 8.5 одинаковый current
donor результат `25 methods / 58 assertions / 0 failures`; это compatibility
baseline, не GREEN перечисленных новых RED/mutation cases.

## Approval boundary

Этот раздел фиксировал pre-implementation boundary: package нельзя было
называть готовым до witnessed natural RED, deterministic short-write RED,
corrected GREEN, mutations, exact predecessor range и independent whole-file
review. Все перечисленные gates выполнены на final implementation tip; closure
зафиксирован ниже.

## Post-sync revalidation — 2026-08-30

Final merge `4b3cd79925e7b73ea25feb1658a34e6b698c9855` основан на upstream
`529033335e66e1acd4084b73030f5880035ce1c0`; historical
`755404f3e38af98b6901852b35be10fb9659ffd3` baselines и approval hashes
остаются frozen. Exact delta `755404f3..52903333` — только #3220/#3202 и три
package-lock/filedrop path — имеет пустое пересечение с exact seven-path SCGI
scope. Pre-755 #3209/#3211 parser shield сохранён в sibling XMLRPC surface и,
как требует этот контракт, не перенесён в SCGI ownership.

Container qualifications не меняют verdict. PHP 7.4 full harness всё ещё
зависит от known package #1 `up/php74-torrent-properties`; predecessor SCGI на
default `128M` имеет уже зафиксированный nondeterministic OOM и не является
successor GREEN. На PHP 8.1 один fresh default-`128M` run также исчерпал память
после 44 assertions, затем три немедленных fresh raw repeats того же immutable
image прошли `58/58`; значит predecessor memory cell недетерминирован и не
может считаться stronger GREEN. Test-only rerun с `512M` прошёл `58/58`;
отдельное повышение до `512M` в `RemoveWithDataTest` также не относится к SCGI
production limit. Literal PHP 7.4 `128M` accepted-body/bounded-failure остаётся
combined final-parent/retrackers-consumer gate, а не ретроактивно выполненная
гарантия этого predecessor.

На этом историческом 2026-08-30 checkpoint статус оставался **DESIGN APPROVED —
implementation pending**. Последующая реализация ниже заменяет этот статус.

## Implementation closure — 2026-08-31

Clean branch `up/scgi-transport=4682a761cda6c813e3911ac6229dcf84ea4c7e99`
содержит один non-merge commit прямо на
`c7a431aaf5ad470f9fc7487395d38b48d12c722f`, exact seven paths
`+1569/-51`. Final focused suite — `34 methods / 129 assertions`; recorded
clean full harness на PHP 7.4, 8.1 и 8.5 — одинаковые `50 files / 344 methods /
1990 Passed / 127 ok / 0 failures`. Later broad repeat встретил только
unchanged `_task` process-exit race после всех SCGI tests; focused repeat,
PHPStan, lint, named mutations, test-name accounting и independent whole-file
review GREEN.

Real UNIX-SCGI success независимо повторён на rTorrent 0.16.21 через full lab и
на daemon-only 0.9.8 через direct PHP 7.4 client с string port `"0"`. Fork
integration `19086b5f` сохраняет richer proxy/path/fault behavior; отдельный
`3ff4860c` адаптирует только erasedata test stub, не ослабляя production API.
Push и deployment не выполнялись. Verdict окончательно **IMPLEMENTED /
APPROVED — locally integrated**; package #4 уменьшает открытую очередь с 15 до
14. Полная проверка и upstream PR text:
`VERIFICATION-scgi-transport-2026-08-31.md`, `PR-scgi-transport.md`.
