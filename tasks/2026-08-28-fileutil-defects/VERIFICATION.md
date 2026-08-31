# Независимая перепроверка FileUtil

Дата: 2026-08-28.

## Актуализация после rebase — 2026-08-29

Готовый единственный commit перебазирован без конфликтов и без изменения патча:

- старая вершина: `a8144c18` на `fde9863b`;
- текущая вершина: `79190927` с прямым parent `755404f3`;
- `range-diff`: патч идентичен;
- scope неизменен: 7 файлов, `+514/-10`.

Свежая проверка на расширенном upstream-suite:

- PHP 8.5.4: 48 файлов, 303 named tests, 1815 `Passed:`, без failure markers;
- root `php:8.1-cli` 8.1.34, RW bind mount, без `--user`: те же
  48/303/1815;
- PHPStan 2.2.9 level 0: exit 0 с `--memory-limit=1G`;
- все семь изменённых файлов проходят lint на PHP 8.5, 8.1 и 7.4;
- прямые проверки octal mask, FIFO `0600`, relative-path refusal,
  write-only append под UID 65534 и recursive delete зелёные.

Полную текущую PHP 7.4-матрицу нельзя честно назвать зелёной: байт-в-байт
upstream-файл `php/Torrent.php` из #3213 использует `public mixed ... = null`,
который фатален на PHP 7.4 до исполнения FileUtil-тестов. Это отдельная
регрессия текущего upstream, а не этой ветки. Старый полный PHP 7.4-прогон ниже
относится к прежней базе; текущая ветка подтверждена на 7.4 lint-ом всех семи
изменённых файлов.

Проверенные ревизии:

- fork `master`: `9e6e7d955609f0ccbc5a7119a7f07437fa1ba40c`;
- свежий `upstream/master`: `fde9863b23518a8a9f851589a985d1a219391520`.

`FINDINGS.md` использовался только как перечень утверждений. Все вердикты ниже
получены повторными замерами. За время между брифом и перепроверкой upstream
сдвинулся с `f5dc2d88` на `fde9863b`; в частности,
`tests/php/LogFileModeTest.php` уже есть в upstream (commit `515a738e`, PR #3193).

## Сводка

| Пункт | Вердикт | Что идёт в upstream PR |
|---|---|---|
| D0 | **подтверждён** | Нет: upstream уже использует несупрессированный `fopen`; форковое расхождение удаляется отдельно |
| D1 | **подтверждён** | Да |
| D2 | **подтверждён** | Да |
| D3 | **подтверждён** | Да, с сохранением `ab+` для non-regular sink |
| D4 | **опровергнут** как RCE | Нет; остаётся только перенаправление логов |
| D5 | **подтверждён** | Да |
| D6 | **опровергнут** | Нет |

## D2 — подтверждён

Это была первая проверка, до остальных пунктов.

### Заполняется ли `$_ENV`

Host PHP 8.5 и shipped IvanShift image используют `GPCS`, поэтому там `$_ENV`
пуст:

```text
host:       PHP=8.5.4 variables_order=GPCS ENV_count=0 RU_PROFILE_MASK_present=no
IvanShift:  PHP=8.5.9 variables_order=GPCS ENV_count=0 RU_PROFILE_MASK_present=no
```

Однако официальные FPM-образы используют `EGPCS`, а их pool задаёт
`clear_env = no`:

```sh
docker run --rm -e RU_PROFILE_MASK= php:8.1-fpm php -r '
printf("PHP=%s variables_order=%s ENV_count=%d present=%s len=%d\n",
 PHP_VERSION, ini_get("variables_order"), count($_ENV),
 array_key_exists("RU_PROFILE_MASK", $_ENV) ? "yes" : "no",
 strlen($_ENV["RU_PROFILE_MASK"]));'
```

```text
PHP=8.1.34 variables_order=EGPCS ENV_count=15 present=yes len=0
```

`php:8.5-fpm` дал тот же результат:

```text
PHP=8.5.9 variables_order=EGPCS ENV_count=15 present=yes len=0
```

Следовательно, D2 недостижим именно в текущем `ivanshift/rutorrent:latest`, но
достижим в документированном upstream-варианте с официальным PHP-FPM. Поэтому
общий вердикт — **подтверждён**, а не «недостижим в проде».

### Сквозной FPM-запрос

В `php:8.1-fpm` был поднят штатный FPM, а через `cgi-fcgi` запрошен настоящий
`php/getplugins.php` с `RU_PROFILE_MASK=`:

```sh
docker run --rm -e RU_PROFILE_MASK= -v "$PWD":/w -w /w/php php:8.1-fpm bash -lc '
  apt-get update -qq >/dev/null
  apt-get install -y -qq --no-install-recommends libfcgi-bin >/dev/null
  php-fpm -D
  SCRIPT_FILENAME=/w/php/getplugins.php SCRIPT_NAME=/php/getplugins.php \
    REQUEST_METHOD=GET SERVER_PROTOCOL=HTTP/1.1 REMOTE_ADDR=127.0.0.1 \
    SERVER_ADDR=127.0.0.1 SERVER_PORT=80 SERVER_NAME=localhost \
    HTTP_HOST=localhost DOCUMENT_ROOT=/w CONTENT_LENGTH=0 \
    cgi-fcgi -bind -connect 127.0.0.1:9000 </dev/null
'
```

На базе:

```text
Status: 500 Internal Server Error
PHP Fatal error: Uncaught TypeError: Unsupported operand types: string & int
  in /w/php/utility/fileutil.php:248
```

После исправления тот же запрос возвращает `200` без `TypeError`.

### Искажение прав

```sh
for value in 0777 0770 0700 abc ''; do
  RU_PROFILE_MASK="$value" php -d variables_order=EGPCS -r '
    require "conf/config.php";
    try { echo json_encode($_ENV["RU_PROFILE_MASK"])." -> ".decoct($profileMask & 0666)."\n"; }
    catch (Throwable $e) { echo json_encode($_ENV["RU_PROFILE_MASK"])." -> ".get_class($e)."\n"; }'
done
```

```text
"0777" -> 400
"0770" -> 402
"0700" -> 264
"abc" -> TypeError
"" -> TypeError
```

`makeDirectory()` получает строку напрямую и создаёт ещё другие режимы:

```text
0777 (integer) -> 0777
"0777"         -> 01411
"0770"         -> 01402
"0700"         -> 01274
"abc" / ""    -> TypeError
```

В upstream найдено 17 прямых выражений `$profileMask & ...`, ещё два прямых
`chmod(..., $profileMask)` и оба потребителя в `FileUtil`. Значит исправлять
нужно один раз на конфигурационной границе, а не локальными cast в каждом месте.

## D1 — подтверждён

`is_file()` отвечает на вопрос о типе inode, а код использовал его как проверку
существования.

```sh
php -r '
$f=sys_get_temp_dir()."/rutorrent-d1-".getmypid();
posix_mkfifo($f,0600);
printf("before is_file=%s exists=%s mode=%s\n",
 is_file($f)?"true":"false", file_exists($f)?"true":"false",
 decoct(fileperms($f)&0777));
touch($f); chmod($f,0666); clearstatcache(true,$f);
printf("after mode=%s\n",decoct(fileperms($f)&0777));
unlink($f);'
```

```text
before is_file=false exists=true mode=600
after mode=666
```

PHP 8.1 под UID 65534 и реальный `FileUtil::toLog()` в shipped PHP 8.5.9 дали
тот же результат. Два последовательных вызова снова расширяют возвращённый
оператором режим `0600` до `0666`.

Для `/dev/stderr` в disposable container измерено:

```text
fd2 type=fifo mode=600, file_exists=true, is_file=false
touch=true, chmod=true, resulting mode=666
fopen(ab+)=false ENOENT; fopen(ab)=false ENOENT
```

Точный env-сценарий `RU_LOG_FILE=/dev/stderr` в shipped image недостижим из-за
`GPCS`, но сам дефект достижим: `/config/rutorrent/conf` является постоянной
operator-owned конфигурацией, а `update-config` не переписывает `$log_file`.
Именованный FIFO с читателем реально принял строку. Поэтому общий вердикт D1 —
**подтверждён**.

Исправленный реальный FIFO сохраняет режим:

```text
is_file=false exists=true mode=600
```

## D3 — подтверждён

Под непривилегированным UID regular file `0222` показывает именно различие
`O_RDWR` и `O_WRONLY`:

```text
openat(..., O_RDWR|O_CREAT|O_APPEND, 0666) = -1 EACCES
openat(..., O_WRONLY|O_CREAT|O_APPEND, 0666) = 3
ab+ exit=7; ab exit=0
```

Тот же замер через текущий `FileUtil::toLog()`:

```text
toLog_size=0 ab_plus=denied ab=open
final_size=11
```

Root в `php:8.1-cli` маскирует этот дефект, поэтому тест только документированной
root-командой его не доказывает.

Глобальная замена `ab+` на `ab` неприемлема. Реальный FIFO без читателя:

```text
ab+ returned, exit=0
ab blocked, timeout exit=124
```

Поэтому исправление открывает regular file через `ab`, а non-regular sink
оставляет на `ab+`. После исправления regular `0222` получил строку:

```text
mode_before_restore=222 size=33 contains_line=yes
```

## D0 — подтверждён

При production-настройках `display_errors=0`, `log_errors=1` обычный `fopen`
ничего не добавляет в response body, но пишет предупреждение в PHP error log.
`@fopen` подавляет именно этот диагностический канал.

Новый root-resistant fixture использует `/proc/version`: это regular file,
append к которому запрещён и root, поэтому `chmod 0444` не нужен.

```text
до удаления @:
Passed: An unwritable log emits no response output
Failed: An unwritable log remains visible in the PHP error log

после удаления @, host PHP 8.5 и root php:8.1-cli:
Passed: An unwritable log emits no response output
Passed: An unwritable log remains visible in the PHP error log
```

Исторический тест из `5346acbf` действительно красный без `--user`, потому что
root пишет в `0444`. Но утверждение «весь suite зелёный с `--user`» также неверно:
тот прогон имеет семь других permission/process failures. D0-тест при этом
зелёный.

Решение: удалить `@fopen` и ложный комментарий из fork `master`. В upstream
этого расхождения уже нет.

## D5 — подтверждён

Ссылка в `FINDINGS.md` неточна:

```text
php/env_check.php absent
env_check.php exists
```

На свежем upstream строка 187 действительно начинает проверку `$log_file`, но
она проверяет только `dirname()` и не требует абсолютного пути. Поэтому:

```sh
RU_LOG_FILE=errors.log php -d variables_order=EGPCS env_check.php \
  | grep -a '\$log_file writable'
```

На базе:

```text
[OK  ] $log_file writable       log dir: .
```

Настоящий FPM-запрос показал `getcwd()=/w/php`. Scheduler entrypoints
`update.php` и `batch_check.php` явно делают `chdir(dirname(__FILE__))`.
Один и тот же относительный конфиг поэтому создаёт разные файлы:

```text
CWD=.../php LOG_FILE=errors.log
REALPATH=.../php/errors.log MODE=666

CWD=.../plugins/rutracker_check LOG_FILE=errors.log
REALPATH=.../plugins/rutracker_check/errors.log MODE=666
```

После исправления runtime не создаёт файл и выдаёт видимую классифицированную
ошибку:

```text
created=no warning=$log_file must be an absolute path or stream URI; the log line was not written.
```

`env_check.php` теперь выводит:

```text
[WARN] $log_file writable       must be an absolute path or stream URI: errors.log
```

Пути с диском Windows (`C:\...`) принимаются только на Windows: на Unix это
относительное имя и runtime его отвергает. Stream URI разбираются отдельно от
локальных путей: `php://stderr` не проходит через `touch/chmod`, неизвестный
wrapper получает `WARN`, а checker не заявляет непроверенную «writability»:

```text
[INFO] $log_file stream         registered php wrapper; writability is checked on first write
[WARN] $log_file stream         stream wrapper is not available: nosuch
```

`file://` является особым случаем: это URI, но локальная файловая цель. Проверка
после замечания независимого review показала, что обобщение всех URI обходило
`$profileMask` (`0666` вместо `0600` при mask `0700`). Финальный вариант разбирает
authority/path, принимает локальные формы `file:///...` и
`file://localhost/...`, допускает UNC authority только на Windows, проверяет
целевой каталог и применяет тот же mask, что к обычному пути. Схема сохраняет
исходный регистр: пользовательские wrappers сопоставляются точно, а известные
встроенные PHP wrappers — регистронезависимо.

## D4 — опровергнут как RCE

Изолированный container, разные UID, sticky directory владельца root и
`fs.protected_symlinks=1`:

```text
protected_symlinks=1
dangling is_link=true is_file=false exists=false
touch=false warning=Permission denied
chmod=false warning=Permission denied
fopen=true
target mode=644 owner=3000:3000 size=6
mallory_write=false Permission denied
```

На existing target `is_file()`/`file_exists()` также вернули false из-за kernel
policy; `touch`/`chmod` режим не изменили, а append выполнился от web UID.

Следовательно, заявленного шага «сделать цель `0666`, затем записать код» нет.
Создатель ссылки не получает права записи, поэтому RCE **опровергнут**. Остаток —
перенаправление строк лога в путь, куда web UID и так может писать. Из-за низкой
severity и риска сломать легитимные symlink/rotation setups D4 в PR не включён.

## D6 — опровергнут

В текущих fork `master` и `upstream/master` метода
`testAnUnwritableLogIsSkippedQuietly()` уже нет. В историческом `5346acbf` он
создаёт `quiet.log` сам:

```php
$log = $this->dir . '/quiet.log';
touch($log);
chmod($log, 0444);
$GLOBALS['log_file'] = $log;
FileUtil::toLog('a line nobody can write');
```

Он не вызывает `logAfterFirstWrite()`. Поскольку путь уже является regular
file, ветка `touch/chmod` не выполняется и `$profileMask` не читается.
Изолированный вызов дал одинаковый результат для unset/`0777`/`0444`/`0000`:

```text
UID 1000: exit=0 для всех четырёх значений
UID 0:    exit=1 для всех четырёх значений
```

Зависимости от порядка методов нет; результат зависит только от способности
root писать в `0444`. Поэтому D6 **опровергнут**.

## Набор для upstream PR

В чистую ветку от `upstream/master` входят только подтверждённые и достижимые
пункты:

- D2: parse `RU_PROFILE_MASK` как проверенный octal integer на конфигурационной
  границе;
- D1: не вызывать `touch/chmod` для уже существующего non-regular sink;
- D3: использовать `ab` для regular file и сохранить `ab+` для non-regular;
- D5: запретить нестабильный relative log path в runtime и сделать отказ
  видимым в `env_check.php`; различать локальные пути, `file://` и прочие stream
  URI.

D0 остаётся отдельной коррекцией fork `master`; D4 и D6 в upstream diff не
попадают.

## Проверка патча

Полный upstream suite выполнен без `--user`, то есть в той же root-модели,
которая была принципиальна для перепроверки D0:

```text
host PHP 8.5.4:       32 test files, exit 0
php:8.1-cli 8.1.34:   32 test files, exit 0
php:7.4-cli 7.4.33:   32 test files, exit 0
```

Все изменённые PHP-файлы прошли `php -l`, `git diff --check` чист. После root
container-прогонов файлов с чужим владельцем в worktree нет.

Мутационная проверка временно возвращала каждый дефект и затем восстанавливала
рабочий код:

| Мутация | Адресный красный тест |
|---|---|
| строковый/пустой `RU_PROFILE_MASK` | integer, octal и fallback assertions в `ConfigTest` |
| `is_file()` вместо existence guard | права существующей non-regular filesystem target |
| всегда `ab+` | write-only regular sink |
| всегда `ab` | non-regular/FIFO mode guard |
| снять absolute-path guard | relative и Windows-path tests |
| вернуть metadata для stream URI | custom unstatable stream и `php://temp` |
| считать `file://` обычным stream | equality с plain-path mask |
| считать любой wrapper доступным | unknown-wrapper availability |
| потерять `localhost` authority | runtime и Requirements file-URI tests |
| привести custom scheme к lowercase | mixed-case wrapper tests |
| вызвать `dirname()` до validation | subprocess `env_check.php` deprecation test |

Каждый указанный тест действительно выполнился и упал без `Fatal error`; после
каждой мутации исходный патч был восстановлен.

Disposable `rt-lab` с overlay финального upstream-кода на
`ivanshift/rutorrent:latest` поднял rTorrent 0.16.20; настоящий
`/php/getplugins.php` вернул HTTP 200 (375638 bytes), а container log не содержал
`PHP Fatal`, `TypeError` или `Unsupported operand`. Контейнер после проверки
удалён.

Подготовленные локальные коммиты:

- upstream-ветка `up/fileutil-defects`: `a8144c18`;
- fork `master`, отдельный D0: `d78c6070`.

Текст upstream PR сохранён рядом в `PR_BODY.md`.

## Актуализация на `upstream/master` после #3226 — 2026-08-31

Финальный publish-кандидат пересобран прямо поверх
`f19c9d86df72ad6b1720f31252297340049e5eab`:

```text
76485317b414b435a4cecb752fa6d769f67149b3 Validate FileUtil log paths and permissions
parent: f19c9d86df72ad6b1720f31252297340049e5eab
branch: up/fileutil-defects-f19
diff:   7 paths, +514/-10
```

#3226 менял только braces в `FileUtil::makeDirectory()` и добавил
`MakeDirectoryTest.php`. Новый кандидат сохраняет эти braces, не меняет
`MakeDirectoryTest.php` и не содержит собственного delta в `makeDirectory()`.
Шесть из семи файлов byte-identical прежнему donor; единственное отличие
`fileutil.php` — уже принятая upstream brace-форма.

Fresh clean matrix на финальном SHA:

```text
PHP 7.4: 50 files, 318 methods, 1843 Passed, 127 ok, failures 0
PHP 8.1: 50 files, 318 methods, 1843 Passed, 127 ok, failures 0
PHP 8.5: 50 files, 318 methods, 1843 Passed, 127 ok, failures 0
PHPStan 2.2.9: GREEN
7 changed paths x 3 PHP versions lint: GREEN
10 targeted mutations: expected RED, then restored GREEN
```

Повторный официальный `php:8.1-fpm` подтвердил `variables_order=EGPCS` и
наличие пустого `RU_PROFILE_MASK` в `$_ENV`: base падает на string/int operation,
candidate преобразует значение в integer `0777` и обслуживает запрос. Direct
probes также подтвердили FIFO mode `0600`, запись в regular mode `0222` и отказ
создавать relative log path.

Независимый whole-candidate review: **APPROVED**, Critical/Important/Minor — 0.
Remote branch на момент подготовки отсутствует; push не выполнялся.
