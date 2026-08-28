# FileUtil: находки и их доказательный статус

Дата: 2026-08-28. Все номера строк — по `upstream/master` (`f5dc2d88`), если не сказано иное.
В форке `master` файл сдвинут на +5 строк из-за комментария у `@fopen` (см. D0).

Статусы: **[измерено]** — я воспроизвёл лично, команда приведена.
**[агент]** — заявил субагент, я НЕ воспроизводил. **[опровергнуто]** — проверил, не подтвердилось.

---

## D0. Форк несёт `@fopen`, признанный вредным — и это не откачено

`php/utility/fileutil.php:255` в `master` форка:

```php
$w = @fopen( $log_file, "ab+" );
```

плюс пятистрочный комментарий над ним. В `upstream/master` — `fopen` без `@`.

**[измерено]** Обоснование в комментарии ложно. Он утверждает «a warning here has
nowhere to go but the response». Но `php/util.php:44-47` на каждом веб-запросе ставит:

```php
ini_set('display_errors',false);
ini_set('log_errors',true);
```

Замер при этих настройках: **в ответ уходит 0 байт**, предупреждение уходит в error_log.
То есть `@` убирает не шум в ответе (его нет), а единственную запись о том, что
логирование сломано.

**[измерено]** Предупреждение, которое я когда-то «наблюдал» и положил в основу ветки,
видно только под `php -c php-test.ini` — этот флаг **заменяет** системный php.ini целиком
и включает `display_errors=1`. Я измерил артефакт тест-харнесса.

**[измерено]** Тест `tests/php/LogFileModeTest.php` (он тоже в `master` форка) красный в
документированной командe:

```
docker run --rm -v "$PWD":/w -w /w/tests php:8.1-cli bash php-test.sh
→ EXIT=1, 1420 passed, Failed: The line is dropped, not half-written
```

Под root `chmod 0444` не действует. Зелёным он был только с `--user "$(id -u):$(id -g)"`,
которого в README нет.

**[измерено]** Зелёный прогон печатает ложное утверждение:
`Passed: Writing to an unwritable log printed: ` — сообщение написано как отчёт о падении,
а `TestCase::assertEquals` печатает его и при успехе.

**Решение владельца:** откатывать ли `@fopen` + комментарий + тест из `master` форка.
Ветка `up/log-unwritable` уже снята (вернуть: `git branch up/log-unwritable 5346acbf`).

---

## D1. `is_file()` там, где нужен `file_exists()` — chmod РАСШИРЯЕТ права на каждом вызове

`php/utility/fileutil.php:245-249` (upstream):

```php
if( !is_file( $log_file ) )
{
    touch( $log_file );
    chmod( $log_file, (isset($profileMask) ? $profileMask : 0777) & 0666 );
}
```

`is_file()` ложно для FIFO, сокета и устройства. Значит для такой цели условие
**никогда** не станет ложным: `touch`+`chmod` срабатывают на каждом `toLog()` до конца
жизни установки, и chmod при этом **расширяет** права.

**[измерено]**

```php
posix_mkfifo($f, 0600);
var_dump(is_file($f));            // bool(false)
chmod($f, 0777 & 0666);
printf("%o", fileperms($f)&0777); // 666
```

Сценарий: оператор направил `RU_LOG_FILE` в пайп, который читает лог-шиппер. Его
пайп с правами 0600 становится доступен на запись всему миру, и так заново на каждый
HTTP-запрос.

**[агент, не проверял]** `RU_LOG_FILE=/dev/stderr` под Docker: stderr — это пайп,
`is_file()` ложно, `fopen` падает с ENOENT, то есть `touch`+`chmod` шумят на каждом
вызове и ничего не логируется.

---

## D2. Строковый `$profileMask` превращает `& 0666` в ДЕСЯТИЧНОЕ И — или роняет процесс

`conf/config.php:87` (upstream; в форке строка 97):

```php
$profileMask = $_ENV['RU_PROFILE_MASK'] ?? 0777;
```

Значения из окружения — строки. `??` не срабатывает на пустой строке, а
`php/util.php:64-65` (`if(!isset($profileMask)) $profileMask = 0777;`) не перетипизирует.

**[измерено]** `"0777" & 0666` → `int(256)`, то есть **0400** (`decoct` = `"400"`).
Для сравнения `0777 & 0666` = `0666`.

**[измерено]** `"" & 0666` → `TypeError: Unsupported operand types: string & int`.
`@` такое не гасит — это исключение, а не warning. Значит HTTP 500.

**[агент, не проверял]** `"0770"` → 0402 (world-writable), `"0700"` → 0264, `"abc"` → TypeError.

### Радиус поражения гораздо шире логгера

`$profileMask & 0666` (и `& 0755`, `& 0644`) стоит примерно в **25 боевых местах**, не
считая тестов: `php/cache.php:68,101,104,173`, `php/addtorrent.php:119`,
`php/getplugins.php:252-254,413,428`, `php/initplugins.php:174,186`,
`plugins/rss/rss.php:102`, `plugins/create/createtorrent.php:109`,
`plugins/create/correct.php:93`, `plugins/extsearch/engines.php:74`,
`plugins/trafic/update.php:55`, `plugins/trafic/stat.php:73`,
`plugins/bulk_magnet/action.php:26`, `plugins/erasedata/removewithdata.php:32`,
`plugins/rutracker_check/state.php:101,231,278`, и оба места в `fileutil.php` —
`toLog:248` и `makeDirectory:216-222`.

При пустом `RU_PROFILE_MASK` падает **первый же** из них.

### ВАЖНАЯ ПРЕДПОСЫЛКА, ТРЕБУЮЩАЯ ПРОВЕРКИ

`$_ENV` заполняется, только если `variables_order` содержит `E`. В `php.ini-production`
по умолчанию `variables_order = "GPCS"` — **без E**, тогда `$_ENV` пуст и весь дефект
недостижим. Это ровно та ловушка, на которой уже сгорела ветка `up/snoopy-gzip-body`:
дефект реален в коде и недостижим в проде. **Проверить до отправки PR:** какой
`variables_order` в официальном образе `php:*-fpm`, в `ivanshift/rutorrent`, и что
именно видит `conf/config.php` — `$_ENV` или `getenv()`.

---

## D3. Режим `"ab+"` требует прав на чтение, которые дописыванию не нужны

`php/utility/fileutil.php:250`: `fopen($log_file, "ab+")`. `+` означает `O_RDWR`, но
`toLog()` только пишет (`fputs`/`fclose`), не читает ни разу.

**[агент, не проверял]** На файле с режимом 0222: `fopen($f,'ab+')` → Permission denied,
`fopen($f,'ab')` → успех. Тот же файл `rpc2_log()` пишет через
`file_put_contents(..., FILE_APPEND)`, которому нужна только запись.

Осторожно при исправлении: `'a'` на FIFO блокируется до появления читателя, `'a+'` — нет.
Менять вместе с D1.

---

## D4. Симлинк на месте лога — заявка на RCE ОПРОВЕРГНУТА, остаётся перенаправление

Агент заявил локальную эскалацию до выполнения кода: атакующий кладёт висячий симлинк
на `/tmp/errors.log` → `touch` создаёт цель, `chmod` делает её 0666, атакующий пишет
туда PHP.

**[опровергнуто]** PoC агента был поставлен так, что симлинк и процесс — один
пользователь; при этом `fs.protected_symlinks` по определению не применяется.
Замер в контейнере при нормальной расстановке (симлинк — `mallory`, каталог — `root`
sticky 1777, процесс — `www`, `/proc/sys/fs/protected_symlinks` = 1):

```
Warning: touch(): Unable to create file ... Permission denied
Warning: chmod(): Permission denied
fopen: УСПЕХ  →  -rw-rw-r-- 1 www www  /docroot/shell.php
```

Ядро блокирует `touch` и `chmod`, то есть **шаг, дающий 0666, не срабатывает**. Файл
всё же создаётся через `fopen` — владелец `www`, режим 0664; атакующий писать в него
не может. Это не выполнение кода.

**[измерено]** Вариант «симлинк на существующий файл» не работает вовсе: `is_file()`
тогда истинно, ветка `touch`/`chmod` пропускается, режим жертвы не меняется (644 → 644).

**Что реально остаётся:** атакующий может заставить ruTorrent создать файл и писать лог
по произвольному пути, доступному веб-пользователю на запись. Severity — низкая/средняя,
не RCE. Стоит ли это отдельной строки в PR — вопрос.

---

## D5. Относительный `$log_file` расщепляет лог по рабочим каталогам

**[агент, не проверял]** `$topDirectory` (config.php:44) и `$tempDirectory` (:91) оба
требуют абсолютного пути; `$log_file` (:39) не требует ничего. При `RU_LOG_FILE=errors.log`
веб-SAPI кладёт лог 0666 в каталог скрипта (то есть в docroot, откуда он раздаётся), а
cron-точки входа (`plugins/rutracker_check/update.php`, `batch_check.php`) пишут другой
файл там, где оказался cron.

Проверить заодно: агент ссылается на `php/env_check.php:187`, где `dirname('errors.log')`
даёт `"."` и отчёт зеленеет. **Мой grep не нашёл в `php/env_check.php` ни `log_file`, ни
`log dir` — ссылка сомнительна, проверить существование файла и строки.**

---

## D6. Расхождение агентов, не разрешено

Про `tests/php/LogFileModeTest.php`:

- Агент A: `testAnUnwritableLogIsSkippedQuietly` не зовёт `logAfterFirstWrite()`, работает
  с унаследованным `$GLOBALS['profileMask'] = 0444` и корректен только по счастливой
  случайности порядка объявления методов.
- Агент B: прогнал этот метод изолированно (переименовав соседей) — зелёный, exit 0.

Взаимно исключающие утверждения. Не разбирал.
