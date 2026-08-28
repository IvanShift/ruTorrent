# Перепроверка и исправление `up/test-harness`

Дата: 2026-08-28. Итоговая вершина на `upstream/master` (`fde9863b`) —
`64778267`.

## Вердикт

**Готова к отправке.** Обе проблемы исходной ветки исправлены, каждая production-
строка держится отдельной мутацией, полные матрицы PHP 8.5 и 8.1 зелёные.

## Что было подтверждено

Старый `TestCase::assertTrue()` вызывал `assert($bool, $message)`. При
`zend.assertions=-1` PHP не выполняет выражение, и заведомо ложная проверка
печатала:

```text
Passed: ONE IS NOT TWO
```

После замены на обычный boolean branch та же команда печатает:

```text
Failed: ONE IS NOT TWO
```

Ссылка исходного commit message на прямой запуск `CacheTest.php` была ложной:
`php -f tests/php/CacheTest.php` даёт exit 0 и 0 байт, потому что test driver
добавляется только `php-test.sh`. Commit message переписан и теперь содержит
реально исполняемый однострочный probe.

## Исправление runner-а

Удалённый `php-test.ini` содержал одну активную настройку
`zend.assertions = 1`, но `php -c php-test.ini` заодно не загружал системный
`php.ini`. На текущем host простое удаление `-c` меняло контракт:

| setting | старый `-c php-test.ini` | исходная ветка без `-c` | итог |
|---|---:|---:|---:|
| `zend.assertions` | 1 | -1 | 1 |
| `error_reporting()` | 30719 | 22527 | -1 |
| `display_errors` | 1 | 0 | 1 |
| `memory_limit` | 128M | -1 | -1 |

Файл остаётся удалённым, а нужный test contract теперь задан на месте вызова:

```sh
php -d zend.assertions=1 -d error_reporting=-1 -d display_errors=1 -f ...
```

`memory_limit` намеренно не пинится: обязательный контракт здесь — выполнение
assertions и видимость всех диагностик, а не неявные built-in defaults PHP,
возникшие из-за подмены всего ini-файла.

## Regression coverage

Новый `tests/php/TestCaseTest.php` проверяет две независимые границы:

1. запускает child PHP с `zend.assertions=-1` и требует точный
   `Failed: deliberately false`;
2. проверяет фактические `zend.assertions=1`, `error_reporting()=-1` и
   `display_errors=1` внутри процесса, созданного `php-test.sh`.

Мутации выполнялись в disposable-копиях. Все завершились exit 1, оба named test
дошли до start/end markers, fatal/parse/uncaught не было:

- возврат legacy `assert()` при неизменном runner-е;
- удаление каждого из трёх `-d` по отдельности;
- ослабление значений до `zend.assertions=0`, `error_reporting=0` и
  `display_errors=0`.

Visible warning/deprecation по прежнему сами по себе не меняют exit code: runner
гейтит `Failed:`, `not ok`, non-zero child exit и fatal-class diagnostics. Это
существующий upstream-контракт, а не новая регрессия ветки.

## Итоговая проверка

- local PHP 8.5.4: 31 test file, 1421 `Passed:`, 0 failure markers, exit 0;
- root `php:8.1-cli` 8.1.34: 31 test file, 1421 `Passed:`, 0 failure markers,
  exit 0;
- оба новых test method исполнились: совокупно два start и два end marker в
  каждой матрице;
- `bash -n tests/php-test.sh` и PHP lint обоих PHP-файлов зелёные;
- direct probe при `zend.assertions=-1` печатает `Failed: ONE IS NOT TWO`;
- diff против upstream — один commit, ровно четыре test-файла, `+49/-17`;
- рабочее дерево чистое, root-owned artifacts после container-run отсутствуют;
- независимый read-only review blocker/important findings не нашёл.
