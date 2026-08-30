# `up/erasedata-remove-payload` (A) — independent design review

Дата: 2026-08-29. Current base: `upstream/master=755404f3`. Fork и прежние
findings использованы как гипотезы; review был read-only.

## Verdict: CORRECTED DESIGN APPROVED on the same 8+2 boundary

A остаётся одним cohesive package на точной границе **8 production + 2 test
paths**. Это review scope/дизайна, не ещё не существующего isolated code.

Последующий Ratio B audit обнаружил production-reachable schedule gap, не
увеличивающий эту границу: remove-payload producer публикует job, но не запускает
collector, когда independently disabled erasedata снял periodic schedule.
Простой targeted one-shot отклонён: accepted launch не доказывает запуск PHP,
nonblocking scheduler/hash locks могут silently skip единственную попытку, а
one-worker-per-hash/invocation создаёт unbounded waiters. Пользователь явно
утвердил вариант 2: durable wake generation и один coalesced repeating
`erasedata-drain` schedule. Финальный independent re-review выполнен: exact
set/cardinality, no-ack recovery,
journal lifetime, lock order, settle-before-remove crash cut и restart split
одобрены; production implementation ещё не строилась.

Если разработку нужно разделить, допустим только stacked order:

1. **A1 reader/collector:** legacy-compatible parse, identity owner,
   fail-closed deletion, exact retention/retry и classified diagnostics;
2. **A2 producer/entrypoints:** unique generation-bound v2 staging, exact force,
   capability preflight и erase только после durable wake/arm, complete staging
   и real child acknowledgement; final publication следует после erase.

A1/A2 не siblings, producer-first запрещён: old collector может разобрать
partial/new JSON как legacy input и затем потерять obligation. Предпочтителен
один финальный A package.

## Frozen path boundary

Production:

1. `plugins/erasedata/action.php`;
2. `plugins/erasedata/collector.php` — только remove-payload/v1-v2;
3. `plugins/erasedata/erase.php`;
4. `plugins/erasedata/filesystem.php`;
5. `plugins/erasedata/init.php`;
6. `plugins/erasedata/manifest.php` — только legacy/v2;
7. `plugins/erasedata/removewithdata.php`;
8. `plugins/erasedata/update.php`.

Tests:

9. `tests/plugins/erasedata/CollectorFixture.php`;
10. `tests/plugins/erasedata/RemoveWithDataTest.php` — только A tests.

Пять путей пересекаются с cleanup-obsolete/v3 package C, поэтому whole-file
copy ten-path fork diff запрещён. Final numstat существует только после hunk
carve.

Exclude: `plugins/httprpc/action.php`, Ratio paths, v3/C, P0/P1, SCGI/core,
`php/xmlrpc_path.php`, `init.js`, config/locales/UI. В `init.php` сохранить
upstream #3218 anchored `dirname(__FILE__)."/../../php/xmlrpc.php"`.

## Filesystem identity owner

Единственный production owner для A —
`plugins/erasedata/filesystem.php`. Fork dependency на
`XMLRPCPathResolver::filesystemIdentity()` из `php/xmlrpc_path.php` ошибочна и
удаляется; SCGI не имеет identity callers и не является prerequisite.

Erasedata owner обязан fail closed и давать:

- component-aware canonical path и deepest existing ancestor для missing tail;
- `lstat` identity самой entry и followed `stat` target identity;
- physical-alias detection без ложного component-prefix match;
- отказ для relative/NUL/dangling/raced/unresolvable input;
- один shared primitive для всех destructive consumers без core/plugin copies.

## Descriptor capability до erase

Текущий `/proc/self/fd`-only collector может впервые обнаружить отсутствие
capability уже после staging и `d.erase`, навсегда оставив obligation без
torrent entry. Исправленный контракт:

- пробовать hardcoded `/proc/self/fd`, затем `/dev/fd`;
- принимать root только если open handle `fstat` и `stat(root/fd)` совпали с
  expected `dev/ino`; существования directory недостаточно;
- держать handle открытым до конца traversal;
- не принимать configured/request-provided descriptor root;
- force-2 multi-file preflight выполнять после path discovery, но **до**
  manifest staging и `d.erase`;
- при отказе release только этот hash lock, не создавать job, не стирать
  torrent, записать unconditional classified consequence и продолжить другие
  безопасные hashes batch-а;
- никогда не превращать force 2 в force 1;
- уже queued job сохранять byte-exact/retryable до появления capability.

На review host оба roots дали одинаковые `dev/ino`, что доказывает fallback
shape, но не универсальную доступность: `/dev/fd` может быть procfs alias.

## Retained-job visibility

Текущий collector часто правильно сохраняет v1/v2 manifest, но пишет причину
через disabled-by-default `eLog`. Независимый malformed-manifest probe оставил
exact bytes и пустой normal log: это реальный silent permanent stall.

Required contract:

- exact path/bytes остаются и retry выполняется на каждом pass;
- один unconditional summary на **physical job** на invocation, не на hash;
- никакого quarantine/rewrite/retry cap/persistent suppression/consume при
  indeterminate или failed outcome;
- только canonical hash и stable classification, без paths, manifest bytes и
  raw RPC fault;
- deterministic classification независимо от enumeration order;
- ordinary nonblocking per-hash lock contention periodic broad pass-а не
  логируется как permanent;
- scheduler-lock open и index scan failures классифицируются unconditional;
- repaired transient job завершается на следующем pass.

Boolean deletion result недостаточен: parser/mutation layers должны возвращать
structured reason. Минимальный pinned vocabulary:
`rpc-unknown`, `unreadable-manifest`, `active-path`, `unsafe-path`,
`unlink-failure`, `rmdir-failure`, `directory-reference-unavailable`,
`legacy-force-limited`.

## Durable coalesced drain contract

Ratio не зависит от enabled state erasedata: helper остаётся на диске после
того, как `done.php` снял ordinary `erasedata<User>` schedule. Поэтому один
periodic schedule или fire-and-forget child не является consumer guarantee.

### Persistent state and schedule ownership

A владеет тремя bounded служебными entries внутри per-user
`<settings>/erasedata`; они не должны совпадать с manifest grammar:

- `.drain-state` — canonical versioned JSON с fixed-width 16-hex monotonic
  `generation`, `acknowledged`, arm phase, bounded diagnostic state и bounded
  journal фаз active generations (`prepared`/`erase-started`);
- `.drain-state.lock` — short state/linearization flock;
- `.drain-worker.lock` — admission одного long-lived drain worker.

Каждая новая staging name явно содержит canonical drain generation и связывается
с journal entry; legacy/v2 names без такой связи сохраняют прежнюю conservative
semantics. Journal имеет hard size/count cap: исчерпание capacity означает
видимый refusal до erase, а не eviction старой generation.

State записывается только atomic temp + flush + close + rename под state lock.
Generation инкрементируется строковым hex алгоритмом, чтобы не зависеть от
PHP integer width; PID, inode mtime и wall clock не являются generation или
ownership token. Invalid/partial/overflow state fail closed, оставляет jobs и
даёт unconditional classified recovery diagnostic.

Fixed schedule key — `erasedata-drain<User>`, через mapped `schedule` и
`schedule_remove` names для rTorrent 0.9.x/0.16.x. Он намеренно отличается от
ordinary `erasedata<User>`, поэтому unchanged `done.php` снимает periodic pass,
но не durable drain. Schedule повторяется с фиксированным bounded interval; его
body direct-backgrounds CLI `update.php <user> drain`, чтобы rTorrent event loop
не ждал PHP/RPC/locks. 0.16-only `schedule.if_absent` запрещён.

### Producer transaction and acknowledgement

Для одного multi-hash producer invocation, не для каждого hash:

1. exact force/path/fd preflight и unique complete staging plans выполняются до
   destructive RPC; hash locks берутся в canonical sorted order. Canonical
   unique requested set `R` обязан получить полное disjoint partition
   `R = A accepted + F refused`: ни один input hash нельзя молча отбросить,
   дублировать или обрезать до первых `k`;
2. под `.drain-state.lock` producer durably advances generation, переводит
   stale/disarmed state в `arming`, synchronously registers один fixed repeating
   schedule и только после успешного RPC фиксирует armed state;
3. journal generation фиксируется как `prepared`, а complete unique
   generation-bound `.tmp` obligations создаются под тем же state lock и hash
   locks **до** первого `d.erase`. Exact hash set и cardinality journal bindings
   и staging obligations обязаны совпадать с `A`; для `F` нет staging,
   journal binding, erase или publish. Если capacity либо staging не покрывает
   всё `A`, invocation aborts целиком до erase, а не продолжает с prefix;
4. state lock освобождается, hash locks остаются. Scheduled PHP child сначала
   подтверждает observed generation, поэтому producer bounded ждёт
   `acknowledged >= own generation` и только затем выполняет destructive RPC;
5. после ack producer под hash + state locks atomically переводит только свою
   всё ещё `prepared` generation в `erase-started`; missing/cancelled/mismatched
   journal entry означает no erase;
6. после destructive RPC/reconciliation каждый hash из `A` получает ровно один
   явный outcome. `E ⊆ A` — exact erase-attempted set, `P ⊆ E` — exact
   published set, `T1 = E \ P` — retained-after-attempt и `T0 = A \ E` —
   retained-unattempted; только outcome sets дают disjoint partition
   `P ⊎ T1 ⊎ T0 = A`. При all-success `E = P = A`, `T1 = T0 = ∅`.
   При injected partial failure tests отдельно сверяют exact sets/counts
   `E/P/T1/T0`; необработанный suffix `T0` остаётся связанным с exact retained
   obligation — loop не имеет права молча остановиться после первых `k`.
   Journal остаётся active
   `erase-started`, пока существует хотя бы один bound staging этой generation;
   complete/remove entry разрешён только когда все bound staging promoted,
   consumed либо terminally resolved. Published final manifests self-describing
   и больше не требуют phase journal. Затем producer освобождает hash locks.

Arm-before-erase необходим: crash после published job, но до post-publication
kick, при disabled erasedata снова создаёт permanent stall. Arm с start >= 1
только вставляет scheduler event и не исполняет callback внутри arm RPC.
Scheduled worker может ждать producer locks: он detached, а producer ждёт лишь
state acknowledgement, которое происходит до worker admission/lock wait.

Registration success сам по себе не является acknowledgement: если rTorrent
принял schedule, но PHP child не стартовал, producer по timeout не стирает
torrent. Под своими ещё удерживаемыми hash locks он identity-validates и
откатывает только staging своей `prepared` generation, atomically помечает её
cancelled/complete, не трогает более поздние generations и пишет один
`drain-no-ack` summary с count, consequence и recovery. Unlink/state rollback
failure сохраняет exact staging/wake как видимую retryable obligation. Producer
не может записать ack за worker.

Первый переход в active state регистрирует schedule; следующие concurrent
producers только advance generation. Безусловная re-registration каждого
invocation запрещена: rTorrent заменяет schedule по key и сбрасывает start,
поэтому continuous producer stream мог бы бесконечно откладывать первый tick.

### Worker admission, locks and retirement

Каждый `drain` tick:

1. после strict CLI/SAPI check кратко берёт state lock и durably поднимает
   `acknowledged` до observed generation;
2. затем пробует `.drain-worker.lock` через `LOCK_EX|LOCK_NB`; loser немедленно
   и молча выходит, поэтому ticks не накапливаются за global/hash locks;
3. winner берёт общий `scheduler.lock` blocking `LOCK_EX`, выполняет broad
   collector с blocking per-hash locks и периодически обновляет bounded
   heartbeat; state lock никогда не удерживается при ожидании scheduler/hash;
   после crash освобождённый hash lock позволяет worker отменить exact
   generation-bound staging только если journal всё ещё `prepared`. Для
   `erase-started` действует conservative presence/publication recovery;
4. после release всех hash locks, но ещё под global lock, worker берёт state
   lock, делает fresh conservative queue scan и сравнивает generation;
5. pending/unknown queue или changed generation сохраняет/rearms тот же fixed
   schedule. Для stable generation + proven empty worker, всё ещё удерживая
   state lock, **сначала** durably пишет `settled/disarmed`, и только затем
   synchronously вызывает `schedule_remove`. После successful remove больше нет
   обязательной state write, поэтому не существует cut «schedule снят, durable
   state всё ещё armed»;
6. crash/refusal после durable `settled/disarmed`, но до/в `schedule_remove`
   оставляет только harmless extra runtime tick. Такой tick повторяет
   empty/stable retirement; любой новый producer под state lock видит disarmed,
   заново регистрирует fixed key до staging, а A-init/B-startup трактуют
   settled с неожиданным pending queue как rearm-required. Remove refusal даёт
   bounded classified diagnostic и self-heals последующим tick/trigger, но не
   переводит durable state обратно в ложное `armed`.

Periodic broad mode остаётся `scheduler LOCK_NB` + hash `LOCK_NB`. Drain mode
blocking ждёт global/hash locks. Later combined P0+C может добавить explicit
targeted mode, но не имеет права обходить global lock: collector выполняет
shared recovery до hash filtering.

Hash→state lock order одинаков у producer и prepared-generation recovery.
Worker не берёт state lock до получения соответствующего hash lock, а state
lock никогда не держит при ожидании hash, поэтому cycle не возникает.

Ack имеет узкий смысл: реальный guarded PHP entrypoint увидел durable wake.
Completion доказывает только stable-generation retirement; manifests/staging
остаются source of truth до collector success. Crash освобождает flock, а
repeating schedule запускает successor. Permanently hung owner не ломается
насильно: later ticks дают один bounded `drain-worker-stalled` diagnostic на
generation и сохраняют obligations.

Final scan/settle/remove и producer advance/staging сериализованы одним state
lock: если worker выиграл, следующий producer видит settled state и re-arms;
если producer выиграл, worker видит changed generation/pending candidate и не
retire-ится. Schedule-remove/rearm refusal сохраняет state/jobs и даёт один
classified diagnostic без raw command/path/manifest/RPC text/hash list.

### Restart recovery and exact limitation

Runtime schedule не persistent. Поэтому:

- A `init.php` безусловно re-arms pending/stale wake или queued candidate при
  enabled erasedata;
- Ratio B startup/reload при enabled Ratio вызывает A-owned rearm entrypoint,
  если erasedata установлен, но disabled;
- каждый следующий producer делает ту же stale-state reconciliation;
- ordinary `done.php` не меняется и не удаляет drain key.

Строго автономный recovery после daemon/container restart при одновременно
unlaunched erasedata и Ratio, без любого последующего producer/init, внутри
этих восьми production files **недостижим**: disabled loader не выполняет ни
один из них, а rTorrent schedules volatile. Такой stronger guarantee потребовал
бы always-run core/container bootstrap и не заявляется A. Durable job/state не
потребляется; documented recovery — enable одного owner. Actual audited case
`erasedata disabled + Ratio enabled` закрывает двухпутевой B startup hook.

## Dependencies

- `up/httprpc-refusals` — delivery/evidence parent, но не production-symbol
  dependency A;
- SCGI и `up/xmlrpc-proxy-policy` — independent siblings;
- позже `up/httprpc-erasedata-contract` ждёт **оба** A и proxy-policy;
- Ratio B ждёт final A и добавляет startup rearm для audited
  `erasedata-disabled/Ratio-enabled` case; obsolete C складывается внутрь P0
  как отдельный RED-first slice после A;
- C/P0 producer не входит в A.

## Finding verdicts

Подтверждено и production-reachable:

- publication failure может быть проигнорирован перед erase;
- failed payload delete/rmdir может сопровождаться manifest consume;
- same-hash generations могут overwrite/suppress друг друга;
- RPC unknown, physical alias, symlink/rename races и FS failure реальны;
- web, httprpc consumer и Ratio scheduler достигают shared API;
- web может достичь `update.php` на PHP 7.4, поэтому `count(null)` TypeError не
  является boundary; нужен explicit CLI-SAPI gate;
- поддерживаемый-style deployment без procfs возможен;
- malformed/RPC-unknown/lock/index stall невидим при default logging.
- при выключенном erasedata Ratio может publish/erase без живого periodic
  schedule; durable pre-erase wake/arm/ack и B startup rearm обязательны;
- accepted schedule registration не доказывает, что guarded PHP child
  стартовал;
- rTorrent заменяет schedule с тем же key и сбрасывает start, поэтому
  re-register на каждый concurrent invocation может starvation-ить worker.

Опровергнуто:

- filesystem identity должен принадлежать SCGI/core;
- одного существования `/dev/fd` достаточно;
- force 2 может degrade в force 1;
- diagnostics можно deduplicate только по hash;
- httprpc/Ratio production paths входят в A;
- green differential доказывает correctness старого collector.

Недостижимо/не относится к production scope A:

- no-descriptor refusal на конкретном instance с identity-validated mapping;
- cleanup-obsolete/v3 до появления C/P0 producer;
- missing-helper fallback при целой plugin installation — owner later consumer;
- invalid force, переданный напрямую в Ratio public method: его production
  callers используют только exact literals, validation остаётся owner A;
- обычный busy hash lock не является retained failure.
- zero-trigger recovery после restart, когда оба possible owners intentionally
  unlaunched: ни один A production entrypoint не выполняется; obligation
  сохраняется до enable/producer и stronger bootstrap находится вне A.

## Required RED/mutations

Natural RED на exact parent обязан покрыть:

1. exact force domain до staging/RPC;
2. unique durable v2 write/flush/close/rename/collision и disk/permission fail;
3. present/absent/unknown с retention unknown;
4. exact-byte retry для malformed/transient failures;
5. две physical same-hash generations;
6. lexical/physical aliases, prefixes, symlinks, missing tails и races;
7. CLI-only start на PHP 7.4 semantics;
8. residue/blocker без удаления unrelated files;
9. no roots до erase, `/dev/fd` fallback, mismatch refusal, force-1/single-file,
   mixed batch, queued recovery и capability loss after preflight;
10. debug-off classifications, per-physical-job aggregation, no leakage,
    scheduler/index failures, busy-lock silence и next-pass recovery.
11. erasedata-disabled Ratio path: durable generation → fixed repeating arm →
    complete staging → real child ack → erase/publish; `done.php` удаляет
    periodic key, но не drain key;
12. crash barriers до arm, arm-before-staging, staging-before-ack,
    ack-before-erase и erase-before-publish; ни один не оставляет erased torrent
    без durable wake + recoverable exact job;
13. accepted schedule/no PHP child: bounded timeout, torrent retained, только
    own prepared staging identity-rollback, later generation untouched, schedule
    может settle и пишет один unconditional `drain-no-ack` diagnostic;
14. N concurrent one-hash Ratio-style invocations и large multi-hash batch:
    one fixed schedule key, one active long worker, ticks acknowledge newer
    generations before NB admission, no per-hash/per-invocation fan-out. Test
    фиксирует exact canonical `R`, exact disjoint `A/F`, `|R|=|A|+|F|`, exact
    equality `A` с staging/journal hashes и, для all-success, `E=P=A`,
    `T1=T0=∅`; для injected cut exact `E/P/T1/T0` и cardinality, где
    `T1=E\P`, `T0=A\E`, `P⊎T1⊎T0=A`. Для `F` staging/journal и
    destructive side effects равны нулю.
    Mutations first-`k`, dropped middle/last hash и duplicate outcome обязаны
    RED;
15. continuous producer stream не postpones first tick: already-armed state не
    re-registers schedule; mutation с unconditional replacement обязана RED;
16. held scheduler и exact hash locks: ack остаётся bounded, drain worker ждёт
    blocking и consumes after unlock, periodic broad немедленно exits NB;
17. child/producer crash до/после admission, после staging, после durable
    `erase-started`, после partial drain и до retirement: prepared generation
    safely cancels, erase-started остаётся conservative/retryable, flocks
    освобождаются и repeating schedule retries;
18. producer-vs-retirement barriers в обеих очередностях, включая publication
    во время initial scan и между final compare/durable settle/schedule removal;
    отдельный crash-cut после durable `settled/disarmed`, но до successful
    `schedule_remove`, доказывает harmless leftover tick + rearm нового producer
    и запрещает mutation, снимающую schedule до durable settle;
19. stable generation + proven empty retires; retained/scan-unknown/gen-change
    не retires; remove/rearm refusal видим и retryable;
20. simulated daemon/container restart: A enabled init re-arms; A disabled +
    Ratio enabled startup/reload re-arms; corrupt/partial state fail closed;
21. hung owner даёт bounded classified stale-worker consequence/recovery, но
    не force-break flock и не consume jobs;
22. mutation с обходом global lock показывает overlap в shared recovery, а не
    только два успешных RPC launch.
23. mixed batch с publish failure: successful final manifests остаются
    self-describing, retained `.tmp` сохраняет active `erase-started` journal;
    mutation с premature journal completion обязана RED.

Mutations должны убить: erase after failed publish, direct final-name write,
UNKNOWN collapse, consume after failure, hash-only publication/logging, lexical
instead of physical identity, CLI-gate bypass, no-preflight, missing fallback,
existence-only fd acceptance, force coercion, `eLog` routing, retry suppression,
raw leakage, silent index/lock failure, post-publication kick, erase-before-arm
или ack, producer-written ack, one-shot/same-as-periodic schedule key,
schedule replacement на каждый producer, ack-after-worker-admission,
blocking worker admission, unbounded worker-per-hash fan-out, silent arm/no-ack,
drain scheduler/hash `LOCK_NB`, retire при pending/changed/unknown, final compare
вне wake lock, `done.php` removal drain key, missing A/B restart rearm и
global-lock bypass. Также обязательны mutations: no-ack staging retention без
terminal recovery, rollback чужой/later generation, erase без durable
`erase-started`, eviction active journal entry и prepared recovery без exact
hash+identity binding, а также completion journal при любом bound staging.

Каждый named RED обязан реально выполниться без preceding fatal; после restore
нужен свежий GREEN на focused non-root permission run, PHP 7.4/8.1/8.5
матрицах, PHPStan, exact ten-path scope и whole-file review.

## Container verification checkpoint

После approval выполнена read-only перепроверка immutable exports
`edcea5a96332835335199ef6d33e667e32dfbaf6`, затем текущего
`24891da9edddc252835493a4100e966dcd50cdf9`, и exact base `755404f3`.
Контейнеры запускались как uid 1000, `--network none`, export mount `:ro`; live
rTorrent не вызывался.

На обоих fork refs `RemoveWithDataTest` дал **204 methods / 1305 assertions /
0 failed** в
`php:7.4-cli`, `php:8.1-cli`, shipped PHP 8.5.9 и `rutorrent-rt21:test`.
`tests/php/ScheduleTest.php` дал 11/0 во всех четырёх runtimes. Exact upstream
base дал 9 methods / 14 assertions и Schedule 11/0 во всех трёх PHP
compatibility containers. Все десять A paths прошли `php -l` в PHP
7.4/8.1/shipped-8.5/rt21 runtime.

Baseline подтверждён на обоих fork refs; disposable mutations запускались на
initial `edcea5a9`. Это не выдаётся за GREEN нового дизайна: в immutable code
отсутствуют `.drain-state`, fixed drain
key, child acknowledgement, exact `E/P/T1/T0` batch assertions, journal phases,
restart/hung-worker/retirement tests. Они остаются обязательным natural RED →
container GREEN этапом реализации. `rutorrent-rt21:test` использован как PHP
test runtime, полный s6/rTorrent daemon здесь не запускался.

## Acceptance condition

A получил явное user approval варианта 2. RED-first реализация разрешается
после выполненного independent approval этого arm/ack/retirement контракта.
Merge/handoff возможен только без v3/C, httprpc consumer, Ratio, SCGI и
`php/xmlrpc_path.php` hunks. Агент push не выполняет.

## Post-sync revalidation — 2026-08-30

На final merge `4b3cd79925e7b73ea25feb1658a34e6b698c9855` с upstream
`529033335e66e1acd4084b73030f5880035ce1c0` историческая база
`755404f3e38af98b6901852b35be10fb9659ffd3` и все принятые на ней evidence/
approval hashes остаются frozen. Exact delta `755404f3..52903333` состоит
только из #3220 и #3202 (`tests/package-lock.json`, `plugins/filedrop/init.js`,
`tests/plugins/filedrop/init.spec.js`) и имеет пустое пересечение с frozen
8+2 scope A.

Relevant pre-755 shield #3218 сохранён: plugin-relative requires в
`plugins/erasedata/init.php` остаются anchored к каталогу самого файла, а
current `tests/php/PluginInitPathsTest.php` byte-identical upstream
(SHA-256 `75731ac6eefb7a190ede59c568145d9cc3be148ff0d41a8faa6e25ab1ee576d2`).
Container qualifier не меняет acceptance: PHP 8.1 SCGI characterization шёл
при default `memory_limit=128M`, тогда как A-owned
`testEveryManifestReadBoundaryStopsAtTheByteCeiling` поднимает лимит до `512M`
только внутри этого test и восстанавливает его; это не SCGI GREEN.

Статус остаётся **DESIGN APPROVED — implementation pending**. Scope A,
dependencies и счёт общей очереди неизменны: все 18 implementation packages
общей очереди остаются pending, A является одним из них.
