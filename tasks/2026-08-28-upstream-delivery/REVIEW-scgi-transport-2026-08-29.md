# `up/scgi-transport` — independent current-base review

Дата: 2026-08-29. База: `upstream/master=755404f3`. Fork использован только
как donor гипотез; repository, Docker и live service не мутировались.

## Verdict: CHANGES REQUIRED

Production defect и семипутевая transport boundary подтверждены. Направление
верное: один shared transport, complete writes, separate connect/transfer
budgets, mandatory response framing и разные raw/body consumer contracts.

До реализации обязательны поправки:

1. прежний baseline RED для blocking single `fwrite()` недетерминирован;
2. request tests не держат security boundary `UNTRUSTED_CONNECTION`;
3. fixed 64 MiB limit не выведен из protocol: оба supported daemon допускают
   ответы до 100 MiB; policy надо выбрать и документировать;
4. PHP 7.4 нужен как runtime, а не lint-only;
5. нужен real UNIX-socket success case;
6. client-facing headerless/timeout failure должен иметь neutral endpoint
   wording, а не ложное «rTorrent unreachable».

## Reachability

Current `php/xmlrpc.php::rXMLRPCRequest::send()`:

- делает один `fwrite()` и игнорирует byte count;
- читает до false/empty без framing или size bound;
- не классифицирует timeout metadata;
- может вернуть partial response как non-false string.

Это основной core path для settings, startup/plugin discovery, httprpc и
scheduled bundled plugins, то есть широко production-reachable.

Optional `rpc2.php::rpc2_send()` повторяет one-write assumption, использует
connect timeout как read timeout и также принимает partial/malformed answer.
Он production-reachable при включённом documented filtered `/RPC2` endpoint.

## Supported-daemon ground truth

Source tags rTorrent 0.9.8 и 0.16.21 независимо подтверждают:

- response всегда содержит `Status`, `Content-Type`, `Content-Length`;
- обе версии отвергают только output больше `100 << 20`;
- 0.16.21 имеет hard-coded 60-second absolute request timer, который нельзя
  изменить registered command.

Следовательно, missing `Content-Length` — не compatibility case и должен fail
closed. Headerless EOF означает «socket connected, response closed before
headers», но transport не может без контекста назвать причиной именно deadline:
это также restart/close.

## Exact seven-path scope

1. `conf/config.php`;
2. новый `php/scgitransport.php`;
3. `php/xmlrpc.php`;
4. `rpc2.php`;
5. новый `tests/php/SCGITransportFixture.php`;
6. новый `tests/php/SCGITransportTest.php`;
7. `README.md`.

Оценка около `+850/-45` не является final numstat до нового accumulator/tests.

Exclude: `php/xmlrpc_path.php`, `php/rtorrent.php`, proxy policy, erasedata,
trackers, `env_check.php`, `tests/php/XMLRPCProxyTest.php`, SimpleXML/env-check
requirement, Docker/runtime config и task artifacts.

Пакет строится после final `up/httprpc-refusals` из-за shared `rpc2.php` и не
повторяет predecessor refusal/empty-body/helper hunks. #3209/#3211 proxy parser
tests не трогаются.

## Required transport contract

- `$rpcTimeOut` остаётся positive-float connect budget.
- Новый `$rpcTransferTimeOut = null` наследует `default_socket_timeout` с
  documented fallback для non-positive ini; fractional seconds сохраняются.
- Один absolute deadline покрывает все request writes, read timeout является
  idle budget.
- SCGI prefix/header/payload отправляются без второго concatenated full request;
  short writes loop через remaining slice; false/zero/select failure/expiry —
  classified failure.
- Точно сохранить request fields и `UNTRUSTED_CONNECTION=0` для trusted,
  `=1` для untrusted calls.
- Header bound 65,536 bytes; ровно один case-insensitive syntactically valid
  **positive** `Content-Length`. Missing/duplicate/malformed/overflow/zero/
  over-policy отвергаются до body read.
- `fread()` получает не больше remaining bytes. EOF раньше count — truncation;
  exact count завершает frame без ожидания redundant EOF.
- Перед append incoming length сверяется с remaining/policy; rejected chunk не
  должен менять accumulator.
- Один implementation даёт explicit raw и body-only modes: core
  `rXMLRPCRequest::send()` сохраняет legacy header+body bytes, `rpc2_send()`
  возвращает только body. Два full response representation одновременно
  запрещены.
- XML parsing остаётся consumer responsibility; transport не требует
  SimpleXML.
- Logs содержат classified connect/write/read/header/truncation/closed-before-
  headers reason без raw remote payload.

## Response-limit policy: user decision before code

Fork cap 67,108,864 bytes bounded, но не protocol-derived и не гарантирует
отсутствие OOM при common 128 MiB PHP limit плюс XML parse.

Допустимы два честных контракта:

1. hard cap 100 MiB, совпадающий с supported rTorrent wire ceiling, с явным
   требованием достаточного PHP memory;
2. меньший/configurable client cap как сознательная PHP-worker protection,
   operator-visible classified rejection и документация, что supported daemon
   способен выдать больше.

Предпочтителен второй вариант с консервативным default и upper bound 100 MiB,
но exact config name/default/normalization требуют явного design approval.
Нельзя называть 64 MiB protocol limit или OOM guarantee.

## Endpoint diagnostic ownership

Transport log может точно классифицировать failure, но current httprpc и rpc2
для любого false/null сообщают клиенту, что rTorrent unreachable.

- `up/httprpc-refusals` должен дать httprpc neutral wording вроде
  «Could not complete the rTorrent XMLRPC request; see server log»;
- SCGI package даёт тот же neutral outcome в принадлежащем ему `rpc2.php`;
- точная transport classification остаётся server log owner.

Если neutral wording откладывается, PR может заявлять только protocol/log
classification и обязан оставить client-facing false wording открытым.

## Honest RED/mutation evidence

Старый natural RED «large delayed reader + one blocking fwrite» запрещён.
Direct reproduction на current algorithm с 4/16/32 MiB и 1.5-second delayed
peer вернула полный write каждый раз. Это не доказывает корректность one-write,
но опровергает честность гарантированного baseline RED.

Required evidence:

1. source/current-behavior characterization двух duplicated callers;
2. deterministic short-write seam или nonblocking socket-pair: one-write
   mutation RED, full-write loop GREEN;
3. transfer-budget→connect-budget mutation RED на slow reply;
4. trust-bit removal/inversion RED для trusted и untrusted request с exact
   netstring/header/payload capture;
5. case-sensitive, duplicate/malformed/missing/zero/oversize length mutations;
6. append-before-check mutation через small deterministic policy seam;
7. raw/body mode swap/collapse через real consumer contracts;
8. timeout-metadata/headerless-EOF classification mutations;
9. missing legacy transfer global fallback без warning;
10. real UNIX-domain socket success плюс TCP.

Fixture обязан возвращать parsed request headers/payload, а не только boolean
`complete`; иначе trust flag остаётся непроверенным.

## Verification gate

- focused transport + оба consumer-contract tests на PHP 8.5/8.1;
- focused suite **runtime** на PHP 7.4;
- full harness 8.5/8.1 и после PHP74 prerequisite — full 7.4;
- PHPStan 2.2.9 level 0 и lint всех changed PHP;
- exact seven-path diff, `git diff --check`, no task/agent artifacts;
- disposable `tasks/rt-lab.sh` read-only call через real UNIX SCGI socket,
  желательно на 0.9.8 и 0.16.21;
- никакого mutating live probe и никакого 65-second CI sleep.

## Approval condition

Seven-path ownership approved. Implementation начинается только после явного
одобрения response-limit policy и внесения deterministic short-write,
trust-bit, neutral wording, PHP 7.4 runtime и UNIX-socket gates в design.
