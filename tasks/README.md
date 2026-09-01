# Задачи форка ruTorrent

Каталог в `.gitignore` — в upstream не уезжает.

## Активные, 2026-08-28

| задача | что это |
|---|---|
| [2026-08-28-upstream-delivery/](2026-08-28-upstream-delivery/) | **Главный статус заливки в upstream.** Реестр 18 packages: 5 реализованы, package 5 partial, 13 ещё открыты; refs, dependencies, upstream/local-only delivery |
| [2026-09-01-gemini-rtorrent-alias-surface/](2026-09-01-gemini-rtorrent-alias-surface/) | Завершённый delegated brief package 13; candidate `3146f741`, fork integration `4d779ff9`, APPROVED |
| [2026-09-01-gemini-xmlrpc-proxy-policy/](2026-09-01-gemini-xmlrpc-proxy-policy/) | Следующее подробное RED-first ТЗ для Gemini: package 14, exact seven-path XMLRPC proxy policy |
| [2026-08-28-fileutil-defects/](2026-08-28-fileutil-defects/) | Четыре дефекта `FileUtil` + бриф на **независимую перепроверку другой моделью**. Часть находок измерена, часть нет, одна опровергнута |
| [2026-08-28-harness-defects.md](2026-08-28-harness-defects.md) | 42 дефекта тест-харнесса (`php-test.sh`, `TestCase.php`), 7 разделов. Пред-существующие, ничьим PR не являются. **Адверсальный проход по `[reported]` не доделан** |
| [2026-08-28-scgi-oom-and-unpinned-ini.md](2026-08-28-scgi-oom-and-unpinned-ini.md) | SCGI отклоняет 64 МиБ уже после того, как их выделил (измеренный OOM-фатал); харнесс перестал пинить `memory_limit`; документированная команда тестов красная под root |
| [2026-08-28-round6-open-items.md](2026-08-28-round6-open-items.md) | Раунд 6 `rutracker_check`: 9 незакрытых пунктов, PHP 8.1 не гонялся ни разу |
| [2026-08-28-plan2-open-items.md](2026-08-28-plan2-open-items.md) | Plan 2: 7 незакрытых пунктов, включая тихий клин `collector.php:1641` |
| [2026-08-28-upstream-rebuild/](2026-08-28-upstream-rebuild/) | Рабочие материалы дня: план, тексты PR, ответ на ревью #3198, вердикты по снятым веткам |

## Правила, общие для всего

- Ветки для upstream режутся от `upstream/master`, называются `up/<имя>`.
- **Никогда `git add -A` на ветке `up/*`** — `.gitignore` upstream не знает про
  `tasks/`, `docs/`, `.claude/`, `.agents/`, `.codex/`, `.superpowers/`, `backup/`.
- Никакого PHPUnit и composer.
- Две матрицы PHP перед отправкой: локальный 8.5 и контейнер 8.1 **по команде из README,
  без `--user`** — с `--user` результаты расходятся.
- Мутационная проверка каждой правки.
- Локальный `grep` — обёртка над ugrep с `-I`, молча пропускает бинарный ввод: `grep -a`.
- **Мерить посылку до того, как писать аргумент.**

## Архив

`backup/2026-08-28/` (вне git) — материалы прежних раундов: `ROUND6/`,
`REMEDIATION_4B4938CC/` (в нём `PROGRESS.md`, 270 КБ), старые отчёты код-ревью, логи,
скриншоты. Ничего не удалено, только перенесено.
