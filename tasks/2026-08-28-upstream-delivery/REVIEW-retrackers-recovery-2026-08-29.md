# `up/retrackers-recovery` — закрытый технический контракт

Дата: 2026-08-29; contract correction: 2026-08-31. База исследования:
`upstream/master=755404f3`; immediate implementation parent — final
`up/scgi-transport=4682a761`. Опубликованный fork baseline
`43484fba`. Fork, включая новый partial implementation
`Fix retrackers replacement handoff`, и исторический `FINDINGS.md`
использованы только как donor гипотез; каждый race и daemon primitive
перепроверен независимо по исходникам и в disposable rTorrent 0.9.8 и 0.16.21
labs. Historical research base содержала predecessor
seven-argument/array-returning `rSCGITransport::send()`. Actual implementation
parent `4682a761` и fork master уже содержат отдельно verified final
nine-argument/string-returning interface; package 5 строится и проверяется
только против него.

## Verdict: DESIGN APPROVED — implementation pending

Шестипутевой recovery package нужен, но current donor переносить нельзя. Его
главная ошибка — трактовать return `rTorrent::sendTorrent()` как daemon
acknowledgement. Return вычисляется локально после XMLRPC dispatch; actual
`DownloadFactory` load завершается позже и может быть отвергнут.

Approved design поэтому разделяет четыре доказательства:

1. old-object ownership: native `d.local_id` + exact dedicated marker + state;
2. erase commit: один daemon-side conditional stop/erase с surviving subkeys в
   одном private multi-ledger и exact sentinel;
3. load ordering: armed file-backed load wrapper ставит transaction claim
   первой plugin-supplied creation command, а one-shot daemon scheduler receipt
   даёт post-dispatch fence;
4. new-object commit: distinct daemon-scheduler fence, exact ready marker,
   exact insert-hook acknowledgement и доказанный pre-`inserted_new`
   restoration baseline; последующие mutation новой generation authoritative.

Hook никогда не вызывает `d.stop`, `d.close` или `d.erase`. Поэтому failure
запуска worker не оставляет torrent остановленным. Commit `43484fba` уже
реализует безопасный generation handoff и quiesce+inner-CAS predecessor, но не
surviving receipts, armed scheduler fences, exact candidate acknowledgement,
owned partial cleanup или multi-profile gate этого контракта. Поэтому package
ещё не реализован, а current `42 tests / 0 failures` остаётся false-green
characterization, не closure.
Design approval сам по себе не закрывает implementation package и не даёт
authority на push. Из первоначальных 18 packages реализации 1–4 уже закрыты;
текущий счёт — **14 pending**, и retrackers остаётся одним из них до отдельного
RED→GREEN исполнения.

## Contract correction 2026-08-31: exact six-path scope

Первоначальный exact-five scope был внутренне противоречив: новый
side-effect-free `update.php` должен импортироваться из sequence-test, но тот
же контракт одновременно требовал byte-for-byte неизменности всего test file.
Без отдельного import sentinel test либо запускает production entrypoint, либо
не проверяет production helpers. Исправленный package меняет ровно шесть
путей:

1. `plugins/retrackers/init.php` — ledger/profile install protocol, generation
   handoff, loop suppression и background launch only; no stop;
2. `plugins/retrackers/done.php` — acknowledged delete обоих historical own
   hook keys, не overwrite их no-op actions;
3. `plugins/retrackers/run.sh` — quoted cwd/argv и `exec`, без собственного
   backgrounding;
4. `plugins/retrackers/update.php` — side-effect-free importable constants and
   canonical functional/defer/safety hook builders, CLI worker, immutable
   source, conditional commit, confirmation и rollback;
5. `tests/plugins/retrackers/UpdateTest.php` — focused worker/hook/shell
   contract;
6. `tests/plugins/retrackers/RetrackersUpdateSequenceTest.php` — только
   import/bootstrap preamble; сама class body и все 12 predecessor methods
   остаются byte-identical.

Не входят: `plugins/retrackers/guard.php`, новые service-wrapper conditions,
`.chk-meta`/`chk-meta-old` recovery и их tests. Research prerequisite
`755404f3` не содержит `guard.php`; implementation режется от final
`up/scgi-transport`, чтобы не дублировать SCGI framing/timeouts. Published
`43484fba` используется только как independently audited donor и не расширяет
package scope. `init.php` и `done.php` загружают side-effect-free definitions из
`update.php`; CLI execution находится за явным entrypoint, поэтому include не
читает argv/config/filesystem/RPC. `init.php`, `done.php`, `UpdateTest.php` и
`RetrackersUpdateSequenceTest.php` определяют `RETRACKERS_IMPORT_ONLY` до
include. Sequence-test явно загружает `retrackers.php`, затем `update.php`.
Service label/marker predicates принадлежат
отдельному P3 package и здесь не создаются, не меняются и не тестируются.

Upstream `tests/plugins/retrackers/RetrackersUpdateSequenceTest.php` теперь
является шестым target path. Full-file SHA не является invariant, потому что
bootstrap preamble обязан измениться. Вместо него заморожены два проверяемых
инварианта:

- registration-aware sorted 12-name SET SHA-256
  `0ee7b35f9cda898d00e963b7e23aff02351e3653db21bbf2e99e31a34d5c7044`;
- bytes от строки `class RetrackersUpdateSequenceTest extends TestCase` до EOF
  SHA-256
  `f0dac045fa3b9e98172132977e05fa14b7f091d1b9779a989d8b1d047fecc8f3`.

Все 12 methods обязаны выполниться и пройти. Допустимое изменение preamble:
`RETRACKERS_IMPORT_ONLY`, explicit `retrackers.php`, затем `update.php`;
никакого второго helper implementation в test нет:

```text
testTheScriptsHelpersAreTheRealOnes
testAnnounceOnlyTorrentGainsAnAnnounceListEndingWithTheAddition
testAddToBeginPutsTheAdditionFirst
testATrackerlessTorrentGetsAnnounceAndAnnounceList
testATrackerAlreadyPresentIsNotAddedTwice
testATorrentThatNeedsNothingIsNotRewritten
testAnEmptyConfigurationChangesNothing
testDeletingEveryTrackerInAGroupRemovesTheGroup
testAdditionAndDeletionTogetherOnASessionTorrent
testASessionTorrentThatNeedsNothingKeepsItsRtorrentKey
testClearTrackerDropsAGroupItEmpties
testDeleteTrackersMatchesOnASubstringCaseInsensitively
```

Исторический `+1493/-45` не является final numstat; точный delta считается от
final predecessor `up/scgi-transport=4682a761`.

## Перепроверенные verdicts

| Гипотеза | Вердикт | Основание |
|---|---|---|
| hook stop + initial worker fault оставляет started torrent stopped | **ПОДТВЕРЖДЕНО, production-reachable** | Upstream hook stop-ит до shell; initial RPC/source failure не гарантирует restart. |
| stop можно оставить в hook и только улучшить rollback | **ОПРОВЕРГНУТО** | Shell/PHP/fork/exec failure происходит до recovery code. Stop переносится в validated worker commit. |
| `custom4` state достаточно как generation identity | **ОПРОВЕРГНУТО** | State повторяется, custom field может пережить reload; stale same-hash worker не отличает object. |
| `system.time_usec` уникален для generation | **ОПРОВЕРГНУТО** | В live 0.16.21 из 200 calls только 78 unique, 122 adjacent duplicates. |
| private epoch+counter обязателен | **ОПРОВЕРГНУТО** | Native immutable `d.local_id` уже различает download objects без daemon registry. |
| `d.local_id` — математически unique ID | **ОПРОВЕРГНУТО** | Это 96 random bits после 8-byte peer prefix; contract называет его collision-resistant identity. |
| `d.local_id` годится в ownership tuple | **ПОДТВЕРЖДЕНО** | Getter immutable, setter отсутствует, новая same-hash generation получает новое значение; marker даёт второй exact predicate. |
| `sendTorrent()` hash подтверждает load | **ОПРОВЕРГНУТО, blocking** | Hash вычислен из local metainfo после accepted dispatch, до async daemon parse. |
| plain `d.hash` подтверждает нужную generation | **ОПРОВЕРГНУТО** | Candidate/original/foreign имеют один info-hash. |
| duplicate same-hash load может переписать marker существующего object | **ОПРОВЕРГНУТО** | `download_add` rejects duplicate до creation commands; rt21 runtime сохранил marker/local_id byte-for-byte. |
| tracker mutation меняет info-hash | **НЕДОСТИЖИМО В ПРОДЕ при target raw-envelope rewriter** | Announce keys вне `info`; target переносит source `info` value bytes без изменений и повторно требует exact raw SHA-1. |
| `Torrent` byte-semantically сохраняет `libtorrent_resume` | **ОПРОВЕРГНУТО, production-reachable** | `decode_integer()` превращает все integers в float; large resume counters re-encode-ятся с потерей precision/экспонентой. Target никогда не сериализует decoded resume и переносит его raw value slice byte-for-byte. |
| username даёт shell injection через quoted variable expansion | **ОПРОВЕРГНУТО** | `User::getUser()` canonicalizes; shell не reparses substituted metacharacters. |
| PHP path с whitespace/quotes безопасен сейчас | **ОПРОВЕРГНУТО** | Current unquoted shell/script composition ломает argv. |
| из torrent object можно вывести его ruTorrent profile/config owner | **НЕДОСТИЖИМО В ПРОДЕ** | Shared daemon не хранит profile identity; `retrackers.dat` при этом per-profile. Автовыбор first hook поменял бы policy на lexical winner. |
| два retrackers profiles на одном daemon можно оставить как есть | **ОПРОВЕРГНУТО, production-reachable** | В pre-`43484fba` grammar оба hooks могли запустить работу. В `43484fba` lexical-first hook запускает единственного worker с empty ack, а следующий hook меняет ack на marker, поэтому worker ownership-reject-ит себя; policy всё равно не имеет canonical profile owner. Package fail-closed при ambiguity. |
| replacement сохраняет runtime settings torrent | **ОПРОВЕРГНУТО для `43484fba`, production-reachable** | Both-daemon capture потерял `custom2`, `custom3`, arbitrary `d.custom`, priority и вернул default priority. Target exact восстанавливает operator/plugin baseline creation commands до ready-last boundary; штатные `inserted_new` hooks и последующие user/plugin writes новой generation затем authoritative. Прочие session counters остаются явным compatibility non-goal, а не скрытой гарантией. |
| replacement сохраняет disabled tracker state | **ОПРОВЕРГНУТО для `43484fba`, production-reachable** | Direct UI-visible `t.is_enabled.set=0` на обеих families после erase/reload тех же bytes вернулся в `1`. Target использует stable exact-URL identity, old CAS и pre-event creation disable; mixed-state duplicate URL видимо отвергается до mutation. |
| transaction marker можно оставить после successful terminal outcome | **ОПРОВЕРГНУТО, production-reachable** | Non-empty marker навсегда suppress-ит ordinary branch; без live capability replay не меняет ack, но object остаётся quarantined. Every successfully released surviving generation conditional clear-ит marker/ack; ambiguous/partial generation остаётся видимо quarantined. |
| fresh absence отличает наш erase от concurrent user delete | **ОПРОВЕРГНУТО, blocking** | Object-local marker исчезает вместе с download; нужен surviving daemon receipt. |
| outer CAS сразу перед `d.stop`/`d.erase` закрывает hook races | **ОПРОВЕРГНУТО, blocking** | `d.stop` synchronous вызывает `event.download.paused`, а `d.erase` внутри вызывает `close()` и `event.download.closed`; оба hook могут изменить frozen tuple до removal. |
| `d.state=1` означает, что replacement должен снова стартовать torrent | **ОПРОВЕРГНУТО, production-reachable** | На обеих families `d.pause` оставил tuple `(state,is_active,is_open)=(1,0,1)`; `load.start` сделал бы его `(1,1,1)`. Target видимо отказывает paused/unsupported lifecycle до mutation. |
| explicit `d.close` + inner post-quiesce CAS закрывает paused/closed races | **ПОДТВЕРЖДЕНО** | На обеих daemon families `close` синхронно quiesce-ит object и завершает hooks; повторный internal close в `erase` при `is_open=0` возвращается до closed hook. |
| `state=0,is_active=0,is_open=0` достаточно для eventless repeated close | **ОПРОВЕРГНУТО** | `pause()` сначала сбрасывает nonzero `d.hashing` и вызывает `event.download.hash_removed`; inner CAS дополнительно требует exact `d.hashing=0`. |
| worker может увидеть present-target intent в transaction-owned absent gap | **НЕДОСТИЖИМО В ЭТОМ SIX-PATH SCOPE** | Любой `d.stop`/pause/start/setter/erase по уже absent hash не оставляет intent; нужна сериализация target endpoint либо durable journal, который пишет сам command path. |
| worker может отличить user `d.stop` после собственного partial stop | **НЕДОСТИЖИМО В ЭТОМ SIX-PATH SCOPE** | Повторный stop idempotent и не меняет full tuple; контракт fail-closed не restart-ит object. |
| per-transaction `method.insert` можно убрать через `method.erase` | **ОПРОВЕРГНУТО, blocking** | Обе версии удаляют только command-map slot, но оставляют object-storage value; имя нельзя вставить повторно до daemon restart. |
| один private `multi` ledger даёт reusable receipts | **ПОДТВЕРЖДЕНО** | На 0.9.8/0.16.21 exact subkey переживает `d.erase`, двухаргументный `method.set_key` удаляет только его, key сразу переиспользуется. |
| отдельный RPC reply после load является causal fence | **ОПРОВЕРГНУТО, blocking** | SCGI callbacks от independent connections могут обгонять уже принятый request. Нужен receipt от заранее зарегистрированного daemon scheduler item с timestamp строго позже factory tasks. |
| claim является первой daemon mutation candidate | **ОПРОВЕРГНУТО** | Factory defaults/resume, `DownloadList::insert` и `event.download.inserted` выполняются до creation-command list. Claim — только первая **plugin-supplied** creation command; pre-claim object не owned и не cleanup-ится. |
| in-memory receipt/rollback crash-safe | **ОПРОВЕРГНУТО** | Daemon/process crash после erase до confirmed load требует durable journal/worker wake вне этого package. |
| current service guard готов | **ОПРОВЕРГНУТО** | Non-empty-view runtime и nested grammar не доказаны; owner — P3. |

## Native object identity

На rTorrent 0.9.8 и 0.16.21 `d.local_id` registered read-only и возвращает
canonical uppercase 40 hex. Соответствующие libtorrent v0.13.8 и v0.16.21 при
каждом `download_add` строят 20-byte peer id:

```text
8-byte PEER_NAME + 12 bytes from random_device-seeded mt19937
```

Последние 12 bytes дают nominal 96-bit collision space. Это не monotonic и не
formal zero-collision guarantee. Contract принимает collision-resistant tuple:

```text
exact d.local_id
+ exact d.custom[retrackers-recovery]
+ exact captured d.state
```

Same-hash object без marker не может быть принят, а session-restored marker с
новым local id также не может быть принят. Transaction loads дополнительно
имеют independent 128-bit PHP token.

Disposable 0.16.21 evidence:

- 256 erase/reload generations одного info-hash дали 256 distinct local ids;
- stale old-local-id conditional erase/start после same-hash reload оба вернули
  `SKIPPED`; foreign object сохранился;
- direct duplicate load сохранил existing marker и local id;
- timestamp comparator для этой роли провалился.

## Hook and shell contract

Dedicated generic custom keys:

```text
retrackers-recovery       ownership/handoff/transaction marker
retrackers-recovery-ack   exact inserted_new hook acknowledgement
```

Original handoff format:

```text
v1:original:<0|1>:<D.LOCAL_ID_UPPER40>:<SHA256_CANONICAL_USER_LOWER64>
```

Последнее поле bind-ит handoff к exact canonical argv user, не вынося
username в daemon marker. Worker требует exact lowercase SHA-256 от
canonical `$argv[2]`.

`init.php` устанавливает один functional action под существующим
`tadd_trackers1<user>`. Единственный canonical builder остаётся в
side-effect-free importable части `update.php`:
`retrackersBuildInsertAction()` строит приведённую ниже grammar, а
отдельный `retrackersBuildSafetyOnlyInsertAction()` строит ту же ack/legacy
часть без profile-specific launch:

1. non-empty dedicated recovery marker означает owned handoff/load: hook не
   запускает worker. Если ack уже exact равен marker, body no-op. При exact
   empty ack hook копирует marker **только** когда private ledger содержит exact
   key, равный live marker; candidate/rollback arm создаёт такую marker-specific
   capability под live `wa`, terminal cleanup её удаляет. Wrong non-empty ack
   никогда не overwrite-ится даже при live capability и остаётся quarantine
   evidence. Любой другой marker остаётся untouched quarantine. Для original equal marker/ack даёт
   idempotence между multi-profile hooks, для candidate/rollback capability-
   guarded copy является commit acknowledgement;
2. marker empty + exact `custom3 === "1"` означает shipped cross-plugin reload
   suppression (`plugins/edit` -> `autotools` -> retrackers): hook clear-ит
   `custom3`, не пишет marker/ack и не запускает worker;
3. marker empty + любое другое `custom3` означает ordinary insert:
   при shared-daemon ambiguity body равен no-op; иначе он формирует
   exact original handoff, сначала ставит synchronous daemon-ledger
   `wh:<D.LOCAL_ID>=1` hook-active receipt, затем
   `wp:<D.LOCAL_ID>=1` launch-pending lease, пишет exact handoff в ack и
   recovery key и только после всех четырёх записей вызывает
   `execute.throw.bg`, не меняя `custom3`. Последней command после возврата
   launch является двухаргументный delete exact `wh`; launch throw/unknown tail
   оставляет `wh` sticky и запрещает hook erase до daemon restart;
4. worker получает hash, canonical user и exact live handoff;
5. action не содержит `d.stop`, `d.close`, `d.erase` ни в одной ветке.

Порядок branch-ей обязателен: transaction marker выигрывает даже при
`custom3=1`, поэтому owned candidate сохраняет exact captured value и получает
ack; legacy-clear применяется только при empty marker. Original
marker при этом уже имеет equal ack до background launch, поэтому
любой последующий handler только idempotently повторит ack.
Оба generic keys
зарезервированы этим plugin. Ordinary non-session metainfo не
может пронести stale `rtorrent` customs: daemon strips that section, а
transaction builder сам first-write-ит recovery marker. `custom1`, `custom2` и
non-suppression `custom3` остаются пользовательскими данными и сохраняются
exact. Transaction ack capability check и legacy-clear предшествуют ordinary
branch. Этот package не вводит service wrapper: upstream private/name gates
остаются worker-side eligibility, а P3 может добавить отдельную service policy
только своим контрактом.

### Shared-daemon profile gate

`rRetrackers::load()` читает per-profile `retrackers.dat`, но event map
принадлежит shared rTorrent daemon. Ни torrent object, ни daemon не
дают canonical profile owner; агрегировать потенциально
конфликтующие add/delete policies без новой operator policy нельзя.
Поэтому package не выбирает lexical first hook и fail-closed при
более одного retrackers profile на daemon.

Install protocol имеет один frozen total order. Каждый variable-size request
сначала полностью строится как exact XML, обязан помещаться в
`rTorrentSettings::maxContentSize()` и отправляется одним unsplittable
`system.multicall`; implicit chunking `rXMLRPCRequest` запрещён.

Каждый упомянутый ниже historical stable scan означает один и тот же private
`HistoricalBindingSampleV2`: два **отдельных** complete RPC back-to-back,
каждый — один unsplittable ordered five-member `system.multicall`, который в
одном non-yielding daemon turn читает event list/map, RecoveryRows4 и private
ledger list/map. Это не две copies в одном request и не four-RPC bracket.
Sample 1 fully consumed/validated, его RAW освобождается, остаются только
bounded digest/count/phase/decision fields; затем sample 2 independently
проходит те же gates. Нельзя смешивать rounds, выдавать partial slots либо
вызывать consumer после первого success/второго failure. Только sample 2
достигает init/done после same codec-owned family, digest, counts, membership,
phase, owner, epoch и recovery decisions. Exact request/digest/bounds заморожены
в codec section ниже.

Этот protocol начинается только на **fresh full-service generation**. Operator
останавливает весь service/container/process namespace, доказывает zero old
`plugins/retrackers/update.php`/`run.sh` workers и quiesced web init/done,
запускает fresh daemon, доказывает zero retrackers event names, recovery
marker/ack evidence и отсутствие `rr.receipts.v1`, затем один раз inserts empty
private `multi` ledger, validates privacy/empty list+map и берёт BOOTSTRAP pair.
Plain rTorrent restart недостаточен: historical hash-only PHP worker мог
пережить daemon. `rr.receipts.v1` сохраняет name только потому, что никакой Task
1 ledger schema ещё не deployed/committed и fresh boundary исключает old object
storage; package никогда не erase/recreate-ит его. Если несовместимый v1 когда-
либо deployed, следующий package обязан взять новое method name и explicit
migration.

После boundary действует visible **exclusive current writer** prerequisite:
только callbacks/builders, принадлежащие четырём production paths package,
могут менять reserved
`tadd_trackers1*|tadd_trackers2*`, `pf:*`, `pv:*`, `ma:1`, `ta:1` и extended
`to:*`. HTTP XMLRPC не может вызвать private ledger, но trusted SCGI сам по себе
не защищает от другого local writer. Self-consistent trusted overwrite action +
matching claim + epoch недетектируем и является
`profile-binding-writer-untrusted`, не обещанной attack detection. При
невозможности обеспечить exclusivity plugin visibly disables и требует новый
full-service boundary.

Canonical profile bytes и persistent binding:

```text
canonical user  = ^[a-z0-9_-]*$
H(U)            = lowercase SHA-256 exact canonical-user bytes
key1(U)         = tadd_trackers1 || U
key2(U)         = tadd_trackers2 || U
pf:H(U):H(F(U)) = explicit string "1"
pv:<epoch32>    = explicit string "1"
to:<token32>:(i|d|c):H(U) = explicit string "1"
```

`F(U)` — exact once-decoded production functional action bytes, generated
owning profile's frozen builder with its own `Utility::getPHP()`; foreign
reader never loads foreign config and never parses command AST. `H(F)` hashes
entire action string. At most one `pf` per user hash; every claim has exactly
one complete same-suffix pair. Stable IDLE claim hashes visible key1. The only
exceptions: prepared `pf` under owner `i` while key1/key2 are `D/D`, и retained
`pf` under owner `d` while pair is `S/S`. Containment deletes all claims.
Malformed/singleton/extra pair, suffix/hash collision, orphan/duplicate claim or
action-only/claim-only drift is corruption.

Exactly one persistent `pv:<32 lowercase hex CSPRNG epoch>` exists after first
bootstrap acquire, including zero-profile idle after done. Every callback that
creates/changes/deletes retrackers action, `pf`, lifecycle owner, `ta` or `ma`
rotates it. Non-bootstrap callback freezes sample-2 `oldPv` and fresh
`newPv=bin2hex(random_bytes(16))`, requires old present/new absent/new unequal,
installs any missing safety gate first, then **deletes old pv before setting new
pv and before first protected mutation**. Every later profile/lifecycle callback
CASes its sampled pv; cooperating mutation therefore makes delayed callback
no-op. Zero pv after old delete or fresh pv after new set is safe/sticky under
`ta|ma`, never retried/released. A callback never deliberately sets `newPv`
equal to its sampled current `oldPv`. No epoch history set exists: reuse of any
earlier now-absent value is unobservable and is excluded only by the explicit
negligible 128-bit CSPRNG non-reuse assumption. Stable self-consistent trusted
forgery remains the exclusive-writer violation above. Operational receipt-only
callbacks do not rotate pv, but retain their existing exact owner/receipt
predicates.

Single owner key is authoritative; no second owner alias exists. `ta:1` present
iff exactly one extended owner exists. Modes `i` and `d` require no `ma`; `c`
requires `ma`. Completed contained state has `ma` but no `ta`/owner. Old
token-only owner names are invalid migration residue.

Six and only six stable semantic phases:

| Phase | `pv/ma/ta/owner` | Exact profile/claim state | Result |
|---|---|---|---|
| `BOOTSTRAP` | no pv/ma/ta/owner | zero profiles/pf, empty ledger/recovery evidence | first init may bootstrap-acquire |
| `IDLE_CURRENT` | one pv; no ma/ta/owner | zero or one `F/S` pair; exact one pf iff pair exists | init/done may proceed; absent-own done no-op |
| `INIT_OWNER` | one pv; no ma; ta + exact `to:T:i:H(U)` | owner `D/D` + prepared pf; zero or one foreign exact `F/S` + pf | foreign caller BUSY; owner final-installs or contains |
| `DONE_OWNER` | one pv; no ma; ta + exact `to:T:d:H(U)` | exactly one owner `S/S` + retained pf; no foreign profile | foreign caller BUSY; owner drains/deletes |
| `CONTAIN_OWNER` | one pv; ma+ta+exact `to:T:c:H(U)` | at least two profiles, all `S/S`, zero pf | owner drains; no acquire |
| `CONTAINED` | one pv+ma; no ta/owner | at least two profiles, all `S/S`, zero pf | sticky disabled; full-service restart |

Stable idle never accepts two functional profiles: second-profile init first
creates its own `D/D` INIT_OWNER, then containment makes every pair `S/S`,
deletes all `pf`, transitions to `c+ma`, drains and releases to CONTAINED.
`i+ma`, `d+ma`, `c` without ma, claims under c/contained, foreign profile during
done, two idle functional profiles, incomplete/unbound pair and missing/multiple
epoch are invalid, not alternate phases.

Refusal precedence is exact: transport/full-document/schema/bound failure;
ledger grammar/value/privacy => `receipt-ledger-corrupt`; family or pair drift =>
`profile-binding-unstable`; stable valid foreign owner => `lifecycle-busy`;
stable phase/claim/action violation => `historical-hook-restart-required`; valid
CONTAINED => visible contained/restart-required. BUSY никогда не mask-ит
malformed ledger или unbound action.

Frozen profile callback order and crash prefixes:

1. BOOTSTRAP acquire predicates absent pv/pf/ma/ta/owner and own pair, then in
   one callback sets `ta`, fresh pv, `to:T:i:H(U)`, prepared pf, key1=`D`,
   key2=`D`, returns typed `ACQUIRED`. A competing bootstrap loses on ta/pv.
2. Current init acquire from IDLE predicates sampled pv plus absent ma/ta/owner,
   sets ta, deletes old pv, sets fresh pv, sets i owner, sets new prepared pf,
   deletes changed old pf, then writes D/D. Same-pv loser is BUSY/STALE with
   zero mutation. Per-user config rotation is claim+future action binding, not
   foreign config read.
3. Final init under exact i owner/ta/pv and zero dirty/worker predicates deletes
   old pv, sets fresh pv, writes exact claimed F then S, deletes owner and
   deletes ta last. Fault prefix keeps functional action gated by ta.
4. Done acquire only from own IDLE F/S predicates sampled pv/no ma/ta/owner,
   sets ta, rotates pv, sets d owner, writes S/S and retains old pf. Absent-own
   done is no-op. After exact zero-active/clean gates final done rotates pv,
   deletes key1, key2, exact pf, owner, then ta last.
5. Multi-profile containment only from INIT_OWNER with one foreign F/S. With ta
   already gating, it rotates pv, sets ma **before any overwrite**, changes i
   owner to c, overwrites every detected pair S/S, deletes every pf and returns
   exact names/counts. Any fault prefix is safe/sticky and
   `shared-daemon-owner-ambiguous-uncontained`; no `i+ma` prefix is accepted.
   After wh/wa/wp/dq/di drain, c cleanup predicates exact phase/current pv,
   rotates pv, deletes c owner and ta last; ma and all S/S pairs remain.

Every unknown state-writing reply is one-shot: no retry, revoke, blind release
or functional activation. `ta` is the only allowed operation before epoch
deletion on acquire and immediately makes functional hooks defer; `ma` is set
before containment overwrite. Crash after ta-only, old-pv delete, new-pv set,
owner/claim/action change or owner-delete-before-ta leaves a sticky invalid/busy
prefix which old-pv callbacks cannot pass. Transaction marker ack and legacy
`custom3=1` clear remain earlier than ma/ta and active so an already-started
transaction can terminally classify itself.

`di:<LOCAL_ID>` хранит только literal `1`; profile user/config остаются у init,
а hash находится fresh **direct**
`d.multicall2("", "main", "d.hash=", "d.local_id=")` scan-ом по immutable
local id. Его restricted plan принимает `N >= 0` rows ровно из двух explicit
uppercase 40-hex strings `(hash, local-id)`: zero matches означает stale,
ровно один match даёт paired hash, multiple matches fail-closed. Подмена на
`d.multicall` является named 0.9.8 RED; `system.multicall`-member envelope для
этого scan не используется и отвергается. Для exact match owner-CAS replay
требует empty recovery marker и current
non-suppression `custom3`, ставит
`wh`, затем `wp`, ack, marker, удаляет own `di`, launch-ит canonical argv и
tail-delete-ит `wh`. Absent или
different-local-id object делает `di` stale и owner-delete-ится без launch;
changed service/custom3 predicate также no-launch и classified. Unknown replay
повторяется под тем же lifecycle owner. Every defer callback ставит `dq` **до**
`di`, а consumer clear-ит `dq` **до** следующего fresh `di` scan. Поэтому event
до acknowledged clear оставляет `di`, видимый post-clear scan-у; event после
clear заново
ставит `dq`; event между clean scan и final callback reinstates `dq` и отменяет
release; event после callback видит fully installed functional hook и absent
`ta`. Final release callback проверяет `dq` в том же daemon turn. Одноразовый
`inserted_new` в arbitrarily long init window не теряется.

`done.php` не использует `getOnInsertCommand(..., cat=)`: такой call лишь
overwrite-ит value. Он берёт complete HistoricalBindingSampleV2 pair; absent-
own IDLE — no-op, own F/S — exact d-acquire order выше, valid foreign owner —
BUSY, а stable malformed/unbound state refuse-ится по frozen precedence.
Unknown acquire никогда не retry-ится; exact d owner сразу запрещает новые
ordinary launch/defer, сохраняя marker/ack branch уже active workers.

Done сначала ждёт absence всех `wh`: hook, зависший между launch и tail, нельзя
erase-ить или подменять предположением. Затем owner-CAS drain-ит `di/dq` без
launch и atomically cancel-ит каждый `wp`: если worker adopt линейризовался
раньше, видны `wa` и absent `wp`; если done первый, он при exact present
original marker=ack+local-id clear-ит ack, marker, затем `wp`, и delayed CLI уже
не может adopt. Changed/absent object теряет только pending key и остаётся
видимо quarantined. Таким образом worker-never-started не оставляет вечный
`wp`, а done не стирает live `wh`/`wa`.

После cancellation done repeatedly валидирует ledger и может delete-ить hooks
только при zero worker/transaction keys `wa|ea`…`rf`; lifecycle owner/ta в этот
count не входят. Последний zero check и profile deletion используют exact
d-owner/current-pv callback order выше: rotate pv; двухаргументно delete key1,
key2 и pf; затем owner; `ta:1` last. Typed readback доказывает exact
IDLE_CURRENT zero-profile + one fresh pv. Delayed old-token/old-pv callback
no-op.

Done прекращает новые polling rounds после 5 seconds cumulative monotonic
poll/pause budget с 50 ms pause; каждый начатый RPC имеет отдельные transport
budgets ниже, поэтому literal wall-clock `<=5s` не обещается. Если `wh`, `wa` или любой
phase receipt не исчез, он не удаляет ack-capable hooks и не release-ит mutex:
остаются exact `safetyOnly+ta+extended-owner`, пишется visible
`hook-teardown-pending; daemon restart required after active recovery finishes`.
Это finite polling web path и safe sticky shutdown state. Crash/unknown
даёт `hook-teardown-unconfirmed`; stable contained state не допускает done до
full-service restart.

Nested grammar и production argv builder заморожены полностью. `Q(value)` — это
ровно существующий `rTorrent::quoteCommandArg(value)` из `php/rtorrent.php`,
который пакет не меняет. Он кодирует один rTorrent parser layer: сначала каждый
`\` заменяется на `\\`, затем каждый `"` на `\"`, после чего value окружается
literal double quotes. Эквивалентная frozen форма helper-а:

```php
return '"' . str_replace(
    array('\\', '"'),
    array('\\\\', '\\"'),
    $value
) . '"';
```

`$script = $rootPath.'/plugins/retrackers/run.sh'`, `$php = Utility::getPHP()`
и canonical `$user` freeze-ятся до request build. Script обязан быть absolute;
script/PHP — non-empty; ни одно static value не содержит NUL/CR/LF и не
начинается с `$`. Space, tab, comma, quote и backslash разрешены и проходят
`Q()`. Shell escaping здесь не применяется: `execute.throw.bg` получает argv
list, а не `sh -c` string.

Далее action строится **ровно** этими expressions; рекурсивный `Q()` показывает
каждый parser layer и не оставляет implementation placeholder:

```text
userToken = sha256(canonical user) as exact 64 lowercase hex
handoff = $cat=v1:original:,$d.state=,:,$d.local_id=,:,Q(userToken)
activeHookKey = $cat=wh:,$d.local_id=
pendingKey = $cat=wp:,$d.local_id=
deferredKey = $cat=di:,$d.local_id=
setActiveHook = $method.set_key=rr.receipts.v1,Q(activeHookKey),1
clearActiveHook = $method.set_key=rr.receipts.v1,Q(activeHookKey)
setPending = $method.set_key=rr.receipts.v1,Q(pendingKey),1
setDirty = $method.set_key=rr.receipts.v1,dq:1,1
setDeferred = $method.set_key=rr.receipts.v1,Q(deferredKey),1
defer = cat=Q(setDirty),Q(setDeferred)
setAck = $d.custom.set=retrackers-recovery-ack,Q(handoff)
setMarker = $d.custom.set=retrackers-recovery,Q(handoff)
launch = $execute.throw.bg={sh,Q(script),Q(php),$d.hash=,Q(user),$d.custom=retrackers-recovery}
ordinary = cat=Q(setActiveHook),Q(setPending),Q(setAck),Q(setMarker),Q(launch),Q(clearActiveHook)
deferOrOrdinary = branch=Q(method.has_key=rr.receipts.v1,ta:1),Q(defer),Q(ordinary)
ownerOrNoop = branch=Q(method.has_key=rr.receipts.v1,ma:1),Q(cat=),Q(deferOrOrdinary)
legacyOrOrdinary = branch=Q($equal=d.custom3=,cat=1),Q(d.custom3.set=),Q(ownerOrNoop)
copyLiveMarker = d.custom.set=retrackers-recovery-ack,$d.custom=retrackers-recovery
capabilityAck = branch=Q(method.has_key=rr.receipts.v1,$d.custom=retrackers-recovery),
                       Q(copyLiveMarker),Q(cat=)
emptyAckOrNoop = branch=Q(equal=d.custom=retrackers-recovery-ack,cat=),
                        Q(capabilityAck),Q(cat=)
idempotentOrCapabilityAck = branch=Q(equal=d.custom=retrackers-recovery-ack,d.custom=retrackers-recovery),
                                   Q(cat=),Q(emptyAckOrNoop)
action = branch=Q(d.custom=retrackers-recovery),
                Q(idempotentOrCapabilityAck),
                Q(legacyOrOrdinary)
safetyOnly = branch=Q(d.custom=retrackers-recovery),
                    Q(idempotentOrCapabilityAck),
                    Q(branch=Q($equal=d.custom3=,cat=1),Q(d.custom3.set=),Q(cat=))
deferOnly = branch=Q(d.custom=retrackers-recovery),
                   Q(idempotentOrCapabilityAck),
                   Q(branch=Q($equal=d.custom3=,cat=1),Q(d.custom3.set=),Q(defer))
```

Outer condition намеренно передаёт getter без `$`, чтобы `branch` исполнил его
как условие; eager `$d.custom=...` сначала превратил бы marker в строку, которую
`branch` ошибочно попытался бы разобрать как command. Marker/ack equality также
является command condition без eager `$` и имеет exact форму
`equal=d.custom=retrackers-recovery-ack,d.custom=retrackers-recovery`:
`$equal=$d.custom=...` измеренно fault-ится до capability branch. Legacy
equality отдельно имеет exact eager форму `$equal=d.custom3=,cat=1`. Второй
command-form equality exact сравнивает ack с `cat=` empty; поэтому wrong
non-empty value, включая строку `"0"`, не проходит по truthiness и не
перезаписывается. Последний launch argument
читает **уже записанный live marker**, а не повторно строит handoff в PHP.
Marker-present branch также передаёт live marker как dynamic ledger key; это
измеренная на 0.9.8 форма и не зависит от отсутствующего там `string.substr`.
Capability exact равна complete ready marker, поэтому unrelated live `wa` или
другая transaction не может authorize ack.
`activeHookKey`/`pendingKey`/`deferredKey` обязательно входят в
`method.set_key` через inner `Q()`: без него comma после `$cat=wh:|wp:|di:`
делит arguments и обе daemon families
fault-ят вместо создания full local-id key. Both-daemon runtime gate читает
exact resulting `wh:<LOCAL_ID>`/`wp:<LOCAL_ID>`/`di:<LOCAL_ID>` через
coherent ordered ledger multicall (`list_keys`, `get`, required `has_key`) и
требует `wh` absent после known launch tail.
`safetyOnly` не содержит profile path/user/launch и поэтому может одинаково
overwrite-нуть каждый detected retrackers key. Оба target builder-а находятся
в side-effect-free importable части `update.php`. Ongoing transaction ack и
legacy clear сохраняются, ordinary insert становится no-op.

Disposable 0.9.8 и 0.16.21 проверили исходные три branch результата
и дали RED для multi-profile ack/legacy races. Независимый exact-grammar probe
дополнительно отверг `$equal=$d.custom=...`: на 0.9.8 и 0.16.21 он дал XMLRPC
fault до capability lookup, тогда как command-form equality и exact dynamic
`method.has_key` на обеих families проходят; удаление capability затем делает
replayed hook no-op. Отдельный both-family probe exact nested empty gate дал
`empty -> ready`, но сохранил byte-identical `"0"`, wrong non-empty, foreign
marker-like и уже equal ready ack; прежний capability-only branch переписал все
три wrong формы. Marker-ready +
`custom3=1` дал exact ack и сохранил `custom3`; empty marker + exact `1` очистил
только legacy field; empty marker + `7` записал equal handoff в ack и
marker, сохранил `7` и запустил argv probe. Два handlers на rt21
воспроизвели current bug: второй скопировал original marker в
прежде empty ack. Corrected runtime gate требует equal marker/ack уже
после первого handler, один launch при single profile и zero launch при
`ma:1`. Runtime gate
реализации повторяет exact production builder с script/PHP paths, содержащими
space/comma/quote/backslash, и требует четыре script argv: PHP, hash, user,
handoff, то есть exact `$#=4` внутри script.

Существующий `tadd_trackers2<user>` обязательно перезаписывается exact
`safetyOnly`. Это удаляет stale second-hook stop с upgraded install, сохраняя
transaction ack/legacy clear; target `done.php` затем direct-delete-ит оба
historical keys.

Candidate/rollback load additions исполняются до `inserted_new` event. Last
creation command ставит ready marker; functional hook копирует его в ack. Full
success поэтому доказывает и завершение creation list, и actual hook execution.
Source order и real-daemon capture уже доказали visibility creation commands в
hook; exact marker-to-ack grammar остаётся named runtime gate implementation.

Launch использует registered на обеих версиях `execute.throw.bg`. В 0.9.8 его
double-fork подтверждает первый fork, но не сообщает поздний grandchild
`execvp` failure; поэтому launch return **не считается acknowledgement**.
Инвариант безопасности обеспечен отсутствием stop в hook. Startup plugin checks
по-прежнему валидируют PHP/run.sh. Поздний exec failure безопасен, но может быть
невидим из этого hook; worker-start receipt, supervision и automatic relaunch —
явный P3, а не ложное обещание этого package.

`run.sh` заморожен по смыслу и не содержит `&`:

```sh
cd "$(dirname "$0")" || exit 1
exec "$1" ./update.php "$2" "$3" "$4"
```

Запрещены `php -r`, environment flag вместо sentinel, дополнительный argv и
скрытое backgrounding/redirection в wrapper: worker получает один прямой POSIX
`exec`, а logging policy остаётся у PHP entrypoint/runtime.

Hook/rTorrent command builder обязан сохранить PHP/script/hash/user/handoff как
отдельные arguments с canonical rTorrent escaping. Whitespace, comma, quote и
backslash в configured PHP path не могут менять argv shape. Shell получает
ровно PHP + hash + user + handoff; worker получает dense `$argv[0..3]`.

## Entrypoint and immutable pre-commit gates

`init.php`, `done.php`, `UpdateTest.php` и
`RetrackersUpdateSequenceTest.php` определяют exact
`RETRACKERS_IMPORT_ONLY` до своего respective `require_once` production-файла
`plugins/retrackers/update.php`. Production importers используют sibling path,
tests — plugin-relative path из test tree. Top level `update.php` содержит только
constants и function/class-independent definitions, которые не требуют loaded
`Torrent`/XMLRPC classes; raw scanner/rewriter не наследует `Torrent`.
Внизу exact discriminator имеет форму «если import sentinel отсутствует,
`exit(retrackersCliMain(isset($argv) ? $argv : null))`». `realpath()`/
`SCRIPT_FILENAME` guards запрещены: они добавляют filesystem behavior на
include. CLI main сначала pure-валидирует argv, и только потом ставит
`REMOTE_USER` и включает `retrackers.php`, `xmlrpc.php`, `rtorrent.php`.

До include/config/filesystem/RPC side effects валидируются:

- dense argv keys exactly `0,1,2,3`;
- hash `^[0-9A-F]{40}$`;
- canonical user `^[a-z0-9_-]*$`;
- handoff `^v1:original:([01]):([0-9A-F]{40}):([0-9a-f]{64})$`;
- handoff local id и captured state извлекаются один раз, user token
  exact равен `hash('sha256', $argv[2])` с constant-time comparison.

Invalid argv завершает entrypoint без log/include side effects. Canonical hook
не может построить такую форму; это fail-closed CLI boundary, а не routine
production diagnostic.

После этого worker устанавливает `REMOTE_USER`, загружает dependencies и
получает initial stable snapshot. Frozen ordered scalar-only
`system.multicall` request plan покрывает
session/tied source, `d.loaded_file`, directory и global daemon default
directory, `custom1`…`custom5`, priority, throttle name, оба dedicated keys,
private/name guards, live state, `d.is_active`, `d.is_open`, `d.hashing`,
`d.hashing_failed`, `d.local_id` и global `trackers.use_udp` wire integer `0|1`
(`0.16.21` обязан вернуть fixed `1`). Snapshot принимается только при exact
count/types,
exact equal original marker/ack, exact `hashing_failed=0` и
ownership tuple. Missing hash, already hashing-failed object, foreign
marker/local id, changed mutable field, malformed/fault/transport outcome не
вызывают stop/erase/start.

Полный generic `d.custom` map и exact URL/group/type/extra/enabled tracker rows
не подменяются scalar members этого plan: каждый specialised snapshot читается
своей direct stable pair ниже. Initial snapshot принимается только когда эти
request-specific components и scalar tuple относятся к одной stable
generation; drift запускает fresh bounded round, а не смешивает ответы разных
форм.

Initial lifecycle eligibility exact и fail-closed. При `hashing=0` и
`hashing_failed=0` принимаются только measured обеими families tuples
`(state,is_active,is_open) ∈ {(0,0,0),(0,0,1),(1,1,1)}`: fresh `load.normal`,
ordinary stopped-but-open и active started соответственно. Paused
`(1,0,1)` и любая другая комбинация дают visible `lifecycle-unsupported`,
terminal handoff release и zero staging/arm/stop/close/erase. Это не попытка
восстановить open/active flags: baseline выбирает только `load.normal` для
state `0` либо `load.start` для canonical active state `1`. Both-daemon
one-shot scheduler-fenced probes pin-ят все три allowed формы, paused RED и
обычный `d.stop=(0,0,1)`.

Generic map freeze двухфазный и bounded. Worker читает canonical
`d.custom.items` direct stable pair только через named iterative restricted
RAW-response codec
`retrackersDecodeRestrictedRawResponse()` в его exact request-schema mode:
единственная accepted result shape — direct success с одним XMLRPC
`value/struct`; каждый child exact `member/name + value/string`, decoded member
names unique, value type explicit `<string>`, member order arbitrary. Empty
string является валидным present value и его key presence обязана сохраниться.
Integer/boolean/array/nested/missing value type, duplicate name или extra node
отвергаются codec-ом до map use. Current ruTorrent regex parser используется
только как historical reason для нового boundary: package никогда не потребляет
его output, потому что он теряет struct keys и принимает shapes вне frozen
grammar. `system.multicall`-member форма `d.custom.items` не используется и
отвергается. Worker затем читает второй direct `d.custom.items`; две canonical
sorted key/value sequences обязаны быть byte-identical. Допускается ровно три
двухчтенных round; первый stable pair принимается, а drift во всех трёх даёт
`runtime-map-unstable` без mutation. Recovery marker/ack входят в map count
и CAS, но в candidate/rollback заменяются transaction values; все прочие keys,
включая empty-valued keys, восстанавливаются sorted creation commands.
Daemon-side CAS доказывает полное равенство map как exact function-object
`math.cnt=(d.custom.keys)` count плюс equality predicate через
`d.custom_throw=<key>` для каждого frozen key. В отличие от `d.custom`,
`d.custom_throw` возвращает present-empty string, но fault-ит при missing key;
поэтому delete-empty/add-new same-count не проходит condition и не входит в
destructive true body. Addition меняет count, deletion fault-ит exact known-key
predicate, value change меняет equality. Both-daemon source и disposable
runtime подтвердили эту границу: exact frozen count predicate имеет форму
`equal=math.cnt=(d.custom.keys),value=<captured-decimal-count>`. `$d.custom.keys=`
запрещён: он передаёт empty argument в zero-argument command и fault-ит на обеих
supported families. Ordinary `d.custom` для membership запрещён.

Tracker snapshot использует отдельную direct stable pair typed
`t.multicall(hash,"","t.url=","t.group=","t.type=","t.is_extra_tracker=","t.is_enabled=")`.
Каждый direct result имеет `N >= 0` rows; каждая row — nested array ровно из
пяти cells в requested order: explicit URL string, затем четыре explicit
canonical `i8` для group/type/extra/enabled. Tracker numeric cells — `i8` на
обеих daemon families, не family-dependent scalar union. Group/type —
non-negative characterized integers, enabled и extra — exact `0|1`; missing,
extra, reordered, nested или wrong-tag cell отвергает весь ответ. URL —
once-decoded string bytes. `system.multicall`-member форма этого specialised
snapshot не используется и отвергается. Как generic map,
tracker rows читаются парами не больше трёх rounds и принимаются только при
stable canonical result. Intra-group row order не identity: libtorrent
0.16.21 рандомизировал один и тот же `[B,C]` tier как B,C и C,B. Canonical
topology поэтому является multiset `(group, exact decoded URL, type, extra)` с
multiplicity; enabled map keyed только exact decoded URL.

Zero tracker rows — valid direct empty array, но missing target всегда direct
fault, никогда empty row set. Both-family zero-row gate использует captured
0.9.8 trackerless object и captured **private** trackerless 0.16.21 fixture:
non-private 0.16.21 metainfo может синтезировать `dht://` и не является zero-row
evidence.

До mutation worker сравнивает source tracker semantics с live topology. Source
announce/announce-list нормализуется по тем же правилам, что обе daemon
families: при valid announce-list top-level announce не добавляет отдельную
row; empty tiers, whitespace normalization и supported-scheme filtering
следуют characterized loader behavior; порядок tiers и их group numbers
значимы, порядок внутри tier — нет; duplicate rows сохраняют multiplicity.
Если source normalizer не может построить exact daemon projection, object
ineligible, а не best-effort. Source rows обязаны exact совпасть с live
non-synthetic `(group,URL,type,extra=0)` multiset. Единственное допустимое
non-source исключение — максимум одна daemon synthetic row exact URL `dht://`,
type `3`, extra `0`; private/DHT-off object может её не иметь. Любая
`is_extra_tracker=1`, другая row либо mismatch даёт visible
`runtime-tracker-topology-mismatch`, terminal handoff release и zero
arm/stop/close/erase. Это production gate, а не теоретическая проверка:
`d.tracker.insert` доступен в обеих supported families и создаёт runtime-only
topology без изменения metainfo hash/path.

Raw `libtorrent_resume` остаётся byte-exact, но его nested `trackers` map имеет
отдельную narrow precommit projection. Обе libtorrent families до creation
commands применяют stored enabled state, а entry с `extra_tracker=1` + group
может reinsert URL, отсутствующий в candidate metainfo. Worker строго проверяет
map/key/value types и требует, чтобы каждый resume-extra URL присутствовал как
ordinary URL и в source, и в final candidate projection; иначе refusal до old
erase. Resume entry для deleted/non-source URL, malformed group/state или
непредсказуемая projection даёт `runtime-tracker-topology-mismatch`. Raw bytes
при этом не переписываются: eligibility + explicit post-resume state commands
ниже делают load outcome предсказуемым.

Enabled identity exact URL, не index/group/`t.id`: group сдвигается при новых
tiers, 0.16.21 меняет row order внутри tier, а stopped HTTP trackers возвращали
empty id. Canonical `{URL => (multiplicity,state)}` принимается только если все
duplicates одного exact URL имеют uniform state. Mixed `0/1` duplicate
production-reachable, но не имеет stable cross-reload identity; он даёт
`tracker-state-ambiguous`, terminal release и untouched original. Surviving или
reordered URL наследует old state, deleted исчезает, а new textual URL получает
frozen load-time policy: HTTP-family `1`, UDP на 0.9.8 — captured
`trackers.use_udp`, UDP на 0.16.21 — `1`. Uniform duplicates наследуют один
endpoint state. Final ordinary projection принимает только characterized
HTTP-family type `1` и UDP type `2`; ordinary `dht://`/type `3` или иной type
refuses before old mutation. Daemon synthetic `dht://` остаётся отдельным
разрешённым row и наследует captured presence, не user-configured state.

Outer и post-quiesce inner CAS включают тот же captured live topology/state
order-independently в одном daemon turn. При N>0 это exact total
`math.cnt=(t.multicall,,cat=1)`; exact multiplicity каждого
`(URL,group,extra)` через recursive `math.add=(t.multicall,...)`; и
`math.min=(t.multicall,...)` row-membership OR по всем captured
`(URL,group,extra,enabled)` tuples. `type` проверяется в stable snapshot/source
projection и детерминирован exact URL; runtime setter для него отсутствует.
При N=0 exact predicate —
`not=(t.multicall,,cat=1)`; `math.cnt`/`math.min` empty-list запрещены, потому
что обе families fault-ят `Wrong argument count`. Full actual XML проходит прежний
pre-size gate; каждый URL проходит `Q()` и representability gate. Disposable
0.9.8/0.16.21 exact branch дал `MATCH`; enabled/group/extra drift — `SKIP`.
URL/group/enabled-only predecessor дал доказанный false-green при подмене
ordinary row на runtime extra с теми же URL/group/state/count, поэтому extra
обязателен. Total + per-tuple multiplicity также ловят duplicate allowed row,
который один `math.min` пропустил бы. Nested grammar и zero-row predicate frozen
runtime gate-ом ниже.

`Q()` является escaper-ом ровно одного rTorrent command-parser boundary; он
защищает delimiter bytes, но не literalizes leading `$`, который следующий
parser layer исполнил бы как eager command. Two-family recursive-CAS и real
fresh-path `load.normal` capture измерили exact generic-map boundary:

```text
left = d.custom_throw=Q(key)
right = cat=Q(value)
condition = equal=Q(left),Q(right)
recreation = d.custom.set=Q(key),Q(value)
```

Поэтому generic keys/values принимают и byte-exact сохраняют empty, literal LF,
backslash, quote и comma. Это включает ordinary captured `addtime`, заканчивающийся
LF. One-layer equality без recursive quoting является named RED. Leading `$`, CR
и NUL остаются `runtime-value-unrepresentable`: `$` измеренно eager-evaluate-ится,
CR не имеет общего byte-exact 0.9.8/0.16.21 round trip, а NUL invalid/truncated.
Та же граница не обобщается на другую unmeasured command shape: directory,
tied/loaded guard, `custom1`…`custom5` и throttle сохраняют conservative
NUL/CR/LF/leading-`$` refusal до отдельного exact-shape evidence. Refusal
происходит до old-object mutation и conditional terminally release-ит exact own
handoff.

### Restricted RAW XMLRPC response codec

`retrackersDecodeRestrictedRawResponse()` в side-effect-free definitions
`update.php` — единственный typed response/API seam этого contract. Он получает
один bounded `RESPONSE_RAW` string от direct adapter,
находит ровно один already transport-validated SCGI delimiter `\r\n\r\n`,
проверяет exact body boundary/declared `Content-Length` и разбирает BODY
iteratively по offsets/cursor в исходном string. `substr()`/другая вторая
full-size BODY copy запрещена. Decoder обязан consumed-нуть весь BODY и XML
document, включая permitted measured structural whitespace; 0.9.8 final CRLF
принимается именно как captured XML whitespace. Prefix, suffix, second root,
extra node и любой byte вне frozen document grammar запрещены.

Request plan заранее передаёт codec-у exact expected schema, command/member count,
scalar types и order. Разрешены только captured на disposable supported families
envelopes:

1. direct success — exact
   `methodResponse/params/param/value/ResultNode`, с одним `params`, одним
   `param` и одним result value;
2. direct fault — exact `methodResponse/fault/value/struct`;
3. `system.multicall` — one outer `array/data`, где каждый requested member в
   request order есть либо exact one-value success
   `value/array/data/value/ResultNode`, либо member fault `value/struct`
   непосредственно в этом outer data slot. Success wrapper содержит ровно один
   inner result value; empty wrapper и два result values запрещены;
4. `d.custom.items` — direct `StringStruct(generic-map)` grammar, frozen выше.

`ResultNode` — не recursive arbitrary XMLRPC union. Plan выбирает ровно один
named node: `Scalar(plan)`, `FlatList(plan)`, `RowList(plan)` либо
`StringStruct(plan)`, а wrapper выбирается отдельно; private
`HistoricalBindingSampleV2` выбирает свой closed five-member composite и не превращает
эти nodes в generic union. Exhaustive application-container inventory:

```text
download-local-id-rows = RowList(N rows, width 2):
  explicit string ^[0-9A-F]{40}$, explicit string ^[0-9A-F]{40}$

tracker-five-column-rows = RowList(N rows, width 5):
  explicit string URL,
  explicit i8 canonical non-negative group,
  explicit i8 canonical non-negative characterized type,
  explicit i8 exactly 0|1,
  explicit i8 exactly 0|1

ledger-key-list = FlatList(N unique explicit strings matching exact ledger union)

historical-event-key-list = FlatList(N unique explicit strings)
historical-event-action-map = EventActionMap(N unique names => explicit
  string or family-bounded recursive array action)
historical-recovery-rows = RecoveryRows4(N rows, width 4)
historical-ledger-key-list = FlatList(0..4096 unique exact ledger keys)
historical-ledger-one-map = StringStruct(same names => explicit string "1")
```

`method.get` whole-ledger result остаётся
`ledger-string-one-map = StringStruct` с `N` unique decoded member names из той
же ledger union и explicit `<string>1</string>` values. Wire member order не
authoritative; после parse exact name set обязан совпасть с
`method.list_keys`. Empty map — exact empty struct. `d.custom.items` остаётся
другим request-specific string struct с arbitrary order, unique names и
present-empty string preservation. `method.has_key` —
`ledger-own-has = Scalar(explicit i8 exactly 0|1)`; targeted result является
единственной receipt-outcome authority. Daemon-internal nested `t.multicall`,
результат которых PHP получает только как scalar predicate/sentinel, не
добавляют PHP-visible array plan. Historical `EventActionMap`, its foreign
recursive opaque arrays (и family-0x02 nested structs) и `RecoveryRows4` —
отдельные private producers, а не broadened ledger/generic-map structs либо
width-2/width-5 rows.

Frozen final request modes:

- fresh deferred local-id scan — direct `d.multicall2` +
  `download-local-id-rows`;
- tracker snapshot — каждый read direct `t.multicall` +
  `tracker-five-column-rows`, exact direct stable pair;
- generic-map snapshot — каждый read direct `d.custom.items` + exact direct
  stable pair `StringStruct(generic-map)`;
- initial/final scalar batches — exact ordered `system.multicall` с frozen
  member count/order и только request-specific `Scalar(plan)` inner results;
  outer/success arrays являются protocol wrappers, не отдельным application
  producer-ом;
- coherent ledger validation/read — один exact ordered
  `system.multicall(method.list_keys, method.get, required method.has_key...)`.
  Outer result count exact `2+K`; slots 1/2 являются соответственно member
  `ledger-key-list`/member `ledger-string-one-map`, следующие `K` slots — member
  `ledger-own-has` в exact requested own-key order. Empty ledger всё равно
  возвращает exact `2+K` slots: empty flat list, empty struct и exact i8 `0` для
  каждого requested absent key;
- direct `method.has_key` разрешён только отдельно named targeted receipt либо
  modifiability probe, не как whole-ledger substitute.
- historical binding — exact ordered replacement-five
  `HistoricalBindingSampleV2` below; no scalar presence tail.

#### HistoricalBindingSampleV2

Historical lifecycle scan — единственное additional composite member mode.
Один sample является exact ordered `system.multicall` только из этих пяти
members:

```text
1 method.list_keys("", "event.download.inserted_new")
2 method.get("",       "event.download.inserted_new")
3 d.multicall2("", "main",
      "d.hash=", "d.local_id=",
      "d.custom=retrackers-recovery",
      "d.custom=retrackers-recovery-ack")
4 method.list_keys("", "rr.receipts.v1")
5 method.get("",       "rr.receipts.v1")
```

Exact 1,820-byte request serialization has SHA-256
`ae96a2e5264798d84e4a35e981bbe99d8337820a93a07ff989e480b329b44210`.
Ни optional `K`, tail, sixth slot, split, reorder, direct substitute или extra
member нет. Response outer array имеет ровно five slots in request order.
Каждый success — exact one-result member
`value/array/data/value/ResultNode`; empty/two-result wrapper запрещён. Member
fault — direct fault struct в соответствующем outer slot. Любой member fault
отвергает весь sample после full document consumption; соседние successes не
authorizes/exposes.

Exact success plans:

```text
1 EventKeyList    = 0..4096 unique explicit strings
2 EventActionMap  = 0..4096 unique decoded names => EventAction
3 RecoveryRows4   = 0..16384 exact width-four explicit-string rows
4 LedgerKeyListV1 = 0..4096 unique explicit strings
5 LedgerOneMapV1  = same unique names => explicit exact string "1"
```

Event list/map и ledger list/map decoded name sets независимо сортируются
unsigned bytewise `strcmp` и обязаны exact совпасть. Own key1/key2 membership
derived только из validated event set after equality; scalar presence tails
отсутствуют. Event/ledger names и recovery rows canonicalize-ятся as unordered
sets; array child order, row cell order, XMLRPC tag, canonical integer lexeme и
once-decoded bytes significant.

`EventActionMap` top-level value допускает только explicit string либо array.
Любое exact `tadd_trackers1|2` name проходит canonical suffix/user/pair grammar,
его value обязан быть explicit string, а complete pair bind-ится к same-sample
`pf:H(user):H(functional)` claim, epoch, owner mode and six-row phase table.
Caller-injected action allow-map не существует. IDLE exact functional bytes
hash claim; i-owner D/D uses prepared future functional hash; d-owner S/S uses
retained prior hash; c/contained S/S has zero claims. Unknown prefix shape,
noncanonical suffix, incomplete/extra pair, non-string value, claim/action drift
или phase mismatch даёт `historical-hook-restart-required` after full sample.
Exact S/D compare-ятся frozen global bytes; no foreign config is loaded.

Unrelated action consumed-ится только iterative `OpaqueAction` typed projector;
он не parse-ит/normalizes/rebuilds/executes command AST и не передаёт foreign
value другому plan:

```text
family 0x01: explicit string | canonical i4 | canonical i8 | array recursively
family 0x02: explicit string | canonical i8 | array/struct recursively
top-level EventAction: explicit string | array only in both families
```

Family `0x01` запрещает struct на opaque depth; family `0x02` разрешает
unique-name struct, но запрещает nested i4. Boolean, `<int>`, double, base64,
nil, implicit string и unknown type запрещены. Opaque depth начинает unrelated
top-level action array как depth 1. Projector хранит offsets, bounded stack/
counters/hash contexts, no node/action AST. Struct name decode-ится once,
duplicate rejects, bounded `(name,digest)` pairs сортируются по **full decoded
name bytes** binary `strcmp`, never entry/child digest.

Family byte выбирает сам HistoricalBindingSampleV2 до first application value
по exact BODY declaration и затем complete measured layout:

```text
0x01 RT098_XMLRPCC: exact encoding="UTF-8" declaration + CRLF/final-CRLF
0x02 RT01621_TINYXML2: exact compact declaration + compact/self-closing layout
```

Caller flag, settings, SCGI header и heuristic запрещены; mixed declaration/
layout rejects. Source owners: Debian rTorrent 0.9.8 upstream `6154d169` plus
`xmlrpc-c 1.33.14-11`, and pinned rTorrent
`109a20c09c3cab9eb13c2d96ea79362ac6c318fc` built-in TinyXML2. Verbatim BODY
remains wire authority, not source inference.

Typed child hashes are self-contained binary SHA-256 streams:

```text
StringDigest = SHA256("HS1-S\0" || U32(len(bytes)) || bytes)
I4Digest     = SHA256("HS1-4\0" || U32(len(lexeme)) || lexeme)
I8Digest     = SHA256("HS1-8\0" || U32(len(lexeme)) || lexeme)
ArrayDigest  = SHA256("HS1-A\0" || U32(child-count) ||
                      SHA256("HS1-AC\0" || raw32(child-digest)...))
StructDigest = SHA256("HS1-O\0" || U32(member-count) ||
                      SHA256("HS1-OC\0" ||
                        (U32(len(name)) || name || raw32(child-digest))...
                        sorted by full decoded name bytes))
EventKeyDigestV1 = SHA256("HS1-K\0" || U32(key-count) ||
                          (U32(len(name)) || name)... sorted)
EventMapDigestV1 = StructDigest(complete EventActionMap)
RecoveryRowDigestV1 = SHA256("HS1-R\0" || U32(len(hash)) || hash ||
  U32(len(local-id)) || local-id || U32(len(marker)) || marker ||
  U32(len(ack)) || ack)
RecoveryRowsDigestV1 = SHA256("HS1-RS\0" || U32(row-count) ||
                              raw32(row-digest)... sorted by (hash,local-id))
```

`SHA256(stream)` returns the raw 32-byte digest whenever it is composed into
another stream; hexadecimal text is display-only. `U32(n)` is exactly four-byte
unsigned big-endian `pack('N', n)`. `raw32(x)` is the raw 32 bytes of digest
`x`, never its hexadecimal representation. Every length is the byte length of
the exact adjacent sequence and every count is the exact validated
cardinality. `FamilyTag` below is exactly one raw byte, `0x01` or `0x02`.

String `bytes` are exact once-decoded explicit-string bytes. Integer `lexeme`
is the exact validated decimal byte sequence inside its explicit i4/i8 tag:
grammar `0|-?[1-9][0-9]*` and signed i4/i8 range validate lexically before
hashing, without PHP integer cast, so the tag remains digest-significant. Array
children remain in exact wire order. Struct members cover the complete
validated struct exactly once and sort by full decoded name bytes before their
length-prefixed name/raw-child-digest tuples are hashed. Event keys use the
same full decoded-name byte sort; `EventMapDigestV1` covers the complete
top-level `EventActionMap`. Recovery row fields are the exact validated bytes
defined below; row digests sort by binary `(hash,local-id)` order. No
implementation helper or earlier unstated definition is required to
reconstruct any stream.

`RecoveryRows4` remains distinct from width-2 deferred scan:

```text
0..16384 rows; each row array/data exactly four values
1 explicit string ^[0-9A-F]{40}$ hash
2 explicit string ^[0-9A-F]{40}$ local-id
3 explicit string marker, empty allowed
4 explicit string ack, empty allowed
```

Entire row validates before cell use/marker semantics; then unique hash and
unique local-id, frozen marker/ack union, binary `(hash,local-id)` row order and
fixed cell order. Captured 0.16.21 partial row is schema failure; analogous
0.9.8 killed/no-response is transport unknown, never empty. Empty outer rows
valid; empty row, width 0/1/2/3/5, wrong tag/order/identity/duplicate/marker
rejects.

V2 ledger/sample digest exact:

```text
LedgerKeyDigestV1 = SHA256("HB2-LK\0" || U32(key-count) ||
  (U32(name-length) || name)... in sorted decoded-name order)
LedgerMapDigestV1 = SHA256("HB2-LM\0" || U32(member-count) ||
  (U32(name-length) || name || 0x01)... in sorted decoded-name order)
LedgerDigestV1 = SHA256("HB2-L\0" || raw32(LedgerKeyDigestV1) ||
  raw32(LedgerMapDigestV1))
HistoricalBindingDigestV2 = SHA256("HistoricalBindingSampleV2\0" || FamilyTag ||
  raw32(EventKeyDigestV1) || raw32(EventMapDigestV1) ||
  raw32(RecoveryRowsDigestV1) || raw32(LedgerDigestV1))
```

`0x01` in ledger-map stream encodes already validated literal string `1`, not
unchecked input. Both samples independently pass full schema/bounds/profile/
claim/epoch/owner/recovery/full-RAW checks. Compare same family, raw digest,
event key/action/row/ledger counts, profile/claim counts, derived caller
membership, phase, owner fields and every retained recovery decision, never
digest alone. Any drift is `profile-binding-unstable`; only bounded sample-2
projection reaches lifecycle and its exact pv becomes next mutation CAS.

Old production plan `HistoricalSampleV1`, its two scalar tails and all four
BODY identities below are **serializer-shape provenance and explicit V2 RED**,
never accepted production samples:

```text
b9a8f7504aa9c2112a2591df8f97d153936bcdeaa393d256b944e96e791b2ac4
e0fd0e152f473c2cde4d4c5f309460f288e00f0a025eb208fafa0eb67f88c6cb
db548d9f026175cc87107b07ae79c79b3dfa4203b53c27bf35b5e488a7ab9f34
3a3592dbb52793745ec458fc81301b607ba7dc02d5222e10e525f37b7886f723
```

Old populated fixtures additionally have absent-tail inconsistency, synthetic
action strings and noncanonical synthetic marker. They must fail complete V2
plan; no old hash may be labelled accepted.

Measured current reachable B5 boundary is also rejection evidence, not
BOOTSTRAP: exact request above returned two byte-identical reads on both
families with slots 1–3 success and slots 4–5 natural `rr.receipts.v1` missing-
key faults because current tracked tree has no producer. Rejected BODY evidence:

```text
0.9.8   1563 bytes  505031b6aa974f339343ab70778eb4430fb9d0e4eed702ecdb6fec32baed04ac
0.16.21 3412 bytes  f32032540fe36d6a0b5e6a024174da67f6d25d31742b16a159b3e8307b45f927
```

No phase/digest/partial projection follows those faults. Manual
`method.set_key` can prove serializer shape only and remains synthetic/RED; it
cannot prove production builder/action/marker/epoch/callback provenance.

Captured, но final-plan-unused формы отвергаются: member `t.multicall`, member
`d.custom.items`, direct `method.list_keys`, direct `method.get`, а также любая
direct/member перестановка named node. Member `method.list_keys`, `method.get`
и `d.multicall2` разрешены только в своих exact slots frozen ledger/historical
plans; historical member `d.multicall2` нельзя использовать как width-2 scan,
а ledger member `method.get` нельзя использовать как EventActionMap. Direct
`d.multicall2` остаётся единственным deferred width-2 mode. `d.multicall`
вместо cross-family `d.multicall2` запрещён. Codec не имеет untyped fallback,
unknown row width или surplus/reordered cell acceptance.

`Scalar(plan)` допускает только explicit `<string>`, `<i4>` или `<i8>` в
соответствии с exact request plan. Captured family difference explicit:
mutating zero и fault code приходят как i4 на rTorrent 0.9.8 и i8 на 0.16.21;
ordinary numeric getters были i8 на обеих families. Такой scalar integer plan
принимает только measured request-specific tag, а не глобальный speculative
union. Lexeme canonical `0|-?[1-9][0-9]*`; i4 дополнительно в signed 32-bit
range, i8 — в exact contract-specific signed/range/schema boundary до PHP
numeric conversion. Caller затем требует exact semantic range. Tracker cells и
`method.has_key` требуют i8 на обеих families. `<ix>`, implicit/missing type,
plus, leading zero, whitespace и exponent запрещены.

`N` определяется числом exact children соответствующего container и может быть
zero только у планов, допускающих empty collection. Нельзя игнорировать child:
parsed count обязан равняться числу полностью consumed rows/elements/members,
каждая row имеет frozen width. Empty direct array на 0.9.8 имеет measured
`<data>\r\n</data>` и final structural CRLF, на 0.16.21 — `<data/>` без
structural whitespace. Empty member array сохраняет one-result wrapper и empty
inner `array/data`; это не success wrapper с zero values. Empty ledger map
аналогично имеет 0.9.8 `<struct>\r\n</struct>` и 0.16.21 `<struct/>` внутри
one-result member wrapper. Empty direct `d.custom.items` использует те же две
family-specific struct forms непосредственно в `DirectSuccess`. Другой
whitespace/sibling layout отвергается.

Verbatim BODY captures остаются единственной wire-byte authority. Exact source
audit только связывает их с deterministic owners: disposable-oldest использует
Debian `rtorrent 0.9.8-1` (audited rTorrent files byte-equal upstream
`6154d169`) и Debian `xmlrpc-c 1.33.14-11`; его единственный relevant Debian
patch меняет только help typo. Там rTorrent строит typed result objects, а
xmlrpc-c registry владеет `system.multicall` wrappers и response serialization.
Pinned 0.16.21 использует rTorrent
`109a20c09c3cab9eb13c2d96ea79362ac6c318fc`; его built-in TinyXML2 path владеет
и `system.multicall` shim, и serializer. Source совпал со всеми captures, но не
заменяет BODY length/SHA/layout proof.

Source ownership дополнительно freeze-ит fail-closed branches. На 0.9.8
`d.multicall2` — единственная unconditional registration; на 0.16.21 canonical
`d.multicall` имеет unconditional `d.multicall2` redirect **до** и вне
`method.use_deprecated`, поэтому legacy name остаётся exact cross-family call.
0.16.21 `d_multicall` может прекратить заполнение row, если requested command
erase-ит current download: даже source-reachable partial row отвергается width-2
plan-ом. 0.16.21 `t_multicall` allocates row до invalid-wrapper check и может
вернуть empty row: width-5 plan также отвергает её. Captured stable fixtures не
входили в эти branches, поэтому это rejection provenance, не новый accepted
wire shape. В обоих owners ordinary numeric application results — i8;
family-specific i4/i8 остаётся только exact void/fault request plans.

Fault struct содержит ровно два unique decoded names: `faultCode` с explicit
i4|i8 canonical integer и `faultString` с explicit string; member order может
различаться. Generic struct требует unique names, explicit string values и
present-empty preservation. Attributes, namespaces, comments, CDATA, DTD/entity
declarations, processing instructions после declaration, unknown/nested types,
malformed UTF-8, malformed entity/reference или tag, duplicate
member и partial parse отвергаются. Decoder принимает только две measured XML
1.0 declarations/layouts: 0.9.8 `encoding="UTF-8"` + structural CRLF и compact
0.16.21 declaration/body; BOM не принимается.

Fault placement также request-specific: direct call принимает fault только как
direct root `methodResponse/fault`, а ledger/HistoricalBindingSampleV2 multicall —
только как one member `value/struct` в exact requested slot; fault заменяет
результат этого slot и не добавляет/удаляет outer member. Captured missing-target direct `t.multicall`
имеет exact code/tag `i4 -501` на 0.9.8 и `i8 -500` на 0.16.21 и никогда не
интерпретируется как empty tracker rows. Любой ledger member fault отвергает
весь coherent read, а любой historical member fault — весь complete sample до
использования соседних success slots.

XML predefined/numeric references decode-ятся ровно один раз в UTF-8 bytes; old
regex-parser prefix escaping не является wire rule и не имеет inverse/round-trip
adapter. Literal LF внутри `<string>` сохраняется, включая LF непосредственно
перед closing tag, и не trim-ится как structural whitespace. Raw scan обязан
reject/flag literal или referenced CR/NUL в string/member-name character data до
XML normalization/typed output, чтобы daemon value не мог silently измениться;
captured structural CRLF 0.9.8 остаётся разрешённым только вне scalar text. Only
codec output становится typed input request-specific consumer; fallback в
current regexp parser запрещён.

HistoricalBindingSampleV2 freeze-ит отдельные decimal application bounds:

```text
historical XML BODY bytes                    67108864
opaque array/struct depth                          32
all XML <value> elements                       262144
foreign opaque <value> subset                    65536
HIST_MAX_XML_STRUCT_MEMBERS: all XML <member>   12288
event-key list cardinality                       4096
event-action map cardinality                      4096
HIST_MAX_LEDGER_KEYS: ledger list/map             4096
HIST_MAX_PROFILE_CLAIMS: profile claims           2048
recovery rows                                    16384
one decoded name bytes                            4096
one decoded scalar/lexeme bytes                 1048576
total decoded text bytes                       8388608
retained name bytes                            2097152
projected semantic output bytes                8388608
```

Counters monotonic per complete response и не уменьшаются при close/free child.
Global `<value>` counter включает outer multicall value, outer slots,
one-result wrappers, event/ledger list-map values, every row/cell, nested
opaque value и direct/member fault values. Global `<member>` включает event,
ledger, opaque struct и оба fault members. `12288` сохраняет прежний aggregate
8192-member event/opaque/fault budget и добавляет максимум 4096 ledger members.
Fault, уже сделавший sample doomed, всё равно fully
consumed и charged. Opaque value counter — дополнительный subset global counter;
opaque depth начинается только с foreign top-level action array, а protocol
wrappers/event struct/recovery rows его не расходуют.

Каждое counter/byte/depth увеличение gated subtract-before-add **до** frame,
hash, name, scalar, pair, action, row, stack или output allocation: сначала BODY
length до XML cursor state; затем global/opaque values; member count до
name/pair; depth до push; action/ledger/profile/row cardinality до record;
one-name/one-scalar, decoded-text и retained-name totals до каждого append/
retention; projected output до consumer byte. Ledger names charge value/text/
retained totals в list и map appearances; profile records charge projected
output. Entities decode-ятся incrementally ровно один раз и
каждый resulting chunk проверяется до append. Нет second full BODY copy,
XML/action AST или unchecked add; numeric validation остаётся lexical и
32-bit-safe.

До lifecycle acquire, acknowledged historical neutralization и любой другой
event-map mutation `init.php` idempotently обеспечивает один daemon-local
ledger:

```text
method.insert = "", rr.receipts.v1, multi|private
```

Это замена отвергнутому варианту с четырьмя dynamic value methods. В исходниках
обеих daemon versions `system_method_erase()` вызывает только
`rpc::commands.erase()` и не удаляет запись `object_storage`. Runtime после
`method.erase` всё ещё прочитал старое value через `method.get`, а повторный
`method.insert` того же name вернул `Invalid key`. Поэтому `method.erase` не
является receipt cleanup ни при каких тестовых ожиданиях.

После успешной вставки или exact `Invalid key` от concurrent/repeated init он
обязан заново доказать через trusted SCGI, что существующий object. Каждый
coherent ledger validation/read является одним exact ordered
`system.multicall(list_keys,get,required has_key...)`; member count/order и
requested own-key tail фиксируются до serialization, fault в любом slot
отвергает весь set:

1. принимает `method.list_keys`, `method.get` и `method.has_key` как `multi`;
2. `list_keys` содержит не более 4096 unique names: exact `ma:1`/`ta:1`,
   persistent epoch `^pv:[0-9a-f]{32}$`, не более 2048 action-bound claims
   `^pf:[0-9a-f]{64}:[0-9a-f]{64}$`, единственный authoritative owner
   `^to:[0-9a-f]{32}:(i|d|c):[0-9a-f]{64}$`, deferred dirty flag `dq:1`,
   synchronous launch/defer keys `^(wh|wp|di):[0-9A-F]{40}$` либо strings
   формы `^(wa|ea|eb|ed|ex|la|lb|lf|ca|cb|cd|cx|ra|rb|rf):[0-9a-f]{32}$`
   либо exact marker-capabilities
   `^v1:(candidate|rollback)-ready:[0-9a-f]{32}$`;
   `method.get` возвращает unordered unique-name struct, exact name set которого
   равен `list_keys`, и **каждое** member value является explicit exact string
   `1`; это не positional value list;
3. только после этого safe value check direct XMLRPC invocation
   `rr.receipts.v1` даёт exact normalized method-not-defined `-506`, то есть
   dynamic command действительно private. Public collision может попытаться
   исполнить только уже проверенные literal `1`, которые не являются command и
   не имеют side effect; любой его не-`-506` outcome отвергается;
4. lifecycle invariant exact: `ta:1` absent требует zero owner, `ta:1` present
   требует ровно один extended owner. Modes i/d требуют ma absent, c требует
   ma present; completed ma has no ta/owner. Outside attested BOOTSTRAP exactly
   one pv обязателен. Claim/profile/action relation затем обязана match-ить одну
   из six phase rows; structural owner/pv/key failure —
   `receipt-ledger-corrupt`, stable valid foreign owner — `lifecycle-busy`,
   stable semantic mismatch — `historical-hook-restart-required`;
5. modifiable: init генерирует отдельный random 32-hex token, требует absence
   exact `eb:<probe>`, затем exact set/targeted-direct-has/delete/reuse adapters
   доказывают set/has/delete/reuse/**final delete+absence**, не затрагивая другой
   subkey.

Wrong type, public/callable/const object, malformed/duplicate/foreign key или
иной fault disable-ит plugin до functional activation с visible
`receipt-ledger-corrupt`. Инициализация никогда не вызывает `method.erase`, не
recreate-ит и не clear-ит существующий ledger: его valid keys могут
принадлежать concurrent worker или пережившему reload transaction. Межключевые
комбинации **transaction receipts** не валидируются — crash или
последовательный cleanup легально оставляет любую subset одного transaction;
transaction-receipt subsets не подменяют обязательные cross-key `pv/pf/ta/
owner/ma` и event-pair phase relations выше.

Worker генерирует один transaction token `bin2hex(random_bytes(16))`, exact 32
lowercase hex, и получает до семнадцати exact собственных subkeys:

```text
wa:<tx>   worker active lease while any worker/daemon mutation can still begin
ea:<tx>   old-object erase armed
eb:<tx>   old-object erase began
ed:<tx>   old-object erase completed
ex:<tx>   old-object branch reached its non-yielding terminal tail
la:<tx>   candidate load armed
lb:<tx>   candidate load wrapper began
lf:<tx>   candidate post-dispatch scheduler fence fired
ca:<tx>   owned-candidate cleanup armed
cb:<tx>   owned-candidate cleanup began
cd:<tx>   owned-candidate cleanup completed
cx:<tx>   cleanup branch reached its non-yielding terminal tail
ra:<tx>   rollback load armed
rb:<tx>   rollback load wrapper began
rf:<tx>   rollback post-dispatch scheduler fence fired
v1:candidate-ready:<tx>   candidate inserted-hook ack capability
v1:rollback-ready:<tx>    rollback inserted-hook ack capability
```

Ordinary hook synchronously создаёт отдельный, не tx-owned key
`wp:<handoff-local-id>` до detached launch. После minimal exact
marker/ack/local-id ownership и ledger validation worker использует один
idempotent three-way adopt callback:

1. own `wa:<tx>=1`, `wp=0` и exact ownership возвращают `ADOPTED`, даже если
   `ma` появился уже после первого adopt;
2. `wa=0`, `wp=1`, `ma=0` и exact ownership atomically ставят `wa`, удаляют
   `wp`, readback-ят `1/0` и возвращают `ADOPTED`;
3. `ma` first при `wp=1` возвращает `DENIED`, остальные формы — `CHANGED`, без
   mutation.

Unknown adopt можно повторять только с тем же tx: accepted-response-lost
попадает в case 1, competing done/containment — в case 3, delayed request после
terminal cleanup видит `wa=wp=0` и ничего не создаёт. До adopt exact-known
absent/foreign object acknowledged-delete-ит только own `wp`; unknown
transport/ledger state оставляет его sticky. Все дальнейшие source/lifecycle/
eligibility gates выполняются уже под `wa`.

`wa` непрерывно присутствует через precommit и inter-phase gaps, но не зависит
от успешного handoff release. Он живёт, пока ещё может начаться phase callback
или worker может послать mutating RPC. Known terminal absent/foreign/partial/
hash-failed/quarantined outcome тоже удаляет `wa`, когда dispatch closed и
никакой mutation больше невозможна; только genuinely pending unknown request,
missing terminal tail/fence или crash оставляет lease sticky. Поэтому done не
erase-ит ack-capable hook под живым worker, но terminal quarantine не блокирует
teardown навсегда.

Каждый phase arm (`ea`, `la`, `ca`, `ra`) — отдельный **one-shot** non-yielding
callback. Он требует live `wa` и absence всех keys этой phase, ставит arm и
возвращает exact `RETRACKERS_PHASE_ARMED`; `la`/`ra` в том же true body ставят
value `1` под exact full candidate/rollback ready-marker capability. False body только возвращает
`RETRACKERS_PHASE_CHANGED`. Fault/malformed/transport-unknown arm никогда не
retry-ится и не revoke-ится: worker сохраняет `wa`, не dispatch-ит phase и
переходит в visible unbounded/restart-required pending. Это исключает delayed
arm A → cleanup/revoke B → late recreate A.

Каждый destructive/load/cleanup dispatch также посылается ровно один раз.
State-writing true body всегда имеет outer predicate live `wa` + exact arm;
outer false **не** пишет `ex`/`cx` или другие receipts. При exact known reply
request завершён и его sentinel можно классифицировать. При unknown worker
только читает receipts: absent begin никогда не доказывает dropped request и
остаётся sticky pending; present begin требует terminal tail/fence. Никаких
state-writing revoke/disarm retries в target grammar нет.

До adopt один initial tx-collision preflight повторяет full ledger key +
all-values-exact-`1` check и требует absence всех семнадцати own keys, включая
`wa`, четырнадцать phase и две marker-capabilities; только затем adopt
единолично создаёт `wa`. Retry adopt не повторяет collision preflight и
принимает только exact own `wa=1/wp=0` response-loss form. После adopt whole-ledger
validation проверяет grammar/value, но допускает expected receipts прошлых
phases. Каждый later arm отдельно требует absence только keys своей phase и
exact допустимую prior-receipt subset; candidate/cleanup/rollback поэтому не
противоречат surviving erase evidence. Каждый receipt записывается
`method.set_key` с fixed value `1`, а outcome proof читается только targeted
`method.has_key` для exact own key: как required tail соответствующего coherent
ledger multicall либо как отдельно named direct receipt probe. Whole-map
`method.get` используется для exact key-to-value grammar validation: его
unordered member names обязаны exact совпасть с `method.list_keys`, а каждое
associated value обязано быть literal string `1`; он всё равно не определяет
receipt outcome. Collision,
unknown key/value,
transport, malformed или иной fault останавливает package до mutation.
`method.*` доступен только через trusted internal SCGI и запрещён HTTP XMLRPC
proxy утверждённым policy contract.

Worker остаётся importable без production `TEST_MODE` branch. Side effects
принадлежат явному CLI entrypoint; clock/RPC/filesystem/sender/logger задаются
обычными dependency objects/overridable methods в tests.

Production RPC dependency — approved final `rSCGITransport` immediate parent,
не timeout-семантика старого `rXMLRPCRequest::send()`. Узкий injected adapter в
side-effect-free/importable definitions `update.php` используется всеми direct
retrackers RPC, включая finite init/done calls. Один logical RPC строит один
payload и делает ровно один final-parent call с exact nine arguments:

```php
global $scgi_host, $scgi_port, $rpcTransferTimeOut, $rpcMaxResponseBytes;

$failure = null;
$raw = rSCGITransport::send(
    $scgi_host,
    $scgi_port,
    $payload,
    true,
    0.25,
    $failure,
    isset($rpcTransferTimeOut) ? $rpcTransferTimeOut : null,
    isset($rpcMaxResponseBytes) ? $rpcMaxResponseBytes : null,
    rSCGITransport::RESPONSE_RAW
);
```

Оба `isset` independent и остаются непосредственно на call boundary. Legacy
config без обоих symbols передаёт literal `null,null`; final transport применяет
approved default transfer timeout и response cap без notice. Present configured
values forwarding-ятся unchanged и валидируются transport-ом. `$rpcTimeOut`,
private response cap и hardcoded transfer timeout не заменяют optional globals.
Default null transfer сохраняет positive `default_socket_timeout`/60-second
fallback final contract; connect `0.25s` намеренно не становится read budget,
потому что first-byte latency включает whole daemon multicall.

`$raw === null` — один classified transport failure из `$failure`, без retry и
raw third-party text в routine log. Non-null output ровно один раз получает
`retrackersDecodeRestrictedRawResponse()`; malformed RAW или XMLRPC fault body
не вызывают second send и не fall back в `rXMLRPCRequest::run()`/`send()`.
Package не реализует socket/SCGI framing и не ходит через HTTP proxy. Current
base predecessor interface не является execution proof этой final API;
implementation/runtime gates начинаются только на approved final parent.
Individual call bounded; worker reconciliation при unknown остаётся unbounded
по числу read-only polls и потому не получает ложный overall wall-clock deadline.

Approved transport response cap и historical application BODY cap имеют разных
owners. Argument 8 остаётся exact independently forwarded
`isset($rpcMaxResponseBytes) ? $rpcMaxResponseBytes : null`; configured operator
value `1..104857600` передаётся type/value-unchanged, а `null` выбирает transport
default `67108864`. Historical codec **не** подставляет `min`, private cap или
hardcoded argument 8. Transport первым отвергает declared `Content-Length` выше
своего selected cap. После successful RAW HistoricalBindingSampleV2 проверяет normalized
decimal Content-Length/actual BODY against application `67108864` lexical и
32-bit-safe до XML cursor/hash/stack/container/output/consumer allocation:

- configured cap меньше 64 MiB выигрывает на своей границе и не widening-ится;
- default 64 MiB отвергает BODY `67108865` при transport header handling;
- configured cap выше 64 MiB может allocate/return RAW с BODY
  `67108865..cap`; только затем application gate отвергает его до decoder
  allocations и consumer use.

Последний case не обещает избежать transport RAW allocation. Accepted-boundary
decoder не делает second BODY copy. PHP 7.4 `memory_limit=128M` retained-state
gate использует application-accepted BODY не больше 64 MiB, никогда не держит
оба RAW: после sample 1 остаются digest/bounded decision, RAW освобождается,
затем посылается sample 2; sample-2 RAW освобождается до передачи bounded
projection lifecycle logic. Struct entries retained как bounded name+32-byte
digest, recovery output как packed bounded records, не nested zval/XML AST.

Source protocol использует exact PHP integer constant
`RETRACKERS_METAINFO_CAP = 64 * 1024 * 1024 = 67108864`; source и candidate
имеют independent CAP:

1. Уже выбранный canonical session `<HASH>.torrent` либо tied-source pathname
   resolve-ится ровно один раз и ровно один раз открывается
   `fopen($path, 'rb')`. Open failure — `source-unreadable`. `filesize`,
   pre-sized buffer и second pathname read запрещены.
2. Сразу после open descriptor проходит `fstat`. Failure либо mode, отличный от
   regular file (`($mode & 0170000) === 0100000`), —
   `source-not-regular` с zero `fread`: FIFO, socket, character/block device,
   directory и unknown wrapper запрещены. Symlink допустим только когда уже
   opened target regular. Descriptor, а не `is_file(path)`, authoritative;
   `stat` size advisory и не является preallocation/acceptance proof.
3. Один initially empty capture string заполняется reads не больше 64 KiB.
   Перед каждым read exact request равен
   `min(65536, (CAP + 1) - strlen(capture))`; никогда не запрашивается и не
   сохраняется byte CAP+2. `fread === false` — `source-read-error`. Empty result
   принимается только при `feof(handle) === true`; empty с false EOF —
   `source-short-read`. Positive short result normal только если `feof` уже true,
   иначе `source-short-read` без retry. Handle закрывается на каждом outcome.
4. Capture exact CAP обязан сделать final one-byte EOF probe и принимается
   только при true EOF. Как только retained length равна CAP+1, worker даёт
   `source-too-large` до scanner, `Torrent`, hash, RPC, temp file, arm и любой
   old-object mutation. Zero-length regular source доходит до scanner и
   классифицируется bencode-invalid, не read failure.
5. Accepted capture string становится единственным immutable byte authority.
   Никакая subsequent operation не reread-ит pathname и не передаёт pathname в
   `Torrent`. Raw scanner хранит `(offset,length)` descriptors в capture, а не
   eager full `substr()` values; source capture никогда не меняется. Это
   descriptor-bound capture + scanner/hash guarantee, не недоказанное обещание
   atomic filesystem snapshot при concurrent pathname writer.
6. Один iterative raw-bencode scanner без PHP recursion/numeric conversion
   принимает source не больше CAP, depth не больше `128` containers и не больше
   `1_000_000` total values/keys. Он валидирует full consumption и grammar:
   integer только `i0e|i-?[1-9][0-9]*e` (arbitrary-length raw lexeme,
   `-0`/leading zero/plus запрещены); string length только
   `0:|[1-9][0-9]*:` с overflow-safe decimal bound against remaining bytes;
   dictionary key только byte string, unique в своём dictionary. Empty strings
   разрешены. Unsorted dictionaries, которые принимают current Torrent/rTorrent,
   не сужают eligibility; scanner сохраняет exact raw key/value offset spans, а
   только новый top-level envelope canonical-sort-ит output keys. Он требует
   ровно один `info` и exact `SHA1(raw info value)==expected hash`.
7. Ровно один semantic `Torrent` construction получает captured bytes только
   после raw scanner/raw-info hash gate и используется только для существующей
   tracker-list semantic logic. Parse error-free; path запрещён, а decoded object
   никогда не сериализует `info`, `libtorrent_resume` или unknown entries.
   Tracker mutation выполняется на object; narrow logical replacement принимает
   только resulting `announce` string/null и nested list-of-strings
   `announce-list`/null. Integer/object input запрещён.
8. Rewriter сначала строит logical ordered output plan: opening `d`, sorted
   encoded key/value pairs и closing `e`. Exact source key/value остаётся
   `(offset,length)` reference; tracker replacement остаётся logical narrow
   string/list, не pre-concatenated bencode. Plan переносит source spans для всех
   keys кроме `announce`, `announce-list`, `rtorrent`; `rtorrent` omit-ится, два
   tracker keys replace/add/delete-ятся, а keys сортируются по decoded payload
   bytes (bencode order), не raw `<decimal-length>:` prefix.
9. До candidate allocation exact encoded length каждого delimiter, key prefix/
   payload, raw span, narrow value/list delimiter/member рассчитывается checked
   arithmetic. Каждый component — non-negative PHP integer не больше CAP; digit
   counts вычисляются только для checked `0..CAP`. Каждое сложение имеет exact
   subtract-before-add order:

   ```php
   if ($part < 0 || $part > RETRACKERS_METAINFO_CAP
       || $total > RETRACKERS_METAINFO_CAP - $part) {
       return false; // candidate-too-large
   }
   $total += $part;
   ```

   Comparison предшествует addition, поэтому intermediate sum не overflow-ится
   на 32-bit PHP даже для hostile many-component plan. Exact CAP valid, CAP+1 —
   `candidate-too-large`. Measurement через recursive string build, `implode`
   либо `(string) Torrent` запрещён.
10. Только successful exact preflight разрешает один candidate output и один
    bounded append primitive. Перед каждым append он повторяет
    `length(chunk) <= CAP - currentLength` и при отказе сохраняет prior output
    unchanged; raw spans и generated payload подаются slices не больше 64 KiB.
    Full segment array/`implode`, complete-envelope concatenation-before-check и
    second source read запрещены. Final candidate не больше CAP и до temp file,
    arm, RPC или old mutation повторно проходит тот же strict scanner:
    `rtorrent` absent, tracker semantics exact, raw `info`, present/absent
    `libtorrent_resume` и unknown protected spans byte-identical capture, hash
    expected. Failure preflight/append — `candidate-too-large`; partial output
    уничтожается, immutable capture остаётся rollback payload. Valid source и
    candidate strings могут одновременно существовать до CAP каждый; offset
    descriptors запрещают avoidable full raw copies, но PHP COW/reallocation не
    выдаётся за hard RSS ceiling.
11. До old-object mutation worker создаёт private per-transaction directory под
   `FileUtil::getTempDirectory()` через fresh 128-bit component, temporary
   `umask(0077)` и `mkdir`; directory обязана быть new, non-symlink и без
   group/other bits. В ней `x+b` открываются два distinct files с exact
   candidate/original bytes, complete-write loop и `fflush`. `fstat(handle)` и
   `lstat(path)` требуют same dev/inode, regular file, link count `1`, exact
   size, owner uid и mode `0600`. Пока unpredictable private path никому не
   передан, worker unlink-ит own names, повторно требует по handles link count
   `0`, удаляет пустую own directory и находит соответствующие numeric fd через
   `/proc/self/fd` по exact dev/inode/size. Daemon получает только immutable
   `/proc/<worker-pid>/fd/<fd>` capabilities. Candidate handle остаётся open
   как минимум до `lf`; original handle остаётся open через candidate
   classification и возможный `ca/cx` cleanup, а если rollback разрешён — до
   exact `rf`. Он закрывается раньше только после terminal candidate class,
   который contract-ом исключает rollback. Worker запущен самим rTorrent,
   имеет тот же uid/PID
   namespace и живёт до fence. До `ea` arm trusted daemon request отдельно для
   **каждой** capability вызывает registered `execute.capture` с configured
   PHP binary, `-r`, одним frozen no-shell probe и values только как argv.
   Daemon-launched child открывает exact `/proc/<worker-pid>/fd/<fd>`, требует
   same regular-file dev/inode/nlink=`0`/size, полностью SHA-256-хеширует stream
   и печатает только fresh random nonce при exact match; worker принимает exact
   nonce string, zero integer values и successful/fault-free request. Код probe
   не печатает path/bytes и не получает их через shell. Это проверяет реальный
   open/read из daemon execution context, а не только worker-side
   `/proc/self/fd` lookup. Missing/inaccessible procfs, remote daemon, missing
   `execute.capture` или mismatch — pre-commit refusal, original нетронут.
   После unlink нет pathname, который cleanup мог бы перепутать с файлом
   другого torrent;
12. Builder до old-object mutation создаёт exact candidate/rollback
   conditional `load.normal`/`load.start` command strings, one-shot arm/dispatch и
   scheduler-fence requests со всеми creation commands. Actual full XML
   каждого request не больше `rTorrentSettings::maxContentSize()`;
   failure — visible `load-command-too-large`, original нетронут;
13. Pre-event restoration baseline заморожен exact: directory,
   `custom1`…`custom5`, priority, throttle name, полный generic custom key/value
   map (включая transaction-owned marker/ack), URL-keyed tracker enabled state
   и intended
   state. Old-generation safety tuple
   дополнительно включает tied/loaded source guards и exact initial
   `d.is_active`/`d.is_open`/`d.hashing`; эти lifecycle/source guards не
   выдаются за restored final values.
   Reload остаётся intentionally untied, как current non-session `sendTorrent`;
   tied/loaded source входят в old CAS, а new tied и loaded values exact empty.
   `d.loaded_file.set` зарегистрирован как private creation-command setter на
   обеих daemon versions. На 0.9.8 он не экспортирован через
   `system.listMethods`, но реальный `load` creation list успешно clear-ит и
   повторно читает поле; worker не вызывает setter как direct XMLRPC method.
   Every frozen generic key/value является once-decoded output restricted RAW
   codec-а и проходит exact recursive parser-boundary `Q()` construction выше;
   count + per-key old-object CAS запрещает потерю concurrent new key.
   Все baseline setters стоят до ready-last. Ready доказывает exact применение
   baseline **до** `event.download.inserted_new`; после ready synchronous event
   hooks, их asynchronous descendants и user/plugin RPC принадлежат уже новой
   generation, authoritative и не сравниваются с old baseline при terminal
   confirmation. Worker не откатывает и не overwrites такие post-event writes.
   Protected
   metainfo slices (`info`, `libtorrent_resume`, unknown top-level
   values) никогда не decode/re-encode-ятся; только tracker keys проходят
   semantic decode и narrow encode. Snapshot strings decode-ятся XML codec-ом
   ровно один раз; command construction получает decoded bytes и применяет
   `Q()` рекурсивно ровно на каждом actually measured parser boundary. Повторный
   XML/entity decode либо quoting legacy-parser escaped artefact запрещён.

Upstream private/name eligibility behavior сохраняется. Opened non-regular
source, read error/short-read, source CAP+1, candidate preflight/append CAP+1,
depth >128, >1,000,000 nodes, duplicate keys или non-canonical integer/length
grammar теперь явно **недостижимы для mutation target по contract gate**.
Worker до `ea` пишет classified `source-not-regular|source-read-error|
source-short-read|source-too-large|candidate-too-large|source-too-deep|
source-too-complex|source-duplicate-key|source-bencode-invalid` и оставляет
original нетронутым. Это осознанное fail-closed narrowing вместо unbounded
CPU/memory parser/output allocation. P3 service label/marker guards не
встраиваются сюда.

## Atomic old-object commit, arm and surviving receipt

Только после всех pre-commit gates worker посылает one-shot `ea` arm callback
выше и продолжает лишь по exact `RETRACKERS_PHASE_ARMED`. Unknown/fault/
malformed arming никогда не retry/revoke-ится и никогда не dispatch-ит
destructive request; live `wa` и возможный delayed arm остаются sticky pending.

После acknowledged arm worker отправляет один top-level `branch`
targeting expected hash. Condition проверяет в одном daemon event-loop turn:

```text
method.has_key(rr.receipts.v1, ea:<tx>) === 1
AND method.has_key(rr.receipts.v1, wa:<tx>) === 1
AND live d.custom[retrackers-recovery] === argv handoff
AND live d.custom[retrackers-recovery-ack] === argv handoff
AND math.cnt=(d.custom.keys) === captured generic-key count
AND every captured generic d.custom_throw[key] === captured value
AND live tracker total/per-topology multiplicity/enabled membership === captured map
AND live d.local_id === handoff local id
AND live d.state === captured state
AND live d.is_active === captured is_active
AND live d.is_open === captured is_open
AND live d.hashing === captured hashing
AND live d.hashing_failed === 0
AND live d.directory_base === captured directory
AND live d.custom1 === captured custom1
AND live d.custom2 === captured custom2
AND live d.custom3 === captured custom3
AND live d.custom4 === captured custom4
AND live d.custom5 === captured custom5
AND live d.priority === captured priority
AND live d.throttle_name === captured throttle name
AND live d.tied_to_file === captured source guard
AND live d.loaded_file === captured loaded-file guard
```

Restoration subset этого tuple builder позднее применяет как baseline до ready;
включение whole old-generation tuple в CAS запрещает erase после user/plugin
metadata change между initial snapshot и commit. `d.is_active` и `d.is_open` не metadata для restoration, а
lifecycle predicates: они запрещают commit после concurrent runtime
transition. Name/private/metainfo hash immutable для generation и уже
проверены pre-commit.

False body возвращает exact `RETRACKERS_SKIPPED` и ничего не меняет. True body
ставит begin, двухаргументным `method.set_key` удаляет own arm и
не переходит из `d.stop` сразу к `d.erase`. `d.stop` синхронно
запускает `event.download.paused`, а internal `close()` в `d.erase`
запускает `event.download.closed`; любой из этих hooks может
изменить frozen metadata после outer CAS.

Поэтому exact single-request protocol сначала quiesce-ит object,
затем делает inner CAS и только после него erase-ит. Begin
ставится до первой mutation, done — только после успешного
возврата `d.erase`:

```text
started:
  method.set_key=rr.receipts.v1,eb:<tx>,1
  method.set_key=rr.receipts.v1,ea:<tx>
  d.stop
  d.close
  inner branch:
    if post-quiesce full CAS:
      d.erase
      method.set_key=rr.receipts.v1,ed:<tx>,1
      yield exact RETRACKERS_ERASED sentinel to outer cat
    else:
      yield exact RETRACKERS_QUIESCE_CHANGED sentinel to outer cat
  method.set_key=rr.receipts.v1,ex:<tx>,1              # terminal tail

stopped:
  method.set_key=rr.receipts.v1,eb:<tx>,1
  method.set_key=rr.receipts.v1,ea:<tx>
  d.close
  inner branch: same as above
  method.set_key=rr.receipts.v1,ex:<tx>,1              # terminal tail

outer false:
  yield exact RETRACKERS_SKIPPED sentinel only; write no receipt
```

`ex` стоит последней potentially stateful command только после begun true
path. Между предшествующей command и `ex` нет `execute*` или другого known
global-lock yield. Если любая command fault-ит раньше, `ex` отсутствует и
worker не классифицирует snapshot terminal, а остаётся в visible unbounded
`commit-completion-pending` reconciliation.

Post-quiesce full CAS требует exact исходные local id, marker,
exact handoff ack, `hashing_failed=0`, directory,
tied/loaded source, customs 1…5, priority, throttle name и full generic-map
count+values, а также exact tracker total/per-URL multiplicity/enabled map, но
lifecycle уже exact `d.state=0`, `d.is_active=0`, `d.is_open=0`,
`d.hashing=0`. После этого repeated internal `close()` в `d.erase`
вернётся до paused/hash-removed/closed hooks; `event.download.erased`
остаётся причинным следствием уже committed erase.
`RETRACKERS_QUIESCE_CHANGED` запрещает candidate, rollback и restart:
object остаётся stopped/closed с hook/user changes и visible diagnostic.

Canonical generator использует тот же `Q()` и recursive AST, что
и hook grammar. Он замораживает:

```text
success = cat=Q($d.erase=),Q($method.set_key=rr.receipts.v1,ed:<tx>,1),RETRACKERS_ERASED
innerCall = $branch=Q(postQuiesceCondition),Q(success),Q(cat=RETRACKERS_QUIESCE_CHANGED)
startedTrue = cat=Q($method.set_key=rr.receipts.v1,eb:<tx>,1),Q($method.set_key=rr.receipts.v1,ea:<tx>),Q($d.stop=),Q($d.close=),Q(innerCall),Q($method.set_key=rr.receipts.v1,ex:<tx>,1)
stoppedTrue = cat=Q($method.set_key=rr.receipts.v1,eb:<tx>,1),Q($method.set_key=rr.receipts.v1,ea:<tx>),Q($d.close=),Q(innerCall),Q($method.set_key=rr.receipts.v1,ex:<tx>,1)
falseBody = cat=Q(RETRACKERS_SKIPPED)
commit = branch=Q(outerCondition),Q(capturedState == 1 ? startedTrue : stoppedTrue),Q(falseBody)
```

Здесь `postQuiesceCondition` и `outerCondition` — exact `and=` trees из
predicates выше; every dynamic string operand проходит `Q()` на своём
parser layer. В nested command grammar перед ledger name нет XMLRPC
target placeholder.
PHP-built direct calls и `system.multicall` members к
`method.insert/list_keys/get/has_key/set_key` используют canonical empty
global-method target; wrapper mode для каждого остаётся ровно frozen plan выше.
Private flag запрещает direct invocation
ledger command через XMLRPC; trusted `method.has_key` при этом читает его
object storage. Это одинаково зарегистрировано в 0.9.8 и 0.16.21.

Нельзя разбивать ownership check, stop и erase на independent PHP requests.
Даже exact initial response sentinel не разрешает candidate сам по себе:
worker всегда ждёт terminal `ex` и читает receipts/snapshot. Transport/fault/
malformed/other sentinel — `unknown`, никогда implicit success.

Disposable 0.9.8 и 0.16.21 подтвердили old direct bodies и причину
их отбраковки: stopped и started objects становились absent, но
injected paused/closed hooks успевали изменить custom до removal.
Corrected quiesce+inner-CAS body и exact hook-race sentinel теперь
являются mandatory both-daemon implementation gate. Stale local id в outer
CAS вернул `SKIPPED` без mutation. После proven erase оба exact
`method.has_key` дали `1`.
Двухаргументный `method.set_key` удалил только own subkeys, сохранил adversarial
non-owned probe key (production init отверг бы его malformed grammar), дал exact
`0` при re-read, и те же имена сразу успешно переиспользовались.

### Cross-connection late-request fence

После unknown destructive response любой fresh read на новом SCGI
connection **не** доказывает, что старый request dropped. На обеих
daemon families independent connections могут enqueue main-thread callbacks в
обратном порядке: probe увидит original + missing receipts, а
задержанный destructive request затем erase-нет object. Прежняя
`both missing => dropped` classification опровергнута.

Shipped `history` erased hook на 0.9.8 вызывает foreground `execute.nothrow`;
`ExecFile::execute` освобождает global lock во время wait. Поэтому branch может
уже поставить `eb`, consume `ea`, yield-нуть внутри stop/close/erase hook, а
другой SCGI callback увидеть промежуточный state. Именно поэтому target не
пытается revoke/disarm-ить accepted request и не превращает fresh absence в
terminal proof.

Commit dispatch посылается один раз. Exact `RETRACKERS_SKIPPED` reply означает,
что false body завершился и ничего не писал. При fault/malformed/transport
unknown worker только read-ит receipts: `eb` absent остаётся
`commit-dispatch-pending` без cleanup/retry/second phase; `eb=1` требует
`ex=1`. Если begun branch fault-нул до tail, `ex` не появится: worker сохраняет
`wa`, пишет rate-limited `commit-completion-pending` и ничего второго не
dispatch-ит. Delayed request либо eventually поставит `eb`, либо останется
безопасным sticky unknown; terminal cleanup не может удалить `wa` под ним.

## Response-lost stop/erase state machine

Worker читает exact begin/done/exit receipts и делает fresh object snapshot.
Только exact known reply либо begun+terminal-tail proof определяет outcome:

| Dispatch/begin/done/exit + fresh state | Действие |
|---|---|
| exact known `SKIPPED`, begin `0` + same local id + exact own marker/ack | branch не вошёл; release handoff без candidate, не сравнивая и не меняя authoritative concurrent drift остальных fields |
| exact known `SKIPPED`, begin `0` + absent/foreign/wrong marker или ack | branch не вошёл; no mutation, terminal quarantine без release |
| unknown, begin `0` + любое snapshot | delayed/dropped неразличимы; sticky pending, ничего не cleanup/retry/dispatch |
| begin `1`, exit missing | callback всё ещё выполняется/yield-нут либо fault-нул до tail; unbounded pending, snapshot не terminal |
| begin `1`, exit `1`, done `1`, absent | own erase доказан; candidate dispatch разрешён |
| begin `1`, exit `1`, done missing, exact original pre-quiesce tuple | branch вошёл, но object mutation не началась; оставить original |
| begin `1`, exit `1`, done missing, exact post-quiesce tuple | own stop/close прошли, erase не доказан; visible `partial-quiesce-ambiguous`, no restart/candidate |
| begin `1`, exit `1`, done missing, stopped/closed tuple с changed frozen field | paused/hash-removed/closed hook или concurrent writer; `quiesce-changed`, no touch/restart/candidate |
| begin `1`, exit `1`, done missing, absent | ambiguous erase failure/user delete; no candidate, no rollback |
| done `1`, exit `1` + any present object | object появился после own erase; foreign/user generation, no touch |
| structurally invalid receipt либо changed field в begun prefix, где таблица требует exact tuple | classified refusal; не угадывать и не мутировать |
| transport/persistent unknown | unbounded rate-limited pending; не считать terminal |

Автоматического restart нет. После worker-owned `d.stop` concurrent user
`d.stop` является idempotent и не меняет ни один observable field, поэтому даже
full-tuple CAS не может отличить user stop intent от собственного partial stop.
Fail-closed outcome оставляет exact original object остановленным и пишет один
visible `partial-quiesce-ambiguous`; user/operator решает, открывать/запускать ли
его снова.
Это редкий liveness loss после daemon fault между stop и erase, но не скрытое
нарушение user intent. Worker вообще не вызывает `d.start`.

Exact response sentinel begun true body без `ex` не подтверждает completion.
Только `ed=1 && ex=1` плюс absent snapshot разрешает candidate. Phase receipts
не удаляются отдельно: они остаются evidence до единого terminal cleanup ниже.
In-memory ledger не является crash journal; daemon/host crash между receipt и
erase/load остаётся explicit non-goal.

### Transaction-owned absent gap

Done subkey доказывает собственный erase, но не делает отсутствующий hash
наблюдаемым для других writers. Между old `d.erase` и armed candidate file load
есть intentional absent gap. Если user/remove endpoint именно в этот промежуток
пытается удалить hash, daemon отвечает missing-hash и не оставляет intent;
worker затем законно загрузит candidate. Это не та же гонка, что успешное
удаление присутствующей original/candidate generation: такую generation full
CAS не трогает, а observed candidate absence никогда не запускает rollback.

Frozen guarantee поэтому узкий: package не resurrect-ит generation после
успешного foreign/user erase **присутствующего** object и не overwrite-ит
foreign same-hash generation. Любой successful target command, принятый
daemon-ом для присутствующей generation, сохраняется full-CAS/post-event
authority rules выше. Но stop, pause, start, setter или erase, нацеленный в
owned absent gap, получает missing-hash и не оставляет intent. Такое intent
недостижимо в exact six-path scope: соответствующие command endpoints должны
писать tombstone/ledger либо сериализоваться с rewrite transaction. Это
отдельный durable package, не скрытая гарантия этого контракта.

## Candidate and rollback acknowledgement

`rTorrent::sendTorrent()` здесь вообще не вызывается: он добавляет filename,
directory/comment/label до `$addition`, поэтому transaction claim не является
первой plugin-supplied creation command и partial failure оставляет unowned
same-hash object.
`update.php` владеет file-backed builder. Exact procfd capability передаётся
в `load.normal` для captured state `0` и `load.start` для `1`;
creation commands идут строго в таком порядке:

```text
candidate:
  d.custom[retrackers-recovery] = v1:candidate-claim:<tx>   # first
  d.custom[retrackers-recovery-ack] = empty
  each frozen generic custom except recovery/ack = exact value, byte-sorted key
  each final candidate URL = t.multicall URL-match -> exact t.enable|t.disable
  d.directory_base = preserved canonical directory
  d.tied_to_file = empty
  d.loaded_file = empty
  d.custom1 = exact preserved value
  d.custom2 = exact preserved value
  d.custom3 = exact preserved value
  d.custom4 = exact preserved value
  d.custom5 = exact preserved value
  d.priority = exact preserved integer
  d.throttle_name = exact preserved value
  assert exact candidate tracker topology + enabled map or fault
  d.custom[retrackers-recovery] = v1:candidate-ready:<tx>   # last

rollback:
  d.custom[retrackers-recovery] = v1:rollback-claim:<tx>    # first
  d.custom[retrackers-recovery-ack] = empty
  each frozen generic custom except recovery/ack = exact value, byte-sorted key
  each rollback/source URL = t.multicall URL-match -> exact t.enable|t.disable
  d.directory_base = preserved canonical directory
  d.tied_to_file = empty
  d.loaded_file = empty
  d.custom1 = exact preserved value
  d.custom2 = exact preserved value
  d.custom3 = exact preserved value
  d.custom4 = exact preserved value
  d.custom5 = exact preserved value
  d.priority = exact preserved integer
  d.throttle_name = exact preserved value
  assert exact rollback tracker topology + enabled map or fault
  d.custom[retrackers-recovery] = v1:rollback-ready:<tx>    # last
```

Tracker restore commands сортируются по decoded URL bytes и применяются для
каждого final candidate exact URL: surviving captured URL получает captured
uniform state; new URL получает frozen load-time policy (HTTP-family enabled,
UDP на 0.9.8 равен captured `trackers.use_udp`, UDP на 0.16.21 enabled).
State `1` вызывает URL-selective `t.enable`, state `0` — `t.disable`, независимо
от intended torrent state.
Это обязательно для 0.9.8: при global `trackers.use_udp=0` factory создаёт UDP
row disabled, хотя old live row мог быть вручную enabled. 0.16.21 hardwires
этот setting true. Explicit command нужен и для new URL: preserved resume
tracker map применяется раньше creation list и иначе может override daemon
default. Deleted URL не match-ится; uniform duplicates получают одно endpoint
state. Обе command forms измеряются real-daemon runtime.

Immediately before ready builder ставит transaction-specific assertion command.
Его order-independent predicate требует exact expected ordinary candidate
multiset `(group,URL,type,extra=0)`, captured synthetic `dht://` presence и
expected enabled membership после explicit surviving-URL commands; unsupported,
trimmed, normalized-away или missing disabled target не проходит. False branch
намеренно вызывает `d.custom_throw` по fresh tx-specific key, absence которого
доказана frozen generic map. На обеих families creation-command input error
ставит `hashing_failed=1` и не достигает state/`inserted_new`; claim-prefix
cleanup/rollback затем применяет обычные ownership rules. Только true predicate
ставит ready последней command. Поэтому URL, молча отброшенный loader-ом, и
no-op `t.disable|t.enable` не могут дать `candidate-confirmed`.

Как и остальные baseline fields, enabled state после `inserted_new` не force-ится
назад: subsequent toggle новой generation authoritative.

`load.normal`/`load.start` регистрируют file source как tied и loaded, поэтому
оба поля сначала равны capability path. Оба затем exact clear-ятся creation
commands на 0.9.8 и 0.16.21. Отсутствие private 0.9.8 setter в exported
`system.listMethods` не означает отсутствие registered creation command;
disposable load на обеих versions прочитал exact empty после clear. Directory
валидируется до old erase; каждое command value проходит один
canonical rTorrent-argument escaper. Builder отправляет тот же
frozen XML, который прошёл full-length check, без второго build
pass. Supported operator/plugin tuple здесь exact; transfer totals,
state-change timestamps/counters, peer/file priorities, views, connection
heuristics and per-download peer/upload limits не обещаются этим recovery
package и перечислены как compatibility non-goal ниже.

Перед candidate wrapper worker one-shot arm-ит `la:<tx>` и требует exact
`RETRACKERS_PHASE_ARMED`. Top-level trusted branch проверяет live `wa` + arm.
Его true body имеет exact order:

```text
schedule <rr-lf-<tx>>, +1 second, one-shot:
  if wa:<tx>=1 AND lb:<tx>=1:
    method.set_key=rr.receipts.v1,lf:<tx>,1
method.set_key=rr.receipts.v1,lb:<tx>,1
method.set_key=rr.receipts.v1,la:<tx>          # consume arm
load.normal|load.start=<candidate-stage>,<creation commands above>
return exact RETRACKERS_LOAD_DISPATCHED
```

False body — command `cat=RETRACKERS_LOAD_CHANGED`, без receipt write. Canonical schedule и remove
names получаются только через version map
`getCmd('schedule')`/`getCmd('schedule_remove')`; start exact decimal string `1`,
interval exact `0`, name exact `rr-lf-<tx>`. Schedule ставится **до**
`lb` и load: если `lb=1`, fence уже обязательно зарегистрирован;
если schedule fault-нул, load ещё не dispatch-нуть. Callback additionally
requires `wa+lb`, поэтому schedule-created/lb-fault и late fire после terminal
cleanup не могут создать orphan fence. Для rollback
используются distinct `ra/rb/rf` и `rr-rf-<tx>`.

`claim` отличает partial creation command execution. `ready` доказывает, что
все restoration commands дошли до конца; затем rTorrent устанавливает state и
синхронно вызывает `event.download.inserted_new`. Hook копирует ready marker в
ack. Поэтому authenticated success после corresponding scheduler fence требует
одного fresh snapshot:

- expected hash exists;
- exact corresponding `*-ready:<tx>`;
- ack exact равен тому же `*-ready:<tx>`;
- `d.hashing_failed` exact wire integer `0`;
- local id — canonical uppercase 40 hex.

State, directory, `custom1`…`custom5`, priority, throttle, generic map и
tied/loaded fields после event намеренно **не** сравниваются с old baseline:
ready-last уже доказал его применение, а любое последующее synchronous hook,
async descendant либо user/plugin изменение новой generation authoritative.
`load.normal`/`load.start` доказывает intended baseline state до event; если
после него пользователь остановил torrent или plugin изменил metadata, success
сохраняет это состояние. Handoff release меняет только reserved marker/ack.

### Post-dispatch daemon-scheduler fence

Load response означает только dispatch. И первый отдельный RPC reply после него
тоже **не** является barrier: callbacks от independent SCGI connections могут
попасть в main-thread queue в обратном порядке. Snapshot, который уже ответил
`absent`, не запрещает ранее принятому `load` создать object позже.

Barrier создаёт только one-shot scheduler item, зарегистрированный внутри того
же armed wrapper **до** `lb` и load. Оба factory tasks local file load получают
stored due time `T`, равный daemon cached time wrapper callback. Fence получает
stored due time строго больше `T`:

```text
rTorrent 0.9.8:
  DownloadFactory::load()/commit() -> priority_queue_insert(..., cachedTime)
  schedule2 first=1 -> (cachedTime + 1s).round_seconds()
rTorrent 0.16.21:
  DownloadFactory::load()/commit() -> scheduler wait_for(..., 0ms)
  schedule first=1 -> ceil_seconds(cached_time + 1s)
```

В 0.9.8 `round_seconds()` округляет вниз, но `floor(T+1s)` всё равно strictly
greater than любое fractional `T`; в 0.16.21 ceiling также strictly greater.
Factory load — только local absolute path: HTTP/magnet follow-up здесь
запрещён. Priority queue исполняет меньший stored timestamp раньше независимо
от того, увидит ли event loop forward/backward wall-clock jump; jump может лишь
задержать либо одновременно сделать due обе группы. Поэтому exact
`lf:<tx>=1`/`rf:<tx>=1` доказывает, что обе factory tasks уже получили scheduler
turn и local load terminally завершился: creation commands и полный synchronous
inserted_new hook chain либо выполнились, либо factory уже отказал. Both-daemon
foreground-hook probe с 4-second `execute.nothrow` не дал fence во время
global-lock wait и поставил его только после later ack hook. Сам scheduler item при interval `0`
остаётся unqueued one-shot entry; worker не посылает отдельный state-writing
remove. Оба unique schedule names удаляет только capability-first terminal finalizer
ниже, и reply removal никогда не используется как safety proof.

Known exact `RETRACKERS_LOAD_DISPATCHED` всё равно ждёт corresponding fence.
Known exact `RETRACKERS_LOAD_CHANGED` доказывает завершённый false body без
scheduler/load. Fault, malformed reply или transport failure — unknown; worker
не revoke/disarm-ит arm и не dispatch-ит load повторно. Он только читает begin:

- `lb/rb` absent: dropped и delayed wrapper неразличимы; `wa`, capabilities и
  arm остаются sticky, `load-dispatch-pending` rate-limit-ится без finite exit;
- `lb/rb=1`: schedule был зарегистрирован раньше begin; worker ждёт exact
  `lf/rf=1` без finite exit. Даже если load command fault-нул после begin,
  scheduler receipt terminally отделит эту попытку от следующей;
- malformed/collision/transport: no close, no second load/rollback; тот же
  unbounded reconcile loop с classified rate-limited diagnostic.

После fence один fresh exact snapshot является terminal для этой попытки.
После `lf` закрывается только candidate handle. Original handle закрывается
лишь после terminal no-rollback class либо после `rf`; cleanup, который ещё
может разрешить rollback, его не закрывает. Persistent missing fence оставляет
worker и соответствующие capabilities живыми; daemon/host kill этого worker
остаётся явным crash non-goal, но normal transport ambiguity никогда не создаёт
второй pending load.

### Candidate outcome and owned cleanup

После exact `lf:<tx>=1` candidate snapshot имеет один terminal class:

- authenticated ready+ack candidate с `hashing_failed=0` — success независимо
  от post-event mutable state;
- absent — ambiguous: load мог быть rejected либо user мог удалить уже созданный
  candidate до snapshot; no rollback/no resurrection;
- foreign/original marker or foreign local state — no touch, no rollback;
- exact `candidate-claim:<tx>` + `hashing_failed=1` + snapshot, совпадающий с
  одним из finite valid creation-command prefixes — owned creation-list partial;
- exact `candidate-ready:<tx>` + ack getter exact empty string — owned valid
  new generation с incomplete retrackers hook. Любые sibling/user side effects
  уже могут быть authoritative; поэтому `candidate-hook-incomplete`, no touch,
  no cleanup, no rollback;
- exact ready/ack (или ready/empty ack) + любой nonzero `hashing_failed` —
  terminal `candidate-load-failed`, no touch/no rollback/second load;
- ready+ack exact + `hashing_failed=0` при любом mutable field/state drift —
  success; drift принадлежит post-event new generation и сохраняется;
- ready + любой wrong non-empty ack — external/stale mutation; no touch/no
  rollback. Builder пишет exact empty, а hook может записать только exact ready;
- persistent unknown — no touch, no rollback.

Valid prefix set строится до erase из exact command order, daemon defaults и
expected values: каждый prefix означает, что первые N commands применились, а
следующая нет. Candidate без `rtorrent` section получает empty customs,
capability path в tied/loaded sources и captured daemon default
directory до restoration commands. Creation list затем делает tied и
loaded-file exact empty на обеих versions. Claim snapshot без ready, не
совпавший ни с одним exact prefix, считается user/plugin changed и не
cleanup-ится. Ready-generation никогда не входит в partial cleanup независимо
от ack/metadata.

`hashing_failed` — обязательная часть каждой post-load snapshot, а не
диагностическая косметика. На 0.16.21 malformed preserved
`libtorrent_resume` может поставить flag `1`, затем всё равно выполнить все
creation commands, state set и insert hook; без этого predicate ready=ack дал бы
ложный success. На 0.9.8 тот же resume exception прерывает factory раньше и
обычно оставляет object absent. Command-list exception после claim на обеих
версиях ставит flag `1`, поэтому только такая exact форма входит в valid prefix
cleanup. Ready/ack с flag `1` не cleanup-ится: source rollback сохраняет те же
raw resume bytes и не является безопасной второй попыткой.

Owned partial cleanup не erase-ит object только по hash. Worker captures exact
local id, marker, ack, state,
is_active, is_open,
hashing, hashing_failed, directory, tied/loaded source, customs 1…5, priority,
throttle name, full generic-map count+values и actual tracker
topology/enabled tuple.
Отдельный one-shot arm callback ставит `ca:<tx>=1` и требует exact
`RETRACKERS_PHASE_ARMED`; cleanup branch требует live `wa`, этот arm и весь
captured tuple.
True body exact повторяет old-object quiesce protocol:

```text
method.set_key=rr.receipts.v1,cb:<tx>,1
method.set_key=rr.receipts.v1,ca:<tx>             # consume arm
if captured state == 1: d.stop
d.close
inner branch over exact frozen metadata/local id/marker/ack plus
  state=0,is_active=0,is_open=0,hashing=0 and exact captured hashing_failed:
    d.erase
    method.set_key=rr.receipts.v1,cd:<tx>,1
    yield RETRACKERS_CANDIDATE_ERASED sentinel to outer cat
  else yield RETRACKERS_CANDIDATE_QUIESCE_CHANGED sentinel to outer cat
method.set_key=rr.receipts.v1,cx:<tx>,1           # terminal tail

outer false:
  yield RETRACKERS_CANDIDATE_SKIPPED sentinel only; write no receipt
```

Claim-prefix cleanup требует ready absent и captured `hashing_failed=1`.
Ready-generation с любым ack никогда не входит в cleanup. Любое concurrent
изменение claim-prefix object делает outer/inner branch false. Exact known
`RETRACKERS_CANDIDATE_SKIPPED` завершает false body без receipt. После unknown
cleanup response worker только read-ит keys: `cb` absent означает sticky
`cleanup-dispatch-pending`, не dropped/cancelled; `cb=1` unbounded ждёт `cx=1`.
До `cx` snapshot и отсутствие `cd` не terminal, включая synchronous hook yield.
Только `cd=1 && cx=1` + absent доказывает own cleanup erase и разрешает
rollback; `cb=1,cx=1,cd=0` классифицируется по surviving/absent tuple без
resurrection. Foreign object не трогается. Rollback запрещён без
cleanup-done+completion+absence proof.

Disposable 0.16.21 forced-partial capture поставил invalid creation command
после claim и перед ready. Load dispatch вернул `0`; ранний отдельный read
увидел exact claim и отсутствие ready, но больше не считается barrier.
Conditional cleanup вернул erase
sentinel и оставил surviving ledger subkey при absent download. Final
implementation runtime дополнительно обязан pin-ить exact ack и begin/done
subkeys, а не принять этот более ранний probe как proof всей новой grammar.

### Rollback terminal outcome

Rollback разрешён только после доказанного owned-partial cleanup; plain
candidate absence его не запускает. Он dispatches captured original bytes
byte-for-byte тем же claim-first builder, distinct `ra/rb/rf` и всегда проходит
daemon-scheduler fence. Full exact rollback —
`rollback-confirmed` по тому же authenticated ready+ack boundary независимо от
post-event mutable state. Exact rollback claim без ready остаётся видимым
`rollback-partial`, но больше не erase-ится и не запускает третий load;
ready+empty/wrong ack также no-touch terminal failure, не partial cleanup.
Rollback `hashing_failed != 0` — terminal `rollback-load-failed`, no touch и no
third load. Absent, foreign или persistent unknown — terminal classified
failure. Worker вообще не вызывает `d.start`.

Duplicate same-hash rejection до creation commands остаётся defense-in-depth,
но больше не используется как justification для двух одновременно pending
loads: любой второй load требует terminal scheduler-fence classification
первого.

### Terminal handoff release and no-change outcome

Dedicated marker/ack — transaction quarantine, а не persisted completion
state. Успешно released surviving object заканчивает с exact empty values обоих
keys; failed/unknown release остаётся видимой quarantine, но marker-specific
capability после terminal cleanup уже не позволяет будущему `inserted_new`
переписать ack. Release применяется:

- к exact original после authenticated no tracker change, empty configuration,
  private/name exclusion и любой pre-commit refusal, где generation ещё
  доступна и exact own marker/ack доказаны;
- к `candidate-confirmed` после terminal `lf` snapshot;
- к `rollback-confirmed` после terminal `rf` snapshot;
- к same-generation original после exact known `commit-skipped` и exact own
  marker/ack. Outer false доказывает zero worker mutation, поэтому concurrent
  user/plugin drift остальных fields authoritative, не блокирует release и не
  сравнивается/не откатывается.

Ambiguous absence, foreign generation, owned partial, hashing failure,
completion-pending и changed marker/ack не release-ятся: quarantine остаётся с
classified visible reason. Invalid argv и worker-never-started не имеют
исполняющего recovery process; их automatic retry/supervision остаётся явным
P3 liveness non-goal, а startup PHP/run.sh validation не выдаётся за exec ack.

Release посылается **ровно один раз** как non-yielding daemon branch по live `wa`, exact current
`d.local_id`, exact own marker и exact own ack. True body clear-ит **сначала
ack, затем marker** и возвращает `RETRACKERS_HANDOFF_RELEASED`; он не меняет ни
одного user/runtime field. Response lost reconciliation только read-ит и не
повторяет state-writing clear:

1. both values empty + same local id означает observed terminal release;
2. unchanged exact own marker+ack либо own marker+empty ack означает dropped,
   delayed или first-command prefix; `handoff-release-pending`, live `wa`, zero
   retry/terminal cleanup и restart-required при persistent state;
3. wrong non-empty value, changed local id или absent object не touch-ится и
   становится known terminal quarantine; ordered capability-then-`wa` cleanup
   сначала отключит hook writer, затем возможный delayed release до его execution;
4. transport-unknown read остаётся pending и сохраняет `wa`.

Оба clear setter-а оставляют reserved generic-map members с empty string; key
presence не меняется, поэтому full-map count invariant сохраняется. Delayed
request безопасен: `wa` + same-generation ownership predicates делают его no-op
после terminal cleanup/foreign reload. No-change path никогда не arm-ит `ea`,
не stop/close/erase-ит и после release возвращает `no-change`.

### Terminal worker lease and receipt cleanup

Handoff release и worker lifetime — разные proofs. Safe surviving exact object
сначала проходит release; ambiguous absent/foreign/partial/hash-failed object
намеренно остаётся quarantined. Но в обоих случаях, как только outcome known
terminal и больше нет unknown arm/dispatch, missing tail/fence или другого
возможного mutating callback, worker обязан убрать active lease.

Один non-yielding cleanup callback сначала двухаргументно удаляет обе exact
marker-capabilities, затем `wa:<tx>`, оба unique schedule names и все
четырнадцать phase receipts; readback доказывает absence каждого ledger key.
Capability-first order обязателен: inserted hook predicate-ит capability и
после её удаления уже не может писать ack. Следующее удаление `wa` отключает
phase/release/scheduler state-writing callbacks. Callback до cleanup уже
завершён и его receipt удаляется; callback/hook после соответствующего fence
больше не authorizes write. Это также закрывает schedule-created/
lb-fault orphan: late `lf/rf` не может recreate key, а replayed inserted event
не может превратить quarantined ready+empty/wrong ack в success.

Phase/object/scheduler writers после unknown никогда не retry-ятся. Terminal
cleanup — единственное явно разрешённое исключение. Response unknown при live
`wa` не retry-ится: request может быть dropped, delayed либо остановиться после
capability prefix, поэтому lease остаётся sticky/restart-required. Если fresh
read доказывает `wa` absent, обе capabilities обязательно уже были удалены
предшествующим prefix; оставшиеся operations только delete names unique этому
tx и могут быть повторены/readback-нуты. `wa` absent поэтому является вторым
fence, после которого ни hook, ни target writer не создаёт tx key.

Known terminal candidate absence, foreign generation, ready/ack mismatch,
hashing failure, owned non-rollback partial и failed release reconciliation с
known changed generation оставляют quarantine как есть, но всё равно удаляют
`wa` и receipts. Genuinely pending dispatch/arm/completion/fence либо transport
unknown не запускает cleanup и сохраняет `wa`; worker crash оставляет sticky
lease. Done поэтому различает active recovery от terminal quarantined object.

## Diagnostics and non-goals

Routine records содержат hash + один classified reason, но не raw XMLRPC fault,
third-party text, tracker URL, metainfo bytes или filesystem payload. Required
classifications включают:

```text
hook-neutralization-unconfirmed
historical-hook-restart-required
lifecycle-acquire-unconfirmed
hook-active-unconfirmed
hook-ack-capability-missing
hook-install-unconfirmed
hook-deferred-replay-pending
hook-teardown-pending
hook-teardown-unconfirmed
lifecycle-busy
receipt-ledger-corrupt
profile-binding-unstable
profile-binding-writer-untrusted
shared-daemon-owner-ambiguous
shared-daemon-owner-ambiguous-uncontained
shared-daemon-contained
initial-absent
initial-transport
initial-fault
initial-malformed
initial-hashing-failed
lifecycle-unsupported
ownership-mismatch
receipt-preflight-failed
source-unreadable
source-not-regular
source-read-error
source-short-read
source-decode-failed
source-hash-mismatch
source-too-large
source-too-deep
source-too-complex
source-duplicate-key
source-bencode-invalid
candidate-too-large
runtime-map-unstable
runtime-map-invalid
runtime-value-unrepresentable
runtime-tracker-topology-mismatch
tracker-state-ambiguous
load-command-too-large
candidate-hash-mismatch
commit-skipped
commit-arm-pending
commit-dispatch-pending
commit-completion-pending
partial-quiesce-ambiguous
quiesce-changed
commit-absent-ambiguous
load-arm-pending
load-dispatch-pending
load-fence-pending
terminal-cleanup-pending
candidate-tracker-projection-failed
candidate-confirmed
candidate-partial
candidate-absent-ambiguous
candidate-prefix-changed
candidate-hook-incomplete
candidate-load-failed
candidate-cleanup-confirmed
candidate-cleanup-skipped
cleanup-arm-pending
cleanup-dispatch-pending
cleanup-completion-pending
candidate-unconfirmed
rollback-confirmed
rollback-partial
rollback-load-failed
rollback-unconfirmed
foreign-generation
handoff-release-pending
handoff-release-changed
no-change
```

Expected stale-hash fault ставит request `important=false`; classifier использует
exact normalized fault contract, не substring и не raw logging.

Explicit non-goals:

- crash/host kill после erase до confirmed load;
- durable wake/replay journal;
- automatic retry/supervision, если detached worker вообще не дошёл до CLI
  entrypoint либо умер после transaction quarantine;
- linearizable delete intent, полученный в transaction-owned absent gap;
- automatic restart после ambiguous begin-only partial quiesce;
- guaranteed background exec acknowledgement на rTorrent 0.9.8;
- automatic cleanup/replay leaked valid ledger subkeys after worker death;
- automatic recovery from an unknown one-shot acquire/arm/dispatch whose begin
  receipt never appears; safety requires sticky lease/restart because dropped
  and delayed requests are not causally distinguishable;
- remote-daemon mode или отсутствие readable `/proc/<worker-pid>/fd` file
  capabilities;
- hard overall wall-clock reconciliation deadline поверх bounded individual
  calls approved SCGI transport;
- forcing old baseline back over `inserted_new` hooks, their async descendants
  или user/plugin writes новой generation после ready-last boundary;
- preservation transfer totals, state-change timestamps/counters, views,
  per-file priorities, connection/choke heuristics и per-download peer/upload
  limits вне exact supported operator/plugin tuple;
- P3 service wrappers/guards;
- изменение tracker-list semantics pinned upstream sequence suite.

## Natural RED, mutations and runtime gates

Current-base RED/mutation suite обязана доказать:

1. import sentinel загружает `update.php` из init/done без argv/config/fs/RPC и
   без unloaded-parent fatal; direct CLI invalid argv даёт zero include/file/RPC/
   log side effects;
2. hook не содержит stop/close/erase, ставит `wh`, затем `wp`, ack, marker,
   launch и tail-delete `wh`; delayed first-child completion before hook return,
   launch throw и worker-never-started pin-ят `wh/wp` safety. Marker-first ack,
   legacy `custom3=1` clear и ordinary non-1 preservation покрыты отдельно.
   Candidate/rollback ack требует exact live-marker capability: absent, wrong и
   foreign capability дают zero write; при live capability только exact empty
   ack может стать marker, а wrong non-empty/`"0"` остаётся byte-identical;
   после terminal cleanup replay ready+empty/wrong-ack также ничего не пишет.
   Обе daemon families исполняют
   marker/ack comparison только в exact command-form
   `equal=d.custom=retrackers-recovery-ack,d.custom=retrackers-recovery`;
3. full-service historical boundary pin-ит zero old PHP workers, zero reserved
   retrackers names/recovery evidence/ledger object, fresh one-time private
   `rr.receipts.v1` insert и exclusive-current-writer inventory. Post-producer
   exact V2 pair validates one of six phases, action-bound `pf`, persistent `pv`
   and extended single owner. BOOTSTRAP/current acquire atomically safety-gate,
   rotate/create
   epoch, create `to:T:i:H(U)` + prepared claim + D/D; final init rotates epoch,
   writes claimed F/S and releases owner/ta last. Accepted-response-lost never
   retries/releases. Missing/multiple pv, `newPv == sampled current oldPv`, old
   owner, injected action map, action-only/claim-only drift and every callback
   crash prefix are named RED. Mandatory profile ABA evidence uses three
   explicit pairwise-distinct epochs `A`, `B`, `C` across `F/S@A → D/D@B →
   F/S@C` and fails when the middle protected transition omits its required
   rotation. Reuse of an earlier now-absent epoch is unobservable without a
   history store and remains only the negligible 128-bit CSPRNG non-reuse
   assumption; stable self-consistent trusted forgery is only
   `profile-binding-writer-untrusted`, not claimed detectable;
4. `dq` ставится before `di`, consumer one-shot clear-ит `dq` before fresh scan.
   Empty-scan/producer/late-clear ABA, delayed response-lost clear и event на
   final release boundary не теряют deferred local id;
5. second-profile INIT_OWNER containment rotates pv, ставит `ma` before action
   overwrite, changes i owner to c, rewrites every pair S/S and deletes all pf;
   waits `wh`, не delete-ит `wa`, drains `di/dq`, conditionally releases each
   `wp`, then c-cleanup rotates pv and releases owner/ta last. `i+ma`, c without
   ma, surviving claim, functional/defer under ma, pre-ma drain, adopt races and
   every epoch/owner/action/claim prefix are RED;
6. done only from own sole IDLE F/S atomically gates ta, rotates pv, sets exact d
   owner, S/S and retains pf; absent-own is no-op, foreign DONE_OWNER invalid.
   It waits `wh`, linearizes `wp` cancel/adopt, waits active `wa|phase`, then
   final callback rotates pv and direct-deletes key1/key2/pf/owner, ta last.
   `cat=` overwrite, DONE_OWNER+ma, missing pair/claim, foreign profile or delete
   при active receipt — named RED;
7. private modifiable ledger set/has/delete/reuse/final-delete не затрагивает
   чужие valid keys. Adopt response-loss попадает в idempotent `wa=1/wp=0`
   case; exact no-adopt absent/foreign terminal очищает только `wp`, unknown
   сохраняет его. Initial collision preflight отдельно pin-ит каждую из
   семнадцати own keys, включая pre-existing `wa`: ни одна не позволяет
   initial adopt создать или принять lease;
8. production adapter на exact final-parent interface делает ровно один
   `rSCGITransport::send()` с nine ordered arguments: host, port, payload,
   `true`, `0.25`, by-reference failure, independent
   `isset($rpcTransferTimeOut) ? ... : null`, independent
   `isset($rpcMaxResponseBytes) ? ... : null`, `RESPONSE_RAW`. PHP 7.4 child с
   warnings-as-exceptions доказывает configs: neither symbol, only transfer,
   only response cap и both present; values занимают только positions 7/8.
   Success, null transport, malformed RAW и XMLRPC fault делают exactly one send
   и используют `retrackersDecodeRestrictedRawResponse()` без legacy fallback.
   Large delayed-first-byte multicall не получает hardcoded 0.25-second read
   budget. Static RED всех шести paths запрещает `fsockopen`, `stream_socket*`,
   private SCGI framing и `rXMLRPCRequest::send()`;
9. failed open, non-regular descriptor, false/empty/positive-short read,
   source CAP+1, scanner/hash и candidate CAP+1/preflight/append failures
   происходят до **all** temp-file, arm, RPC dispatch и old-object mutation
   side effects. Procfd/full-XML request и direct RPC/codec failures также
   происходят до arm/old-object mutation. FIFO/socket/device fake имеет zero
   reads; exact CAP доказывает final one-byte EOF probe;
10. scanner хранит raw `(offset,length)` в одном immutable capture, never rereads
    pathname/не передаёт path в `Torrent`; raw source hash остаётся unchanged.
    Exact 32-bit subtract-before-add preflight выполняется до allocation, exact
    CAP accepts/CAP+1 rejects, bounded append checks every crossing and slices
    raw/generated payload не больше 64 KiB. Final scanner предшествует staging,
    candidate drops only `rtorrent`, changes tracker keys, preserves raw `info`,
    resume and unknown spans, while rollback equals capture bytes exactly;
11. request-schema codec matrix на literal two-family captures принимает все
    final-plan modes: direct empty/non-empty two-uppercase-40-hex-string
    `d.multicall2` rows,
    direct empty/non-empty five-column `t.multicall` rows, direct
    `d.custom.items` present/empty struct, ordered populated/empty
    `system.multicall(list_keys,get,has_key...)`, targeted direct `has_key`,
    request-specific scalar i4/i8, direct fault и member fault. Он требует exact
    wrapper nesting, outer/member/row/flat-list counts, row width/cell order,
    list/map key-set equality, string-`1` map values, i8 `has_key=0|1` и full
    consumption; unused direct/member permutations are RED. Separate empty
    captures pin-ят 0.9.8 explicit CRLF containers и 0.16.21 self-closing
    containers. Measured erase adversary даёт captured two-cell partial
    `d.multicall2` row на 0.16.21; на 0.9.8 daemon exits 139, OOM=false и
    возвращает zero bytes, поэтому там transport failure является единственным
    честным evidence. Empty `t.multicall` invalid-wrapper row source-real, но
    deterministic public producer не найден: exact synthetic `[[]]` остаётся
    rejection fixture и не подменяется captured empty outer list. Все эти
    формы отвергаются до consumer use; capture остаётся authority accepted
    shapes. Missing-target fault never becomes zero rows. Named adversarial
    RED отвергают missing/extra/reordered row/cell/member, flat-vs-row/struct
    confusion, wrong success/fault wrapper/tag/nesting/count, attributes,
    namespaces, DTD/entity declarations, malformed UTF-8/entities/tags, `<ix>`,
    implicit/nested types, duplicate member, extra node и prefix/suffix/trailing
    bytes. Entity decode выполняется once; literal LF у closing tag сохраняется.
    Generic CAS pin-ит recursive
    `left/right/condition`, recreation, empty/LF/backslash/quote/comma round trip
    на обеих families и ordinary LF-terminated `addtime`; one-layer equality,
    leading `$`, literal/referenced CR и NUL — named RED. Historical binding
    branch принимает только exact replacement-five same-family pair with event
    list/map, width-4 rows and ledger list/string-`1` map; validates exact
    profile/claim/epoch/owner phase and derives membership from event set before
    exposing only sample 2. Any-slot fault, slot 4/5 mismatch, sixth tail,
    missing/extra/reordered/direct swap, family/event/row/ledger/count/digest
    drift, injected action allow-map, 0.9.8 struct, 0.16.21 i4, partial row,
    consumer before full pair and every old V1 BODY identity are named RED;
12. source/live tracker projection covers N=0 (including captured private
    0.16.21 trackerless fixture), DHT on/off/private, shuffled tier,
    total/multiplicity/group/extra/enabled drift, uniform duplicates and mixed
    duplicate refusal. `d.tracker.insert` extra row and URL/group/enabled-only
    false green remain RED; resume-extra URL deleted by candidate refuses before
    old erase;
13. lifecycle allows exactly measured `(0,0,0)`, `(0,0,1)`, `(1,1,1)` with
    hashing flags zero; paused `(1,0,1)` refuses before stage/arm;
14. old outer+post-quiesce inner CAS covers full metadata/map/tracker/lifecycle
    tuple. `eb` precedes stop, `ed` follows erase, `ex` tails begun body;
    paused/closed hook drift, missing `ex`, partial stop and foreign generation
    never permit candidate/restart. Exact known outer-false `SKIPPED` на той же
    generation с own marker/ack release-ит handoff несмотря на concurrent drift
    прочих fields и сохраняет этот drift byte-for-byte;
15. every phase arm is live-`wa` one-shot; `la/ra` atomically ставят arm + exact
    matching ready-marker capability. Unknown arm never retries/revokes;
    every dispatch is one-shot, outer false writes no orphan `ex/cx`, and
    unknown+begin-absent remains sticky rather than being called dropped. Held
    arm запрещает terminal cleanup; delayed completion пишет только под live wa;
16. file-load claim is first, exact baseline precedes penultimate tracker
    projection assertion and ready is last. Surviving URL state emits explicit
    enable or disable, and new URL explicitly overrides preserved resume state
    to frozen default; rt0.9.8 `trackers.use_udp=0` old manually-enabled UDP,
    disabled UDP and new-URL policy are real-daemon gates;
17. pre-ready exact topology/enabled assertion rejects unsupported scheme,
    whitespace/empty-tier normalization loss, silently trimmed URL and missing
    disable target via deliberate input error; none can report ready+ack success;
18. wrapper schedules first, writes begin, consumes arm, then loads; callback
    requires live `wa+begin`. Schedule-created/begin-fault/remove-unknown/late-
    fire cannot orphan fence; 4-second foreground inserted hook proves fence
    occurs only after synchronous hook tail; separate schedule_remove запрещён,
    оба names удаляет terminal finalizer;
19. unknown load with begin absent keeps capabilities/wa and never second-loads;
    begin present waits fence. Candidate absent, persistent unknown and missing
    fence never dispatch rollback;
20. authenticated ready+ack+hashing_failed=0 succeeds despite post-event user/
    plugin drift; ready+empty/wrong ack and hashing failure remain quarantined.
    Sync seedingtime and async autolabel/toggle survive release;
21. owned partial cleanup requires valid prefix + exact captured tuple, one-shot
    `ca`, `cb` before mutation, `cd` after erase and `cx` tail. Unknown+cb absent
    is sticky; only `cd+cx+absence` permits rollback;
22. rollback uses raw source and distinct `ra/rb/rf`; only authenticated ready+
    ack+zero hashing failure succeeds, with no third load or worker `d.start`;
23. release clear order is ack then marker and same-generation conditional.
    Unknown release sends exactly one clear; unchanged/partial prefix retains
    `wa`, no retry. Terminal cleanup одним ordered non-yielding callback deletes
    both marker capabilities first, затем `wa`, schedule names и phase keys;
    late hook/writers/scheduler
    no-op. Unknown cleanup при live `wa` остаётся sticky; delete-only
    continuation после proven absent `wa` допускает retry; terminal quarantine
    clears lease, genuinely pending work retains it;
24. successful present-generation stop/start/setter/erase races are preserved,
    while any target intent accepted in the owned absent gap is explicitly
    outside this six-path guarantee;
25. duplicate same-hash load cannot alter existing marker/local id/state;
    PHP/script paths with whitespace/comma/quote/backslash keep exact argv;
26. all 12 upstream sequence test methods and the class-through-EOF bytes
    survive byte-for-byte; the registration-aware extractor proves the frozen
    non-empty set/count while the bootstrap preamble adopts the import sentinel.

Mandatory mutations, каждая с named executed RED, no preceding fatal и fresh
GREEN after restore:

- добавить седьмой target path/`guard.php`, top-level include side effect или
  unloaded `Torrent` parent; вернуть stop в hook либо launch раньше `wh/wp`;
- split-ить lifecycle acquire и predecessor neutralization, retry-ить unknown
  acquire, ослабить full-service historical-worker drain до rTorrent restart,
  release-нуть чужой token либо принять orphan `ta|to`;
- поставить `di` до `dq`, clear-ить `dq` после scan, retry-ить response-lost
  clear, release при dirty flag или потерять event на final callback;
- drain-ить multi-profile до `ma`, delete-ить live `wh|wa`, не release-ить exact
   original marker перед `wp` cancel либо разрешить adopt после ma;
- заменить direct done delete на `cat=`, erase hook при `wh|wa|phase`, оставить
  worker-never-started `wp` навсегда или разнести wp-cancel/adopt CAS;
- поставить legacy `custom3=1` branch раньше transaction marker, убрать clear,
  пропустить ordinary non-1 value, unconditional-copy marker в ack без exact
  live-marker capability, убрать exact-empty ack gate/перезаписать wrong
  non-empty или `"0"`, вернуть faulting
  `$equal=$d.custom=retrackers-recovery-ack,$d.custom=retrackers-recovery` или
  оставить `wh` после known hook tail;
- сделать adopt non-idempotent после lost reply, recreate `wa` при absent `wp`,
  удалить `wp` при unknown ownership/ledger, пропустить `wa` или любую другую
  own key в initial seventeen-key collision preflight либо не учитывать
  `wh|wp|wa` в done;
- заменить shared production adapter на `rXMLRPCRequest::send`, `fsockopen`,
  `stream_socket*` или private SCGI framing; сделать больше одного transport
  send на logical RPC, изменить `trusted=true`/connect `0.25s`/`RESPONSE_RAW`,
  заменить любой independent `isset` bare global read-ом, swap-нуть positions
  7/8, подставить `$rpcTimeOut`/private cap/hardcoded value, добавить retry либо
  дать fake и production adapters разные restricted-codec paths;
- вернуть unbounded `file_get_contents`; читать только до CAP без CAP+1 EOF
  probe; принять empty/positive short non-EOF как EOF; допустить non-regular FD;
  preallocate из `fstat` size; reread pathname или передать path в `Torrent`;
  concatenate candidate до preflight; заменить subtract-before-add unchecked
  addition; принять CAP+1; убрать append guard; eagerly copy full raw span. Каждая
  mutation обязана дать named RED до scanner/Torrent/RPC/arm/old mutation, без
  preceding fatal, и fresh GREEN после restore;
- вернуть obsolete `setParseByTypes(true)` + `$strings`/`$i8s` typed-regex
  consumer, regexp `i.` или current-parser fallback; принять partial parse/
  trailing root child, attributes/namespaces/
  DTD/entity declaration, duplicate struct member, implicit string, `<ix>`,
  wrong i4/i8 range/schema, entity double decode, extra scalar/member/order либо
  reject/trim literal LF; заменить direct/member wrapper, принять unused
  member `t.multicall|d.custom.items`, member `d.multicall2` вне exact
  `HistoricalBindingSampleV2` slot 3/с width-4 plan, member `method.get` вне exact
  ledger/historical slot либо direct `list_keys|get`,
  убрать/double-ить one-result member wrapper, принять missing/extra/reordered
  row/cell, wrong row width/count/tag, lowercase/non-40-hex download cell,
  negative tracker group/type, non-`0|1` extra/enabled, tracker i4, nested cell,
  implicit string, flat list как rows/struct, duplicate/foreign ledger key,
  list/map count или set mismatch,
  duplicate struct name, non-string/non-`1` ledger value, `has_key` вне
  canonical i8 `0|1`, extra/missing/reordered outer member, unexpected
  success/fault slot, incomplete document, arbitrary 0.9.8 empty whitespace или
  sibling у 0.16.21 self-closing empty; принять missing target как empty rows,
  `d.multicall` как cross-family scan или non-private 0.16.21 synthetic `dht://`
  fixture как zero trackers; принять source-reachable early-terminated
  `d.multicall2` partial row или invalid-wrapper `t.multicall` empty row. Каждая
  mutation обязана попасть в named executed codec test, доказать что именно этот
  case выполнился и упал, затем fresh GREEN;
- для `HistoricalBindingSampleV2` split-ить один five-slot request между daemon
  turns, объединить два mandatory stable reads в один request, смешать их slots
  либо выдать first/partial projection; принять outer не из exact пяти slots,
  any-slot fault, mismatch event list/map, mismatch ledger list/map, шестой
  scalar tail, orphan/duplicate `pf`, неверный action hash, missing/multiple
  `pv`, `newPv == sampled current oldPv`, epoch drift between reads, incomplete
  profile pair, malformed suffix, два steady functional profiles, legacy short
  owner, wrong owner mode
  либо любую invalid phase/claim/action combination; принять action-only или
  claim-only callback prefix, пропустить epoch rotation перед protected mutation
  либо принять ABA mutation с тремя pairwise-distinct epochs после omitted
  middle rotation. Earlier now-absent epoch reuse не является observable RED и
  исключается только frozen negligible 128-bit CSPRNG assumption; требовать для
  него history store или считать stable forged valid state detectably
  trustworthy при нарушенном exclusive-writer prerequisite запрещено. Принять
  stale/non-string/unknown namespace
  action, foreign top-level integer/struct, 0.9.8 nested struct или 0.16.21
  nested i4; выбрать family caller flag, header/settings/heuristic, принять mixed
  declaration/layout, сортировать struct по digest вместо full decoded name
  bytes или не включить family/tag/lexeme/order в digest; принять RecoveryRows4
  width 0/1/2/3/5, wrong tag/order, duplicate hash/local-id, partial 0.16.21 row
  либо назвать 0.9.8 killed/no-response empty snapshot; удержать оба RAW/second
  BODY/AST или increment после allocation. Exact boundaries depth 32/33, opaque
  values 65536/65537, global values 262144/262145, XML struct members
  12288/12289, each event container 4096/4097, receipt-ledger keys 4096/4097,
  profile claims 2048/2049, rows 16384/16385, name 4096/4097, scalar
  1048576/1048577, decoded text 8388608/8388609, retained names
  2097152/2097153 и output 8388608/8388609 обязаны дать named
  before-allocation RED и fresh GREEN;
- изменить transport argument 8, widen-ить smaller cap, при default пропустить
  BODY 67108865 до application codec, утверждать no transport allocation при
  operator cap 104857600 или запускать PHP 7.4 128M retained-state fixture на
  application BODY >67108864;
- retry/revoke state-writing arm, dispatch phase повторно, убрать live-`wa`
  predicate, писать `ex/cx` из outer false или считать unknown+begin-absent
  terminal;
- требовать absence всех tx keys после adopt вместо phase-local state, принять
  marker-capability другого tx либо не удалить обе capabilities terminally;
- убрать любой field restoration baseline из snapshot, old outer/inner CAS,
  creation list или claim-prefix cleanup tuple; поставить ready до последнего
  restoration command, сравнить/overwrite old baseline после inserted_new либо
  cleanup-ить ready-generation; требовать unchanged full tuple для release после
  exact known outer-false `SKIPPED` либо менять concurrent drift; убрать generic-map
  count/`d.custom_throw` membership, принять wrong-type map value, ordinary
  `d.custom` как membership proof или leading `$` dynamic value;
- заменить local id timestamp/custom4/hash-only identity;
- считать sendTorrent return acknowledgement;
- вызвать generic `sendTorrent()` либо поставить claim после directory/label;
- вернуть четыре per-tx `method.insert`, очищать receipt через `method.erase`,
  принять `multi|private|const`, очистить/recreate-ить shared ledger либо
  удалить чужой subkey;
- убрать `ex`, поставить `ed` до erase, принять sentinel/`ed` без `ex` либо
  считать begin-only terminal;
- убрать claim/ready/ack condition, заменить scheduler receipt первым
  отдельным read, поставить schedule после load/с first `0`, не gate-ить
  callback по `wa+begin`, убрать любой
  `la/lb/lf` или `ra/rb/rf`, закрыть candidate capability до `lf` либо original
  capability до terminal no-rollback/`rf`;
- не emit-ить explicit `t.enable` для surviving enabled URL, не проверять
  group/extra/multiplicity/enabled перед ready, разрешить silently dropped URL
  либо считать new UDP always enabled на 0.9.8 with `trackers.use_udp=0`;
- не parse-ить resume tracker map, разрешить resume-extra deleted URL,
  user-configured ordinary DHT/unknown type или не freeze-ить typed
  `trackers.use_udp`;
- не читать `d.hashing_failed`, принять ready=ack при flag `1` либо cleanup-ить
  malformed-resume ready object;
- разрешить second load после candidate absence/persistent unknown;
- cleanup-ить arbitrary claim snapshot, ready+wrong-nonempty-ack/changed object,
  restart-ить begin-only stopped object или делать branch без exact fresh tuple;
- убрать `cx`, принять cleanup sentinel/`cd` без `cx` или разрешить
  rollback без exact `cd+cx+absence`;
- удалить `wa` при pending arm/dispatch/tail/fence, сохранить `wa` после known
  terminal quarantine, удалить `wa` раньше обеих marker capabilities, удалить
  phase keys раньше `wa` либо разрешить late callback recreate receipt;
- retry-ить unknown handoff clear, отдельным worker request вызывать
  `schedule_remove` или запретить delete-only terminal cleanup continuation
  после уже доказанного `wa=0`;
- разрешить finite exit до fence/pending reconciliation, убрать frozen
  connect/transfer budgets либо назвать unbounded poll hard deadline;
- dispatch-ить повторно сериализованный XML либо использовать RAW response, не
  fully accepted `retrackersDecodeRestrictedRawResponse()` exact request mode;
- re-encode raw rollback либо использовать `(string) Torrent` как candidate
  envelope;
- удалить/пересериализовать candidate `libtorrent_resume`, изменить raw `info`
  или unknown top-level slice, принять large integer как float либо сохранить
  stale `rtorrent`;
- erase candidate/foreign object без exact captured tuple или cleanup arm;
- не release-ить marker/ack после no-change/candidate-confirmed/
  rollback-confirmed, clear-ить marker раньше ack или release-ить wrong local id;
- любой worker-side `d.start`, в том числе conditional partial-quiesce recovery;
- принять substring/raw missing-hash fault;
- сломать argv/rTorrent/shell quoting или вернуть `&` в run.sh;
- добавить production `TEST_MODE` branch;
- потерять любой из 12 upstream test methods.

Verification на exact implementation tip:

- focused `UpdateTest.php`, all 12 sequence methods executed, exact
  class-through-EOF hash и full test-name set/count guard;
- PHP lint/runtime 7.4/8.1/8.5, full harness 8.1/8.5, PHPStan 2.2.9 level 0,
  `sh -n`;
- exact six-path diff и whole-file review;
- final-parent static/fake adapter test на PHP 7.4 с warnings-as-exceptions:
  ровно один nine-argument `rSCGITransport::send()` с `trusted=true`, connect
  `0.25s`, by-reference failure, independent transfer/response-cap positions и
  `RESPONSE_RAW`. Neither/only-transfer/only-cap/both-present configs дают exact
  `null`/configured forwarding без notice; success/null/malformed/fault cases
  дают exactly one send и один restricted-codec path. Delayed-first-byte fixture
  доказывает отсутствие hardcoded 0.25-second transfer budget; static scan всех
  шести paths не находит `fsockopen`, `stream_socket*`, private SCGI framing или
  `rXMLRPCRequest::send()`;
- deterministic bounded-reader/candidate tests на PHP 7.4/8.1/8.5: regular
  CAP-1/CAP/CAP+1, required final EOF probe, false/empty/positive-short reads,
  normal short true-EOF, FIFO/socket/device zero-read refusal, one open/path
  read, immutable capture hash, offset spans и no pathname-to-`Torrent`.
  Candidate exact-CAP/CAP+1 by raw span/narrow value, hostile 32-bit-safe
  arithmetic plan, inconsistent post-preflight bounded append и <=64 KiB slices
  происходят до side effects; actual 32-bit PHP 7.4 runtime запускается where
  available, а subtract-before-add остаётся portable proof;
- capture-backed restricted-codec suite на обеих serializer families принимает
  direct string, measured request-specific i4/i8 integer, direct fault,
  targeted direct i8 `has_key=0|1`, direct empty/non-empty
  two-uppercase-40-hex-string `d.multicall2` rows, direct empty/non-empty
  string+four-i8 `t.multicall` rows,
  direct `d.custom.items` present-empty/LF struct и exact ordered
  `system.multicall(list_keys,get,has_key...)` with empty/populated flat list,
  empty/populated unordered string-`1` struct, scalar i8 slot and member fault.
  Exact count/order/width/key-set/fault-placement checks include 0.9.8 explicit
  CRLF empties, 0.16.21 self-closing empties, captured missing-target faults and
  private trackerless 0.16.21 zero-row fixture. Captured 0.16.21 partial-width
  `d.multicall2`, measured 0.9.8 exit-139 transport failure и source-owned
  synthetic empty-row `t.multicall` отдельно подтверждают rejection без
  расширения capture-backed accepted grammar. Adversarial
  suite отвергает
  unused direct/member modes, every frozen wrapper/row/flat-vs-struct/count/cell/
  slot/attribute/namespace/DTD/entity/type/range/duplicate/`<ix>`/extra/prefix/
  suffix/partial boundary и доказывает full RAW/body consumption без second full
  BODY copy. Every named mutation fails after the named case ran and returns
  fresh GREEN after restore;
- dedicated `HistoricalBindingSampleV2` suite на PHP 7.4/8.1/8.5 pins exact
  five-slot request body SHA-256
  `ae96a2e5264798d84e4a35e981bbe99d8337820a93a07ff989e480b329b44210`,
  and these hard-coded pure-codec semantic-empty known answers:

  ```text
  EventKeyDigestV1       6be78367aeebc5a5291678a69df08c1851c8146d292574dbbd6444bd4cf8adf4
  EventMapDigestV1       e161a0c9b4fa03bde365367c3dad2e241612c5051f57ec679599eecc69509fb6
  RecoveryRowsDigestV1   6ff9698fefb3ae39bf1df57c2bc88ef40b372e6a053bc1a3ef0d776ca067b7a6
  LedgerKeyDigestV1      94b79f8e5caa8e944b32c7f23990dd2b226133255041e62ec8791907760cfa3c
  LedgerMapDigestV1      4d728a8a6967ca2b7abeaa3722e6bac56b59d6ddcf7f354fb6148c8c33b37d1f
  LedgerDigestV1         184b1f3b0661242c459bf97ae031874af765bd0b79c9a9a766414e3cb878729b
  V2 family 0x01         4a2991f206fbd40e09a4bbb54f586013f7d99b2a2a7468a5d6e80b818a848336
  V2 family 0x02         1605e0cd8fb833a0757fd866fa150230c6b1bf3532e382c86b6860a5b95962c2
  ```

  Here empty means zero event keys/members, zero recovery rows and zero ledger
  keys/members; the V2 rows compose those raw child digests with the indicated
  one-byte `FamilyTag`. These vectors are not response BODY identities, valid
  BOOTSTRAP captures or a substitute for the post-implementation manifest.
  Expected bytes must remain hard-coded from an implementation independent of
  the codec helper under test; computing expected and actual through two calls
  to that same helper is forbidden. The suite also pins
  exact one-result wrappers, event list/map equality, ledger list/map equality,
  six valid phases, extended owner modes, same-sample `pf` binding, persistent
  `pv` which rejects `newPv == sampled current oldPv` and never deliberately
  restores that sampled epoch. It does not claim detection of reuse of an
  earlier now-absent epoch; that remains solely the negligible 128-bit CSPRNG
  assumption. The suite pins refusal precedence, foreign family/type matrix, full
  decoded-name sorting, typed/order-sensitive digest и `RecoveryRows4`
  complete-before-use. It rejects every same-family/action/key/epoch/phase/row
  drift, any slot fault, a sixth tail and all limit+1 fixtures before allocation.
  Exact disposable 0.9.8/xmlrpc-c and 0.16.21/TinyXML2 first preserve the
  measured reachable B5 boundary as RED: slots 1--3 succeed, slots 4--5 are
  missing-ledger faults and two reads are byte-identical, but neither result is
  `BOOTSTRAP`. Four old combined BODY SHA-256 values
  `b9a8f7504aa9c2112a2591df8f97d153936bcdeaa393d256b944e96e791b2ac4`,
  `e0fd0e152f473c2cde4d4c5f309460f288e00f0a025eb208fafa0eb67f88c6cb`,
  `db548d9f026175cc87107b07ae79c79b3dfa4203b53c27bf35b5e488a7ab9f34`,
  `3a3592dbb52793745ec458fc81301b607ba7dc02d5222e10e525f37b7886f723`
  remain RED/provenance-only fixtures and are never accepted by V2. After the
  real producers and callbacks exist, both daemon families must emit an exact
  post-implementation manifest for `BOOTSTRAP`, `IDLE_EMPTY_CURRENT`,
  `IDLE_CURRENT`, `FIRST_INIT_OWNER`, `SECOND_INIT_OWNER`, `DONE_OWNER`,
  `CONTAIN_OWNER` and `CONTAINED`: two independent byte-identical reads per
  state, isolated slot-4/slot-5 faults, concrete request/BODY hashes, actual
  epoch/token/local-id, exact builder/action bytes, canonical marker, phase,
  digest/count and provenance. The production callback seam must be imported;
  manual `method.set_key` population is shape-only RED evidence and cannot
  satisfy acceptance. PHP 7.4 `memory_limit=128M` maximum retained-state fixture
  with accepted BODY <=64 MiB проходит или bounded-fail-ится без OOM, second
  BODY/AST and simultaneous RAW. Transport fixtures pin smaller/default/
  104857600 caps: argument 8 unchanged, default BODY 67108865 header-reject,
  high-cap BODY 67108865 application-reject after RAW allocation but before
  XML/hash/stack/container/output/consumer;
- на exact final parent disposable rTorrent 0.9.8 и 0.16.21 с legacy config без
  обоих optional globals доказывают one raw-mode send, default/configured budget
  forwarding, no warning и тот же restricted codec. Two-family exact generic
  CAS/fresh-path load gates принимают empty/LF/backslash/quote/comma через
  recursive `left/right/condition` и recreation forms, включая LF-terminated
  `addtime`; one-layer quoting, leading `$`, CR и NUL остаются RED. Current base
  predecessor transport не может быть runtime proof этой final interface;
- disposable supported-oldest и 0.16.21: private modifiable multi-ledger
  init/reload, privacy/const/wrong-type/unknown-key refusal, exact init и worker
  subkey preflight/delete/reuse/final-delete и preservation other valid
  transaction keys; fresh full-service boundary proves no prior ledger, creates
  one persistent `pv` under the current-sample/CSPRNG non-reuse boundary, then
  exercises extended `ta`/owner `i|d|c`, bound
  `pf`, same-profile/cross-profile/delayed-request lifecycle and all six valid
  phases. Every protected callback first installs only a missing safety gate,
  then rotates epoch before its first profile/action/claim/owner mutation;
  delayed callbacks CAS the sampled epoch. A three-epoch `A/B/C` fixture with
  pairwise-distinct values rejects omission of the middle protected rotation;
  it does not assert detection of earlier absent-history reuse. Injected crash
  prefixes never expose
  an accepted action-only, claim-only, owner-only or mixed-epoch state. A second
  functional profile cannot become steady idle: its `INIT_OWNER` path reaches
  containment, zero claims, `c+ma`, then `CONTAINED`; `DONE_OWNER` has exactly
  one owner profile and no foreign profile. The eight manifest capture states
  are reached only through the production callback seam, never manual setters;
  ta-window `dq/di` enqueue, replay, dirty-final-release fence,
  multi-profile `ma`/`wh` drain and conditional `wp` release, hook-side exact
  quoted `wh/wp`, idempotent atomic `wp->wa`, delayed-CLI and
  every inter-phase done-with-active-worker drain; real hook ordinary/suppression,
  exact command-form marker/ack и ack-empty equality,
  live-marker-capability-to-ack без clobber wrong non-empty/`"0"`,
  post-cleanup replay no-write с preserved
  `custom3=1`/custom2, empty-marker legacy clear и
  ordinary non-1 preserve, recursive CAS quoting LF/comma/quote/backslash, raw
  generic-map
  type/count/value proof, exact custom1…5/priority/throttle/map pre-event
  restoration baseline и authoritative seedingtime/extratio/autolabel/user
  post-event mutation survival, exact leading-`$`/CR/NUL refusal,
  lifecycle tuple matrix, started/stopped commit,
  one-shot arm/dispatch `eb/ed/ex` and `cb/cd/cx` orderings, same-hash stale
  generation, restricted request-schema codec, outer-false `SKIPPED` same-generation
  release с preserved concurrent drift, daemon-launched open/read/hash
  preflight и anonymous procfd
  load on same uid/PID namespace, +1-second
  scheduler fence gated by `wa+begin`, delayed foreground hook tail, every valid
  command prefix, source/live tracker projection, exact pre-ready topology/state
  assertion, resume-extra refusal, ordinary-DHT refusal, typed
  `trackers.use_udp`, 0.9.8 use-udp=0 explicit enable/disable/default,
  malformed-resume
  hashing-failed ready/ack, ready+empty/wrong-ack no-touch и ready+ack
  changed-field success,
  candidate/original FD lifetime, duplicate non-mutation, candidate cleanup,
  raw rollback confirmation, one-shot terminal marker/ack release, terminal
  capability-first, then-`wa` schedule/receipt cleanup and future reinsert;
- one-shot SCGI proxy: acquire/arm/dispatch dropped, delayed and response-lost;
  response lost after stop-only/erase/load schedule, with absent begin never
  treated terminal;
- никакого mutating probe на live service.

Measured published `43484fba` baseline `42 tests / 0 failures` одинаков на
PHP 7.4/8.1/8.5 и сохраняется как
characterization, но target RED name set и exact new count замораживаются до
implementation. Container PHP 7.4/8.1/8.5 equality не превращает false-green
ack model в proof.

## Approval boundary

Historical exact-five **DESIGN / CONTRACT APPROVAL** основан на reviewed
pre-approval commit
`38dd46e3073c6d102b867169c96f8dd9ed1770cd` и candidate SHA-256
`5f44b73cd7fd4f1cb33d481165faece3c2f2076b02a06fa2451bf3fc6b3c8014`.
Round-5 immutable package имеет SHA-256
`57b05a70622596d155345959c4313326e1ccde22a434047b6563c20d09f27840`;
specification `CLEAN` report —
`d0256a456b8b0068555d2726e148e53eb80db96c9d5dd479d139ccf18b59441d`,
adversarial `CLEAN` report —
`b311a75677c82f2df1379173d8b0c1a867adff616cf2c261f8237b0057d31f19`.
Этот historical approval остаётся evidence authority для reviewed technical
bytes, formulas, state transitions, resource limits и acceptance gates, но не
для current scope/sequence invariant. Исправление 2026-08-31 явно supersede-ит
его exact-five scope, immutable full-file sequence SHA и связанные wording;
остальные technical guarantees не расширены и не ослаблены.

Natural missing-ledger B5 RAW/BODY boundary уже captured на обеих daemon
families: это GREEN capture evidence ожидаемого RED behavior. Post-implementation
`BLOCKED` остаются только production-provenance successful B5 и полный
two-family/eight-state/two-read manifest после появления package-owned
ledger/lifecycle producers. Это не отменяет design approval.

**IMPLEMENTATION / CAPTURE ACCEPTANCE** является отдельным более поздним gate.
До него implementation branch ещё нет; в общей очереди 14 implementation
packages, и retrackers нельзя называть mergeable/ready. Acceptance требует real
producers and callbacks, witnessed natural RED, corrected GREEN, mandatory
mutations, exact two-family/eight-state/two-read manifest, PHP 7.4 128M bounds,
both-daemon runtime, exact 12-name/class-body preservation, exact predecessor
range и independent whole-file review. Только этот второй gate подтверждает, что
реализация соответствует уже approved contract.

## Post-sync revalidation — 2026-08-30

Final merge `4b3cd79925e7b73ea25feb1658a34e6b698c9855` использует upstream
`529033335e66e1acd4084b73030f5880035ce1c0`; historical
`755404f3e38af98b6901852b35be10fb9659ffd3` baselines, B5 vectors и approval
hashes остаются frozen. Exact delta `755404f3..52903333` содержит только
#3220/#3202 и три package-lock/filedrop path; пересечение с exact six-path
retrackers scope пусто. Pre-755 #3218 plugin-relative init-path shield сохранён,
а #3212 predecessor test не был адаптирован под fork.

На 2026-08-30 merge и clean parent `4682a761` input
`tests/plugins/retrackers/RetrackersUpdateSequenceTest.php` был byte-exact
upstream, SHA-256
`47c0ad870214e5a8056c20c5a008fd35173732bd50ffae5a1b45c9e975a4eb13`.
Registration-aware 12-name SET имеет SHA-256
`0ee7b35f9cda898d00e963b7e23aff02351e3653db21bbf2e99e31a34d5c7044`:

```text
testASessionTorrentThatNeedsNothingKeepsItsRtorrentKey
testATorrentThatNeedsNothingIsNotRewritten
testATrackerAlreadyPresentIsNotAddedTwice
testATrackerlessTorrentGetsAnnounceAndAnnounceList
testAddToBeginPutsTheAdditionFirst
testAdditionAndDeletionTogetherOnASessionTorrent
testAnEmptyConfigurationChangesNothing
testAnnounceOnlyTorrentGainsAnAnnounceListEndingWithTheAddition
testClearTrackerDropsAGroupItEmpties
testDeleteTrackersMatchesOnASubstringCaseInsensitively
testDeletingEveryTrackerInAGroupRemovesTheGroup
testTheScriptsHelpersAreTheRealOnes
```

Fork follow-up `c4fef63f` уже восстановил реальный `rRetrackers` bootstrap:
текущая predecessor class выполняет **12 methods / 40 assertions / 0 failures**
на PHP 7.4, 8.1 и 8.5. Это baseline, а не implementation closure. Новый
side-effect-free import всё равно требует разрешённой правки preamble с
`RETRACKERS_IMPORT_ONLY`; замороженными остаются 12-name SET и class-through-EOF
hash, а не устаревший full-file SHA. Literal PHP 7.4 `memory_limit=128M`
combined consumer bounds остаются будущим acceptance.

Статус остаётся **DESIGN APPROVED — implementation pending**. Six-path scope и
dependencies скорректированы без новой package строки: из общей очереди 14
implementation packages retrackers recovery является одним и пока не ready/
mergeable. Pre-code RAW fixtures и граница того, что действительно достижимо
до producers, зафиксированы в
`VERIFICATION-retrackers-recovery-precode-2026-08-31.md`.
