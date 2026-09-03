# Статус 18 реализационных пакетов — 2026-09-03

Это текущий авторитетный срез после независимой проверки делегированной работы,
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
