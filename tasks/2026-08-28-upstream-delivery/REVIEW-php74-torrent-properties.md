# PHP 7.4 regression в `Torrent.php`: независимый аудит

Дата: 2026-08-29. База: `upstream/master` = `755404f3`.

## Вердикт

**APPROVED design: подтверждена upstream-регрессия #3213, состоящая из двух
дефектов.** README и
`Requirements::MIN_PHP` обещают PHP 7.4, но текущий upstream не выполняет этот
контракт.

1. Семь свойств вида `public mixed $announce = null` не компилируются на
   PHP 7.4. Чистый parent #3213 проходит lint, сам #3213 и `755404f3` падают.
2. После удаления native `mixed` существующий
   `testATorrentWhoseKeysAreAllNumericIsStillADictionary` честно исполняется и
   падает только на PHP 7.4. Bencode key `"0"` становится integer key `0` в PHP
   array и из-за старого loose comparison совпадает с первым `case 'announce'`.

Это дефект базового upstream-файла. Четыре готовые ветки с parent `755404f3`
не меняют `php/Torrent.php` и только наследуют падение.

## Рекомендуемый carve

Отдельная ветка прямо от `755404f3`, ровно три файла, ожидаемо `+14/-9`:

- `php/Torrent.php`, `+9/-8`: оставить семь properties объявленными как
  однострочные `/** @var mixed */ public $... = null;`; в начале `setMeta()`
  один раз привести `$key` к string;
- `phpstan.neon`, `+1`: задать `phpVersion: 70400`;
- `.github/workflows/tests.yml`, `+4/-1`: запускать прежний PHP job в matrix
  7.4/8.1, с теми же extensions и `bash php-test.sh`.

Новый искусственный source-test не нужен: #3213 уже добавил поведенческие
проверки declared/no-dynamic properties и byte-exact numeric dictionary. Не
хватало реального запуска на документированном минимуме.

## RED -> GREEN

1. До production-правки PHP 7.4 lint падает exit 255, а PHPStan с floor 70400
   выдаёт семь ошибок `mixed`.
2. После снятия типов lint/PHPStan зелёные, но существующий numeric-dictionary
   test исполняется и выдаёт ровно одну ожидаемую ошибку на PHP 7.4.
3. `(string) $key` в dispatch boundary делает его зелёным на 7.4 и сохраняет
   результат на 8.1.

Обязательные мутации: вернуть native `mixed` хотя бы одному property; удалить
string-normalization. Первая обязана уронить lint/PHPStan floor, вторая —
именованный тест на 7.4 при зелёном 8.1.

## Изолированная проверка дизайна

Кандидат был собран только в disposable export, без изменения веток/индекса:

- 383 PHP/INC-файла lint-green на PHP 7.4 и 8.1;
- полный 46-файловый `tests/php-test.sh` green на обоих runtime;
- PHPStan 2.2.9 level 0 с `phpVersion: 70400` green;
- шесть focused Torrent/add/edit/retracker suites green на обоих runtime.

Результат доказывает дизайн, но не заменяет TDD/mutations и финальный review
реальной upstream-ветки.

## Финальная независимая перепроверка

На свежем disposable export exact candidate повторно дал:

- 383/383 PHP/INC lint на PHP 7.4 и 8.1;
- full harness 46 files / 412 cases / 1906 assertions на обоих runtime;
- PHPStan 2.2.9 level 0 с `phpVersion: 70400`, 0 errors;
- exact `3 files, +14/-9` и clean whitespace;
- PHP 7.4 natural numeric-key RED после снятия `mixed`, при GREEN PHP 8.1;
- native-`mixed` и string-normalization mutations независимо RED.

Однострочный PHPDoc сохраняет exact `+9/-8`; PHP 7.4 reflection подтвердил,
что каждый из семи комментариев прикреплён к своему property. Если tags
намеренно исключаются, это остаётся runtime-correct, но данный scope/brief надо
сначала перемерить и переписать.

Current upstream по-прежнему `755404f3`; локальной ветки
`up/php74-torrent-properties` пока нет. Следующий gate — явное user approval,
затем два последовательных RED, implementation, обе mutations и whole-commit
review. Push выполняет только владелец.
