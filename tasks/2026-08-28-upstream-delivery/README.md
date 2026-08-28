# Задача: заливка форка в upstream — что залито, что осталось, где проблемы

Срез на **2026-08-28**, `upstream/master` = `fde9863b`. Форковый `master` — 85 впереди,
2 позади. Подробный старый план: `../2026-08-28-upstream-rebuild/PLAN.md`.

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
| `up/test-harness` | `8231d2eb` | +3/−17, 3 файла | **ЗАБЛОКИРОВАНА.** Две обязательные правки — ниже |
| `up/loginmgr-account-selection` | `1975ecb4` | — | **Уже влита как #3205.** Ветку можно удалять |

### Почему заблокирована `up/test-harness`

Половина про `TestCase.php` верна и измерена (падающий тест печатал `Passed:` при
`zend.assertions=-1`, после правки печатает `Failed:`; 1419/0 во всех трёх конфигурациях).
Но до отправки нужно:

1. Удаление `php-test.ini` + `-c` рас-пинивает гораздо больше, чем `zend.assertions`:
   `error_reporting` 30719→22527, `display_errors` on→off, `memory_limit` 128M→−1, а число
   `Deprecated` в прогоне падает с 14 до 2. Заменить удаление на
   `-d zend.assertions=1 -d error_reporting=-1 -d display_errors=1`.
2. Сообщение коммита ссылается на `php -f php/CacheTest.php`, которая выдаёт 0 байт и
   exit 0, потому что драйвер живёт в `php-test.sh`. Утверждение ложно, переписать.

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

## Очередь: не начато (PR 5-11)

Из `PLAN.md`, по порядку зависимостей. Это основной объём расхождения — 85 коммитов
форка сидят в основном здесь.

| # | ветка | объём | зависит от |
|---|---|---|---|
| 5 | `up/rtorrent-0-16-21` | +1274/−8, 4 файла | 2 |
| 6 | `up/httprpc-refusals` | +182/−170, ~9 файлов | 2 |
| 7 | `up/scgi-transport` | +1100/−15, ~8 файлов | 2, 6 |
| 8 | `up/setsettings-socket-alloc` | +540/−23, 5 файлов | — |
| 9 | `up/retrackers-recovery` | +1540/−45, 5 файлов | 2 |
| 10 | `up/erasedata-collector` | +11347/−210, 12 файлов | 2 |
| 11 | `up/rutracker-post-api` | +29869/−1655, 70 файлов | 2, 10 |

Ветки под эти номера **ещё не нарезаны** — существуют только строки плана.

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

1. Дождаться перепроверки `../2026-08-28-fileutil-defects/` другой моделью.
2. ~~Проверить адверсально `up/history-service-labels`.~~ Проверена и отвергнута;
   безопасную marker-based логику сложить с producer-ом в PR 11. Разбор:
   `REVIEW-history-service-labels.md`.
3. Починить и отправить `up/test-harness` (две правки выше) — **текущий шаг**.
4. Удалить `up/loginmgr-account-selection` — влита.
5. Взяться за очередь 5-11, начиная с независимой #8 (`up/setsettings-socket-alloc`).
