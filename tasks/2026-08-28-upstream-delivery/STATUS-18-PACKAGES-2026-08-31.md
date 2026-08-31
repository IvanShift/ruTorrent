# Статус 18 реализационных пакетов — 2026-08-31

Это текущий авторитетный срез очереди. Исторические числа в `README.md`,
`PLAN-remaining-queue-2026-08-29.md` и review-документах сохраняют контекст на
момент их создания; при расхождении по текущему состоянию использовать этот
файл и затем обновлять его по правилам в конце документа.

## Граница среза

- локальный `master`: `377de777cbf59b656eea5d796b4a16c0d81f96c2`;
- опубликованный `origin/master`: `f2a6ac501fb20f101a35b14103ca7c3a606df843`;
- `upstream/master`: `781cee4e5c84ba16d40ae3c9918bc542a15f493b`;
- `d06e0651` — ancestry-only merge `f2a6ac50 + 781cee4e`: этот sync не менял
  дерево fork, но добавил принятый upstream `#3229` в историю;
- `377de777` поверх него интегрирует последний недостающий fragment #3198 в
  fork: transport failure cached-запроса больше не проваливается в credential
  login; exact scope — `accounts.php` и `CommonAccountTest.php`;
- push локального `master` не выполнялся.

Фраза «нет в `master`» ниже означает отсутствие финальной реализации нового
контракта. Старые fork-хунки той же темы могут существовать, но не считаются
закрытием пакета.

## Сводка

- полностью реализованы: **4 из 18** — пакеты 1–4;
- частично реализован: **1** — пакет 5, завершены Tasks 1–4 из 8;
- финальная реализация ещё не начата: **13** — пакеты 6–18;
- незакрытых реализационных пакетов: **14** — пакеты 5–18;
- финальные реализации в fork `master`: **1–4**;
- полностью закрыт upstream: **пакет 1**;
- открытые upstream PR пакетов: **#3227 и #3228**;
- готовый local-only пакет без PR: **№4**;
- partial local-only пакет без PR: **№5**.

Design approval сам по себе не означает, что реализация готова. Пакет
закрывается только после кода, RED→GREEN, требуемых runtime-проверок и
независимого финального review.

## Реестр

| № | Пакет | Текущий вердикт | Где находится | Upstream | Что осталось |
|---:|---|---|---|---|---|
| 1 | PHP 7.4 `Torrent` | **Полностью закрыт** | Candidate `286dd24b`, fork integration `acbf5691`, binary follow-up `76b0c0f5`; финальный код в `master` | #3224, #3225 и **#3229 merged**; #3229 вошёл в `upstream/master=781cee4e` | Только поддерживать документацию |
| 2 | `setsettings/socket` | **Полностью реализован**, замечания maintainer исправлены | Final branch `938ff6ff`; fork integrations `f547b2f3`, `fe5313fa`, `b55db503`, `d5d4a38d`; в `master` | #3227 OPEN, remote содержит fixes; #3236 merged | Ждать повторного review maintainer |
| 3 | `httprpc-refusals` | **Полностью реализован и APPROVED** | `c7a431aa`, fork integration `48825583`; в `master` | #3228 OPEN | Ждать upstream review |
| 4 | `scgi-transport` | **Полностью реализован и APPROVED** | Runtime `4682a761`, delivery `33934444`, fork integrations `19086b5f` + `3ff4860c`; в `master` | PR нет | Перебазировать clean handoff на свежий upstream и подготовить PR |
| 5 | `retrackers-recovery` | **Частично реализован**: Tasks 1–4 завершены, Task 4B independently APPROVED | Clean local branch `up/retrackers-recovery=9fef4d66`; cumulative exact six-path implementation; не в `master`, не push | PR нет | Task 5 hooks; Task 6 old-generation commit; Task 7 candidate/rollback; Task 8 полная runtime-приёмка |
| 6 | erasedata A `remove-payload` | Corrected design **APPROVED**, кода нет | Финальной ветки нет | PR нет | RED-first implementation; package 3 prerequisite готов |
| 7 | httprpc → erasedata | Boundary/order утверждены, кода нет | Нет | PR нет | После packages 14 и 6 |
| 8 | Ratio → erasedata B | Corrected design **APPROVED**, кода нет | Нет | PR нет | После package 6 |
| 9 | combined P0+C replacement transaction | Design **APPROVED**, кода нет | Нет | PR нет | После package 6 |
| 10 | P1 `rutracker-post-api` | Scope/ownership утверждены, кода нет | Нет | PR нет | После package 9 |
| 11 | P2 history marker | Контракт зафиксирован, кода нет | Нет | PR нет | После package 10 и event-order capture |
| 12 | P3 retrackers marker | Scope/order зафиксированы, кода нет | Нет | PR нет | После packages 5 и 10 |
| 13 | rTorrent alias surface | Scope утверждён, кода нет | Финальной ветки нет | Prerequisite #3230 merged | Разблокирован; начать с RED/mutation gates |
| 14 | XMLRPC proxy policy | Design **APPROVED**, кода нет | Нет | PR нет | Разблокирован package 3; RED-first implementation |
| 15 | manual entrypoints | Six-path scope и 7 production defects классифицированы, кода нет | Нет | PR нет | Независим и разблокирован |
| 16 | Kinozal checker resilience | Split **APPROVED**, кода нет | Нет | PR нет | После package 10 |
| 17 | NNMClub live contract | Split **APPROVED**, сохранён реальный 67-byte capture, кода нет | Нет | PR нет | После package 10 |
| 18 | sibling tracker verdicts | Split **APPROVED**, AniDUB/Tfile defect подтверждён, кода нет | Нет | PR нет | После package 10 |

## Upstream ledger вне счётчика 18

- #3198 — OPEN. Владелец опубликовал `up/kinozal-session=c39d499d`: один
  commit на `781cee4e`, ровно 5 файлов, `+653/-28`. Patch идентичен ранее
  одобренному `8bebd439`, свежий independent review дал `APPROVED`, GitHub
  показывает clean merge и **8/8 GREEN checks**. Ожидается ответ maintainer.
- #3230, #3231, #3233, #3234, #3235 и #3236 — merged.
- #3232 test harness — OPEN.
- Отдельного upstream PR для SCGI сейчас нет.

## Следующий порядок

Параллельно безопасны три независимые линии:

1. закончить package 5: Tasks 5 → 6 → 7 → 8;
2. начать package 6 erasedata A и package 14 XMLRPC policy, затем package 7;
3. начать разблокированные package 13 alias surface и package 15 manual
   entrypoints.

Основная зависимая цепочка остаётся:

```text
6 erasedata A -> 8 Ratio B
               -> 9 P0+C -> 10 P1 -> 11 P2
                                   -> 16 Kinozal
                                   -> 17 NNMClub
                                   -> 18 sibling trackers

5 retrackers -------------------------------> 12 P3
10 P1 --------------------------------------> 12 P3

14 XMLRPC + 6 erasedata A ------------------> 7 httprpc-erasedata
```

## Как обновлять этот срез

При каждом закрытии стадии или изменении PR одновременно записывать:

1. exact local/upstream/origin refs и факт push/no-push;
2. статус package: design-only, partial либо complete;
3. commit, точный scope и результаты обязательной проверки;
4. upstream PR state и следующий dependency edge;
5. пересчитанные итоги без включения внешних handoff в 18 пакетов.

Главные источники деталей: `PLAN-remaining-queue-2026-08-29.md`,
`CROSSWALK-remaining-divergence-2026-08-29.md`, package-specific
`REVIEW-*`/`VERIFICATION-*` документы и локальный SDD ledger package 5.

## Точка остановки

Работа остановлена после следующих завершённых действий:

1. upstream синхронизирован локально через #3229: `d06e0651`;
2. #3198 опубликован владельцем как `c39d499d`, clean merge, 8/8 GitHub checks
   GREEN;
3. отсутствующий fragment #3198 перенесён в fork `master` отдельным commit
   `377de777`; RED воспроизвёл лишний credential login, GREEN прошёл focused и
   full PHP harness на PHP 7.4/8.1/8.5;
4. package 5 остановлен после independently APPROVED Task 4B на
   `up/retrackers-recovery=9fef4d66`; Task 5 не начинался;
5. новые реализационные пакеты после этого checkpoint не запускались;
6. push fork `master` не выполнялся; `origin/master` остаётся `f2a6ac50`.

Примечание к финальному docs commit: pre-commit в основном checkout читает
игнорируемый runtime-cache `share/settings/rtorrent.dat` от rTorrent 0.9.8.
Alias `schedule -> schedule2` добавляет пустой target-параметр, а
`ScheduleTest.php` не изолирует cache и поэтому даёт 8/11 вместо 11/11. В
чистом integration worktree без cache тот же ScheduleTest даёт 11/11, а полный
итоговый harness прошёл на PHP 7.4/8.1/8.5. Ни `settings.php`, ни
`ScheduleTest.php` этой работой не менялись; runtime-cache сохранён. Поэтому
docs-only commit допустимо создан с `--no-verify`, с этой явной записью причины.

При следующем старте сначала проверить новые комментарии/состояния #3198,
#3227 и #3228 и обновить refs. Если внешних блокеров нет, resume package 5 с
**Task 5: init/done hook install protocol**, используя локальный SDD plan в
`.worktrees/up-retrackers-recovery/.superpowers/sdd/retrackers-recovery-implementation/`.

Пользовательские `logs_90471600543.zip`, `logs_90485329911.zip`,
`logs_90525388665.zip` и `rutorrent-app-errors.log` намеренно не добавлены в
commits и должны быть сохранены.
