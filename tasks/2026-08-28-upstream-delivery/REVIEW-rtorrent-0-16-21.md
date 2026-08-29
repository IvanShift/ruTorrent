# Перепроверка `up/rtorrent-0-16-21`

Дата: 2026-08-29. База — `upstream/master` `755404f3`, итоговая вершина —
`48bc6d4b` (`Test rTorrent 0.16.21 compatibility`).

## Вердикт

**Готова к отправке.** Это один test-only commit: три файла, `+9/-4`, без
изменения runtime-кода. Ветка не добавляет поддержку rTorrent 0.16.21, а явно
закрепляет уже существующие version gates на актуальном релизе.

Старая оценка `+1274/-8` была отвергнута после повторного измерения: перенос
целых `php/settings.php` и `js/content.js` смешивал эту задачу с уже существующей
compatibility-архитектурой и socket-веткой. Итоговый scope содержит только:

- `tests/js/rtorrent.spec.js`;
- `tests/php/RequirementsTest.php`;
- `tests/php/RtorrentCompatibilityTest.php`.

## Что именно закреплено

`rTorrentSettings::obtain()` кодирует `0.16.21` как `0x1015`. Новые строки
проверяют три существующих контракта:

1. `Requirements::rtorrentSupport()` относит `0.16.21` к уже заявленной серии
   `0.16.x`;
2. версия наследует canonical port aliases, введённые gate-ом `>= 0x1012`, и
   socket-команды более ранних gate-ов;
3. `d.is_partially_done` вызывается напрямую, а pre-0.9 fallback `cat` не
   применяется.

Ни один production gate не расширен и ни один command alias не добавлен.
Socket-specific `setsettings`, полный аудит alias surface, SCGI transport и
production-комментарии намеренно исключены: у них отдельные ветки.

## Независимая проверка поведения

Официальный исходный код rTorrent 0.16.21 содержит все команды, которые
проверяет patch: canonical port range/random, `system.sockets.max_size`,
динамически зарегистрированный `system.sockets.files.max_alloc.set` и
`d.is_partially_done`.

Дополнительный disposable smoke на заранее существовавшем локальном образе
`rutorrent-rt21:test` получил `system.client_version=0.16.21`, API 26 и подтвердил
наличие всех семи проверяемых методов через `system.listMethods`. Контейнер после
проверки удалён; образ не создавался и не изменялся.

Это не полный runtime certification: UI/plugin workload, длительный SCGI-трафик
и production deploy не выполнялись.

## Mutation evidence

Мутации выполнялись в disposable worktree и затем полностью удалялись:

- исключение `0x1015` из JS canonical-port gate и принудительный pre-0.9
  partially-done path уронили ровно две именованные Jest-проверки на новых
  строках;
- ограничение Requirements версией 0.16.20, возврат legacy port aliases и
  pre-0.9 partially-done aliases для `0x1015` уронили три именованных PHP test
  method: одну support-policy, девять alias и одну partially-done assertion;
- старые version rows при этом оставались зелёными; fatal, parse error и
  uncaught exception не было.

Независимый spec-review повторил эти RED-проверки и дал `PASS`. Отдельный
quality-review проверил production gates, официальный command surface и локальный
daemon smoke; вердикт `APPROVED`, Critical/Important находок нет.

## Итоговая проверка

- Jest: 20 suites, 196 tests, всё зелёное;
- host PHP 8.5: 46 файлов, 285 test method, 1790 assertions;
- root `php:8.1-cli`, writable bind, без `--user`: те же 46 / 285 / 1790;
- reviewer-side focused Jest: 20/20;
- reviewer-side focused PHP: 47 assertions;
- `git diff --check` чист;
- parent и merge-base — ровно `755404f3`;
- один commit, три test-файла, `+9/-4`, рабочее дерево чистое;
- push не выполнялся.
