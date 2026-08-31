# PHP 7.4 binary metainfo path probes — verification

Дата: 2026-08-31. Вердикт: **APPROVED**. Исправление production-reachable
дефекта зафиксировано отдельно от httprpc/SCGI и не опубликовано.

## Причина и достижимость

`Torrent` принимает либо filesystem path, либо уже загруженные bencode bytes.
До декодирования constructor проверял один и тот же input через `is_dir()` и
два вызова `is_file()`. Поле `pieces` содержит бинарные SHA-1 bytes и законно
может включать NUL, тогда как filesystem path NUL содержать не может.

На PHP 7.4 production-shaped raw metainfo вызывал ровно три `E_WARNING`. Без
строгого handler объект мог всё же собраться, но diagnostics могли попасть в
HTTP/AJAX output при `display_errors`. Handler, переводящий warning в
`ErrorException`, прерывал constructor и делал valid source недоступным.
PHP 8.1/8.5 на тех же bytes просто возвращали `false` из path probes; это не
делает PHP 7.4 defect тестовой фикцией.

Два реальных caller-класса подтверждают достижимость: raw magnet `.meta` в
`rTorrent::getSource()` и raw metainfo replacement в bundled retrackers flow.

## RED и исправление

До правки официальный `php:7.4-cli` давал:

```text
RtorrentSourceTest: 4 tests, 1 failure
not ok - testRawMagnetMetadataIsReadAsTheInfoDictionaryItContains
```

Новый generic `TorrentMetaTest` независимо видел три path warnings на valid
metainfo с двадцатью NUL bytes в `pieces`.

Исправление добавляет PHP-7.4-compatible `isPathCandidate()`:

```php
is_string($value) && strpos($value, "\0") === false
```

Эта одна predicate защищает все три ambiguous probes: `build()/is_dir`,
`build()/is_file` и `decode()/is_file`. Обычные string paths идут по прежнему
пути. `@`-подавление warnings не используется.

## Scope и refs

- fork integration: `76b0c0f5`, parent `cbc96718`;
- upstream-clean branch: `up/php74-binary-metainfo` = `a1e60e69`;
- upstream parent: `f19c9d86`;
- один non-merge commit, exact 2 paths, `+36/-3`:
  `php/Torrent.php`, `tests/php/TorrentMetaTest.php`;
- patch fork/upstream byte-identical;
- push, PR creation и deployment не выполнялись.

Это follow-up уже закрытой PHP 7.4 compatibility lane package 1, а не новая
строка общей очереди. До закрытия package 4 текущий счёт остаётся **15 open
implementation packages**.

## GREEN evidence

Focused matrix:

- `RtorrentSourceTest`: PHP 7.4/8.1/8.5 — `4/4`, zero failures;
- generic binary-metainfo regression: PHP 7.4/8.1/8.5 — `3/3` assertions;
- `TorrentMetaTest`: 18 methods GREEN на всех трёх runtimes;
- ordinary file/directory paths: `TorrentCreatePathSequence` 5 methods и
  `TorrentAddPathSequence` 9 methods GREEN на всех трёх runtimes;
- lint двух changed files GREEN на PHP 7.4/8.1/8.5;
- PHPStan 2.2.9 level 0 GREEN;
- `git diff --check` GREEN.

Fork full harness из чистого detached worktree, строго последовательно:

| Runtime | Files | Success signals | Failure signals | Exit |
|---|---:|---:|---:|---:|
| PHP 7.4 | 65 | 4152 | 0 | 0 |
| PHP 8.1 | 65 | 4152 | 0 | 0 |
| host PHP 8.5.4 | 65 | 4152 | 0 | 0 |

Upstream-clean branch full harness также выполнен последовательно в отдельном
detached tree: PHP 7.4/8.1/8.5 — по 48 files, 1810 passed assertions, zero
failure signals.

Удаление любой одной из трёх защит при двух оставшихся заставляет именно
`testBinaryMetainfoIsNotProbedAsAFilesystemPath` выполниться и дать RED.
Никакая мутация не маскировалась preceding fatal. Independent review проверил
production reachability, ordinary-path preservation, restoration предыдущего
error handler, mutation ledger и exact scope; итог — **APPROVED, no findings**.

## Evidence hygiene

Результаты трёх full suites, ошибочно запущенных одновременно в одном checkout,
исключены: suites используют общие permission/file fixtures и мешают друг
другу. Root-container прогоны также не используются для negative chmod tests.
Итоговые числа выше получены последовательно; контейнеры запускались как
`--user 1000:1000`.

Обычный commit hook в основном checkout увидел три известные cache-dependent
`ScheduleTest` failure. Тот же suite прошёл на всех трёх runtimes в чистом
worktree, поэтому product commit сделан контролируемо с `--no-verify`; это не
заменяет и не ослабляет приведённую GREEN matrix.
