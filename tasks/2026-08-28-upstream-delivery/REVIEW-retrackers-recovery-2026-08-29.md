# `up/retrackers-recovery` — кандидат технического контракта

Дата: 2026-08-29. База исследования: `upstream/master=755404f3`; immediate
implementation parent — final `up/scgi-transport`. Опубликованный fork baseline
`43484fba`. Fork, включая новый partial implementation
`Fix retrackers replacement handoff`, и исторический `FINDINGS.md`
использованы только как donor гипотез; каждый race и daemon primitive
перепроверен независимо по исходникам и в disposable rTorrent 0.9.8 и 0.16.21
labs.

## Verdict: CANDIDATE DESIGN — independent review pending

Пятипутевой recovery package нужен, но current donor переносить нельзя. Его
главная ошибка — трактовать return `rTorrent::sendTorrent()` как daemon
acknowledgement. Return вычисляется локально после XMLRPC dispatch; actual
`DownloadFactory` load завершается позже и может быть отвергнут.

Candidate design поэтому разделяет четыре доказательства:

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

## Exact five-path scope

Package меняет ровно пять путей:

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
   contract.

Не входят: `plugins/retrackers/guard.php`, новые service-wrapper conditions,
`.chk-meta`/`chk-meta-old` recovery и их tests. Research prerequisite
`755404f3` не содержит `guard.php`; implementation режется от final
`up/scgi-transport`, чтобы не дублировать SCGI framing/timeouts. Published
`43484fba` используется только как independently audited donor и не расширяет
package scope. `init.php` и `done.php` загружают side-effect-free definitions из
`update.php`; CLI execution находится за явным entrypoint, поэтому include не
читает argv/config/filesystem/RPC. Service label/marker predicates принадлежат
отдельному P3 package и здесь не создаются, не меняются и не тестируются.

Upstream `tests/plugins/retrackers/RetrackersUpdateSequenceTest.php` остаётся
byte-for-byte и сохраняет exact 12 methods. Это predecessor gate, а не седьмой
target path: implementation branch режется от upstream prerequisite tip, где
файл обязан иметь SHA-256
`47c0ad870214e5a8056c20c5a008fd35173732bd50ffae5a1b45c9e975a4eb13`.
На published fork `43484fba` файл отсутствует как старый merge artifact; такой
tip нельзя использовать predecessor-ом без отдельного restore/rebase до начала
этого package:

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

Его удаление в current fork diff — merge artifact. Исторический `+1493/-45`
не является final numstat; точный delta считается от final predecessor.

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
| worker может увидеть present-target intent в transaction-owned absent gap | **НЕДОСТИЖИМО В ЭТОМ FIVE-PATH SCOPE** | Любой `d.stop`/pause/start/setter/erase по уже absent hash не оставляет intent; нужна сериализация target endpoint либо durable journal, который пишет сам command path. |
| worker может отличить user `d.stop` после собственного partial stop | **НЕДОСТИЖИМО В ЭТОМ FIVE-PATH SCOPE** | Повторный stop idempotent и не меняет full tuple; контракт fail-closed не restart-ит object. |
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

1. До event-map mutation `init.php` создаёт/валидирует private ledger по
   правилам ниже и делает два stable scans: exact event-key/action map и
   downloads с recovery marker/ack. Любой pre-existing own или foreign
   retrackers action, не byte-equal текущей `ma/ta`-aware frozen grammar, либо
   noncanonical historical marker/old-worker evidence означает
   `historical-hook-restart-required`; live upgrade дальше не идёт. Старый hook
   мог начать foreground action непосредственно перед scan, и пяти target paths
   не могут атомарно остановить чужой historical PHP request. Plain rTorrent
   restart недостаточен: upstream `run.sh` background-ит hash-only PHP worker,
   который может пережить daemon и затем erase-нуть новую generation. Поэтому
   upgrade с upstream/`43484fba` требует full service/container/process-namespace
   stop, proof zero historical `plugins/retrackers/update.php` workers, quiesced
   web plugin init/done и только затем fresh daemon start. После такого boundary
   event map/ledger empty, а concurrent current-version init-ы уже обязаны
   использовать mutex ниже.
2. Init генерирует fresh lifecycle token и отправляет acquire **ровно один
   раз**. Один non-yielding daemon callback при simultaneous absence `ta:1` и
   всех `to:*` последовательно ставит `to:<token>`, `ta:1` и в том же true body
   overwrites оба known own keys exact `deferOnly`, затем возвращает exact typed
   `ACQUIRED`; false body возвращает `BUSY`. Так safety-disable own predecessor
   линейризуется вместе с mutex, без acquire-to-neutralize window. Fault,
   malformed или transport-unknown acquire никогда не retry-ится, не release-ит
   token и не мутирует hooks другим request: dropped request оставляет plugin
   disabled, accepted/delayed request оставляет sticky `ta+to+deferOnly`.
   Оба исхода требуют daemon restart и дают
   `lifecycle-acquire-unconfirmed`; это намеренная ABA-safe one-shot граница.
3. После exact `ACQUIRED` init повторяет stable action/marker scans. Newly found
   historical/noncanonical action или evidence означает sticky refusal and
   restart; current-version foreign action допустим только если byte-equal
   frozen grammar. Каждая последующая hook-map mutation имеет condition
   `ta:1 && to:<token>`; delayed callback после release no-op. Functional
   ordinary branch сначала проверяет `ma:1`, затем `ta:1`: `ma` даёт no-op,
   `ta` durable-queue-ит `dq/di`, absence обоих запускает ordinary worker.
   Transaction-marker ack и legacy clear расположены раньше flags и остаются
   active.
4. При current-version multi-profile ambiguity один owner-CAS callback
   **сначала** ставит `ma:1`, затем overwrites все exact current retrackers keys
   `safetyOnly` и подтверждает map. `ma` sticky до daemon restart и запрещает
   новых producers/adopts. Containment никогда не удаляет live `wh` или `wa`:
   оно ждёт disappearance всех `wh`, затем fresh rounds drain-ят `di/dq` и
   atomically resolve каждый `wp`. Для exact present original
   marker=ack+local-id callback clear-ит ack, marker и только затем pending key;
   absent/changed generation только теряет `wp` с visible quarantine reason.
   Adopt-before-`ma` оставляет `wa`, и owner ждёт его terminal cleanup;
   `ma`-before-adopt запрещает worker mutation, а owner cancellation выше не
   оставляет permanent quarantine marker. Unknown `wh`, `wa`, containment или
   oversized request оставляет `ma+ta+to+safetyOnly` и требует restart; live
   `wh` не delete-ится. Pre-`ma` drain запрещён.
5. При single profile owner-CAS ставит functional action в first key и
   `safetyOnly` во second. Каждая drain round clear-ит `dq` **ровно один раз**;
   только exact acknowledged absence разрешает fresh `di` scan и canonical
   replay ниже. Unknown clear не retry-ится и оставляет sticky lifecycle,
   иначе delayed first clear мог бы стереть новый dirty bit. После zero `di`,
   absent `dq` и clean profile scan final non-yielding callback ещё раз требует
   `dq` absent, повторно ставит functional+safety actions и удаляет сначала
   `to:<token>`, затем `ta:1`. Event до final callback reinstates `dq`; event
   после него видит installed functional hook. Delayed owner callbacks after
   release no-op по token predicate. Crash/unknown оставляет `ta+to` sticky,
   plugin не register-ится и требует daemon restart.
6. Transaction/original-marker ack и legacy `custom3=1` clear остаются active
   при `ma`/`ta`, чтобы уже начатая transaction terminally classify-ла себя.

`di:<LOCAL_ID>` хранит только literal `1`; profile user/config остаются у init,
а hash находится fresh `d.multicall2` scan-ом по immutable local id. Для exact
match owner-CAS replay требует empty recovery marker и current
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
overwrite-ит value. После тех же historical scans и ledger validation он fresh
token-ом отправляет тот же **one-shot acquire+own-neutralize** callback, только
с `safetyOnly` вместо `deferOnly`. Unknown acquire никогда не retry-ится;
plugin teardown остаётся disabled/sticky до daemon restart. Exact acquire
сразу запрещает новые ordinary launch/defer, сохраняя marker/ack branch уже
активным workers.

Done сначала ждёт absence всех `wh`: hook, зависший между launch и tail, нельзя
erase-ить или подменять предположением. Затем owner-CAS drain-ит `di/dq` без
launch и atomically cancel-ит каждый `wp`: если worker adopt линейризовался
раньше, видны `wa` и absent `wp`; если done первый, он при exact present
original marker=ack+local-id clear-ит ack, marker, затем `wp`, и delayed CLI уже
не может adopt. Changed/absent object теряет только pending key и остаётся
видимо quarantined. Таким образом worker-never-started не оставляет вечный
`wp`, а done не стирает live `wh`/`wa`.

После cancellation done repeatedly валидирует ledger и может delete-ить hooks
только при zero worker/transaction keys `wa|ea`…`rf`; lifecycle `ta/to` и sticky
`ma` в этот count не входят. Последний zero check, двухаргументный delete обоих
`tadd_trackers1<user>`/`tadd_trackers2<user>` и lifecycle release находятся в
одном owner-CAS non-yielding callback: hooks удаляются, затем `to:<token>`, затем
`ta:1`, а typed readback доказывает absence всех четырёх names. Delayed callback
другого token no-op.

Done прекращает новые polling rounds после 5 seconds cumulative monotonic
poll/pause budget с 50 ms pause; каждый начатый RPC имеет отдельные transport
budgets ниже, поэтому literal wall-clock `<=5s` не обещается. Если `wh`, `wa` или любой
phase receipt не исчез, он не удаляет ack-capable hooks и не release-ит mutex:
остаются exact `safetyOnly+ta+to`, пишется visible
`hook-teardown-pending; daemon restart required after active recovery finishes`.
Это finite polling web path и safe sticky shutdown state. Crash/unknown
даёт `hook-teardown-unconfirmed`; genuine multi-profile `ma` done не очищает.
Explicit cross-profile owner/coordinator принадлежит future P3.

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
`method.list_keys` и требует `wh` absent после known launch tail.
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
exec "$1" ./update.php "$2" "$3" "$4" >/dev/null 2>&1
```

Hook/rTorrent command builder обязан сохранить PHP/script/hash/user/handoff как
отдельные arguments с canonical rTorrent escaping. Whitespace, comma, quote и
backslash в configured PHP path не могут менять argv shape. Shell получает
ровно PHP + hash + user + handoff; worker получает dense `$argv[0..3]`.

## Entrypoint and immutable pre-commit gates

`init.php`, `done.php` и tests определяют frozen import-only sentinel до
`require_once __DIR__.'/update.php'`. Top level `update.php` содержит только
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
получает initial stable snapshot: session/tied source, `d.loaded_file`,
directory и global daemon default directory, `custom1`…`custom5`, priority,
throttle name, оба dedicated keys, private/name guards, live state,
`d.is_active`, `d.is_open`, `d.hashing`, `d.hashing_failed`, `d.local_id` и
полный generic `d.custom` key/value map, а также typed `t.multicall` rows exact
URL/group/type/extra/enabled и global `trackers.use_udp` wire integer `0|1`
(`0.16.21` обязан вернуть fixed `1`). Snapshot принимается только при exact
count/types,
exact equal original marker/ack, exact `hashing_failed=0` и
ownership tuple. Missing hash, already hashing-failed object, foreign
marker/local id, changed mutable field, malformed/fault/transport outcome не
вызывают stop/erase/start.

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
`d.custom.items` через отдельный strict raw-XML struct decoder: единственная
accepted shape — one XMLRPC value/struct, unique `<member>`, exact decoded
`<name>` и value с explicit `<string>`; integer/array/nested/missing type,
DTD/entity declaration, duplicate member, malformed UTF-8 или trailing node
отвергаются. Empty string является валидным value и его key presence обязана
сохраниться. Это нужно потому, что обычный ruTorrent regex parser теряет struct
keys, а per-key `d.custom` getter превращает wrong-type bencode value в empty
string. Worker затем читает второй `d.custom.items`; две canonical sorted
key/value sequences обязаны быть byte-identical. Допускается ровно три
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

Tracker snapshot использует отдельный typed
`t.multicall(hash,"","t.url=","t.group=","t.type=","t.is_extra_tracker=","t.is_enabled=")`.
Accepted result имеет N rows, ровно N strings и 4N
canonical wire integers; URL strings проходят тот же strict inverse adapter.
Group/type — non-negative canonical integers, enabled и extra — exact `0|1`;
counts/types обязаны совпасть. Как generic map,
tracker rows читаются парами не больше трёх rounds и принимаются только при
stable canonical result. Intra-group row order не identity: libtorrent
0.16.21 рандомизировал один и тот же `[B,C]` tier как B,C и C,B. Canonical
topology поэтому является multiset `(group, exact decoded URL, type, extra)` с
multiplicity; enabled map keyed только exact decoded URL.

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

`Q()` является command-layer escaper, но не literalizes leading `$`: следующий
rTorrent parser layer исполнил бы такую строку как command. Поэтому every
dynamic value/key, который попадёт в nested CAS/load grammar — directory,
tied/loaded guard, `custom1`…`custom5`, throttle name и generic keys/values —
обязан не содержать NUL/CR/LF и **не начинаться с `$`**. Нарушение даёт visible
`runtime-value-unrepresentable`, conditional terminal handoff release и zero
old-object mutation. Это frozen fail-closed eligibility narrowing; никакого
"почти literal" encoder контракт не обещает.

Typed reads не используют untyped `$req->val`: ruTorrent PHP складывает и XML
`<string>`, и `<i4>/<i8>` туда как PHP strings. Каждый safety snapshot включает
`setParseByTypes(true)`, группирует commands в заранее известные string/integer
части и требует exact counts в `$strings` и `$i8s`. Поэтому далее «wire integer
`0|1`» означает одновременно integer XML tag и canonical decimal lexeme
`"0"|"1"` в `$i8s`; PHP integer `0|1` от этого transport не ожидается. Sentinel
requests, напротив, требуют ровно один XML string и zero integer values.

`$strings` при этом **не raw daemon values**: existing parser после XML entity
decode сохраняет legacy command-escaped representation — каждый raw `\`
становится `\\`, каждый raw `"` становится `\"`. До freeze/CAS/`Q()` target
adapter строго декодирует этот prefix code ровно один раз: `\\ -> \`,
`\" -> "`; одиночный `\`, bare `"` и любой иной escape отвергаются. После
decode adapter повторно применяет existing transform и требует byte-identical
round trip с исходным элементом `$strings`. Только decoded values входят в
snapshot и command builder. Disposable 0.9.8/0.16.21 raw response для exact
`left\middle"right&tail` и typed result `left\\middle\"right&tail` подтвердили
эту границу; без inverse path double-escaping ломает directory/custom CAS.

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
обязан заново доказать через trusted SCGI, что существующий object:

1. принимает `method.list_keys`, `method.get` и `method.has_key` как `multi`;
2. `list_keys` содержит только unique exact `ma:1`/`ta:1`, lifecycle owner
   `^to:[0-9a-f]{32}$`, deferred dirty flag `dq:1`, synchronous launch/defer
   keys `^(wh|wp|di):[0-9A-F]{40}$` либо strings
   формы `^(wa|ea|eb|ed|ex|la|lb|lf|ca|cb|cd|cx|ra|rb|rf):[0-9a-f]{32}$`
   либо exact marker-capabilities
   `^v1:(candidate|rollback)-ready:[0-9a-f]{32}$`;
   `method.get` возвращает ровно столько же values и **каждое** value
   является exact string `1`;
3. только после этого safe value check direct XMLRPC invocation
   `rr.receipts.v1` даёт exact normalized method-not-defined `-506`, то есть
   dynamic command действительно private. Public collision может попытаться
   исполнить только уже проверенные literal `1`, которые не являются command и
   не имеют side effect; любой его не-`-506` outcome отвергается;
4. lifecycle invariant exact: `ta:1` absent требует zero `to:*`; `ta:1`
   present требует ровно один `to:<token>`. Любая другая комбинация —
   `receipt-ledger-corrupt`, а valid `ta+to` означает sticky `lifecycle-busy`
   до daemon restart; его нельзя автоматически clear-ить;
5. modifiable: init генерирует отдельный random 32-hex token, требует absence
   exact `eb:<probe>`, затем exact set/has/delete/reuse adapters доказывают
   set/has/delete/reuse/**final delete+absence**, не затрагивая другой subkey.

Wrong type, public/callable/const object, malformed/duplicate/foreign key или
иной fault disable-ит plugin до functional activation с visible
`receipt-ledger-corrupt`. Инициализация никогда не вызывает `method.erase`, не
recreate-ит и не clear-ит существующий ledger: его valid keys могут
принадлежать concurrent worker или пережившему reload transaction. Межключевые
комбинации **transaction receipts** не валидируются — crash или
последовательный cleanup легально оставляет любую subset одного transaction;
обязательная `ta/to` lifecycle relation выше является единственным
cross-key structural invariant.

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
`method.set_key` с fixed value `1`, а proof читается только отдельным
`method.has_key` для exact own key. Whole-map
`method.get` используется лишь для count + homogeneous safe-value validation:
ruTorrent PHP parser теряет map keys, поэтому он никогда не связывает values с
конкретным concurrent subkey и не определяет receipt outcome. Collision,
unknown key/value,
transport, malformed или иной fault останавливает package до mutation.
`method.*` доступен только через trusted internal SCGI и запрещён HTTP XMLRPC
proxy утверждённым policy contract.

Worker остаётся importable без production `TEST_MODE` branch. Side effects
принадлежат явному CLI entrypoint; clock/RPC/filesystem/sender/logger задаются
обычными dependency objects/overridable methods в tests.

Production RPC dependency — утверждённый `rSCGITransport` immediate parent, не
несуществующая на `755404f3` timeout-семантика старого `rXMLRPCRequest::send()`.
Узкий injected worker adapter использует shared framing once с `trusted=true`,
explicit connect `0.25s`, approved configured `$rpcTransferTimeOut` для общего
write-deadline/read-idle budget, raw-response mode и configured shared
`$rpcMaxResponseBytes`; XMLRPC typed parsing остаётся в existing consumer layer.
Default `null` transfer наследует positive `default_socket_timeout`/60-second
fallback final SCGI contract; он намеренно не заменяется `0.25s`, потому что
first-byte latency включает whole daemon multicall. Init/done finite calls
используют тот же adapter. Package не
реализует второй socket/framing parser и не ходит через HTTP proxy. Individual
call bounded; worker reconciliation при unknown остаётся unbounded по числу
read-only polls и потому не получает ложный overall wall-clock deadline.

Source protocol:

1. выбрать canonical session `<HASH>.torrent` или readable tied source;
2. `file_get_contents()` ровно один раз;
3. один iterative raw-bencode scanner без PHP recursion/numeric conversion
   принимает source/final candidate не больше exact `64 * 1024 * 1024` bytes,
   depth не больше `128` containers и не больше `1_000_000` total values/keys.
   Он валидирует full consumption и grammar: integer только
   `i0e|i-?[1-9][0-9]*e` (arbitrary-length raw lexeme, `-0`/leading zero/plus
   запрещены); string length только `0:|[1-9][0-9]*:` с overflow-safe decimal
   bound against remaining bytes; dictionary key только byte string, unique в
   своём dictionary. Empty strings разрешены. Unsorted dictionaries,
   которые принимают current Torrent/rTorrent, не сужают eligibility; scanner
   сохраняет их raw slices, а только новый top-level envelope canonical-sort-ит
   output keys. Он сохраняет
   exact raw key/value byte slices, требует ровно один `info` и exact
   `SHA1(raw info value)==expected hash`;
4. из тех же immutable bytes создаётся `Torrent` только для существующей
   tracker-list semantic logic. Parse должен быть error-free, но decoded object
   никогда не сериализует `info`, `libtorrent_resume` или unknown entries;
5. tracker mutation выполняется на object, после чего narrow canonical encoder
   принимает только resulting `announce` string/null и nested list-of-strings
   `announce-list`/null. Integer/object input этому encoder-у запрещён;
6. raw-envelope rewriter один раз собирает candidate top-level dictionary:
   переносит exact source key/value slices для **всех** keys кроме
   `announce`, `announce-list`, `rtorrent`; `rtorrent` удаляет, а два tracker
   keys заменяет/добавляет/удаляет narrow encoded values и сортирует keys по
   decoded dictionary-key payload bytes (bencode order), не по raw
   `<decimal-length>:` slices. Поэтому `info`, `libtorrent_resume` и unknown top-level values
   сохраняются byte-for-byte, независимо от float behavior `Torrent`;
7. final candidate повторно проходит тот же strict scanner: `rtorrent` absent,
   tracker semantics exact ожидаемым, raw `info` и присутствующий/отсутствующий
   `libtorrent_resume` exact equal source slices, hash exact expected. Эти
   candidate bytes freeze-ятся один раз; rollback payload остаётся captured
   source bytes byte-for-byte и никогда не re-encode-ится;
8. до old-object mutation worker создаёт private per-transaction directory под
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
9. builder до old-object mutation создаёт exact candidate/rollback
   conditional `load.normal`/`load.start` command strings, one-shot arm/dispatch и
   scheduler-fence requests со всеми creation commands. Actual full XML
   каждого request не больше `rTorrentSettings::maxContentSize()`;
   failure — visible `load-command-too-large`, original нетронут;
10. pre-event restoration baseline заморожен exact: directory,
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
   Every frozen generic key/value проходит один strict inverse adapter и один
   `Q()`; count + per-key old-object CAS запрещает потерю concurrent new key.
   Все baseline setters стоят до ready-last. Ready доказывает exact применение
   baseline **до** `event.download.inserted_new`; после ready synchronous event
   hooks, их asynchronous descendants и user/plugin RPC принадлежат уже новой
   generation, authoritative и не сравниваются с old baseline при terminal
   confirmation. Worker не откатывает и не overwrites такие post-event writes.
   Protected
   metainfo slices (`info`, `libtorrent_resume`, unknown top-level
   values) никогда не decode/re-encode-ятся; только tracker keys проходят
   semantic decode и narrow encode. Typed snapshot strings
   проходят ровно один strict inverse adapter выше и затем ровно один `Q()`;
   повторный decode либо quoting уже escaped transport value запрещён.

Upstream private/name eligibility behavior сохраняется. Metainfo больше 64 MiB,
depth >128, >1,000,000 nodes, duplicate keys или non-canonical integer/length
grammar теперь явно **недостижимы для mutation target по contract gate**:
worker до `ea` пишет classified `source-too-large|source-too-deep|
source-too-complex|source-duplicate-key|source-bencode-invalid` и оставляет
original нетронутым. Это осознанное fail-closed narrowing вместо unbounded
CPU/memory parser. P3 service label/marker guards не встраиваются сюда.

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
Top-level PHP calls к `method.insert/list_keys/has_key/set_key` используют
canonical empty global-method target. Private flag запрещает direct invocation
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
недостижимо в exact five-path scope: соответствующие command endpoints должны
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
shared-daemon-owner-ambiguous
shared-daemon-owner-ambiguous-uncontained
initial-absent
initial-transport
initial-fault
initial-malformed
initial-hashing-failed
lifecycle-unsupported
ownership-mismatch
receipt-preflight-failed
source-unreadable
source-decode-failed
source-hash-mismatch
source-too-large
source-too-deep
source-too-complex
source-duplicate-key
source-bencode-invalid
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
3. full-service historical upgrade boundary pin-ит zero old PHP workers. Current
   acquire atomically ставит `to+ta+own defer|safety` и является one-shot:
   accepted-response-lost не retry/release-ится; real `43484fba`/upstream hook
   между hypothetical split acquire-neutralize является named RED;
4. `dq` ставится before `di`, consumer one-shot clear-ит `dq` before fresh scan.
   Empty-scan/producer/late-clear ABA, delayed response-lost clear и event на
   final release boundary не теряют deferred local id;
5. multi-profile containment ставит `ma` first, ждёт `wh`, не delete-ит `wa`,
   drain-ит `di/dq`, а каждый `wp` cancel-ит вместе с conditional ack→marker
   release. Adopt-before/after-`ma`, delayed CLI и future restart/reinsert не
   оставляют permanent quarantine marker;
6. done one-shot acquire сразу ставит safety hooks, ждёт `wh`, atomically
   linearizes `wp` cancel против adopt, ждёт active `wa|phase`, затем direct-
   delete-ит оба keys и lifecycle token в одном zero-check callback; `cat=`
   overwrite, missing one key и delete при active receipt — named RED;
7. private modifiable ledger set/has/delete/reuse/final-delete не затрагивает
   чужие valid keys. Adopt response-loss попадает в idempotent `wa=1/wp=0`
   case; exact no-adopt absent/foreign terminal очищает только `wp`, unknown
   сохраняет его. Initial collision preflight отдельно pin-ит каждую из
   семнадцати own keys, включая pre-existing `wa`: ни одна не позволяет
   initial adopt создать или принять lease;
8. production adapter на final SCGI prerequisite делает ровно один
   `rSCGITransport::send()` на RPC с `trusted=true`, connect/transfer budgets
   `0.25s`/exact configured `$rpcTransferTimeOut`, configured shared
   `$rpcMaxResponseBytes` и `RESPONSE_RAW`; default null transfer сохраняет
   approved socket-timeout fallback, а large delayed-first-byte multicall не
   получает hardcoded 0.25-second read budget. Named static RED
   сканирует все пять target paths и запрещает `fsockopen`, `stream_socket*`,
   private SCGI framing и `rXMLRPCRequest::send()`. Fake-adapter tests и
   real-adapter disposable call доказывают один и тот же typed consumer;
9. source/RPC/decode/hash/procfd/full-XML failures predate mutation;
   daemon-launched no-shell probe open/read/hash-ит обе anonymous capabilities,
   candidate FD живёт до `lf`, original — до terminal no-rollback/`rf`;
10. raw scanner rejects duplicate/malformed/depth/size/complexity before mutation;
   candidate drops only `rtorrent`, changes tracker keys, preserves raw `info`,
   resume and unknown slices, while rollback equals source bytes exactly;
11. typed XML strings/integers, stable strict `d.custom.items`, present-empty,
    count + `d.custom_throw` membership and leading `$`/NUL/CR/LF refusal pin-ят
    exact generic-map CAS and one decode/quote path;
12. source/live tracker projection covers N=0, DHT on/off/private, shuffled tier,
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
    outside this five-path guarantee;
25. duplicate same-hash load cannot alter existing marker/local id/state;
    PHP/script paths with whitespace/comma/quote/backslash keep exact argv;
26. all 12 upstream sequence tests survive byte-for-byte and test-name extractor
    proves the frozen non-empty set/count.

Mandatory mutations, каждая с named executed RED, no preceding fatal и fresh
GREEN after restore:

- добавить шестой target path/`guard.php`, top-level include side effect или
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
  send на logical RPC, изменить `trusted=true`/connect `0.25s`, заменить exact
  configured `$rpcTransferTimeOut` на hardcoded `0.25s`/иной budget, изменить
  configured `$rpcMaxResponseBytes` или `RESPONSE_RAW`, либо дать fake и
  production adapters разные typed-consumer paths;
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
- dispatch-ить повторно сериализованный/непроверенный XML;
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

- focused `UpdateTest.php`, unchanged 12-method sequence suite и full test-name
  set/count guard;
- PHP lint/runtime 7.4/8.1/8.5, full harness 8.1/8.5, PHPStan 2.2.9 level 0,
  `sh -n`;
- exact five-path diff и whole-file review;
- final-SCGI-prerequisite production-adapter test: ровно один
  `rSCGITransport::send()` с `trusted=true`, connect `0.25s`, exact configured
  `$rpcTransferTimeOut`, configured `$rpcMaxResponseBytes` и `RESPONSE_RAW`,
  затем existing typed consumer; null/default и delayed-first-byte fixture
  доказывают отсутствие hardcoded 0.25-second transfer budget;
  static forbidden-primitive scan всех пяти target paths не находит
  `fsockopen`, `stream_socket*`, private SCGI framing или
  `rXMLRPCRequest::send()`;
- disposable supported-oldest и 0.16.21: private modifiable multi-ledger
  init/reload, privacy/const/wrong-type/unknown-key refusal, exact init и worker
  subkey preflight/delete/reuse/final-delete и preservation other valid
  transaction keys; ta/to same-profile/cross-profile/delayed-request lifecycle,
  ta-window `dq/di` enqueue, replay, dirty-final-release fence,
  multi-profile `ma`/`wh` drain and conditional `wp` release, hook-side exact
  quoted `wh/wp`, idempotent atomic `wp->wa`, delayed-CLI and
  every inter-phase done-with-active-worker drain; real hook ordinary/suppression,
  exact command-form marker/ack и ack-empty equality,
  live-marker-capability-to-ack без clobber wrong non-empty/`"0"`,
  post-cleanup replay no-write с preserved
  `custom3=1`/custom2, empty-marker legacy clear и
  ordinary non-1 preserve, CAS quoting comma/quote/backslash, raw generic-map
  type/count/value proof, exact custom1…5/priority/throttle/map pre-event
  restoration baseline и authoritative seedingtime/extratio/autolabel/user
  post-event mutation survival,
  unrepresentable-value refusal, lifecycle tuple matrix, started/stopped commit,
  one-shot arm/dispatch `eb/ed/ex` and `cb/cd/cx` orderings, same-hash stale
  generation, typed response parsing, outer-false `SKIPPED` same-generation
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

Candidate design, identity, state machine, constants, scope и evidence gates
сформулированы; independent re-review ещё должен снять этот candidate marker.
Implementation branch ещё нет. Package нельзя называть готовым до witnessed
natural RED, corrected GREEN, mandatory mutations, both-daemon runtime,
byte-for-byte 12-test preservation, exact predecessor range и independent
whole-file review.
