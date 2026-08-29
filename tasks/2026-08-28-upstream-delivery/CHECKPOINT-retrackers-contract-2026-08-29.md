# Checkpoint контракта `up/retrackers-recovery` — 2026-08-29

## Статус остановки

Работа остановлена по прямому указанию пользователя до `APPROVED`. Текущий
документ контракта:

```text
path    tasks/2026-08-28-upstream-delivery/REVIEW-retrackers-recovery-2026-08-29.md
sha256  af64403d70586eaeae4bb2e15e58b59d04727c8aaa21e60658de64a46cff3649
status  CANDIDATE / NOT APPROVED
```

Repository snapshot:

```text
HEAD/origin/master  43484fba8160677d7764c3263fab083f15e20383
upstream/master     755404f3e38af98b6901852b35be10fb9659ffd3
divergence          110 ahead / 12 behind relative to origin-vs-upstream view
```

В этом проходе production/test implementation не менялась. Все **18**
реализационных пакетов остаются открытыми; `43484fba` не закрыл ни один из них.
Push не выполнялся. Unrelated `rutorrent-app-errors.log` не читался, не
стейджился и должен остаться нетронутым.

## Что уже закрыто в candidate

- scope заморожен на пяти путях: `init.php`, `done.php`, `run.sh`,
  `update.php`, `UpdateTest.php`; `guard.php` и P3 service policy исключены;
- immediate implementation parent — final `up/scgi-transport`, а не published
  six-path donor;
- hook/lifecycle, `wh/wp/wa`, one-shot phase receipts, scheduler fences,
  rollback, tracker projection, raw metainfo/resume preservation и
  capabilities-first terminal cleanup сведены в один протокол;
- exact marker/ack equality исправлена на измеренную command form;
- both-family probe доказал nested exact-empty ack gate:
  empty ack становится ready marker, а `"0"`, wrong non-empty, foreign marker и
  already-equal value остаются byte-identical;
- exact known outer-false `SKIPPED` release-ит same generation только при own
  marker/ack и сохраняет concurrent drift остальных полей;
- SCGI budget согласован с prerequisite: connect `0.25s`, transfer из
  `$rpcTransferTimeOut`, response cap из `$rpcMaxResponseBytes`, `RESPONSE_RAW`.

Published `43484fba` независимо перечитан как donor. Его generation handoff и
quiesce+inner-CAS полезны, но current implementation всё ещё принимает local
`sendTorrent()` return за daemon acknowledgement, не имеет surviving
erase/load/fence receipts, полноценного lifecycle/metadata/tracker CAS,
multi-profile policy и exact runtime-state restoration. Current test suite
поэтому остаётся false-green characterization.

## Итог независимых проверок

Первый whole-document reviewer дал `CLEAN` для exact SHA выше после проверки
lifecycle, delayed callbacks, `SKIPPED`, marker capabilities, scheduler,
rollback, raw source/resume, tracker projection и SCGI dependency.

Второй independent scope/API reviewer после этого нашёл три воспроизводимых P1.
Его более узкие findings делают итоговый verdict **NOT CLEAN**; первый `CLEAN`
не является approval и не должен цитироваться отдельно от этих блокеров.

### P1. Strict XMLRPC response codec/API seam

Candidate всё ещё ссылается на `setParseByTypes(true)`/`$strings`/`$i8s`, но
одновременно требует direct one-call `rSCGITransport::send()` и запрещает
`rXMLRPCRequest::send()`. У current `rXMLRPCRequest::run()` нет публичного seam
для parse already-received RAW response. Его regex parser не является safety
boundary: garbage/trailing XML и произвольный `<i.>` tag могут пройти как
typed value.

Scope-safe решение остаётся в `update.php`: один bounded iterative restricted
codec, который без второй полной копии отделяет BODY после validated RAW
delimiter, полностью потребляет документ и принимает только frozen
direct/system.multicall success, direct/member fault и `d.custom.items` struct
envelopes. Разрешённые scalar tags — exact `string|i4|i8`; attributes,
namespaces, DTD/entity declarations, extra nodes, malformed UTF-8, duplicate
struct member и trailing bytes дают malformed/unknown до mutation. Request
count/type/schema и canonical integer lexeme проверяются до использования
sentinel или snapshot. Нужны named RED и mutations для каждого rejected shape.

Перед freeze надо снять sanitized verbatim BODY на disposable rTorrent 0.9.8 и
0.16.21 для direct string, i4/i8, mixed multicall success, direct fault,
multicall member fault и `d.custom.items` с empty value. Эта XML-съёмка была
остановлена до результата и не должна считаться выполненной.

### P1. Bounded source read и candidate construction

Текущий candidate сначала делает неограниченный `file_get_contents()`, а лишь
затем применяет scanner cap 64 MiB. Candidate envelope также сначала
конкатенируется, а потом проверяется. Большой readable source или output может
достичь `memory_limit` раньше classified refusal и оставить lease/quarantine.

Контракт должен заморозить bounded read до exact `64 MiB + 1` с отличием EOF от
oversize/read error, а для rewriter — overflow-safe encoded-length preflight до
allocation и bounded append, который никогда не превышает cap. Нужны named
source/output `cap`, `cap+1`, short-read/error и preflight-mutation gates.

### P1. Legacy config fallback для response cap

Direct adapter уже требует legacy-safe
`isset($rpcTransferTimeOut) ? $rpcTransferTimeOut : null`, но тот же contract
не зафиксирован для нового `$rpcMaxResponseBytes`. Он обязан передавать
`isset($rpcMaxResponseBytes) ? $rpcMaxResponseBytes : null` и иметь tests для
конфигурации, в которой отсутствуют оба новых global. Bare read запрещён.

## Свежая контейнерная матрица

На current published `43484fba` read-only bind mount и `--network none` дали
одинаковый результат в `php:7.4-cli`, `php:8.1-cli` и
`ivanshift/rutorrent:latest`:

```text
SCGITransportTest          25 methods / 58 passed / 0 failed / 0 fatal
XMLRPCProxyTest            75 methods / 194 passed / 0 failed / 0 fatal
XMLRPCProxyContractTest     7 methods / 849 passed / 0 failed / 0 fatal
XMLRPCProxyRejectionTest    7 methods / 17 passed / 0 failed / 0 fatal
Retrackers UpdateTest      42 tests / 0 failures / 0 fatal
```

Images:

```text
php:7.4-cli                 7bbbb12d14986e855e5213c6b349e97e0f2e3da82536ec87da11a6c66fe2fcb2
php:8.1-cli                 7699e39d88f66297bc94a8e3ab1ba60cfa68440a7c511599594475133eb863c7
ivanshift/rutorrent:latest  b9f58df32a5ae70f5b5e796418abbbb6c0e36d9bd9b61c20415c2d12022b8479
rutorrent-rt21:test         542dc45be35616096b57899a60b36015d4a99bc1a8aa8f3b92b0c338cfeca1f2
rutorrent-rt098:contract    2fce8587d588652af5ee2308243bc0803b8a82ec81bf5377940801a131c283b7
```

Это baseline/compatibility evidence, не GREEN отсутствующих codec/bounded-I/O
RED и не closure implementation package.

Repository pre-commit hook запустил полный host PHP 8.5.4 suite и отказал на
существующем `php/ScheduleTest.php`: **11 tests / 3 failures**. Два отдельных
fresh focused rerun дали те же три failures (`boundary-1s`, per-task slot,
optional clock seam). Staged scope содержит только два Markdown task-файла и не
может менять этот PHP behavior. Поэтому checkpoint commit разрешено сделать
через документированный hook escape `--no-verify`; полный suite нельзя
называть green. Полный hook output сохранён в
`/tmp/rutorrent-precommit.1ivaIk` до очистки host `/tmp`.

## Точный resume order

1. Снять перечисленные sanitized XML BODY captures на обеих daemon families.
2. Добавить в candidate один strict response codec, bounded source/candidate
   rules и fallback обоих optional SCGI globals; обновить natural RED,
   mutations и runtime gates.
3. Выполнить `git diff --check`, пересчитать SHA-256 и заморозить документ.
4. Получить новый whole-document `CLEAN` от двух независимых reviewers.
5. Только после двух `CLEAN` заменить header/verdict на
   `DESIGN APPROVED — implementation pending` и сделать отдельный approval
   commit.
6. Затем отдельными commits оформить сводную контейнерную верификацию и
   обновить README/PLAN/CROSSWALK.
7. Остановиться. К 18 implementation packages без нового указания не
   приступать; push не выполнять.

## Локальные resume resources

Сохранены вне repository и не входят в commit:

```text
/home/dev/contract-matrix.sh
/home/dev/retrackers-capability-probe.php
/home/dev/retrackers-temp-load-probe.torrent
/home/dev/typed-ledger-probe.php
/home/dev/scheduler-fence-probe.php
/home/dev/procfd-load-probe.php
```

Disposable containers, которые можно переиспользовать или удалить только по
точным именам после следующего evidence capture:

```text
audit-meta-21
contract-rt098
contract-rt21
fence-rt098
fence-rt21
proof-stage-098
proof-stage-21
```
