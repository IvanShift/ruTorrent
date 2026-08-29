# Задача: заливка форка в upstream — что залито, что осталось, где проблемы

Срез обновлён **2026-08-29**: `upstream/master` = `755404f3`, форковый `master` =
`511ed13f` (`origin/master`), 91 commit впереди и 12 позади. Подробный старый
план: `../2026-08-28-upstream-rebuild/PLAN.md`.

## Цель

Отправить в upstream **все** расхождения форка, корректно оформленными PR. Это решение
владельца, принятое в начале работы; оно не менялось.

---

## Залито и принято (14 PR)

| PR | тема |
|---|---|
| #3165 | Snoopy: protocol-relative URL |
| #3167 | rTorrent compatibility |
| #3168 | stale details pane |
| #3169 | cache: concurrent writers |
| #3170 | RSS encodings |
| #3174 | conf debug flag |
| #3175 | NNMClub own passkey |
| #3176 | Kinozal login wall |
| #3177 | ratio view guard |
| #3178 | history service entries |
| #3179 | partial seed |
| #3199 | `getScheduleCommand` — детерминированный старт |
| #3200 | Up/small UI |
| **#3205** | **loginmgr: выбор аккаунта по хосту URL, и никогда ниже его схемы** — security |

Отдельно: **#3206** (`fde9863b`) — xirvik сам расширил нашу работу из #3205 на NNMClub.
То есть подход принят и мейнтейнер на нём строит.

---

## Открыто в upstream

| PR | ветка | состояние |
|---|---|---|
| **#3198** | `up/kinozal-session` (`0d1da02f`) | Открыт. Переписан в один коммит, ответ на ревью xirvik готов в `../2026-08-28-upstream-rebuild/REPLY-3198.md`. Содержимого в upstream пока нет (проверено: `classifyAnswer` там отсутствует). +636/−28, 5 файлов |

---

## Готово, но НЕ отправлено

| ветка | коммит | объём | что мешает |
|---|---|---|---|
| `up/history-service-labels` | `4cf3bd69` | +37/−5, 3 файла | **НЕ ОТПРАВЛЯТЬ.** Достижимая потеря истории/Pushbullet для пользовательских `.private`-меток; producer отсутствует в upstream; тест не держит production-gate. Разбор: `REVIEW-history-service-labels.md` |
| `up/test-harness` | `64778267` | +49/−17, 4 файла | **ГОТОВА.** Обе обязательные правки сделаны; 1421/0 на PHP 8.5 и root PHP 8.1; семь мутаций красные. Тексты: `REVIEW-test-harness.md`, `PR-test-harness.md` |
| `up/rtorrent-0-16-21` | `48bc6d4b` | +9/−4, 3 test-файла | **ГОТОВА.** Один commit прямо на `755404f3`; обе полные PHP-матрицы 46/285/1790, Jest 20/196, независимые spec/quality reviews зелёные. Тексты: `REVIEW-rtorrent-0-16-21.md`, `PR-rtorrent-0-16-21.md` |
| `up/loginmgr-account-selection` | `1975ecb4` | — | **Уже влита как #3205.** Ветку можно удалять |

### Что исправлено в `up/test-harness`

Половина про `TestCase.php` подтверждена: падающий test печатал `Passed:` при
`zend.assertions=-1`, после правки печатает `Failed:`. Два прежних блокера закрыты:

1. Runner явно передаёт `-d zend.assertions=1 -d error_reporting=-1
   -d display_errors=1`; системный php.ini больше не меняет видимость diagnostics.
2. Ложная команда `php -f php/CacheTest.php` удалена из commit message и заменена
   реальным standalone probe.

Добавлен `TestCaseTest.php`, который держит и поведение при выключенных assertions,
и все три effective runner settings. Полный разбор: `REVIEW-test-harness.md`.

---

## Снято с отправки

| ветка | почему |
|---|---|
| `up/snoopy-gzip-body` | Дефект реален в коде, недостижим в проде: ни один живой сервер не ставит gzip-флаг FNAME, а curl-путь вообще не запрашивает сжатие. Моя правка внесла регрессию **хуже** исходного бага (`gzdecode()` возвращает `false` там, где `gzinflate()` возвращал тело целиком → `STE_DELETED` в `check.php:445`). Ветка удалена. Разбор: `../2026-08-28-upstream-rebuild/REVIEW-snoopy.md`, память `snoopy-gzip-dead-end` |
| `up/log-unwritable` | Обоснование перевёрнуто, тест красный в документированной команде. Ветка снята, вернуть: `git branch up/log-unwritable 5346acbf`. Разбор: `../2026-08-28-upstream-rebuild/VERDICT-log-unwritable.md`. Настоящие дефекты, найденные вместо неё: `../2026-08-28-fileutil-defects/` |

### Никогда не отправлять (решено ранее)

- Гард `unserialize` в `php/cache.php` — адверсально проверен, это no-op
- Хунки `.gitignore` (форковые строки 68-75 + `backup/`; в upstream их нет)
- `AGENTS.md`
- Правки реестра в `tests/plugins/rutracker_check/run.php`

---

## Очередь: в работе и не начато (PR 5-11)

Из `PLAN.md`, по порядку зависимостей. Это основной объём расхождения — 91 commit
форка сидят в основном здесь.

| # | ветка | объём | зависит от |
|---|---|---|---|
| 5 | `up/rtorrent-0-16-21` | **+9/−4, 3 test-файла; готова как `48bc6d4b`** | — |
| 6 | `up/httprpc-refusals` | +182/−170, ~9 файлов | 2 |
| 7 | `up/scgi-transport` | +1100/−15, ~8 файлов | 2, 6 |
| 8 | `up/setsettings-socket-alloc` | +910/−15, 4 файла до последней review-поправки | — |
| 9 | `up/retrackers-recovery` | +1540/−45, 5 файлов | 2 |
| 10 | `up/erasedata-collector` | +11347/−210, 12 файлов | 2 |
| 11 | `up/rutracker-post-api` | +29869/−1655, 70 файлов | 2, 10 |

Ветки #5 и #8 уже нарезаны. #5 готова; #8 проходит последний fix/review cycle.
Ветки #6, #7 и #9-11 ещё не нарезаны — старые оценки для них не считаются
доказательством и должны быть перемерены от текущего upstream.

---

## Правила, которые нельзя нарушать

- Ветки режутся от `upstream/master`, называются `up/<имя>`, несут состояние файлов из
  форкового `master`.
- **Никогда `git add -A` на ветке `up/*`** — в `.gitignore` upstream нет строк про
  `tasks/`, `docs/`, `.claude/`, `.agents/`, `.codex/`, `.superpowers/`, `backup/`,
  и весь этот мусор уедет в PR.
- Никакого PHPUnit и composer — в upstream их нет.
- Обе матрицы PHP перед отправкой: локальный 8.5 и контейнер 8.1
  **по команде из README, без `--user`**.
- Мутационная проверка каждой правки.
- **Мерить посылку до того, как писать аргумент.** Три ветки за один день сгорели
  ровно на этом.

## Что делать дальше

1. ~~Дождаться перепроверки `../2026-08-28-fileutil-defects/`.~~ Завершено и
   зафиксировано в той задаче.
2. ~~Проверить адверсально `up/history-service-labels`.~~ Проверена и отвергнута;
   безопасную marker-based логику сложить с producer-ом в PR 11. Разбор:
   `REVIEW-history-service-labels.md`.
3. ~~Починить `up/test-harness`.~~ Готова как `64778267`; осталось push/PR по
   `PR-test-harness.md`.
4. Удалить `up/loginmgr-account-selection` — влита.
5. Отправить готовую test-only ветку `up/rtorrent-0-16-21` по тексту
   `PR-rtorrent-0-16-21.md`.
6. Закрыть последнюю review-находку в `up/setsettings-socket-alloc`, заново
   проверить её на текущем upstream и только затем переносить исправление в
   форковый `master`.
7. Продолжить очередь с независимо перемеренной `up/httprpc-refusals`, затем
   `up/scgi-transport` и plugin-пакетами.
