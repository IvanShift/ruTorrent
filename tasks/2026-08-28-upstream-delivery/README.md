# Задача: заливка форка в upstream — что залито, что осталось, где проблемы

Срез обновлён **2026-08-29**: `upstream/master` = `755404f3`, опубликованный
`origin/master` = `b186341c`, 92 commit впереди и 12 позади. Commit после
behavior snapshot `511ed13f` в origin/local master меняют только task-
документы; production-состояние не менялось. Подробный старый план:
`../2026-08-28-upstream-rebuild/PLAN.md`.

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
| **#3198** | `up/kinozal-session` (local `de98a49a`, remote `4cf74c52`) | Открыт. Локальный один commit перебазирован на `755404f3`, 5 файлов, +636/−28; focused 35/35, обе полные PHP-матрицы и PHPStan зелёные. Remote намеренно не менялся и требует owner-only force-with-lease. Ответ xirvik: `../2026-08-28-upstream-rebuild/REPLY-3198.md` |

---

## Готово, но НЕ отправлено

| ветка | коммит | объём | что мешает |
|---|---|---|---|
| `up/fileutil-defects` | `79190927` | +514/−10, 7 файлов | **ГОТОВА.** Один commit прямо на `755404f3`, patch после rebase идентичен; PHP 8.5/8.1 — 48 файлов, 303 теста, 1815 assertions; PHPStan и direct probes зелёные. PHP 7.4 qualification: `../2026-08-28-fileutil-defects/VERIFICATION.md` |
| `up/history-service-labels` | `4cf3bd69` | +37/−5, 3 файла | **НЕ ОТПРАВЛЯТЬ.** Достижимая потеря истории/Pushbullet для пользовательских `.private`-меток; producer отсутствует в upstream; тест не держит production-gate. Разбор: `REVIEW-history-service-labels.md` |
| `up/test-harness` | `8eafb529` | +49/−17, 4 файла | **ГОТОВА.** Один commit прямо на `755404f3`; PHP 8.5/8.1 — 47 файлов, 287 тестов, 1781 `Passed:`; семь полных мутаций красные. Тексты: `REVIEW-test-harness.md`, `PR-test-harness.md` |
| `up/rtorrent-0-16-21` | `48bc6d4b` | +9/−4, 3 test-файла | **ГОТОВА.** Один commit прямо на `755404f3`; обе полные PHP-матрицы 46/285/1790, Jest 20/196, независимые spec/quality reviews зелёные. Тексты: `REVIEW-rtorrent-0-16-21.md`, `PR-rtorrent-0-16-21.md` |
| `up/kinozal-session` | `de98a49a` | +636/−28, 5 файлов | **ГОТОВА ЛОКАЛЬНО.** Один commit прямо на `755404f3`; remote открыт на старом `4cf74c52` и обновляется только владельцем с exact lease. Handoff: `REVIEW-ready-branches-2026-08-29.md` |
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

## Актуальная очередь

Старые PR 5–11 больше не являются допустимой декомпозицией: whole-file оценки
смешивали независимые fixes и в некоторых случаях удаляли новые upstream tests.
Полный current crosswalk: `CROSSWALK-remaining-divergence-2026-08-29.md`;
исполняемый план: `PLAN-remaining-queue-2026-08-29.md`.

Точный текущий счёт после завершения всех пяти disposition-аудитов:

- **19 обязательных реализационных пакетов**;
- **0 неразобранных carve/verdict-аудитов**;
- четыре уже готовых owner handoff в это число не входят.

Арифметика: прежние `13 implementations + 5 audits = 18` преобразованы в
`13 + 6 audited successors = 19`. Successors: rTorrent alias surface, XMLRPC
proxy policy, manual rutracker entrypoints и три foreign-handler packages.
Exact generic `sendTorrent() +17/-0` закрыт как no-send: семантическая
dispatch-vs-load граница реальна, но unconditional log одинаков для будущего
успеха и отказа и потому ничего не диагностирует.

Уже измеренные новые границы: httprpc — 5 файлов, около `+190..230/-3`;
SCGI — 7 файлов, около `+850/-45`; retrackers — 4 файла без честного final
numstat; erasedata разбита на A/B/C, где только B пока имеет exact `+168/-2`;
70-путевый rutracker snapshot заменён P0/P1/P2/P3, manual entrypoints и тремя
foreign-handler packages. Полный синтез: `REVIEW-disposition-wave-2026-08-29.md`;
foreign brief: `REVIEW-foreign-tracker-handlers-2026-08-29.md`.

Отдельная ветка `php/xmlrpc_path.php` не нужна: filesystem identity принадлежит
erasedata A, а SCGI и A являются siblings после httprpc. Отдельно добавлен
2-путевый `up/httprpc-erasedata-contract`, закрывающий exact-force/helper/no-
fallback consumer boundary.

---

## Правила, которые нельзя нарушать

- Ветки режутся от финального prerequisite tip и называются `up/<имя>`.
  Fork `master` служит источником intent, но shared files переносятся только
  замороженными hunks поверх текущего upstream; whole-file copy запрещён.
- **Никогда `git add -A` на ветке `up/*`** — в `.gitignore` upstream нет строк про
  `tasks/`, `docs/`, `.claude/`, `.agents/`, `.codex/`, `.superpowers/`, `backup/`,
  и весь этот мусор уедет в PR.
- Никакого PHPUnit и composer — в upstream их нет.
- Обязательные матрицы PHP перед отправкой: локальный 8.5 и root-container 8.1
  **по команде из README, без `--user`**; после PHP74 compatibility PR также
  реальный 7.4 для путей, которые могут затронуть runtime floor.
- Мутационная проверка каждой правки.
- **Мерить посылку до того, как писать аргумент.** Три ветки за один день сгорели
  ровно на этом.

## Что делать дальше

1. ~~Дождаться перепроверки `../2026-08-28-fileutil-defects/`.~~ Завершено и
   зафиксировано в той задаче.
2. ~~Проверить адверсально `up/history-service-labels`.~~ Проверена и отвергнута;
   безопасную marker-based логику сложить с producer-ом в PR 11. Разбор:
   `REVIEW-history-service-labels.md`.
3. ~~Починить `up/test-harness`.~~ Готова как `8eafb529`; осталось push/PR по
   `PR-test-harness.md`.
4. Owner-only handoff готовых FileUtil/test-harness/rTorrent/Kinozal веток —
   exact команды и lease в `REVIEW-ready-branches-2026-08-29.md`.
5. После явного design approval параллельно реализовать PHP74, final socket и
   httprpc RED->GREEN; агент push не выполняет.
6. Продолжить 19-package dependency graph строго по
   `PLAN-remaining-queue-2026-08-29.md`; pending disposition audits больше нет.
