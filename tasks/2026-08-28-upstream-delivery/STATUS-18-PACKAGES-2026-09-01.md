# Статус 18 реализационных пакетов — 2026-09-01

Это текущий авторитетный срез очереди после синхронизации с upstream. Более
ранний `STATUS-18-PACKAGES-2026-08-31.md` сохраняется как исторический
checkpoint.

## Граница среза

- product-sync commit: `88995e7c7a8aae7729809aa0c52bfb095e6be62c`;
- его родители: fork `a4d48853997377eab3a839d4246b687fe79bde60` и
  `upstream/master=495e2a54a657efcc132dc1456db8d7e680304a8a`;
- опубликованный `origin/master`: `a4d48853997377eab3a839d4246b687fe79bde60`;
- push не выполнялся;
- четыре пользовательских диагностических файла в корне сохранены и в commits
  не включены.

Upstream delta после прежнего `781cee4e` содержит:

- package 2 — #3227 merged как `7d2a69db`;
- package 3 — #3228 merged как `7e77ebf0`;
- test-harness handoff — #3232 merged как `2920ad7d`;
- loginmgr handoff вне реестра 18 — #3198 merged как `495e2a54`;
- независимые upstream #3238/#3239 с различимыми заголовками rules dialogs и
  восстановленной изоляцией PHP test ini.

При merge сохранены более строгие fork-only SCGI transport, path boundary,
CI failure annotations и защита disposable PHP server. Приняты upstream body
guards для httprpc, локализации и `php-test.ini`.

## Сводка

- полностью реализованы: **4 из 18** — packages 1–4;
- частично реализован: **1** — package 5, Tasks 1–4 из 8 завершены;
- финальная реализация ещё не начата: **13** — packages 6–18;
- незакрытых реализационных пакетов: **14** — packages 5–18;
- финальные реализации в fork `master`: **1–4**;
- полностью приняты upstream: **packages 1–3**;
- открытых upstream PR среди 18 packages: **0**;
- готовый local-only package без PR: **№4**;
- partial local-only package без PR: **№5**.

Acceptance upstream меняет delivery-state, но не число реализованных packages.
Design approval также не закрывает package без кода, RED→GREEN, обязательных
runtime-проверок и финального review.

## Реестр

| № | Пакет | Текущий вердикт | Где находится | Upstream | Что осталось |
|---:|---|---|---|---|---|
| 1 | PHP 7.4 `Torrent` | **Полностью закрыт** | Candidate `286dd24b`, fork integration `acbf5691`, binary follow-up `76b0c0f5`; код в `master` | #3224, #3225 и #3229 merged | Только поддерживать документацию |
| 2 | `setsettings/socket` | **Полностью закрыт** | Final branch `938ff6ff`; fork integrations `f547b2f3`, `fe5313fa`, `b55db503`, `d5d4a38d`; код в `master` | **#3227 merged** как `7d2a69db`; #3236 также merged | Только поддерживать документацию |
| 3 | `httprpc-refusals` | **Полностью закрыт** | Candidate `c7a431aa`, fork integration `48825583`; код в `master` | **#3228 merged** как `7e77ebf0` | Только поддерживать документацию |
| 4 | `scgi-transport` | **Полностью реализован и APPROVED** | Runtime `4682a761`, delivery `33934444`, fork integrations `19086b5f` + `3ff4860c`; код в `master` | PR нет; origin-ветки нет | Перебазировать clean handoff на `495e2a54`, перепроверить и подготовить PR |
| 5 | `retrackers-recovery` | **Частично реализован**: Tasks 1–4 завершены, Task 4B APPROVED | Clean local branch `up/retrackers-recovery=9fef4d66`; не в `master`, не push | PR нет | Task 5 hooks; Task 6 old-generation commit; Task 7 candidate/rollback; Task 8 runtime-приёмка |
| 6 | erasedata A `remove-payload` | Corrected design **APPROVED**, кода нет | Финальной ветки нет | PR нет | RED-first implementation; package 3 prerequisite готов |
| 7 | httprpc → erasedata | Boundary/order утверждены, кода нет | Нет | PR нет | После packages 14 и 6 |
| 8 | Ratio → erasedata B | Corrected design **APPROVED**, кода нет | Нет | PR нет | После package 6 |
| 9 | combined P0+C replacement transaction | Design **APPROVED**, кода нет | Нет | PR нет | После package 6 |
| 10 | P1 `rutracker-post-api` | Scope/ownership утверждены, кода нет | Нет | PR нет | После package 9 |
| 11 | P2 history marker | Контракт зафиксирован, кода нет | Нет | PR нет | После package 10 и event-order capture |
| 12 | P3 retrackers marker | Scope/order зафиксированы, кода нет | Нет | PR нет | После packages 5 и 10 |
| 13 | rTorrent alias surface | Scope утверждён, кода нет | Финальной ветки нет | Prerequisites #3230/#3236 merged | RED-first implementation и mutation gates |
| 14 | XMLRPC proxy policy | Design **APPROVED**, финального package-кода нет | Старые/richer fork hunks не считаются closure | PR нет | RED-first implementation на post-#3228 базе |
| 15 | manual entrypoints | Six-path scope и 7 production defects классифицированы, кода нет | Нет | PR нет | Независим и разблокирован |
| 16 | Kinozal checker resilience | Split **APPROVED**, кода нет | Нет; loginmgr #3198 — другая тема | PR нет | После package 10 |
| 17 | NNMClub live contract | Split **APPROVED**, сохранён реальный 67-byte capture, кода нет | Нет | PR нет | После package 10 |
| 18 | sibling tracker verdicts | Split **APPROVED**, AniDUB/Tfile defect подтверждён, кода нет | Нет | PR нет | После package 10 |

## Upstream ledger вне счётчика 18

- #3198 loginmgr — **merged** как `495e2a54`; fork уже содержал одобренный
  эквивалент, merge добавил upstream ancestry.
- #3232 test harness — **merged** как `2920ad7d`; последующий #3239 вернул
  изолированный `php-test.ini`. Fork сохранил свои CI failure annotations.
- #3230, #3231, #3233, #3234, #3235, #3236, #3238 и #3239 — merged.
- отдельного upstream PR для package 4 SCGI пока нет.

## Проверка синхронизации

- PHP 8.5 host full harness: 71 files, 3545 `Passed:`, 0 failures;
- PHP 7.4 container, UID 1000:1000: 71 files, 3545 `Passed:`, 0 failures;
- PHP 8.1 container, UID 1000:1000: 71 files, 3545 `Passed:`, 0 failures;
- full Jest: 23 suites / 324 tests GREEN;
- focused socket + localization Jest: 61/61 GREEN;
- PHP lint, `node --check`, `bash -n` и `git diff --check` GREEN.

Первые root-container прогоны PHP 7.4/8.1 дали девять ожидаемых false RED в
permission-denial fixtures: root обходит проверяемые запреты записи. Повтор от
UID 1000:1000 является валидным verdict и полностью зелёный.

## Следующий порядок

1. Продолжить package 5 с Task 5: init/done hook install protocol.
2. Независимо доступны packages 6, 13, 14 и 15.
3. После package 6: package 8 и package 9; после package 9 — package 10 и его
   dependants 11, 12, 16, 17, 18.
4. Package 7 ждёт одновременно packages 14 и 6.
5. Package 4 можно отдельно подготовить к upstream после чистого rebase на
   `495e2a54`; это delivery-задача и не меняет счёт 14.

## Точка продолжения

- product sync: `88995e7c`;
- package 5 branch: `up/retrackers-recovery=9fef4d66`, Task 5 не начинался;
- package 4 delivery branch: `up/scgi-transport=33934444`, local-only;
- `origin/master` отстаёт, потому что push не разрешался и не выполнялся;
- пользовательские logs в корне сохранить.
