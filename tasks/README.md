# Задачи форка ruTorrent

Каталог `tasks/` в `.gitignore`: в upstream он не уезжает. В git отслеживаются только инструменты ниже и этот файл.

## Инструменты (в git)

| файл | назначение |
| --- | --- |
| [matrix.sh](matrix.sh) | Локальная матрица PHP-наборов: local, `php:8.1-cli`, `php:7.4-cli` и образ прода без iconv. Каждая нога идёт на своём экспорте и со своим `TMPDIR`. Подробности в AGENTS.md, раздел «PHP Suite Timing and the Matrix». |
| [rt-lab.sh](rt-lab.sh) | Лабораторный экземпляр на выбранном образе с наложенным рабочим деревом (`up` / `sync` / `down`). Изменяющие пробы делаются только здесь. |
| [retrackers-clear-markers.php](retrackers-clear-markers.php) | Инструмент снятия застрявшего маркера восстановления retrackers. На него ссылается `tests/plugins/erasedata/RepairToolRefusalTest.php`. |

## Текущее, 2026-09-25

Всё в [2026-09-12-app-log-findings/](2026-09-12-app-log-findings/):

| файл | что это |
| --- | --- |
| [OPEN-ISSUES-2026-09-25.md](2026-09-12-app-log-findings/OPEN-ISSUES-2026-09-25.md) | **Единый реестр всех открытых проблем форка**, включая старый долг и находки параллельного ревью R01–R24. Начинать отсюда. |
| [BACKLOG-2026-09-25.md](2026-09-12-app-log-findings/BACKLOG-2026-09-25.md) | Процедура шага 0 (выкладка коммита прокси) и план шагов 1–6. |
| [DECISIONS-2026-09-25.md](2026-09-12-app-log-findings/DECISIONS-2026-09-25.md) | Решения владельца Р1–Р10 и рекомендации, которые ждут утверждения. |
| [ARCHITECTURE-2026-09-25.md](2026-09-12-app-log-findings/ARCHITECTURE-2026-09-25.md) | Архитектурные решения по прокси, Snoopy/loginmgr, rutracker_check и инструментам: обоснования, критерии остановки. |
| [REVIEW-25a0e2cb.md](2026-09-12-app-log-findings/REVIEW-25a0e2cb.md) | Подробности 130 пунктов ревью (X, S, C, O и N/VC/VO), на которые реестр ссылается по ID, и их статус после исправлений. |
| [UPSTREAM-YGG-DISCLOSURE-DRAFT.md](2026-09-12-app-log-findings/UPSTREAM-YGG-DISCLOSURE-DRAFT.md) | Черновик закрытого сообщения в upstream о LG16/LG17 (Р5). Перед отправкой пересверить с текущим `upstream/master`. |

Задача для соседнего репозитория: `../docker-rutorrent/tasks/2026-09-25-open-issues-from-rutorrent.md` (7 пунктов реестра из раздела docker-rutorrent).

## Архив

Всё прежнее перенесено в `backup/` (вне git), структура каталогов сохранена:

- `backup/2026-09-25/tasks/` — отчёты кампаний с августа по 2026-09-25: раунды ревью и проверок app-log, VERIFY-*, FIX-*, CHECK-*, папки `2026-08-*` и `2026-09-*`, планы и тексты PR. Отсюда же статус доставки в upstream: `backup/2026-09-25/tasks/2026-08-28-upstream-delivery/`, 13 пакетов не отправлены. Лог переноса и список ссылок, которые были битыми ещё до переноса, лежат в `backup/2026-09-25/ARCHIVE-LOG.json`.
- `backup/2026-09-25-tasks-full.tar.gz` — полная копия `tasks/` до переноса.
- `backup/2026-08-28/` — материалы более ранних раундов.

Открытые пункты из архивных отчётов перенесены в реестр после проверки по коду. Архивные файлы нужны только для истории.

## Правила, общие для всего

- Ветки для upstream режутся от `upstream/master` и называются `up/<имя>`.
- **Никогда `git add -A` на ветке `up/*`.** `.gitignore` upstream не знает про `tasks/`, `docs/`, `.claude/`, `.agents/`, `.codex/`, `.superpowers/`, `backup/`.
- Никакого PHPUnit и composer.
- Работу вести параллельно, где нет настоящей зависимости: AGENTS.md, «Run Independent Work In Parallel».
- Выкладывать малыми шагами. Блокирует только воспроизведённый P1 или P2: AGENTS.md, «Ship In Small Steps».
- Локальный `grep` — обёртка над ugrep с `-I`, двоичный ввод он молча пропускает, поэтому нужен `grep -a`.
- **Мерить посылку до того, как писать аргумент.**
