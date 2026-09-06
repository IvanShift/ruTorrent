# Статус 18 реализационных пакетов — обновлено 2026-09-05

## Последнее обновление 2026-09-05 (вечер): реестр отработан, №6 собран и проверен

Пользователь снял паузу («исправь все проблемы»). Сделано:

**master** — ветка `fix/consolidated-round1`, три раунда исправлений с
состязательным ревью после каждого. Закрыты M01–M10, Q01–Q16, T01–T03,
I02/I04/I05/I06, O01/O07. Полный набор: **exit 0** (было 773 теста, стало 813+).
В master ещё не влито — ветка локальная, push не делался.

**Пакет №6** — ветка `fix/p6-combined`. Кандидат `df7448e2` влит без правок
(staged diff = записанный SHA `3916f124`), сверху оба сохранённых патча, затем
исправления. Закрыты **P01–P05 и T04**. Полный набор: **exit 0, 8554 assertions
на PHP 7.4 / 8.1.34 / 8.5.4** (8.1 запускался под `--user 1000:1000`, иначе root
молча пропускает весь ремонт P04).

**Контейнерная кампания повторена на исправленных байтах** (метка
`repaired-3cf52e4d`, baseline не тронут): **4 из 4 проходят** там, где кандидат
падал 4 из 4. Торрент удалён, payload собран, манифест убран, расписание
retired, `phase: disarmed`, 5-секундный drain перестал перезапускаться.

Найдено и исправлено сверх реестра — **блокирующий дефект в сохранённом патче
воркера**: скан несвязанного staging из P03 прерывал всю генерацию навсегда,
так что один упавший продюсер, застейджив 1 член батча из 50, замораживал все 50
обязательств на весь срок жизни установки. Воспроизведено на фикстуре, отказ
теперь ограничен своим хешем, соседи получают классифицированную диагностику.

Побочные находки: [SIDE-FINDINGS](../2026-09-05-consolidated-fixes/SIDE-FINDINGS.md).
Разбор S01–S03: [S01-S03-ANALIZ](../2026-09-05-consolidated-fixes/S01-S03-ANALIZ.md)
— рекомендация: S01 менять, S02 в основном документация, S03 оставить.

Осталось по реестру: **M11** (пакет №7, ждёт приёмки №6), **I01/I03**
(сознательно не делаются), **O02/O03/O05/O06/O08** (репозиторий
`docker-rutorrent`, вне границы этого).

[Единый реестр перепроверенных проблем](../2026-09-03-delegated-remaining/CONSOLIDATED-FINDINGS-2026-09-05.md)
объединяет оба входных документа без дублей и отделяет master от candidate №6.
[Точная точка продолжения](../2026-09-03-delegated-remaining/PACKAGE-6-AUDIT-CHECKPOINT-2026-09-05.md)
содержит refs, MERGE_HEAD, сохранённые patches и незавершённые gates.
**Счёт прежний: 8 закрыто / 10 открыто из 18.** Commit/push по этому аудиту нет.

## Текущий срез: upstream-ветки подготовлены, реализационный счёт не меняется

**Закрыто 8/18; остаётся 10 незакрытых реализационных пакетов.**

Принятый product tree — `92d7111f`; после локального squash тот же код
закреплён ref `codex/upstream-handoff-squash-20260905` и master. Первый parent
aggregate — пользовательский `ebb60a7e`, второй — upstream `b4e84b64`.
Повторный fetch подтвердил origin/master `72d1885c`; push, PR и deployment
не выполнялись. Четыре диагностических log-файла сохранены. Точный handoff:
[UPSTREAM-HANDOFF-2026-09-05.md](../2026-09-03-delegated-remaining/UPSTREAM-HANDOFF-2026-09-05.md).

| № | Пакет | Текущий итог | Где находится / что дальше |
|---:|---|---|---|
| 1 | PHP 7.4 Torrent | CLOSED / UPSTREAM | В master; #3224/#3229 приняты по ранее подтверждённой истории |
| 2 | setsettings/socket | CLOSED / UPSTREAM | В master; #3227 принят |
| 3 | httprpc-refusals | CLOSED / UPSTREAM | В master; #3228 принят |
| 4 | scgi-transport | CLOSED / PR BRANCH READY | `up/scgi-transport-v2` — `de781784`, независимо от upstream b4e84b64 |
| 5 | retrackers-recovery | **CLOSED / STACKED BRANCH READY** | `up/retrackers-recovery-v2` — `26991e79`; отправлять после №4 и refresh |
| 6 | erasedata A remove-payload | **CHANGES_REQUIRED / FIXES PAUSED** | `df7448e2` независимо проверен; partial WIP в двух worktrees, не в master; checkpoint выше |
| 7 | httprpc → erasedata | PENDING | Теперь ждёт только №6: №14 закрыт |
| 8 | Ratio → erasedata B | PENDING | После №6 |
| 9 | P0+C replacement transaction | PENDING | После №6 |
| 10 | P1 rutracker-post-api | PENDING | После №9 |
| 11 | P2 history marker | PENDING | После №10 и event-order capture |
| 12 | P3 retrackers marker | PENDING | Теперь ждёт №10: №5 закрыт |
| 13 | rTorrent alias surface | CLOSED / PR BRANCH READY | `up/rtorrent-alias-surface` — `a7bf4986`, независимо |
| 14 | XMLRPC proxy policy | **CLOSED / STACKED BRANCH READY** | `up/xmlrpc-proxy-policy` — `132efb1f`; отправлять после №4 и refresh |
| 15 | manual entrypoints | CLOSED / PR BRANCH READY | `up/rutracker-manual-entrypoints` — `b64afb30`; только ручной маршрут, не весь плагин |
| 16 | Kinozal checker resilience | PENDING | После №10 |
| 17 | NNMClub live contract | PENDING | После №10; capture сохранён |
| 18 | sibling tracker verdicts | PENDING | После №10 |

Остались №6–12 и №16–18. Это не «10 ещё не созданных контрактов»:
архитектурная работа зафиксирована, очередь относится к implementation/review.
Наличие внешней реализации №6 не означает её независимую приёмку.
№6 перепроверен, но не принят; №7–12/№16–18 этим аудитом не реализовывались.

Публикация и upstream acceptance — отдельные статусы. №5/№14 сейчас только
локальные, не отправлены upstream. Ранее принятые PR №1–3 здесь не проверялись
заново через GitHub API; запись сохраняет уже установленный merged status.
Подготовлены пять локальных веток: три независимые и две stacked. English
PR bodies, команды, точные scopes и связь со всеми 18 пакетами — в handoff.
Весь `rutracker_check` пользователь намерен отправлять после полной готовности;
самостоятельные части разрешены сейчас. №15 выделен без fork-only forum crawl.

Проверки итогового объединённого кода:

- PHP 7.4/8.1: №5 — 221/1097, sequence — 12/40;
  №14 — 192 methods / 2542 assertions на каждой версии.
- Полный host PHP 8.5 harness — exit 0; полный Jest — 23 suites / 336 tests.
- Полный PHPStan CI — 0 errors.
- Реальные rTorrent 0.9.8/0.16.21: partial cleanup/rollback, шесть потерянных
  mutation replies, 12 delayed reads и 11 безопасных late callbacks на каждом.
- Frozen sequence class сохранён побайтно; все 42 старых worker-сценария
  имеют проверенную semantic crosswalk.

Полный протокол, refs, ограничения доказательств и точка остановки:
[PACKAGE-5-14-INTEGRATION-2026-09-05.md](../2026-09-03-delegated-remaining/PACKAGE-5-14-INTEGRATION-2026-09-05.md).

Работа остановлена после upstream handoff и локального squash. №6 не ждали:
его проверенная база `72d1885c` остаётся в ancestry; готовность внешнего агента
не заменяет review. По следующему поручению — fresh inventory и review №6
либо публикация/feedback подготовленных веток. Старые числа ниже исторические.

## Исторический срез 2026-09-03

Далее сохранён предыдущий срез после независимой проверки делегированной работы,
синхронизации с `upstream/master=cd814cb5`, корректировки контрактов №6/№14 и
локальной интеграции пакета №15.

## Граница среза

```text
local master              b4d68005828c965b69c69969e835d36208c99ebb
upstream sync commit      4fd60d544b1b9604b6500fc437c6c33bf3a04d40
upstream/master           cd814cb58e260dc08a3894d3fbfd4407e966b031
origin/master             2d2710eb51a35695040d11dc2f18735a6aa5cce1
package 15 candidate      5a1a0d9798a76bff06a07c230eaaae941b1aef49
package 15 integration    b4d68005828c965b69c69969e835d36208c99ebb
package 5 Task 5          0bdac05d6cfab72edc39bcfe955a2ab0bd44ea48
```

Push и deployment не выполнялись. Четыре пользовательских диагностических
файла в корне сохранены вне commits.

## Сводка

- полностью реализованы: **6 из 18** — №1–4, №13 и №15;
- частично реализован: **1** — №5;
- финальная реализация не начата: **11** — №6–12, №14 и №16–18;
- незакрытых реализационных пакетов: **12** — partial №5 плюс 11 pending;
- в локальном fork `master`: №1–4, №13 и №15;
- полностью приняты upstream: №1–3;
- local-only fully implemented: №4, №13, №15;
- package №5 остаётся local-only partial; Task 5 builder проверен, но отдельно
  не интегрируется без wiring;
- неразобранных carve/verdict-аудитов: **0**.

Upstream #3251 и #3240/#3248 не закрывают №14/№6: это prerequisite code и
частичная архитектура, а не полная реализация их утверждённых контрактов.

## Реестр

| № | Пакет | Текущий вердикт | Где находится / что осталось |
|---:|---|---|---|
| 1 | PHP 7.4 `Torrent` | **CLOSED / UPSTREAM** | Основной и binary-metainfo follow-up приняты (#3224/#3229); код в master |
| 2 | `setsettings/socket` | **CLOSED / UPSTREAM** | #3227 merged; код в master |
| 3 | `httprpc-refusals` | **CLOSED / UPSTREAM** | #3228 merged; код в master |
| 4 | `scgi-transport` | **CLOSED / LOCAL APPROVED** | Код в master; clean upstream handoff остаётся delivery-задачей |
| 5 | `retrackers-recovery` | **PARTIAL** | `up/retrackers-recovery`: Tasks 1–4B; Task 5 builder `0bdac05d` APPROVED, но не wired; затем Tasks 6–8/runtime |
| 6 | erasedata A `remove-payload` | **DESIGN APPROVED / PENDING** | Current-base scope исправлен на 10 production + 3 tests; generation-safe admission, durable ack/retry ещё реализовать |
| 7 | httprpc → erasedata | **PENDING** | После final №14 + №6 |
| 8 | Ratio → erasedata B | **DESIGN APPROVED / PENDING** | После final №6 |
| 9 | combined P0+C replacement transaction | **DESIGN APPROVED / PENDING** | После final №6 |
| 10 | P1 `rutracker-post-api` | **PENDING** | После №9 |
| 11 | P2 history marker | **PENDING** | После №10 и event-order capture |
| 12 | P3 retrackers marker | **PENDING** | После final №5 + №10 |
| 13 | rTorrent alias surface | **CLOSED / LOCAL APPROVED** | Candidate `3146f741`, integration `4d779ff9`; upstream delivery optional |
| 14 | XMLRPC proxy policy | **DESIGN APPROVED / PENDING** | #3251 принят как prerequisite; RED-first sanitizer implementation теперь разблокирована |
| 15 | manual entrypoints | **CLOSED / LOCAL APPROVED** | Candidate `5a1a0d97`, fork integration `b4d68005`; upstream handoff отдельно |
| 16 | Kinozal checker resilience | **DESIGN APPROVED / PENDING** | После №10 |
| 17 | NNMClub live contract | **DESIGN APPROVED / PENDING** | После №10; live 67-byte capture сохранён |
| 18 | sibling tracker verdicts | **DESIGN APPROVED / PENDING** | После №10; AniDUB/Tfile defect подтверждён |

## Что изменил новый upstream

- #3251 сделал `conf/xmlrpc_proxy.php` единственным shipped owner списка safe
  parameters и выровнял два proxy entrypoint. Для №14 это снимает config
  blocker, но оставляет основную sanitizer policy нереализованной.
- #3240/#3248 добавили erasedata pending queue. Она сохранена в дереве, но не
  подключена в `erase.php`: hash-only `<hash>.list` acknowledgement подавляет
  re-added torrent того же infohash, а finite retry abandonment противоречит
  durable контракту №6.
- №6 переоткрыт на честной границе 10+3 и теперь готов к реализации; его
  downstream-пакеты остаются заблокированы до фактического GREEN.

## Проверка

- upstream merge: full PHP harness GREEN и full Jest 23 suites / 328 tests
  GREEN до интеграции №15;
- package №15: exact-parent 12 named RED, candidate 13/13 GREEN;
- package №15 в fork: real-entrypoint 13/13 и aggregate 22/22 на PHP
  7.4/8.1/8.5; JS 49/49; full PHP pre-commit GREEN;
- upstream queue trial: `testLegacyManifestDoesNotBlockAReaddedSameHash` RED
  при прямом wiring и GREEN после возврата generation-aware пути;
- `git diff --check` GREEN на sync и package commits.

Полная запись: `VERIFICATION-upstream-sync-packages-2026-09-03.md`.

## Следующий порядок

1. Довести №5: подключить уже проверенный Task 5 builder, затем Tasks 6–8 и
   финальную runtime-приёмку.
2. Независимо можно реализовывать №14 и скорректированный №6 от
   `master=b4d68005`.
3. После №6: №8 и №9; №7 ждёт одновременно №6 и №14.
4. После №9: №10, затем №11/№12/№16/№17/№18 по их prerequisites.
5. Upstream handoff №4/№13/№15 вести отдельными чистыми ветками; это не меняет
   счёт реализаций.
