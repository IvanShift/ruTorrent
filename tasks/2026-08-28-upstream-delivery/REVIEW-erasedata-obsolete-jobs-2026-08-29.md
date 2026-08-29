# `erasedata` obsolete jobs (C) — independent design review

Дата: 2026-08-29. База проверки:
`upstream/master=755404f3e38af98b6901852b35be10fb9659ffd3`.
Fork, прежние findings и старые тесты использовались только как гипотезы.
Review был read-only; executable probes выполнялись в disposable export.

## Verdict: DESIGN APPROVED; IMPLEMENTATION NOT STARTED; C folds into P0

Отдельный `up/erasedata-obsolete-jobs` строить и считать нельзя.

Причины независимы:

1. на current upstream нет ни одного production producer для cleanup-v3 и
   generation-recovery API; до P0 такой branch добавляет только dormant
   protocol, а достижимый inline cleanup остаётся активен;
2. donor защищает successor, но не проверяет, что old-only path или тот же
   physical inode всё ещё принадлежит третьему torrent. Такой shared payload
   достижимо удаляется.

Принятое packaging-решение: C становится внутренним RED-first slice
`up/rutracker-check-replacement-transaction` (P0) поверх final A. Delivery —
один combined P0+C package; внутренние commits допустимы только для review.
После отражения этого решения в controlling plan отдельный package C исчезает,
а implementation queue уменьшается с 19 до 18.

## Что перемерено

- exact `755404f3` versions `plugins/rutracker_check/check.php` и
  `tests/plugins/rutracker_check/CheckerTest.php`;
- current fork v3 codec, scanner, lifecycle/recovery и collector;
- все production callers C API;
- donor erasedata fixtures/tests;
- исторические cross-seed claims, затем свежий executable probe на exact base;
- corrected A boundary и P0 dependency plan.

В репозитории нет ref `up/erasedata-obsolete-jobs`, поэтому честного branch
numstat сейчас не существует.

## Текущее достижимое поведение

Upstream уже вызывает `cleanupObsoleteFiles()` из real `createTorrent()` после
erase predecessor и activation successor, но перед clear replacement marker.
Функция вычисляет `old - new`, сохраняет exact physical aliases successor,
удаляет остальные candidates и затем пустые вложенные каталоги.

Exact upstream `CheckerTest.php` прошёл 31 test / 0 failures. Это доказывает
обычное удаление, traversal refusal, pre-existing symlinked-parent refusal,
filesystem-root refusal, successor hard-link protection и сохранение base.
Suite не доказывает durability, visibility, third-torrent ownership или
stat-to-unlink identity binding.

Свежий cross-seed probe вызвал real private `cleanupObsoleteFiles()` с одним
genuine orphan и одним path, которым владеет другой torrent:

```text
shared=deleted orphan=deleted base=kept requests=0 log=0
```

Функция не делает ни одного fleet/RPC ownership request, поэтому эти два
состояния для неё неразличимы.

Non-root probe с containing directory mode `0500` дал:

```text
old=kept requests=0 log=0
```

После failed unlink durable job не создаётся, marker затем очищается, а default
logging не показывает consequence.

## Вердикты по каждому claim

| Claim | Вердикт | Независимое основание |
|---|---|---|
| Upstream вообще не пытается удалить obsolete files | **ОПРОВЕРГНУТ** | Real `createTorrent()` вызывает `cleanupObsoleteFiles()`; exact suite и probe подтверждают обычное удаление. |
| Failed `unlink()`/genuine failed `rmdir()` остаётся без durable retry | **ПОДТВЕРЖДЁН** | Job не записывается; non-root probe сохранил файл, normal log пуст. |
| Process death после predecessor erase может навсегда потерять cleanup obligation | **ПОДТВЕРЖДЁН** | До commit point нет durable cleanup state, а после erase metainfo predecessor больше не authority. |
| Cleanup refusal/failure видим с shipped defaults | **ОПРОВЕРГНУТ**; silent stall **ПОДТВЕРЖДЁН** | Все сообщения идут через disabled-by-default `logDebug()`; probe дал `log=0`. |
| `realpath/stat` закрывают mutation race | **ОПРОВЕРГНУТ**; TOCTOU **ПОДТВЕРЖДЁН** | Identity читается до path-based `unlink($absolute)` без identity-bound mutation. |
| Path или inode, которым владеет третий torrent, защищён | **ОПРОВЕРГНУТ**; cross-torrent deletion **ПОДТВЕРЖДЁН** | Fresh probe удалил shared и orphan; ownership RPC count равен нулю. |
| Один historical fleet без overlap делает defect недостижимым в prod | **ОПРОВЕРГНУТ** | Snapshot данных не ограничивает поддерживаемые shared/cross-seed layouts и будущие reuse path/inode. |
| Удаляется любой unrelated сосед в том же каталоге | **ОПРОВЕРГНУТ** | Candidates ограничены safe `old - new` metainfo paths. |
| Cleanup удаляет shared base directory | **ОПРОВЕРГНУТ** | Parent walk останавливается на base; probe сохранил base. |
| Static traversal или pre-existing symlinked parent выходит за base | **ОПРОВЕРГНУТ** | Component grammar, `realpath` equality и containment отвергают эти exact cases; это не закрывает поздний TOCTOU. |
| Static hard-link/case alias successor всегда удаляется | **ОПРОВЕРГНУТ** | Upstream строит successor `dev:ino` set; focused alias test проходит. |
| C-only branch создаёт v3 jobs обычным product route | **НЕДОСТИЖИМ В ПРОДЕ** до P0 | Web, httprpc, Ratio, scheduler и tracker не имеют v3 producer. |
| C-only generation recovery вызывается actual replacement | **НЕДОСТИЖИМ В ПРОДЕ** до P0 | Marker/record grammar и callers принадлежат P0 и отсутствуют upstream. |
| Сам факт запуска A collector делает v3 scanner branch reachable | **НЕДОСТИЖИМ В ПРОДЕ** до producer | Supported route не создаёт valid tagged cleanup generation. |
| `php/xmlrpc_path.php` обязан владеть filesystem identity | **ОПРОВЕРГНУТ** | Final A делает единственным owner `plugins/erasedata/filesystem.php`; donor calls надо переписать, не копировать. |

## Почему C и P0 неразделимы

- v3 `replacement_record` validation кодирует P0 record grammar;
- recovery читает P0-owned `chk-replacement`/`chk-replaces` generations;
- `.tmp` может стать committed только после доказанного OLD absent и exact NEW
  generation;
- cancel корректен только если P0 доказал, что commit не произошёл;
- P0 sweep должен reconcile job до clear generation keys;
- retry schedule оправдан только вместе с реальным producer;
- standalone C оставляет unsafe inline cleanup активным.

P1 следует за combined P0+C. Ratio B остаётся sibling от final A. SCGI и
`php/xmlrpc_path.php` в dependency chain не входят.

## Ownership contract, обязательный для combined P0+C

### Кто считается другим owner

Для каждого candidate provider передаёт canonical OLD/NEW hashes, marker и
replacement record. Из owner scan исключаются не «любые строки с тем же hash»,
а только exact OLD predecessor и exact NEW occupant, доказанно связанные этой
marker/record generation. Foreign takeover на OLD/NEW hash остаётся обычным
owner/`unknown`. Это необходимо: на prepare OLD закономерно владеет каждым
old-only candidate; если не исключить exact predecessor, genuine orphan нельзя
будет удалить вообще.

Один erasedata-owned probe строит fleet index по всем остальным torrents:

- canonical lexical file paths считаются claims даже когда имя сейчас не
  существует и `realpath()` его не разрешает;
- для существующих entries дополнительно индексируется physical `dev:ino`,
  поэтому другой lexical path/hard link того же object тоже считается claim;
- неполный fleet scan, failure/malformed row любого relevant `f.multicall`,
  unsafe path composition либо невозможность прочитать identity даёт
  `unknown`, никогда empty claim set.

Probe возвращает ровно одно состояние:

1. `unclaimed` — ни lexical, ни physical owner не найден;
2. `claimed` — path либо expected inode принадлежит другому torrent;
3. `unknown` — отсутствие owner доказать нельзя.

На prepare `claimed` фиксируется как intentionally protected и не попадает в
destructive job, `unknown` aborts prepare до OLD erase, а `unclaimed`
фиксируется в v3 obligation вместе с expected identity.
OLD/NEW exclusion, входной fleet generation и result не являются permission
на будущий unlink: delayed consume повторяет observation.

### Race-closing quarantine protocol

Обычный «fresh RPC scan → path unlink» оставляет окно нового owner после scan.
Поэтому consumer использует A-owned `filesystem.php` и такой порядок для
каждого file candidate:

1. сделать fresh tri-state scan: initial `claimed` не запускает quarantine и
   завершает exact path как intentionally protected; `unknown` ничего не
   мутирует и сохраняет obligation для retry;
2. сверить public entry с exact expected identity из job;
3. atomic `rename()` entry в operation-private quarantine в том же
   parent/filesystem, затем повторно сверить quarantined `dev:ino/type` с exact
   expected identity; mismatch означает no unlink + identity-checked restore
   либо retained quarantine, никогда продолжение;
4. **не** публиковать symlink bridge на public name: после capture имя обязано
   оставаться отсутствующим;
5. при absent public name заново построить fleet ownership. Lexical claim
   считается claim, хотя `realpath()` теперь fail; physical aliases сверяются с
   quarantined inode;
6. post-capture `claimed/unknown` означает no payload unlink и identity-checked
   restore quarantine на original name, только если name всё ещё absent. После
   доказанного restore `claimed` завершает path как intentionally protected, а
   `unknown` сохраняет obligation для retry. Если name уже занят либо restore
   не доказан, quarantine сохраняется с bounded unconditional recovery
   diagnostic;
7. только post-capture `unclaimed` разрешает unlink/rmdir **quarantined** inode,
   после чего private protocol entries identity-bound очищаются.

Claim, возникший до capture, виден post-capture scan. Claim, возникший после
capture, не может открыть отсутствующее public name и приобрести quarantined
inode. Запрещённый symlink bridge снова открыл бы это окно. Crash с object в
quarantine сначала запускает identity-checked restore-before-reevaluation; при
занятом public name объект не удаляется и остаётся видимой obligation.

Empty parent directory удаляется только если fresh fleet lexical index не
содержит component-wise overlapping path другого torrent: ни самого directory,
ни ancestor, ни descendant. `unknown` retain-ит parent retry. Для rmdir
действует тот же no-bridge quarantine и exact empty-directory identity check;
recursive delete запрещён.

Raw paths, hash lists и RPC text в normal log запрещены; unconditional log
несёт только classified reason, count, consequence и recovery.

## Durable P0 transaction ordering

Combined producer не ждёт publication, чтобы создать wake. Под transaction и
canonical hash locks он обязан:

1. подготовить complete v3 cleanup job и exact ownership result;
2. advance/arm fixed A drain generation и получить acknowledgement реального
   guarded PHP child;
3. только затем выполнять predecessor `d.erase`/replacement commit;
4. publish exact v3 job после confirmed OLD absent и exact NEW marker/record;
5. release transaction/hash locks и сделать targeted kick.

Kick failure не теряет cleanup: repeating drain уже armed до erase. Unknown
commit сохраняет staging, OLD/NEW generations и keys; rollback отменяет только
exact still-prepared generation. P0 sweep reconciles cleanup до каждого clear
replacement key.

`plugins/rutracker_check/init.php` вызывает A-owned rearm seam для pending C
job/state после daemon/container restart, даже если UI erasedata и Ratio
disabled. Если сам rutracker_check intentionally unlaunched, autonomous call
недостижим; durable obligation остаётся, documented recovery — enable producer.

## P0 claim-acquisition contract

Current donor после `edcea5a9` сделал claim acquisition tri-state; exact
upstream `755404f3` такого API не имеет, поэтому это не новый verdict к base
findings, а production-reachable donor hazard, который P0 обязан не перенести.

Result domain заморожен:

1. non-empty owner token string — claim durably записан, mutation разрешена;
2. strict `false` — ordinary contention с другим live owner;
3. strict `null` — state document/lock/write unavailable, ownership unknown.

Только первый result может войти в handler/replacement mutation и позже вызвать
release. `null` fail closed и retryable: `run`, fast verdict write, оба
replacement sweep consumers, restore, erase и replacement-key clear не
выполняются; untokened release запрещён. `false` остаётся обычным contention и
не masquerades как storage outage. Один bounded unconditional classified
diagnostic на непрерывный outage называет consequence/recovery без raw state
document, lock path, token или hash list; успешная запись сбрасывает latch.

P0 владеет claim seam, early `run()` refusal, release token gate и двумя
replacement-sweep consumers. P1 fast-path consumer обязан позже соблюдать тот
же result domain, но его hunk не переносится в combined P0+C.

## Frozen exact path boundary: 20 paths

Combined P0+C строится поверх final A и может менять ровно 11 production paths:

```text
plugins/erasedata/collector.php
plugins/erasedata/filesystem.php
plugins/erasedata/manifest.php
plugins/erasedata/removewithdata.php
plugins/erasedata/update.php
plugins/rutracker_check/check.php
plugins/rutracker_check/init.php
plugins/rutracker_check/runstate.php
plugins/rutracker_check/state.php
plugins/rutracker_check/update.php
plugins/rutracker_check/updatepass.php
```

И ровно 9 test paths:

```text
tests/plugins/erasedata/CollectorFixture.php
tests/plugins/erasedata/RemoveWithDataTest.php
tests/plugins/rutracker_check/AtomicOwnershipTest.php
tests/plugins/rutracker_check/CheckerTest.php
tests/plugins/rutracker_check/EntrypointsTest.php
tests/plugins/rutracker_check/ProjectionContractTest.php
tests/plugins/rutracker_check/StateTest.php
tests/plugins/rutracker_check/TestLib.php
tests/plugins/rutracker_check/UpdatePassTest.php
```

`filesystem.php` и `CollectorFixture.php` обязательны: именно они владеют
no-bridge quarantine/race seam. Explicitly excluded: `metafetch.php`,
`CheckerMetaFetchIntegrationTest.php`, P1/bencode/handlers, manual entrypoints,
`init.js`, `run.php`, Ratio B, SCGI и `php/xmlrpc_path.php`. Даже test runner
registration вне 20 paths не входит: package запускает named suites напрямую.

Path allowlist не разрешает whole-file donor copy. В частности, новый
`php/rtorrent.php`/`tests/php/RtorrentSourceTest.php` — P1 magnet-source work и
остаются вне 20 paths. В shared `check.php`/`CheckerTest.php` P1 также владеет
`run_ex()` accepting an already parsed `Torrent`, metadata-only source retry и
`run()` switch на `rTorrent::getSource()`. `flushVerdicts` fast-path claim
consumer тоже P1. Combined P0+C переносит из этой зоны только claim seam/early
fail-closed contract и replacement sweep consumers; hunk ownership проверяется
не только по filename.

## Required natural RED

На final A tip, без preceding fatal:

1. strict v3 round-trip, duplicate keys, canonical base64, limits и exact
   old/new/marker/record binding;
2. tagged-name downgrade isolation для v1/v2/v3;
3. unique `.tmp`, exclusive token, collision/swap/readback refusal;
4. OLD present/unknown retain; только confirmed absent + exact NEW publish;
5. crash-safe committed pair и token-only finalization;
6. original deletion, missing completion, replacement-object/successor alias,
   base preservation, parent retry и exact-byte retention;
7. captured-object/successor-alias recovery;
8. malformed/RPC-unknown/unsafe/unlink/rmdir дают bounded unconditional reason;
9. real P0 `createTorrent()` prepare до predecessor erase;
10. publish после confirmed commit, но до marker close; exact cancel on rollback;
11. unknown commit сохраняет обе generations/job/keys;
12. sweep reconciliation до каждого key clear;
13. cross-seed: shared survives, genuine orphan is deleted;
14. ownership unknown authorizes neither erase nor payload mutation;
15. initial-consume `claimed` terminally completes exact path as intentionally
    protected, поэтому исчезновение third owner позже не разрешает delete;
    initial `unknown` retain-ит obligation и не masquerades as protected;
16. ownership drift between prepare and collect preserves newly claimed object;
17. exact OLD predecessor и matching NEW transaction row исключаются из owner
    set, но foreign occupant на любом из этих hashes остаётся claim/unknown;
18. post-quarantine scan видит lexical claim при отсутствующем public name;
19. claim после initial scan, но до capture/post-capture scan restores exact
    object; post-capture unknown делает то же;
20. crash in quarantine restores-before-reevaluation; occupied name/restore
    failure retain-ит object и видимую obligation;
21. parent lexical overlap: owner `/base/nested/missing.dat` сохраняет otherwise
    empty `/base/nested`; component sibling `/base/nested2/...` не даёт false
    protection `/base/nested`; owner-scan `unknown` retain-ит parent retry;
22. stage v3 → A generation arm → real PHP child ack происходят до OLD erase;
    publish — только после confirmed commit, targeted kick — после lock release;
23. restart через rutracker init re-arms pending C при disabled erasedata/Ratio,
    а kick failure не мешает repeating drain.
24. claim API различает non-empty token, strict `false` contention и strict
    `null` storage unknown; unreadable/unwritable state даёт bounded diagnostic;
25. `run()` при `null` не вызывает handler/fast write/replacement mutation и
    возвращает retryable refusal, а `false` сохраняет ordinary-contention
    semantics;
26. оба replacement sweep consumers при `null` не restore/erase/activate/clear
    keys и оставляют generation retryable;
27. release принимает только exact non-empty owner token; null/false/empty и
    чужой token не снимают claim.

## Required mutations

Каждая mutation обязана сделать named production-path test RED:

- удалить third-torrent ownership query;
- превратить ownership RPC fault в empty claim set;
- retain-ить initial `claimed`, а после исчезновения owner удалить formerly
  protected object, либо terminally consume initial `unknown` как protected;
- включить exact OLD predecessor в other-owner set;
- исключить foreign occupant только из-за совпадения OLD/NEW hash;
- пропустить unresolved lexical claim отсутствующего public name;
- удалить final ownership revalidation;
- вернуть symlink bridge после quarantine;
- удалить quarantined payload при post-capture `unknown`;
- не восстановить exact object при post-capture `claimed/unknown`;
- считать failed/occupied restore successful completion;
- удалить/потерять quarantine после simulated crash вместо restore-before-scan;
- удалить parent lexical-overlap check;
- заменить component-aware overlap byte-prefix сравнением;
- вернуть recursive delete либо symlink bridge для parent quarantine;
- принять OLD unknown/present как absent;
- принять wrong NEW marker/record;
- сгруппировать generations только по hash;
- разрешить token replace/copy;
- dispatch v3 под legacy name или v2 под cleanup name;
- заменить physical identity lexical equality;
- consume после unlink/rmdir/recovery/identity failure;
- разрешить base в reservation/rmdir/traversal;
- убрать successor alias protection;
- перенести publication после marker clear;
- удалить real P0 prepare/publish/cancel call, оставив helpers зелёными;
- убрать durable retry или считать launch acknowledgement cleanup success;
- arm/kick только после publication либо принять schedule RPC вместо real child
  ack до OLD erase;
- убрать rutracker-init rearm для disabled erasedata/Ratio;
- принять claim через `!== false` либо обработать только `=== false`, тем самым
  разрешив `null` mutation;
- превратить `null` storage failure в success/ordinary contention и скрыть
  bounded consequence diagnostic;
- разрешить run или любой из двух sweep consumers продолжить после `null`;
- разрешить untokened/null/false/empty release;
- перенести P1 `getSource`/parsed-`Torrent`/metadata-only/fast-write hunks только
  потому, что их filenames входят в P0 allowlist;
- вернуть identity dependency на `XMLRPCPathResolver`.

## Container verification checkpoint

Проверка выполнена только на immutable `git archive` exports exact base
`755404f3e38af98b6901852b35be10fb9659ffd3`, initial donor checkpoint
`edcea5a96332835335199ef6d33e667e32dfbaf6` и current handoff HEAD
`24891da9edddc252835493a4100e966dcd50cdf9`. Все mounts read-only, uid 1000,
`--network none`; live rTorrent и production data не затрагивались.

Exact upstream `CheckerTest.php` в shipped PHP 8.5 прошёл **31 tests / 0
failures**. На current `24891da9` в каждом из official PHP 7.4/8.1/8.5
containers
шесть focused suites (`AtomicOwnership`, `Checker`, `Entrypoints`,
`ProjectionContract`, `State`, `UpdatePass`) дали суммарно **317 / 0**:
18 + 130 + 22 + 6 + 15 + 126.
Отдельный `RemoveWithDataTest` дал **1305 assertions / 0 failed / 0 fatal** в
PHP 7.4/8.1, shipped PHP 8.5 и rt21 runtime на том же current ref. Более
широкий offline `tests/plugins/rutracker_check/run.php` на official PHP 8.5 дал
19 suite summaries, **700 tests / 0 failures**, exit 0. Initial `edcea5a9`
checkpoint был 312/0 и 695/0; delta +5 — пять новых tests current commit, не
regression.

Fresh exact-base probes повторили оба production defects:

```text
shared=deleted orphan=deleted base=kept requests=0 log=0 third_claim=declared
old=kept base_mode=0500 requests=0 log=0
```

Первый probe доказывает cross-torrent deletion и отсутствие fleet query;
второй — отсутствие durable retry/default-visible consequence после failed
unlink. Shipped image не содержит `tokenizer`, поэтому `EntrypointsTest` там
имеет ожидаемый image-gap 8 failures на `token_get_all()`; тот же export дал
22/0 в official PHP 7.4/8.1/8.5.

Эти GREEN не доказывают будущий контракт. В donor ещё нет OLD/NEW-aware fleet
tri-state, no-bridge/post-capture quarantine, restore/crash/parent-overlap RED,
pre-erase drain acknowledgement и rutracker-init restart rearm; donor даже
публикует symlink bridge и kick-ает только после publish. Current donor уже
содержит token/false/null claim seam и green sweep cases, но exact P0 hunk carve,
все claim mutations и P1 exclusions ещё не построены поверх final A. Поэтому
все 27 пункта matrix выше остаются обязательной implementation acceptance, а
каждый новый production rule обязан сначала дать named RED. Full
daemon/restart/real scheduler-child проверяется только после реализации в
disposable `tasks/rt-lab.sh`.

## Verification and numstat policy

Donor `RemoveWithDataTest`: PHP 8.5 — 204 methods / 1305 passed / 0 failed;
PHP 7.4 non-root — те же 204 / 1305 / 0. Эти greens подтверждают runtime
fixture, но не correctness: third-torrent owner в них отсутствует.

Aggregate 7-path donor `+11165/-193` запрещён как estimate: он смешивает A, C,
Plan-2 refactors, rejected core identity owner и unrelated fixes.

После final A:

1. package delta всегда измерять exact 20 paths как
   `final-A-tip..P0-C-tip`;
2. если PR временно target-ит `upstream/master` до merge A, публиковать два
   разных блока: stacked P0+C delta выше и полный
   `upstream/master..P0-C-tip` PR diff, который включает prerequisite A.
   Полный PR diff нельзя подписывать как numstat P0+C;
3. проверить оба применимых диапазона через `--numstat`, `--name-status`,
   `--stat`, `--check`;
4. отдельно назвать hunk owner каждого общего с A файла;
5. пройти non-root permissions, PHP 7.4 focused/lint, full PHP 8.1/8.5,
   PHPStan, mutations и independent whole-file review.

## Design freeze and later implementation gates

- [x] controlling plan удалил standalone C и уменьшил queue 19 → 18;
- [x] final A остаётся единственным filesystem identity owner; package начинает
  реализацию только от его final tip;
- [x] tri-state + no-bridge quarantine закрывает OLD/NEW exclusion, prepare,
  delayed consume, unknown, drift и post-observation race;
- [x] combined allowlist заморожен: exact 20 paths;
- [x] natural RED/mutation matrix и explicit exclusions заморожены.

Это design handoff, не утверждение о готовом коде. Implementation acceptance
остаётся RED→GREEN работой:

- [ ] named natural RED падают на final A по ожидаемой причине без preceding
  fatal;
- [ ] production/test diff ограничен exact 20 paths и все mutations убиты;
- [ ] runtime/non-root/PHP 7.4/8.1/8.5/PHPStan/whole-file verification зелёная.

Independent approval выполнен. Combined P0+C готов к RED-first implementation
handoff после A; production implementation ещё не начиналась.
