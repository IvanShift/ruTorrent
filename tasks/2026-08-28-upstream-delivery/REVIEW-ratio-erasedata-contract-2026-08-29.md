# `up/ratio-erasedata-contract` (B) — independent design review

Дата: 2026-08-29. База: `upstream/master=755404f3`. Ветки B ещё нет;
fork использован только как donor гипотез. Review и probes были read-only по
репозиторию, mutations выполнялись в disposable copies.

## Verdict: CORRECTED DESIGN APPROVED, exact two-path scope retained

Donor `+168/-2` не является готовым B. Его единственный достижимый Ratio fix —
missing-helper refusal — не удерживается тестом и остаётся невидимым. Остальные
добавки либо опровергнуты, либо принадлежат A.

Кроме того, audit нашёл отсутствующий prerequisite: при установленном, но
выключенном erasedata Ratio запускает helper, producer стирает torrent и
публикует manifest, но ordinary collector schedule уже снят. Пользователь
утвердил corrected A option 2: durable wake/ack и один fixed repeating drain.
Граница B остаётся ровно два пути, но B теперь владеет ещё одним реальным
consumer contract: при Ratio startup/reload он re-arms pending A wake после
rTorrent/container restart, даже если erasedata остаётся disabled.
Independent re-review подтвердил reachability, exact two-path boundary,
no-ack/restart ownership и B mutations; production implementation ещё не
строилась.

## Измеренный donor и финальная граница

Exact `upstream/master..fork` snapshot:

```text
15   2  plugins/ratio/ratio.php
153  0  tests/plugins/ratio/EraseWithDataCommandTest.php
```

Это подтверждённый donor snapshot, но опровергнутая final estimate. После
исключения A-tests, redundant username filter и unreachable force guard final
numstat надо перемерить.

Предпочтительный frozen B scope после исправленного A:

1. `plugins/ratio/ratio.php`;
2. `tests/plugins/ratio/EraseWithDataCommandTest.php`.

Не входят: `plugins/erasedata/**`, UI/locales/plugin metadata, core XMLRPC,
task files и любые rutracker paths.

## Production reachability

Действия достижимы:

1. Ratio UI безусловно показывает actions 3/4;
2. `rRatio::flush()` передаёт exact literals `"1"`/`"2"` в две switch arms;
3. `system.method.set` устанавливает native ratio callback;
4. callback делает stop/close и `execute.nothrow.bg` для
   `plugins/erasedata/erase.php` с evaluated current-target hash;
5. rTorrent передаёт brace-list как argv через `execvp()`, без shell reparse;
6. background detach подтверждает запуск, но не exit status PHP child.

Поэтому синтаксис команды валиден, а post-launch publication/refusal и
collector lifecycle принадлежат A. Формулировка donor comment про
«shell-ish command line» неверна.

## Вердикты по находкам

### Missing `plugins/erasedata/erase.php`

**Подтверждено, достижимо в production.** Ratio не объявляет dependency на
erasedata. Текущий upstream fallback при отсутствующем helper строит
`stop; close; custom5=1; erase`; `custom5` как delete-data marker никто больше
не читает, поэтому torrent исчезает, payload остаётся.

Fail-closed `cat=` — правильное поведение, но donor test его не удерживает:
mutation с возвратом destructive fallback оставила весь focused file GREEN.
Кроме того, уже установленный `cat=` не самоисправится, если helper появится
позже: Ratio надо reload/flush. Поэтому B обязан писать один unconditional
classified log: helper отсутствует, torrent и payload сохранены, после
восстановления helper требуется reload Ratio.

### Erasedata установлен, но выключен

**Подтверждено, достижимо в production; owners — A prerequisite и B startup
hook.** Плагины
независимо shutdownable, Ratio не зависит от erasedata и продолжает показывать
actions 3/4. `plugins/erasedata/done.php` снимает schedule, но helper остаётся.
`erasedataRemoveWithData()` публикует obligation и стирает torrent, не вызывая
`erasedataKickCollector()`; текущие kick callers относятся только к rutracker.

A до destructive erase обязан durably advance wake generation, arm fixed
repeating `erasedata-drain<User>`, создать exact staging и получить real child
ack. Ticks acknowledge generation до nonblocking worker admission; один winner
blocking ждёт общий scheduler/hash locks, losers немедленно выходят. Periodic
broad остаётся nonblocking. Arm/no-ack refusal сохраняет torrent, identity-
rollback-ит только own prepared staging и даёт bounded unconditional
consequence/recovery diagnostic; лишь rollback failure сохраняет staging/wake
как видимую retryable obligation.

rTorrent schedules volatile. A `init.php` re-arms pending state только когда
erasedata enabled; disabled plugin loader его не выполняет. Поэтому B обязан в
своём уже существующем startup/reload path условно вызвать A-owned rearm
entrypoint, если helper/protocol установлен и pending wake/queue существует.
Это не global plugin dependency: отсутствие erasedata не ломает Ratio
stop/throttle, а отсутствие pending state не создаёт drain schedule. Ошибка
rearm видима, но не делает unrelated Ratio actions недоступными.

Если оба owner plugins намеренно unlaunched и после restart не выполняется ни
один producer/init, autonomous recovery внутри A/B недостижим; job остаётся
durable до enable. Actual audited case `erasedata disabled + Ratio enabled`
закрывается этим B hook без расширения two-path scope.

### Extra username filter

**Опровергнуто.** `User::getUser()` уже возвращает `''` либо lowercase login,
нормализованный до `[a-z0-9_-]*`. Дополнительный
`preg_replace('/[^\w\.\-]/', ...)` не меняет production value. rTorrent не
передаёт argv через shell. Mutation с удалением filter осталась GREEN; hunk и
shell-injection claim исключаются.

### Exact-force guard в `getEraseWithDataCommand()`

**Недостижимо в production.** Единственные два callers передают literal
`"1"` и `"2"`; пользовательский `rat_actionN` выбирает switch arm, но не
становится `$force`. Exact force всё равно валидируется A entrypoint и producer.
Direct-method invalid-value matrix честно удерживает defense-in-depth, но не
production defect; из B исключается.

### Re-added same hash и missing CLI force

**Подтверждено и достижимо, owner — A.** Старый `<hash>.list` может относиться
к предыдущему generation и не вправе подавлять новый erase. CLI force не может
иметь implicit default. Donor mutations убиваются, но оба production hunks
живут в `plugins/erasedata/erase.php`; tests переносятся в A, а не B.

### `publishStaging()` без соседних symbols

**Недостижимо в текущем production.** Все production callers загружают
`filesystem.php` и `removewithdata.php`. Isolated public-method test честно
ловит fatal, но удерживает A/library defense, не B. В B не переносится.

## Mutation evidence

Focused donor baseline на PHP 8.5.4: 10 tests, 61 assertions, exit 0.

| Mutation | Результат | Вывод |
|---|---|---|
| удалить Ratio exact-force guard | RED, 14 failures | held, но prod-unreachable |
| вернуть missing-helper destructive fallback | GREEN | реальный B hunk не удерживается |
| удалить username filter | GREEN | hunk redundant/untested |
| вернуть CLI default force | RED, 2 failures | A-owned test |
| убрать isolated publish guard | named child fatal/RED | A/library defense |
| вернуть stale same-hash early exit | RED, 1 failure | A-owned test |

## Corrected B contract

После final A:

- helper существует: сохранить upstream #3187 direct argv command shape;
- helper отсутствует: exact version-mapped `cat=`, без stop/close, `custom5`,
  raw erase или execute;
- записать один unconditional classified consequence/recovery log;
- при `rRatio::obtain()`/startup-reload условно загрузить A-owned rearm seam и
  re-register fixed drain key только для pending durable state/queue;
- startup recovery не должен объявлять Ratio globally dependent on erasedata и
  не должен отключать stop/throttle при missing helper/rearm refusal;
- не добавлять username filter;
- не выдавать искусственный invalid-force direct call за production input.

Copied-real `ratio.php` child fixture обязан физически не содержать
`plugins/erasedata/erase.php` и доказать no-op, no destructive token и exact
log. Нельзя подменять `__FILE__`-relative existence check тестовым seam.

Required B mutations без preceding fatal:

1. вернуть `custom5; d.erase` fallback;
2. добавить stop/close перед absent-helper no-op;
3. подавить unconditional diagnostic;
4. вызвать helper, несмотря на отсутствие файла;
5. удалить Ratio-startup A rearm call;
6. gate rearm на enabled state erasedata;
7. сделать rearm failure причиной disable всего Ratio;
8. arm drain при exact empty queue/state или удалить ordinary Ratio schedule.

A отдельно убивает post-publication kick, arm/ack removal, implicit/coerced
force, stale same-hash suppression, generation collapse, drain scheduler/hash
`LOCK_NB`, same-key replacement starvation, worker fan-out, unsafe retirement
и global-lock bypass. B copied-real startup fixture дополнительно симулирует
lost runtime schedule + pending wake при disabled erasedata и доказывает один
mapped fixed-key rearm без Ratio disable.

## Container verification checkpoint

Read-only immutable exports `edcea5a9`, текущего `24891da9` и exact base
запускались с `--network none`, uid 1000 и `:ro` mount.
`EraseWithDataCommandTest` на обоих fork refs дал **10 methods / 61 assertions /
0 failed** в PHP 7.4, PHP 8.1, shipped PHP 8.5.9 и `rutorrent-rt21:test`; exact
base — 6/8/0 в трёх PHP compatibility images. Оба B paths прошли `php -l` во
всех четырёх runtimes.

Copied-real missing-helper probe initial `edcea5a9` в `php:7.4-cli`,
`php:8.1-cli` и shipped PHP 8.5.9 подтвердил: donor строит exact `cat=` без
stop/close/execute/custom5/erase, base строит destructive
`d.stop=; d.close=; d.set_custom5=1; d.erase=`. Возврат destructive fallback
на initial `edcea5a9` остался ложнозелёным 10/61 в этих трёх runtimes — нужный
B RED действительно отсутствует. Остальные disposable mutations выполнялись
на том же initial ref и дали ожидаемое:
exact-force removal 14 failures, CLI default 2, stale same-hash 1, isolated
publish guard 2 + named child fatal; удаление username filter осталось 10/61
GREEN и подтверждает refuted hunk.

Новый Ratio-startup rearm и disabled-erasedata restart scenario в текущем коде
и tests отсутствуют. Поэтому existing container GREEN подтверждает reachability
и false-green gap, но corrected B станет implementation-complete/handoff-
eligible только после named RED → GREEN в тех же runtimes и disposable
daemon/lab smoke.

## Handoff gate

B не строится до final A с durable drain/rearm seam. Затем: natural RED,
GREEN, все mutations, focused PHP 7.4/8.1/8.5, full harness, PHPStan, exact
two-path diff/final numstat, whitespace и independent whole-file review.
Runtime command-shape smoke выполняется только в disposable lab, не live.
Agent не push.

## Post-sync revalidation — 2026-08-30

Final merge `4b3cd79925e7b73ea25feb1658a34e6b698c9855` основан на upstream
`529033335e66e1acd4084b73030f5880035ce1c0`; историческая база
`755404f3e38af98b6901852b35be10fb9659ffd3` и все утверждённые baseline/
approval hashes остаются frozen. Exact delta `755404f3..52903333` — только
#3220/#3202 и три package-lock/filedrop path — не пересекает exact two-path
scope B.

Relevant pre-755 #3218 shield также сохранён: `plugins/ratio/init.php` требует
`ratio.php` относительно каталога самого init-файла, а upstream
`tests/php/PluginInitPathsTest.php` сохранён byte-for-byte (SHA-256
`75731ac6eefb7a190ede59c568145d9cc3be148ff0d41a8faa6e25ab1ee576d2`).
Этот init/test shield остаётся dependency evidence и не расширяет two-path B.

Статус остаётся **DESIGN APPROVED — implementation pending**. Scope,
prerequisite final A и счёт общей очереди неизменны: все 18 implementation
packages общей очереди остаются pending, B является одним из них.
