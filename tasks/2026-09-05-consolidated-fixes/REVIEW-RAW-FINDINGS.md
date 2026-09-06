# Сырые находки финального ревью — НЕ ПРОВЕРЕНЫ

Ревью схлопнутого коммита `4adfaffa` (61 файл, +20945/-1016).

**Статус: фаза проверки НЕ выполнялась.** Прогон остановлен после первой фазы, чтобы не поднимать 60+ агентов-верификаторов на ночь. Всё ниже — заявления рецензентов, каждое из которых ещё предстоит опровергнуть или подтвердить.

Это существенно: в этой кампании доля выживающих находок заметно меньше единицы. Ревьюеры ошибались, и один целый пункт был опровергнут арбитром. **Не действовать по этому файлу, пока находка не проверена.**

Отработало направлений: 6 из 7 (седьмое, portability, прервано).

| Всего | 63 |
|---|---|
| severity `minor` | 34 |
| severity `nit` | 16 |
| severity `important` | 13 |

- kind `false-comment`: 23
- kind `duplication`: 14
- kind `dead-code`: 11
- kind `strange-logic`: 6
- kind `weak-test`: 4
- kind `complexity`: 3
- kind `other`: 2

---

## Направление: dead-and-complexity

**Итог рецензента:** Eleven findings, one important. The important one is structural rather than local: the ~3,500-line admission/drain/retirement package this diff adds is not reachable from the door the web UI actually uses — plugins/httprpc/action.php:420 still calls the legacy erasedataRemoveWithData(), and the JS only falls back to the rewired plugins/erasedata/action.php when httprpc is absent, which plugin.info forbids (may_be_shutdowned: 0). The gate meant to catch this asserts 'both public doors' and physically cannot see the third, because productionCallers() scans only plugins/erasedata/. This is tracked as package 7 in STATUS-18-PACKAGES, so it is a sequencing decision rather than an oversight — but nothing shipped in this commit says the new protocol is currently dormant on a stock install, and that is the sentence a future reader will need.\n\nThe rest is smaller and concrete: two unreachable diagnostic branches ('arm-unavailable', 'rearm-unavailable') whose only failure mode is excluded by a guard at the top of the same function; a third hand-spelled copy of the chk-forum canonicalisation rule added in the same commit that extracted canonicalForumId() to stop exactly that; two different grammars for a staging-object name, where the looser one produces a stall diagnostic the stricter one silently strips of the file it promises to name; five guards that cannot be false where they stand; and two 400-600 line functions whose try bodies are un-indented across 350-400 lines. I found no zero-caller functions and no unread constants — the package's own structural gates are working inside plugins/erasedata/, and the gap is at that directory's border.

**Проверено и чисто:**

- No zero-caller functions among the 112 functions the diff adds. I built a reference map over every tracked .php/.js file, subtracted definitions, and every new symbol has at least one non-test caller except the four that are deliberately test-facing (openDirectoryReference's alias pair, covered as a finding). The register's worry about 'a helper only the tests reach' is genuinely closed — RemoveWithDataTest.php:8290's productionCallers gate is doing its job inside plugins/erasedata/.
- All 23 new constants are read from production code, none is write-only. Checked each with a grep split into production and test counts; the smallest is ERASEDATA_PENDING_MARKER_MAX_BYTES at 3 production references. $erasePendingMaxAttempts, removed from conf.php, has no remaining reader anywhere except the test that asserts its absence (RemoveWithDataTest.php:9213) — a clean deletion.
- The new $blockingHashLocks parameter on ErasedataCollector is a real two-valued flag, not a constant: false at update.php:31 (the ordinary periodic collector) and true at removewithdata.php:4839 (inside the drain tick). It is not the 'flag that is always the same value in practice' pattern.
- RuTrackerState::update()'s new $failure out-parameter is consumed by two callers (announce.php:309 and forumindex.php:1213) and both consumptions are load-bearing: markSweep splits on whether the mutator ran, which is exactly the unreadable/unlockable vs unwritable boundary state.php implements. The only slack is that no consumer distinguishes 'unwritable' from 'unlockable' — announce.php deliberately gives them the same wording and says why. Not worth changing.
- The legacy and new producers cannot corrupt each other's queue state. I traced the name grammars: erasedataWriteStagedManifest's legacy name is <HASH>.<pid>.<uniqid>.tmp, whose second segment is decimal and so cannot satisfy the [0-9a-f]{16} generation slot; it classifies as 'malformed' in erasedataClassifyRetirementCandidate, and a bare <HASH>.list classifies as 'final'. Neither is counted in erasedataRearmDrainScheduleRun's $owed (which reads pending/staging/journal only), so a legacy leftover cannot arm a drain schedule the worker could never discharge.
- RuTrackerDetector::isForeignRow()'s `!is_array($row)` guard is unreachable today, and the docblock says so in the same breath as explaining why the signature pair invites the slip that would make it reachable. That is the class the register already ruled defensive-and-correct (alongside stillPending()'s defaults), so I did not re-report it.
- update.php requires pending.php unconditionally at file scope (:15) with an explicit comment about conditional includes producing a worker that is 'present in the source, reachable from the tests and dead in production'. The drain mode dispatch at :107-112 sits inside erasedataMayStartCollector() and reads $argv[2] before erasedataCollectorMain() can take it as a targeted hash. No dead-in-production include here.
- I did not run the suite, so I have no measured pass/fail numbers to quote — deliberately, since AGENTS.md's 'This Machine Will Lie To You About Test Results' section makes an unreproduced count worse than none. Every finding above is established by reading the cited lines, by a grep whose exact command is given, or (for the two staging-name grammars) by running the two regexes in php against three constructed names.

### 1. [important / complexity] The whole new admission/drain/retirement package is off the path the web UI actually takes

**Где:** `plugins/httprpc/action.php:420 (case "removewithdata"); plugins/erasedata/removewithdata.php:4936 erasedataAdmitRemoval(), :4956 erasedataRemoveWithData()`

**Заявление:** The diff rewires two of the three production doors (plugins/erasedata/action.php:66 and plugins/erasedata/erase.php:43) to the new generation-bound erasedataAdmitRemoval(), and leaves the third — plugins/httprpc/action.php:420 — on the legacy erasedataRemoveWithData(), which does its own d.erase inline and publishes bare <hash>.list manifests with no generation, no marker, no journal and no drain. plugins/erasedata/init.js routes to plugins/erasedata/action.php only when `typeof this.getCommon !== "function"`, i.e. only when httprpc is absent — and plugins/httprpc/plugin.info carries `plugin.may_be_shutdowned: 0`, so on a stock install the UI's "Remove and delete data" never enters the ~3,500 new lines. The gate that is supposed to catch exactly this, testPublicDoorsShareOneAdmissionApiAndExposeNoTestSeams (tests/plugins/erasedata/RemoveWithDataTest.php:8139), states the invariant as 'both public doors go through the same admission API' and cannot see the third door: productionCallers() (:6944) scans only ErasedataProductionMirror::pluginFiles() under plugins/erasedata/.

**Основание рецензента:** Read plugins/httprpc/action.php:408-422 (requires ../erasedata/removewithdata.php and calls erasedataRemoveWithData); plugins/erasedata/init.js rTorrentStub.prototype.removewithdata (getCommon branch vs the action.php fallback); plugins/httprpc/init.js:22 defines getCommon; plugins/httprpc/plugin.info `plugin.may_be_shutdowned: 0`. `grep -rn 'erasedataAdmitRemoval\|erasedataRemoveWithData' --include=*.php . | grep -v ^./tests/` gives exactly three production call sites. Read productionCallers() at RemoveWithDataTest.php:6944-6963 — it only reads plugins/erasedata/<file>. Mitigating and verified: tasks/2026-08-28-upstream-delivery/STATUS-18-PACKAGES-2026-09-03.md:62 tracks this as package 7, 'httprpc → erasedata | PENDING | Теперь ждёт только №6', i.e. it is a deliberate sequencing decision waiting on this very package. It is not a surprise, but neither the commit message nor any shipped test says the new protocol is currently unreachable from the default UI.

**Предложение:** Either (a) note it explicitly in the commit message / an ADR that package 6 ships dormant on stock installs until package 7 lands, or (b) rewrite the gate's invariant string to name the third door and assert its current state, so the test says 'httprpc/action.php still calls the legacy producer' rather than implying there are only two doors. Removing nothing is the right call — but the cost of the package is being paid now and the benefit arrives with package 7, and that should be stated where a reader will see it.

### 2. [minor / dead-code] Two unreachable diagnostic branches: arm-unavailable and rearm-unavailable

**Где:** `plugins/erasedata/removewithdata.php:2798-2805 (erasedataRemovalAdmissionRun) and :4662-4670 (erasedataRearmDrainScheduleRun)`

**Заявление:** Both sites do `$command = erasedataDrainScheduleCommand($user, ERASEDATA_DRAIN_INTERVAL); if($command === false) { ...'arm-unavailable'/'rearm-unavailable'... }`. erasedataDrainScheduleCommand() (:2003-2019) returns false only when `$key === false || $command === false || $interval < 1`; $key and $command are both false only when erasedataDrainScheduleKey($user) is false — and each enclosing function already returned false on exactly that check at its own entry (:2641 and :4536). $interval is the literal constant ERASEDATA_DRAIN_INTERVAL = 5 (:1410). So neither branch can be entered, and no test pins either token.

**Основание рецензента:** Read erasedataDrainScheduleKey (:1475), erasedataDrainWorkerCommand (:1958), erasedataDrainScheduleCommand (:2003) and both call sites in full. `grep -rn "arm-unavailable" . --include=*.php --include=*.md` returns exactly the two production lines and nothing in tests. `grep -rn ERASEDATA_DRAIN_INTERVAL` shows nothing redefines the constant, and the `if(!defined(...))` guard is never taken by the tests.

**Предложение:** Either delete both branches (nothing is lost — the failure they name cannot occur), or, if the intent is to survive a future caller that skips the entry-point key check, drop the entry-point check and keep only this one. Keeping both means one of the two is permanently untestable.

### 3. [minor / duplication] canonicalForumId() was extracted in this commit and a third hand-spelled copy of the same rule was added in the same commit

**Где:** `plugins/rutracker_check/check.php:1478 (createTorrent) vs plugins/rutracker_check/runstate.php:133 RuTrackerRpcValue::canonicalForumId()`

**Заявление:** The diff adds canonicalForumId() specifically because chk-forum was being read with two different spellings of one rule, and its docblock (runstate.php:108-131) states 'it has two readers -- RuTrackerCheckImpl::resolveForum() and RuTrackerMetaFetch::registrationTime()'. The same commit adds a third reader of chk-forum that spells the rule by hand: `$forumId = RuTrackerRpcValue::canonicalPositiveInt32(trim((string) $req->val[5]));` — canonicalPositiveInt32 over a trimmed value, which is the definition of canonicalForumId. So the docblock's reader count is already stale on the commit that wrote it, and the drift the helper exists to prevent has one new place to happen.

**Основание рецензента:** `grep -rn canonicalForumId plugins/` gives readers at trackers/rutracker.php:217 and metafetch.php:777 only. check.php:1430-1431 issues `d.get_custom(chk-forum)` as $req->val[5] and check.php:1478 canonicalises it inline. Substituting RuTrackerRpcValue::canonicalForumId($req->val[5]) is behaviour-identical: the value is already cast to string, and canonicalForumId trims strings before canonicalPositiveInt32. The neighbouring chk-topic line (:1477) is correctly a topic id, not a forum id, so only one of the pair is affected. forumindex.php:1360 deliberately keeps raw bytes for its compare-and-swap and is correctly excluded by the same docblock.

**Предложение:** Call RuTrackerRpcValue::canonicalForumId($req->val[5]) at check.php:1478 and update the runstate.php docblock's reader list to three, or say 'every reader that wants an id rather than the stored bytes' instead of enumerating them.

### 4. [minor / false-comment] Two grammars for one staging-object name; the looser one produces notes the stricter one silently strips

**Где:** `plugins/erasedata/removewithdata.php:3679 (the $unbound scan in erasedataDrainGenerationPass) vs :1869 erasedataIsStagingObjectName(), used at :1896 and :3424`

**Заявление:** The unbound-staging scan matches `/^([0-9A-Fa-f]{40})\.([0-9a-f]{16})\..+\.tmp$/D` and its comment promises 'The note carries the name the scan MATCHED... Only a human can clear this state, so the line has to say which file.' But erasedataDrainDiagnostic (:1896) prints `file=` only when erasedataIsStagingObjectName() accepts the name, and that grammar is stricter: `[0-9A-Za-z._-]{1,96}` for the token segment. A stray object matching the scan but not the strict grammar therefore produces a permanent-stall diagnostic with no file= at all — the AGENTS.md 'name the document' rule unmet in precisely the case the comment says it is met. erasedataDrainReportGroup (:3419-3427) applies the same filter to the digest, so its own comment — 'two ticks that strand two different files are two different states, and suppressing the second would print a name that is no longer there' — is also false for such names: two different non-conforming files hash to the same digest and the second tick is suppressed.

**Основание рецензента:** Ran both regexes against three names in php: `<40 hex>.<16 hex>.<120 x's>.tmp` → scan=1 strict=0; `<40 hex>.<16 hex>.a b.tmp` → scan=1 strict=0; the real erasedataStageAdmittedManifest shape `<40 hex>.<16 hex>.<pid>.<uniqid>.tmp` → scan=1 strict=1. Read erasedataDrainDiagnostic:1890-1897 and erasedataDrainReportGroup:3411-3436.

**Предложение:** Use erasedataIsStagingObjectName() as the scan predicate too (one grammar, and a non-conforming stray then classifies as 'malformed' for retirement rather than as an unbound member with an unprintable name), or, if the loose scan is deliberate, weaken the two comments to say the file is named only when its name is of the shape this plugin writes.

### 5. [minor / dead-code] Guards that duplicate a guard already applied upstream in the same call path

**Где:** `plugins/erasedata/pending.php:157; plugins/erasedata/removewithdata.php:3612 and :3862; plugins/erasedata/filesystem.php:322 and :341`

**Заявление:** Five new conditions cannot be false where they stand. (1) pending.php:157 `if(!is_string($marker)) return(false);` — erasedataPendingMarkerPath() returns false only for a bad listPath, a non-hash or a bad generation, and erasedataQueueRequest checked all three at :150-156. (2) removewithdata.php:3612 `$final = is_array($entries) ? erasedataPublishedGenerations($entries) : array();` sits seven lines after `if(!is_array($entries)) { ...return... }` at :3605. (3) removewithdata.php:3862-3864, the force-2 re-check, tests `array_key_exists($hash, $capabilities) && isset($collected[$hash]) && is_array($collected[$hash])` — every member of $erasable has presence PRESENT and is not in $unbound, so the preflight at :3745-3771 either set both keys or set $refusedCapability, and a refused member is skipped at :3789 and so never becomes bound. Only erasedataRemovalCapabilityStillMatches() can actually fail, so the comment at :3855-3861 explaining array_key_exists-vs-isset justifies a distinction the guard never gets to make. (4) filesystem.php:341 `!is_array($stat)` — $open !== false already implies is_array($stat), since erasedataIdentityDeviceAndInode() returns false for a non-array. (5) filesystem.php:349 `!isset($before[$index])` — the loop at :330-331 assigns every index, so only the `!is_array()` half can fire.

**Основание рецензента:** Read each function end to end: erasedataQueueRequest (pending.php:149-176) and erasedataPendingMarkerPath (:190-198); erasedataDrainGenerationPass steps (1)-(3) (:3547-3877); erasedataAcquireDirectoryCapability (filesystem.php:314-374) and erasedataIdentityDeviceAndInode (filesystem.php). Automated cross-check: a script over the four erasedata files looking for `!is_array($x)`-then-`is_array($x)` within 30 lines flagged 3612 as the only genuine one of eight hits.

**Предложение:** Remove (1), (2), (4) and the `!isset` half of (5) — nothing is lost, they cannot discriminate. For (3), keep the array_key_exists spelling but drop the two $collected tests and shorten the comment, or leave the guard and delete the sentence that explains a distinction it never exercises.

### 6. [minor / complexity] A 403-line and a 358-line try block whose bodies are not indented

**Где:** `plugins/erasedata/removewithdata.php:2673-3076 (erasedataRemovalAdmissionRun, 466 lines) and :3743-4101 (erasedataDrainGenerationPass, 629 lines)`

**Заявление:** The two longest functions the diff adds each wrap most of their body in a try whose contents sit at the same indentation as the `try` keyword itself, so the `finally` appears 350-400 lines later with no visual scope. Both are also the deepest new code (nesting 6 and 5). Every early `return` inside relies on the finally to release the directory capabilities, which is correct but invisible at the return site: erasedataDrainGenerationPass has 11 `return` statements inside its try.

**Основание рецензента:** `grep -n '^\t\ttry$|^\t\tfinally$' plugins/erasedata/removewithdata.php` → try 2673 / finally 3076, try 3743 / finally 4101, try 4806 / finally 4861 (the third, in erasedataDrainWorkerRun, is 55 lines and reads fine). Measured lengths and max brace depth with a script over all changed production files: these two are #1 and #2 by both metrics, ahead of the pre-existing createTorrent (404) and download_torrent (380).

**Предложение:** Indent the try bodies (a whitespace-only change, no behaviour), or narrow each try to just the region that owns the capabilities. Splitting drainGenerationPass at its own numbered steps — (1) probe, (2) prepare, (3) erase, (4) settle, (5) journal — would be the larger fix; the step boundaries are already written as comments, and each step's inputs are explicit.

### 7. [minor / dead-code] openDirectoryReference/closeDirectoryReference are pure aliases, and acquireDirectoryCapability's third parameter is test-only

**Где:** `plugins/erasedata/filesystem.php:389-397 (the aliases) and :314 (`$candidates = null`)`

**Заявление:** openDirectoryReference()/closeDirectoryReference() forward verbatim to acquireDirectoryCapability()/releaseDirectoryCapability(); production calls only the aliases (collector.php:311, :316 and removewithdata.php:2577, :2583, :2612), and the capability methods directly only from tests. The alias does not forward $candidates, so the third parameter of acquireDirectoryCapability is supplied by no caller reachable through the name production uses — it exists only for RemoveWithDataTest.php:7370-7404. Its own comment says so ('$candidates exists so the fixed roots can be driven in a test'), and the alias comment says so too ('they are the scripted seam the destructive-race tests override').

**Основание рецензента:** `grep -rn 'acquireDirectoryCapability|releaseDirectoryCapability|openDirectoryReference|closeDirectoryReference|erasedataDescriptorCandidates' plugins/ tests/` — production hits are only the four alias call sites; every acquire*/release* call outside filesystem.php is in tests, and the four `$candidates` arguments are all in RemoveWithDataTest.php.

**Предложение:** Keeping the seam is defensible — the fixtures override openDirectoryReference and call parent::. What is not paying for itself is having both names public. Make acquireDirectoryCapability/releaseDirectoryCapability private (or drop the aliases and rename), so there is one public spelling; the tests that call acquire* directly would move to the alias. $candidates should stay: without it the /proc/self/fd ownership proof has no test.

### 8. [minor / dead-code] edit/action.php's authorship snapshot/restore is a documented no-op on this fork

**Где:** `plugins/edit/action.php:98-125 and :145-155`

**Заявление:** The added block reads 'created by'/'creation date' before the setters and writes them back after, and its own comment states 'On this fork the restore is a no-op today.' That is correct and I confirmed it: Torrent::touch() (php/Torrent.php:687) returns immediately unless $built, $built is set only in the constructor's build() branch (:151), and Torrent::build() (:~250) returns false for `pathinfo($data, PATHINFO_EXTENSION) == 'torrent'` — which is what this plugin always passes. So roughly 20 lines of new production code plus a per-edit meta round trip execute for no effect on this tree.

**Основание рецензента:** Read php/Torrent.php __construct (:144-171), build() and touch() (:674-694); read plugins/edit/action.php:95-160. The .torrent-extension exclusion in build() is explicit.

**Предложение:** Keep it — the stated reason (this plugin is proposed upstream separately from php/Torrent.php, and upstream's touch() still stamps unconditionally) is sound and is the kind of thing AGENTS.md's Upstream PR Handoff section anticipates. Worth one added sentence naming the upstream branch or PR that will consume it, so a later reader can retire the block when the class fix lands upstream.

### 9. [nit / complexity] ruTrackerChecker::classifyFetchError() is a one-line wrapper with one caller and a self-referential rationale

**Где:** `plugins/rutracker_check/check.php:1874-1877`

**Заявление:** The de-duplication into RuTrackerFetchError::classify() (fetcherror.php) is a good fix — it really did collapse two drifted copies, and NNMClub now calls the leaf directly (trackers/nnmclub.php:186). What is left over is `static public function classifyFetchError($error) { return RuTrackerFetchError::classify($error); }`, whose docblock says 'The method stays because makeClient() below calls it by this name' — the only reason given for the indirection is its single caller, and no test references the name.

**Основание рецензента:** `grep -rn classifyFetchError plugins/ tests/`: definition at check.php:1874, one call at :1927, two mentions in comments, and one comment mention in tests/plugins/rutracker_check/TestLib.php:26 — no test calls it.

**Предложение:** Have makeClient() call RuTrackerFetchError::classify() directly and delete the wrapper; the docblock's real content (why the rule is classified rather than echoed) already lives on the leaf. If the method is kept as a deliberate public API for the handler suite's stub of ruTrackerChecker, say that instead — it is a much better reason than the one written.

### 10. [nit / dead-code] An erase-force re-normalisation that can never differ (inherited, relocated by this diff)

**Где:** `plugins/erasedata/removewithdata.php:5037-5046 (erasedataRemoveWithData)`

**Заявление:** `$destructiveForce = ErasedataManifestCodec::normalizeForce($forceDelete); if($destructiveForce !== $normalizedForce) { ...unlink every staged manifest, release every lock, return false... }` re-normalises the same $forceDelete that produced $normalizedForce at the top of the same function. normalizeForce() (manifest.php:24-33) is a pure total function over four exact values, so the two can never differ and the whole rollback block is unreachable.

**Основание рецензента:** Read normalizeForce() in full — four identity comparisons and a null. Read erasedataRemoveWithData:4956-5087. `git show master:plugins/erasedata/removewithdata.php | grep -n destructiveForce` confirms this is inherited from master (lines 1358-1359 there); the diff moved the function rather than writing the code.

**Предложение:** Not this diff's debt, but the diff relocated the function and rewrote normalizeForce() right above it, so it is the natural moment: delete the re-check, or keep it and say in a comment that it guards against a future non-pure normalizeForce — right now it reads as a TOCTOU guard over a value nothing can change.

### 11. [nit / strange-logic] confirmDeletion() spends an RPC on a value it discards when the count is zero

**Где:** `plugins/rutracker_check/trackers/rutracker.php:461-471`

**Заявление:** deletionRunStatus() is called unconditionally, and it issues a readCustom() — a full rXMLRPCRequest for chk-stime — but every use of its result is gated on `$count > 0` (:464 and :470). The comment defends this with 'the read is part of this function's request sequence either way, and only the ACTION below is conditional on there being a count', which is circular: the read is in the sequence only because the call is unconditional. On the common path (a first missing cycle, or right after resetDeletion()) $count is 0 and the round trip buys nothing.

**Основание рецензента:** Read confirmDeletion() (:409-480) and deletionRunStatus() (:311-341); readCustom() (:71-79) builds and runs a new rXMLRPCRequest per call. Both consumers of $run are inside `if ($count > 0 && ...)`.

**Предложение:** Move the call inside `if ($count > 0)`, or rewrite the comment to state the real reason if there is one (e.g. keeping the request count constant per torrent so a log line reads the same). As written the comment asserts a property of the code rather than a reason for it.

---

## Направление: duplication

**Итог рецензента:** Ten duplication findings, two of them important. (1) The branch unified the chk-forum canonicalisation rule and added a source-level guard against re-spelling it — but a third spelling already exists at check.php:1476, on the write side, and the guard cannot see it because it inspects a hardcoded pair of files; both the guard's comment and rutracker.php:214 make claims about coverage and about 'the only other reader' that are false. (2) The plugin has five spellings of 'same dev+ino'; the two that stringify carry the only justification in the family, and that justification is measurably backwards — I reproduced distinct inodes colliding as strings while comparing unequal as floats, in the very 32-bit case the comment invokes, at the site that proves a directory capability before deletion. Beyond those: the obsolete-cleanup identity predicate and index key are written once per plugin across the producer/consumer boundary with no owner and no gate (they agree today only because of a fact documented on one side alone); the Snoopy corpus is now asserted twice against makeClient() a hundred lines apart in one class, and exists in four places after a change whose point was to make it exist once; the mirror's plugin-file list is hardcoded and now gates three structural assertions that silently skip anything off it; the missing-info-hash whitelist has three copies and the excellent new parity gate binds two. The deliberate separations I checked — FORUM_HOSTS vs TRACKER_HOST_PATTERN, the generation string rule, the classify/hostOf row selection, the three hash predicates, the edit-plugin restore kept for an upstream PR — all hold up, and in each case the stated reason is true.

**Проверено и чисто:**

- fetcherror.php's own rationale checks out. Its docblock claims makeClient() and guestFetch() each carried the eight token/pattern pairs and had already drifted, one collapsing internal whitespace and one only trimming. Not visible from master (nnmclub.php had no classifier there at all), but true of the campaign's intermediate state: `git log -S connect-errno -- trackers/nnmclub.php` finds 90ea9aed, whose copy at line 202 is trim-only against check.php:1857's collapse. The 'neither owner could hold it' argument for a dependency-free leaf also holds: the checker's own suite stubs ruTrackerChecker, so the classifier could not live there.
- The loginmgr FORUM_HOSTS / RuTrackerDetector::TRACKER_HOST_PATTERN split is a deliberate duplication and every clause of its stated reason is true. plugin.info really does make rutracker_check depend on loginmgr (so the constant cannot move the other way); rutracker.cc really does reach this repository only as api./feed. hosts in RuTrackerForumIndex; and RuTrackerDomainListTest.php really is asymmetric in exactly the way both comments say — it fails on an edit to FORUM_HOSTS or on a removal from the detector, and not on an addition to the detector. The comments say so themselves rather than implying symmetry.
- erasedataGenerationIsValid/Increment/Compare keep generations as strings from end to end, and unlike the inode comparators that is sound: the value ARRIVES as a string, so no precision was lost before the comparison. One rule, one owner, no second spelling — filesystem.php:539-585 is the model the dev/ino family should have followed.
- The RuTrackerDetector::classify() / RuTrackerUpdatePass::hostOf() / describeCounters() row-selection triple is duplicated on purpose (both comments say 'the same row selection'), the three currently select the same rows, and the case that matters — a disabled t-ru row answering 'none' in one and '' in the other — is pinned behaviourally by UpdatePassTest.php:445. I could not find a divergence.
- The erasedata presence double is properly bound. UpdatePassTest's 'the erasedata presence double is bound to removewithdata.php' reads both function bodies out of the token stream, compares the whitelists phrase by phrase in both directions, catches a repeated phrase via a sorted comparison, then clones the production function under another name and drives both over the same probes. This is the strongest anti-drift mechanism in the branch and it does what its comment says.
- erasedataIsPendingHash (pending.php:134), ErasedataManifestCodec::isValidHash (manifest.php:243) and the validity half of erasedataCanonicalHash (removewithdata.php:341) are three spellings of /^[0-9A-Fa-f]{40}$/D. They agree exactly, they sit in three files with a deliberate dependency order (pending.php and manifest.php must not require the RPC layer), and erasedataIsCanonicalPendingHash's uppercase-only variant is a genuinely different question. Not worth unifying.
- The drain staging-name grammar (producer erasedataStageAdmittedManifest:2029, recogniser erasedataIsStagingObjectName:1869, exclusion probe inside erasedataParseCollectorCandidate:387) is spelled three times but the three agree on every name the producer can emit: <HASH>.<16 hex>.<pid>.<uniqid> is about 97 characters, inside both the 200-byte cap and the {1,96} token class. The parse-candidate exclusion is deliberately looser (prefix only) and the comment explains why.
- plugins/edit/action.php's save-and-restore of 'created by'/'creation date' duplicates what Torrent::touch()'s new $built flag already guarantees, and the comment says so openly ('On this fork the restore is a no-op today'), justifying it as insurance for a separate upstream PR. I verified the whole claim: build() returns false for a path whose extension is 'torrent' (Torrent.php:563), so $built stays false; setMeta()/clearMeta() do not call touch() (only the named setters at :403-514 do), so the restore cannot re-trigger a stamp; and clearMeta on an absent key inserts then unsets, leaving $extra's order untouched. Genuinely inert, genuinely justified. Worth noting that no test can therefore detect its accidental deletion — a source-level assertion of the kind RemoveWithDataTest uses would cost three lines.
- The three erasedata recording stubs in CheckerTest.php:196-236, CheckerMetaFetchIntegrationTest.php:34-51 and UpdatePassTest.php:59-78 are per-suite recorders rather than reimplementations, and the signature difference I checked (CheckerTest's erasedataRecoverObsoleteCleanup omits production's trailing &$reason) is harmless: PHP passes the extra argument to a user function without error, and no rutracker_check caller reads a reason back from it.
- No suite was run, so nothing was leaked. `pgrep -af rutorrent-scgi-rpc2` matches only my own shell (the pattern appears in its command line); the machine is otherwise at zero.

### 1. [important / duplication] A third spelling of the chk-forum rule sits in check.php, and the guard that was built to stop exactly that inspects only two files

**Где:** `plugins/rutracker_check/check.php:1476 (ruTrackerChecker::createTorrent); guard at tests/plugins/rutracker_check/ProjectionContractTest.php:372; rationale comment at plugins/rutracker_check/trackers/rutracker.php:214`

**Заявление:** The branch unified the chk-forum rule into RuTrackerRpcValue::canonicalForumId() (runstate.php:133 = canonicalPositiveInt32 over a trimmed value) and added a source-level guard forbidding the literal 'canonicalPositiveInt32(trim(' in the two known readers. check.php:1475-1476 reads the predecessor's chk-topic and chk-forum in createTorrent()'s snapshot and applies that exact forbidden spelling — and it is the site that decides what gets WRITTEN into the successor's chk-forum, i.e. the value the two guarded readers will later read. The guard cannot see it: it iterates a hardcoded two-element array. Its own comment claims otherwise ('If a third reader appears, or one of these stops calling the shared predicate, this fails and names the file'), and so does rutracker.php:214 ('One spelling, shared with the only other reader of this custom') — chk-forum has at least five readers (check.php:1431, forumindex.php:1345 and :1415, metafetch.php:771, rutracker.php:211).

**Основание рецензента:** grep -rn 'canonicalPositiveInt32(trim(' plugins/ returns runstate.php:138 (the owner itself) plus check.php:1475 and :1476. I ran the guard's own two predicates over the third file: check.php shared=false respelt=true, while rutracker.php and metafetch.php are shared=true respelt=false. So check.php would fail the guard verbatim if it were in the list. The two copies agree today (identical rule); the day canonicalForumId() changes — the trim dropped, a form accepted — check.php keeps the old rule and writes '' where the readers would have accepted a value, which costs the successor its forum cache and, per the plugin's own comments, a tracker-wide crawl per cooldown.

**Предложение:** Call RuTrackerRpcValue::canonicalForumId($req->val[5]) at check.php:1476 (the trim-on-a-copy reasoning in the comment above it is exactly canonicalForumId's body, and nothing there writes back to the predecessor's customs, so the byte-comparison hazard the comment describes is unaffected). Then either add check.php to the guard's $readers map, or make the guard enumerate plugins/rutracker_check/*.php and assert that every file containing 'chk-forum' either does not canonicalise it or does so through the shared predicate — otherwise the comment's 'if a third reader appears' promise stays false. Fix the rutracker.php:214 sentence: it is not the only other reader.

### 2. [important / duplication] Five spellings of 'same dev+ino', and the two that carry the justifying comment behave the opposite way to what it claims

**Где:** `plugins/erasedata/filesystem.php:442-465 (erasedataIdentityDeviceAndInode) and :681-705 (erasedataEntryIdentityParts / erasedataSameEntryIdentity) vs plugins/erasedata/removewithdata.php:523 (erasedataSameFilesystemEntry), :567 (erasedataExactFileAlias), :584 (erasedataSameStatIdentity)`

**Заявление:** The plugin answers 'is this the same filesystem object?' in five places. Three compare dev/ino with raw ===; two cast both to string first, and carry the only rationale in the family: 'The comparison is string based on purpose: a 64-bit inode on a 32-bit build arrives as a float, and two distinct inodes beyond 2^53 compare equal as floats while their decimal spellings do not.' That last clause is false — the decimal spelling is derived from the float, so it cannot recover a distinction the float has already lost — and in the 32-bit case the comment names, the string form is strictly WEAKER than the raw ===: PHP renders a float with precision=14, so distinct inodes that are exactly representable as doubles collide as strings.

**Основание рецензента:** Measured on PHP 8.5: (string)4503599627370491.0 === (string)4503599627370492.0 is true while 4503599627370491.0 === 4503599627370492.0 is false. Driving the real functions with a float identity (dev 2049.0, ino 4503599627370491.0 vs ...492.0): erasedataIdentityDeviceAndInode() says SAME, erasedataSameEntryIdentity() says SAME, the raw comparator says DIFFERENT. On a 64-bit build every source (@stat/@fstat/pathIdentity) hands ints, so the cast is a no-op and all five agree — the divergence exists only on the build the comment invokes. It is not cosmetic: erasedataIdentityDeviceAndInode() is the whole identity proof of ErasedataFilesystemOps::acquireDirectoryCapability() (filesystem.php:326/339 and descriptorsNamingIdentity at :293), and a capability handed back for the wrong directory is what erasedataDeleteDirectoryReferenceContents() deletes through. The same producer/parser split shows the encoding half of this: erasedataDirectoryReservationPath() (collector.php:35) builds a name from $identity['lstat']['dev'].'-'.$identity['lstat']['ino'], which for a float inode spells '4.5035996273705E+15' — a name none of the three reservation parsers ([0-9]+-[0-9]+-...) can ever match again.

**Предложение:** Give the plugin one dev/ino comparator and have all five call sites use it. Whatever it does about wide values, make it one decision made once: if the 32-bit concern is real, the fix is to reject a non-integer dev/ino (fail closed) rather than to stringify it, since stringifying loses distinctions the float still holds; if it is not real, delete the cast and the comment. Either way the reservation-name producer must be held to the same rule so it cannot emit a name its own parsers reject.

### 3. [minor / duplication] The obsolete-cleanup identity predicate and its index key are written twice, once per plugin, with no owner and no gate

**Где:** `plugins/rutracker_check/check.php:261 (fileIdentityIndexKey) and :269 (resolveSuccessorFileIdentity) vs plugins/erasedata/collector.php:786 (erasedataCleanupExactIdentityKey) and :794 (erasedataCleanupSuccessorObservation)`

**Заявление:** check.php is the PRODUCER of an obsolete-cleanup manifest and collector.php is its CONSUMER, and each carries its own copy of the same two rules: the identity index key ('i:'.dev.':'.ino, identical string format in both) and a ten-clause successor-file validation (is_file, lstat/stat present, lstat mode is regular-or-symlink, stat mode is regular, and lstat/stat dev+ino agree with the resolved identity) that is clause-for-clause the same in both files. Nothing binds them, and the two copies already read identity from DIFFERENT resolvers: check.php uses XMLRPCPathResolver::filesystemIdentity(), while the erasedata side deliberately abandoned that resolver (filesystem.php:215-232 explains why, and tests/plugins/erasedata/RemoveWithDataTest.php:8380 asserts that no erasedata production file so much as mentions it).

**Основание рецензента:** A mechanical 6-line-window scan over the changed production files pairs collector.php:809-813 with check.php:282-286 verbatim. The resolvers agree today only because the producer records nothing that does not exist — for an existing name both are realpath()+lstat()+stat(), which filesystem.php:215 states outright, and the producer's own guard rejects a candidate whose identity says exists=false (check.php:350). So today's agreement rests on a fact documented on the consumer's side and nowhere on the producer's. If either copy tightens (say the mode mask stops accepting a symlink whose target is a regular file), a manifest the checker legitimately wrote becomes silently unconsumable — the payload is never deleted and nothing is logged.

**Предложение:** check.php already calls into erasedata (erasedataExactFileAlias, erasedataPathsOverlap, erasedataPrepareObsoleteCleanup), so the dependency direction permits the shared owner to live in erasedata and check.php to call it. Failing that, add a parity test of the kind UpdatePassTest already uses for the presence double — read both function bodies and drive them over the same probes — and put the 'both resolvers agree for a name that exists, and the producer records nothing else' sentence in check.php too, since that is the assumption the producer is relying on.

### 4. [minor / duplication] The Snoopy message corpus is asserted twice against makeClient(), a hundred lines apart in the same class

**Где:** `tests/plugins/rutracker_check/CheckerTest.php:2067 (testSnoopyFetchErrorIsLoggedAsOneClassifiedToken) and :2139 (testSharedSnoopyCorpusClassifiesTheSameWayThroughMakeClient); companion pair at tests/plugins/rutracker_check/NNMClubHandlerTest.php:1631 and :1697`

**Заявление:** Both tests are added by this commit, both call ruTrackerChecker::makeClient() with Snoopy error strings and both assert the same field with the same regex (/ error=([^\s]*)$/). The nine rows spelled out at :2067 all appear verbatim in fetchErrorParityCases() (TestLib.php:339), which the second test drives; its three whitespace variants are the same property as the parity table's four whitespace rows. The same shape repeats in NNMClubHandlerTest, where the I05 case list is the parity table's first eight rows plus one, with the host names changed.

**Основание рецензента:** Row-by-row comparison: 'Invalid protocol "gopher"\n', the two 'Refusing to fetch:' sentences, 'Error: cURL could not retrieve the document, error 6.', 'socket creation failed (-3)', 'dns lookup failure (-4)', 'connection refused or timed out (-5)', 'connection failed (111)' and 'something php/Snoopy.class.inc does not say today' are byte-identical between CheckerTest:2076-2090 and TestLib.php:342-363. Counting FetchErrorTest.php:32-42, which spells the eight messages a fourth time with different interpolated values, the eight-message table now exists in four places after a change whose stated purpose was to make it exist once.

**Предложение:** Keep the parity test and keep the part of :2067 that is genuinely additional — the $absent column proving no scheme, host, IP or errno fragment reaches the log — by moving that column into the shared corpus (a third element per row, null where there is nothing to check) and deleting the duplicated message list. The FetchErrorTest 'eight distinct tokens' test earns its separate table only for the count assertion; say so, or fold it in too.

### 5. [minor / duplication] ErasedataProductionMirror hardcodes the plugin's file list, and the structural guards that iterate it silently skip anything not on it

**Где:** `tests/plugins/erasedata/CollectorFixture.php:863 (pluginFiles); consumers at tests/plugins/erasedata/RemoveWithDataTest.php:6948, :8380, :8812`

**Заявление:** pluginFiles() spells out the eleven .php files of plugins/erasedata. The directory listing is the other copy of that list, and nothing compares them. The list is no longer used only to copy bytes: it is now the enumeration behind 'no erasedata production file depends on XMLRPCPathResolver' (:8380), 'a production caller of the worker exists' (:8812) and productionCallers() (:6948), so a new file that is not added by hand is exempt from all three guards while the suite stays green.

**Основание рецензента:** ls plugins/erasedata/*.php gives exactly the eleven names pluginFiles() lists, so the copies agree today. grep for glob(/scandir( in tests/plugins/erasedata shows no test enumerates the plugin directory; isExact() only proves that the files ON the list were copied byte for byte, which cannot detect a file missing from the list. This is the failure mode AGENTS.md names under 'Validate a name extractor by COUNT, not by non-emptiness'.

**Предложение:** Derive the list from glob(plugins/erasedata/*.php) and assert that it is non-empty and equals the shipped set (or keep the literal list and add one assertion that it equals the directory listing, so a new file fails loudly instead of being exempted).

### 6. [minor / duplication] The missing-info-hash whitelist has three copies; the new parity gate binds two of them

**Где:** `plugins/erasedata/removewithdata.php:53-57, tests/plugins/rutracker_check/TestLib.php:619-623, plugins/retrackers/guard.php:71-75`

**Заявление:** The same five fault phrases with the same normalisation (rawFaultString, falling back to faultString, lowercased, in_array strict) decide 'the torrent is gone' in three places. This commit adds a strong parity test binding the erasedata production function to its TestLib double — it reads the source, compares the whitelists phrase by phrase and runs both functions over the same probes — but retrackersIsCompleteMissingHashFault() is not in it, and the assertion messages name only the two files.

**Основание рецензента:** grep -rn -i 'info-hash not found' shows the identical five-row array at all three sites, and the identical strtolower()+in_array($msg, ..., true) shape at removewithdata.php:64, TestLib.php:625 and guard.php:70. The three agree today. When rTorrent adds a sixth spelling, the bound pair moves together and retrackers does not: it goes on answering 'not missing' for a torrent that is gone.

**Предложение:** Either extend the existing parity test to read guard.php's whitelist as a third list (the token-scanning helper it already has takes a variable name and a file), or state in guard.php that its whitelist is deliberately its own and why. As it stands the comment traffic implies one rule while three files own it.

### 7. [minor / duplication] TestLib doubles erasedataExactFileAlias with no comment and no parity test, forty lines below the double that has both

**Где:** `tests/plugins/rutracker_check/TestLib.php:636-647 vs plugins/erasedata/removewithdata.php:567-580`

**Заявление:** check.php:367 calls erasedataExactFileAlias() in production, so the rutracker_check suite has to supply it, and TestLib carries a hand-copy. Its neighbour, the erasedataTorrentPresence double at TestLib.php:609, carries a ten-line 'it must stay identical, and here is the test that proves it' comment and is bound to removewithdata.php by UpdatePassTest. The alias double has neither, though it stands in for a predicate that decides whether a file is claimed by the successor and therefore must not be deleted.

**Основание рецензента:** The two bodies are identical modulo brace style; the ERASEDATA_FILE_ALIAS_* constants are also re-defined at TestLib.php:55-57 with the same 1/0/-1 values as removewithdata.php:20-25. grep shows no test in tests/plugins/rutracker_check references erasedataExactFileAlias by name, so nothing exercises the double directly and nothing compares it with its original. (Both this double and check.php's call predate the branch; the parity mechanism that would cover it is what the branch added.)

**Предложение:** Point the existing source-reading parity test at this second pair as well — it is the same three lines of work — or say in the comment why this one may drift when its neighbour may not.

### 8. [minor / duplication] Three spellings of 'launch the plugin's PHP child in the background', two with the fd redirections and one without

**Где:** `plugins/erasedata/removewithdata.php:1308 (erasedataCollectorScheduleCommand), :1319 (erasedataKickCollector), :2017 (erasedataDrainScheduleCommand)`

**Заявление:** All three build 'sh -c <php update.php ...> &' for rTorrent's execute family. The kick and the new drain schedule append '</dev/null >/dev/null 2>&1'; the periodic collector schedule does not, so its child inherits rTorrent's stdin/stdout/stderr. Nothing says which of the two is intended, and the drain's long comment explains everything about the registration except this difference.

**Основание рецензента:** grep -n "sh,-c|'sh', '-c'" on removewithdata.php returns the three lines; the collector schedule's form is unchanged from master, so this commit introduced a sibling that differs from it without saying why.

**Предложение:** Make the three agree on one spawn string (a single helper returning the ' </dev/null >/dev/null 2>&1 &' suffix would do), or add one sentence at :1308 saying the collector deliberately keeps rTorrent's descriptors.

### 9. [nit / duplication] Two stream wrappers that differ in two methods duplicate forty lines of pass-through

**Где:** `tests/plugins/erasedata/RemoveWithDataTest.php:67 (ErasedataPartialWriteStream) and :131 (ErasedataFlushFailureStream)`

**Заявление:** register(), real(), stream_open(), stream_eof(), stream_stat(), stream_close(), url_stat(), unlink() and rename() are byte-identical between the two classes; only stream_write() (truncating vs. faithful) and stream_flush() (true vs. false) differ, which is the entire point of each.

**Основание рецензента:** Read both class bodies; the differing methods are three lines each, the shared ones about forty. The comment at :112 explains why rename() must exist — that reasoning applies to both classes but is written in one of them.

**Предложение:** A small ErasedataPassThroughStream base with the scheme in a const and the two hooks overridden would leave each subclass at its three distinguishing lines, and the rename() rationale would then have one home.

### 10. [nit / duplication] Twenty-three expectations spell the fixture creator and date literally, beside the accessors added to stop exactly that

**Где:** `tests/php/TorrentSequenceFixtures.php:152 (sourceCreator) and :158 (sourceDate); literals in tests/php/TorrentAddPathSequenceTest.php, tests/php/TorrentMetaTest.php and tests/plugins/edit/EditActionSequenceTest.php`

**Заявление:** The commit introduces sourceCreator()/sourceDate() so no two fixtures can drift, and its own docblock admits 'An expectation that spells the same two values out literally instead -- several still do -- is correct today but will not follow a change made here.' The count is measurable: 'uTorrent/3.5.5' appears at 21 expectation sites across three files (plus the accessor), and 1234567890 at many more.

**Основание рецензента:** grep -rn "uTorrent/3\.5\.5" tests/php tests/plugins/edit: 21 lines outside TorrentSequenceFixtures.php. Judged honestly, the drift here fails LOUDLY — change sourceCreator() and the literal expectations stop matching the bytes the fixtures produce — so this is maintenance cost, not a correctness hazard, which is why it is a nit rather than a finding of the same class as the others.

**Предложение:** Either finish the sweep (the sites are mechanical: $this->bstr($this->sourceCreator())) or trim the docblock's promise to what it actually delivers, since a comment that says 'several still do' invites the next reader to assume someone measured which.

---

## Направление: false-comments

**Итог рецензента:** Audited every comment and assertion message the diff adds or changes, hunting factual claims rather than territory: I opened every file:line citation, resolved every named function to its declaration, counted every enumerated count, and executed the claims I could execute (a PHP probe for __DIR__ under process substitution, a byte-count of the marker record, a mutation of the detector TLD list against RuTrackerDomainListTest, a 6-trial proc_open fork reproduction, and file-size sampling of two suites' temp writes).\n\nSixteen findings, four important. The four that matter are a measurable number that is wrong (pending.php:40 says a marker is 82 bytes; it is 92), a citation that points at unrelated code in the load-bearing sentence of an erasedata test's rationale (collector.php:1093-1094 is a log dedupe, not force-2 base-path deletion), a comment that asserts a distinction PHP does not have (__DIR__ vs dirname(__FILE__) — they are identical, and under process substitution both are wrong), and a universal about removewithdata.php that fails for 5 of its 107 functions and makes the extraction helper it justifies unsound for exactly those cases. The rest are stale or imprecise line ranges (php/settings.php:472-484 twice, filesystem.php:331-333, php/settings.php:450-484 three times), an unreachable evidence path, a \"RENAMED, from\" naming a test that exists nowhere in this history, an exhaustiveness claim about chk-stime that misses a second writer, and a \"no-op today\" that is unproved for the d.get_tied_to_file fallback. One is rot this branch caused rather than wrote: it moved hasValidSuccessSchema() from announce.php:434 to 487 and left AGENTS.md's own reference-shape citation pointing at the old line.\n\nThe good news is substantial and worth stating: the two most claim-dense comments in the diff — detector.php's domain-list header and fetcherror.php's Snoopy mapping — are exactly right down to the ordering and the function names, and the loginmgr/detector asymmetry note survives mutation in both directions. I also nearly filed a false report of my own: the \"64 MiB fixtures\" claim in tests/php-test.sh looked false under 0.2 s du sampling (124 KiB peak) and is true under 0.02 s file-size sampling (67108892 bytes). That measurement method is recorded in checkedAndClean so the next reviewer does not repeat the mistake. No suite processes were leaked; the two php runners still alive on this box belong to another agent's run1.sh.

**Проверено и чисто:**

- plugins/rutracker_check/detector.php:8-44 — the richest checkable comment in the diff and every claim in it holds. TRACKER_HOST_PATTERN really is the only place org|cr|net|nl|cc is enumerated (grep over the plugin). The "three production sites" that use the loose TRACKER_PATTERN are exactly detector.php:184 (classify), trackers/rutracker.php:189 (describeCounters) and updatepass.php:290 (hostOf) — I resolved each line to its enclosing function. The "six functions" that ask isTrackerHost/isTrackerRow/isForeignRow resolve exactly to extractTopicId (rutracker.php:47), ruTrackerRowUrl (115), download_torrent (689), RuTrackerMetaFetch::begin (metafetch.php:163), RuTrackerMetaFetch::pump (538) and classify, which does ask twice (detector.php:177 attribution, 199 alive gate). registerTracker() at rutracker.php:952 is handed exactly two literal regexes, at the bottom of the file.
- plugins/rutracker_check/fetcherror.php — the whole file. "php/Snoopy.class.inc's eight strings, in the order that file writes them" is exact: assignments at Snoopy lines 314, 351, 357, 659, 796, 798, 800, 802 map one-to-one and in order onto invalid-protocol, refused-unresolvable-host, refused-non-public-address, curl-transfer, socket-create, dns-lookup, connect-refused, connect-errno. "Snoopy initialises the field to \"\" and only ever assigns strings to it" — `var $error = "";` at Snoopy:71 and no other assignment anywhere in the file.
- plugins/create/correct.php:53-68 — "each of the six *.sh wrappers that can reach this script". There are seven .sh files in plugins/create; exactly six reference correct.php (inner.sh does not), and all six hash into "${7}/temp.torrent" and then run ./correct.php on success. $tname is correct.php:21 = <task path>/temp.torrent, so the object is decoded, not built, and Torrent::touch() is a no-op — verified against php/Torrent.php:563. createtorrent.php really is the other case (new Torrent($path_edit, ...) on a directory/file).
- plugins/loginmgr/accounts/RUTracker.php:7-33 and tests/plugins/loginmgr/RuTrackerDomainListTest.php — including the asymmetry claim, checked by mutation. Ran the suite: 5 tests, 0 failures. Dropped `cc` from TRACKER_HOST_PATTERN → 1 failure ("rutracker.cc is one rutracker_check calls RuTracker's"), as the comment predicts. Added a `xx` TLD to the detector → still 0 failures, exactly as the comment says ("a host ADDED to the detector alone fails nothing"). plugin.info really declares `plugin.dependencies: loginmgr`, and rutracker.cc really appears in production only as api./feed.rutracker.cc in forumindex.php:11-13.
- plugins/erasedata/removewithdata.php:1445-1451 and 2320-2337 — "One erase costs exactly three commands -- d.set_custom5, d.delete_tied, d.erase -- in that order" matches erasedataEraseCommandsForHash() exactly, and "All three destructive call sites" is exact: erasedataEraseRequest() has precisely three callers (2987, 3900, 5054).
- tests/php-test.sh:22-37 and the matching AGENTS.md sentence — "Several suites write 64 MiB fixtures there (SCGITransportTest, the retrackers bounded-reader cases)". I nearly filed this as false: sampling `du -sk $TMPDIR` at 0.2 s intervals showed a 124 KiB peak for SCGITransportTest. Sampling file sizes at 0.02 s instead caught a 67108892-byte file, and the retrackers suite writes 67108864 and 67108863-byte files. The claim is correct and the 512 MiB threshold in the script is 524288 KiB as written. Recording the wrong method so the next reviewer does not repeat it.
- AGENTS.md's proc_open claim — reproduced. `proc_open("sleep 3", ...)` with a STRING command: 6 of 6 trials the returned pid was `sh` with the real process as its child, so proc_terminate() would signal the shell. Both tests/php/SCGITransportFixture.php:265 and tests/php/SCGITransportTest.php:893 do carry the `exec ` prefix, as the note says. `.git/hooks/pre-commit:28` really does default TMPDIR to $HOME/.cache/rutorrent-tmp.
- plugins/rutracker_check/forumindex.php:1150-1216 (markSweep) — "update()'s three failures split on exactly that (state.php)" is right: RuTrackerState::update() sets 'unlockable' at state.php:354 and 362 and 'unreadable' at 372, both before `call_user_func($mutator, ...)` at 376, and 'unwritable' at 378 after it. The $refusal classifier's four reachable combinations all match what the docblock at 1160-1169 promises, and $refusal is a genuine by-reference out param, not dead.
- plugins/erasedata/update.php and its wiring comments — php/getplugins.php:483 really does re-run every enabled plugin's init.php; plugins/erasedata/init.php:31 really is the only caller of erasedataRearmDrainSchedule(); update.php:115 really is the only production caller of erasedataDrainWorkerMain(); pending.php is required unconditionally at update.php:21; done.php really removes only the 'erasedata' key. erase.php's argv docstring quoted at removewithdata.php:1462 is verbatim on master, so "since the exact base" holds.
- plugins/erasedata/removewithdata.php:4966-4979 — "plugins/httprpc/action.php ... reads the force out of the POST body, validates it with normalizeForce() and hands this function the RAW STRING" is exactly what httprpc/action.php:409-421 does, and plugins/erasedata/init.js:60-70 really only falls back to erasedata/action.php when getCommon (httprpc) is unavailable.
- plugins/rutracker_check/trackers/rutracker.php:255-286 — every number in the deletionGate arithmetic. gate(3600)=3240 (54 min); the reduction is a full tenth from $interval 66 up (66-6=60, the floor exactly); below 66 the floor wins and 61 loses 1 s not 6; gate==interval at exactly one value, 60, which I confirmed is the only solution over the whole domain; gate(600)=540, so it is genuinely not restored at ten minutes; and confirmDeletion() (rutracker.php:415) really floors to MIN_DELETE_INTERVAL when $updateInterval is 0.
- plugins/rutracker_check/conf.php:26-54 — the rutrackerSweepCooldown domain note. canonicalNonnegativeInteger() (runstate.php:76-86) really rejects '', booleans, negatives, floats, '1e3', ' 24', '024', '+24', arrays, objects and past-PHP_INT_MAX decimals; forumindex.php:1102-1118 really falls back to 86400 and logs the exact fixed text once per process via a static flag. The sweep-cost paragraph explicitly labels itself a historical sample and an extrapolation, which is the right way to write an unreproducible number.
- php/Torrent.php:24-27 and 674-694 plus the upstream contrast — build() is the only place $built is set, its file branch really excludes a .torrent extension, and `git show upstream/master:php/Torrent.php` confirms upstream's touch() still stamps both keys unconditionally, so the "kept for the upstream PR" rationale in plugins/edit/action.php is sound.
- tests/plugins/rutracker_check/UpdatePassTest.php:3820-3822 — php/xmlrpc.php:226-227 really are `$this->rawFaultString = self::parseRawFaultString($answer);` and `$this->faultString = trim($this->rawFaultString);`, and parseRawFaultString (xmlrpc.php:254-260) does return the html_entity_decoded text without trimming inside the <string>.
- tests/plugins/erasedata/PendingQueueTest.php — all five "(supersedes testX)" notes name tests that really are on master (testAHashAlreadyCollectedLeavesTheQueue, testAMarkerThatIsNotAHashIsSweptUp, testAFailedEraseIsCountedAndRetriedLater, testItGivesUpRatherThanRetryingForever, testALimitOfZeroNeverGivesUp), and each replacement really pins what its note says the old one got wrong.
- plugins/erasedata/filesystem.php:461-473 — "This is its ONE definition" of erasedataSharedFileMode(): grep across plugins/ and tests/ finds exactly one `function erasedataSharedFileMode`. The cross-reference to removewithdata.php:27-30 is correct, and removewithdata.php really does require filesystem.php (line 336) before anything can call it.
- plugins/erasedata/removewithdata.php:4489-4517 — the "THREE guards" are enumerated (1)(2)(3) and each matches the code; ERASEDATA_DRAIN_INTERVAL=5 with ERASEDATA_DRAIN_ACK_TIMEOUT=11.0 does give the acknowledgement two ticks inside a default 30 s max_execution_time, as removewithdata.php:1405-1407 says; and every drain control file is dot-prefixed or ends in .lock as removewithdata.php:4148 claims (including 'scheduler.lock').

### 1. [important / false-comment] "is 82 bytes" is 92 bytes — measured

**Где:** `plugins/erasedata/pending.php:40, above ERASEDATA_PENDING_MARKER_MAX_BYTES`

**Заявление:** The comment says `version=1\n + generation=<16>\n + hash=<40>\n + force=<1>\n is 82 bytes`. The record the encoder actually emits is 92 bytes.

**Основание рецензента:** erasedataEncodePendingMarker() (pending.php:78-81) emits exactly those four lines; erasedataGenerationIsValid() (filesystem.php:548) pins the generation at 16 hex chars and erasedataIsCanonicalPendingHash() at 40. Measured with `php -r`: strlen("version=1\n")=10, "generation=<16>\n"=28, "hash=<40>\n"=46, "force=1\n"=8 → 92. The figure 82 does not reproduce under any spelling of the four fields.

**Предложение:** "version=1\n + generation=<16>\n + hash=<40>\n + force=<1>\n is 92 bytes." (The 4096 ceiling and the conclusion drawn from it are unaffected.)

### 2. [important / false-comment] Cited line for force-2 whole-base-path deletion points at the manifest-retained log

**Где:** `tests/plugins/erasedata/RemoveWithDataTest.php:9478, docblock of testDisagreeingMarkerForcesAreRefusedEvenWithAJournalRecord`

**Заявление:** "Force 2 is whole-base-path deletion at collector.php:1093-1094". collector.php:1093-1094 is `$this->manifestLogState[$key] = true;` and `FileUtil::toLog('erasedata: manifest retained ...')` inside logManifestRetained() — a log-deduplication write with nothing to do with deletion.

**Основание рецензента:** Opened collector.php:1085-1096. The force-2 base-path deletion is at collector.php:1129 (`$force_delete = ($manifest['force'] === 2 && empty($manifest['legacy'])) ...`) and 1223-1242 (`Retain active forced directory`, `Successfully forced delete dir`, `FAIL force delete dir`, all on $base_path).

**Предложение:** "Force 2 is whole-base-path deletion at collector.php:1129 and 1223-1242". This is the load-bearing sentence of the case's rationale, so it should name the code that actually deletes.

### 3. [important / false-comment] __DIR__ cannot differ from dirname(__FILE__), and is equally wrong under process substitution

**Где:** `tests/plugins/erasedata/RemoveWithDataTest.php:6747-6749, above repositoryRoot()`

**Заявление:** "The focused runner executes this file through process substitution, so __FILE__ and dirname(__FILE__) point at /proc/<pid>/fd/N and __DIR__ is the only correct anchor." __DIR__ is defined by PHP as dirname(__FILE__); the two can never disagree, and under process substitution __DIR__ is /proc/<pid>/fd, which makes repositoryRoot() resolve to /proc.

**Основание рецензента:** Ran a probe (php 8.5.4) three ways — direct, `php <(cat probe.php)`, and require of a redirected file. `__DIR__ === dirname(__FILE__)` printed equal=1 in all three. Under process substitution: __FILE__=/proc/104810/fd/pipe:[684435], dirname(__FILE__)=/proc/104810/fd, __DIR__=/proc/104810/fd. So `__DIR__.'/../../..'` would be `/proc`, not the repository root.

**Предложение:** Either drop the sentence, or state what is actually true: RunNamedCases.php requires the test file by path (`is_file($runnerFile)` then `require_once`), so __FILE__/__DIR__ are the real on-disk path and __DIR__ is a correct anchor — but not because it differs from dirname(__FILE__).

### 4. [important / false-comment] "every function in this file lives inside its own if(!function_exists(...)) guard" — five do not

**Где:** `tests/plugins/erasedata/RemoveWithDataTest.php:12505-12507, justification for guardedFunctionBody()`

**Заявление:** The comment asserts a universal about removewithdata.php to justify using the guard as the extraction delimiter. Five of its 107 top-level functions share a guard with a sibling, so for those the extracted "body" spans two functions — exactly the over-capture the comment warns about for the column-zero alternative.

**Основание рецензента:** Script over plugins/erasedata/removewithdata.php pairing each `^\tfunction NAME` with the nearest preceding `^if(!function_exists('NAME'`: 107 functions, 102 guards, 5 mismatches — erasedataReadExactCleanupFile (593, under the erasedataReadExactCleanupArtifact guard), erasedataPathLookupAnswers (1742), erasedataEraseCommandsForHash (2332, under the erasedataEraseRequest guard), erasedataRemovalCapabilityStillMatches (2589) and erasedataReleaseRemovalCapabilities (2608, both under erasedataAcquireRemovalCapability). guardedFunctionBody() (RemoveWithDataTest.php:6824-6840) cuts at the next `\nif(!function_exists(`, so a body taken for any of those guards' first function includes the siblings.

**Предложение:** "Scoped by hand: almost every function in this file sits in its own if(!function_exists(...)) guard, and the two used here do — a body taken to the next column-zero \"function \" would run to the end of the file. Five guards do hold more than one function (erasedataEraseRequest, erasedataAcquireRemovalCapability, erasedataReadExactCleanupArtifact, erasedataDirectoryLookupsAnswer), so this extractor must not be pointed at those."

### 5. [minor / false-comment] "Copied verbatim from php/settings.php:472-484" cites the wrong function's lines, twice

**Где:** `tests/plugins/erasedata/CollectorFixture.php:205 and :1559`

**Заявление:** Both comments say the getAlignedStart() arithmetic is copied verbatim from php/settings.php:472-484. getAlignedStart() is at php/settings.php:486-498; 472-484 is the tail of getScheduleCommand()'s explanatory comment plus its three statements.

**Основание рецензента:** php/settings.php (untouched by this diff, identical on master) numbered: 462-481 comment, 482-484 getScheduleCommand's body, 486 `static public function getAlignedStart($name,$interval,$now = null)`, 488-497 the body copied into the fixture, 498 close. Also "verbatim" is loose — the fixture reflows `if(!isset($schedule_rand)) $schedule_rand = 10;` onto one line and wraps the $offset expression.

**Предложение:** "Copied from php/settings.php:486-498 (reformatted, arithmetic identical)" in both places.

### 6. [minor / false-comment] openDirectoryReference() alias cited at the wrong lines

**Где:** `tests/plugins/erasedata/RemoveWithDataTest.php:7330-7331`

**Заявление:** "...reaches ErasedataFilesystemOps::acquireDirectoryCapability() through the openDirectoryReference() alias -- collector.php:309 into filesystem.php:331-333." collector.php:309 is right; filesystem.php:331-333 is not the alias, it is three statements inside acquireDirectoryCapability()'s body.

**Основание рецензента:** filesystem.php: acquireDirectoryCapability() is declared at 324; lines 331-333 are `// Before the handle exists...`, `$before = array();`, `foreach($candidates ...)`. openDirectoryReference() is declared at 389 and its whole body is `return($this->acquireDirectoryCapability($path, $expectedIdentity));` at 390.

**Предложение:** "-- collector.php:309 into filesystem.php:389, which forwards to acquireDirectoryCapability() at filesystem.php:324."

### 7. [minor / false-comment] This branch moved hasValidSuccessSchema() and left AGENTS.md citing its old line

**Где:** `AGENTS.md:128 (rot introduced by plugins/rutracker_check/announce.php in this diff)`

**Заявление:** AGENTS.md says "`RuTrackerAnnounce::hasValidSuccessSchema()` (`plugins/rutracker_check/announce.php:434`) is the reference shape". On this branch announce.php:434 is a line of the BENCODE_LIMITS commentary; the function moved to 487.

**Основание рецензента:** `git show master:plugins/rutracker_check/announce.php | grep -n 'function hasValidSuccessSchema'` → 434 (citation was correct on master). On HEAD: `grep -n hasValidSuccessSchema plugins/rutracker_check/announce.php` → 487. The diff edits AGENTS.md (+61 lines) but does not touch this line. announce.php:430-440 is the max_length_digits / BENCODE_LIMITS block.

**Предложение:** Update to `plugins/rutracker_check/announce.php:487`. This is the exact rot class AGENTS.md itself warns about, and it was caused by this change.

### 8. [minor / false-comment] "three lines above" is 91 lines above

**Где:** `plugins/erasedata/update.php:99-101`

**Заявление:** "a CLI child's User::getUser() answers whatever REMOTE_USER was set to three lines above". The only `$_SERVER['REMOTE_USER']` assignment in update.php is at line 9.

**Основание рецензента:** `grep -n REMOTE_USER plugins/erasedata/update.php` → 9 (the assignment) and 100 (this comment). The comment sits at line 100. The phrase looks borrowed from the unrelated comment at update.php:5 ("used to die here, three lines in"), which is correct about line 8. Master carries the line-5 comment but not this one, so the error is new here.

**Предложение:** "...answers whatever REMOTE_USER was set to at the top of this file (line 9)".

### 9. [minor / false-comment] Evidence document cited for three measured daemon facts is not in this tree

**Где:** `plugins/erasedata/removewithdata.php:4113-4114, header of the retirement section`

**Заявление:** "Three facts, all measured on real 0.9.8 and 0.16.21 daemons (.superpowers/sdd/schedule-semantics-evidence.md), shape every line below". No `.superpowers/` directory exists in this worktree or in the delivered commit, so nobody reading the shipped tree can reach the evidence.

**Основание рецензента:** `ls -d .superpowers` → no such file or directory. `find / -name schedule-semantics-evidence.md` finds exactly one copy, in the unrelated worktree /home/dev/Documents/my_projects/.rutorrent-worktrees/package-6-rebuild-2/. I read that file: it does support all three facts (schedule_remove answers OK for an unregistered key on both versions; re-registering restarts the countdown silently; the leading empty target is added by patchDeprecatedCommand() only for the LOGICAL name). So the facts are sound; only the citation is unreachable.

**Предложение:** Either commit the evidence file under tasks/, or drop the parenthetical and say "measured on disposable 0.9.8 and 0.16.21 containers, 2026-09-04" so the claim does not point at a path that is not there.

### 10. [minor / false-comment] "RENAMED, from testCrash..." names a test that exists nowhere in this history

**Где:** `tests/plugins/erasedata/RemoveWithDataTest.php:10809-10824`

**Заявление:** The docblock of testNoAckRollbackKeepsEveryRemainingObligationOfTheBatch says "RENAMED, from testCrashAfterPartialEraseKeepsEveryRemainingObligation" and then explains what the old name wrongly claimed. From the delivered commit there is no such old name to have been renamed.

**Основание рецензента:** `git grep testCrashAfterPartialEraseKeepsEveryRemainingObligation master -- tests/` → nothing. `git log --all -S<name> -- tests/` → only 4adfaffa (this commit, in the comment) and 1617cbe9, which `git merge-base --is-ancestor` shows is an ancestor of neither master nor HEAD (it lives on agent/package-6-erasedata-remove-payload-rebuild-2 and friends). Contrast PendingQueueTest.php's five "(supersedes testX)" notes, all of which name tests that really are on master — those are correct and useful.

**Предложение:** Drop "RENAMED, from ..." and keep the substance: "This case does not drive a producer that died mid-erase — nothing plays the scheduler, so the producer waits out ERASEDATA_DRAIN_ACK_TIMEOUT and takes the no-ack rollback. What it pins is ...".

### 11. [minor / false-comment] chk-stime has a second writer that the "for nothing else" clause hides

**Где:** `plugins/rutracker_check/trackers/rutracker.php:293-297 (deletionRunState docblock); the same claim recurs at rutracker.php:915-917`

**Заявление:** "chk-stime is the independent record of the last up-to-date verdict -- check.php's stateCommands() writes it for STE_UPTODATE and for nothing else". True of stateCommands(), but check.php also writes chk-stime unconditionally from buildReplacementAddition(), on the STE_UPDATED replacement path — so a chk-stime stamp does not by itself mean an UPTODATE verdict ever ran on that row.

**Основание рецензента:** `grep -n chk-stime plugins/rutracker_check/*.php trackers/*.php` gives exactly two production writers: check.php:410 (inside stateCommands(), guarded by `if($state == self::STE_UPTODATE)`) and check.php:564, `getCmd("d.set_custom")="chk-stime,".$now` in buildReplacementAddition() with no state guard. Its only caller, check.php:1518, passes `self::STE_UPDATED`. So a replaced torrent's new row starts life with chk-stime set under STE_UPDATED.

**Предложение:** "...stateCommands() writes it for STE_UPTODATE and for nothing else; the only other writer is buildReplacementAddition() (check.php:564), which stamps the successor row at replacement time, where there is no deletion count for it to invalidate."

### 12. [minor / false-comment] "the restore is a no-op today" is not proved for the tied-file fallback

**Где:** `plugins/edit/action.php:106-110 and the parallel sentence at plugins/rutracker_check/metafetch.php:621-625`

**Заявление:** Both say Torrent::touch() cannot fire because the path handed to `new Torrent(...)` ends in .torrent. Both call sites have a second source for that path — d.get_tied_to_file — which rTorrent does not guarantee ends in .torrent; a readable non-.torrent file makes Torrent::build() succeed, set $built, and stamp both keys (and hash that file as payload).

**Основание рецензента:** php/Torrent.php:563 — build() takes the file branch only when `pathinfo($data, PATHINFO_EXTENSION) != 'torrent'`, so the .torrent half of the claim is exactly right. plugins/edit/action.php:85-91: `$fname = $req->val[0].$hash.".torrent"` and, when the session copy is unreadable, `$fname = $req->val[4]` (d.get_tied_to_file). php/rtorrent.php:171-177 has the identical fallback (val[1]).

**Предложение:** "On this fork the restore is a no-op for the session copy: touch() writes nothing unless the class built the info dictionary itself, which a path ending in .torrent never triggers. It is not provably a no-op on the d.get_tied_to_file fallback, whose extension rTorrent does not constrain — one more reason to keep the snapshot."

### 13. [nit / false-comment] php/settings.php:450-484 starts twelve lines before the comment it cites

**Где:** `plugins/erasedata/removewithdata.php:1998, tests/plugins/erasedata/RemoveWithDataTest.php:12304 (and the same range in AGENTS-adjacent prose)`

**Заявление:** Three added comments say "php/settings.php:450-484 documents it [getAlignedStart's rationale]". The documenting comment is php/settings.php:462-481; line 450 is `return($this->getEventCommand('on_hash_done','hash_done',$args));` inside getOnHashdoneCommand(), and 452-459 is getAbsScheduleCommand(), which is the rand()-jittered builder the comment argues against.

**Основание рецензента:** `grep -n '' php/settings.php | sed -n '448,500p'`. The range is a superset that includes the right lines, so a reader lands nearby, but it opens on an unrelated function.

**Предложение:** Cite php/settings.php:462-481 (the comment) or 460-484 (getScheduleCommand whole).

### 14. [nit / false-comment] AGENTS.md leads with "/tmp is a 1.7 GB tmpfs" while this boot has 4.5 GB

**Где:** `AGENTS.md, "This Machine Will Lie To You About Test Results", third bullet (added by this diff)`

**Заявление:** The bolded lead sentence states a size as a standing fact; four lines later the same bullet says the size varies boot to boot and cannot be relied on, and gives 4701512k as the other observed value — which is what this machine currently has.

**Основание рецензента:** `findmnt -no SIZE,OPTIONS /tmp` → `4.5G rw,...,size=4701512k,...`; `df -h /tmp` → 4.5G. The rest of the bullet's arithmetic checks out: 12093*0.33 = 3990; 1736012k = 1.66 GiB and doubles to 3.31 GiB ("~3.3 GB"); 4701512k = 4.48 GiB and doubles to 8.97 GiB ("~9 GB").

**Предложение:** Lead with the rule rather than one boot's number: "**`/tmp` is a tmpfs sized at half the RAM this VM booted with, because of Hyper-V Dynamic Memory** — 1.7 GB on one boot, 4.5 GB on the next."

### 15. [nit / false-comment] The recommended leak check answers 1, not 0, when run from a shell

**Где:** `AGENTS.md, first bullet of "This Machine Will Lie To You About Test Results" (added by this diff)`

**Заявление:** "Check with `pgrep -cf rutorrent-scgi-rpc2`; a healthy idle machine answers 0." `pgrep -f` matches full command lines, including the shell that is running the pgrep, whose command line contains the literal pattern.

**Основание рецензента:** Ran it after a clean SCGITransportTest run: `pgrep -cf rutorrent-scgi-rpc2` → 1; `pgrep -af rutorrent-scgi-rpc2` showed the single match was my own `/bin/bash -c ... eval 'pgrep -af rutorrent-scgi-rpc2 ...'`. `ps -eo pid,args | awk '$2 ~ /php/ && / -S /'` returned nothing, i.e. genuinely zero servers.

**Предложение:** "Check with `pgrep -cf '[r]utorrent-scgi-rpc2'` (the bracket keeps the checking shell out of its own match); a healthy idle machine answers 0."

### 16. [nit / false-comment] "eleven torrents dated 8 to 15 hours late" has no provenance in the tree

**Где:** `plugins/rutracker_check/metafetch.php:653-657 and tests/plugins/rutracker_check/MetaFetchTest.php:1881-1883`

**Заявление:** Both state a live-fleet measurement — eleven torrents, 8 to 15 hours late, every one on the top of an hour. Nothing in the repository records it, so it cannot be re-derived; given the project's own rule that a number must be measured and re-runnable, this reads as measured without a trace.

**Основание рецензента:** `grep -rn '8 to 15 hours' tasks/ plugins/ tests/` finds only these two comments. tasks/2026-09-05-consolidated-fixes/LIVE-CHECKS.md has no creation-date/authorship measurement. I cannot disprove the figure; I can only say it is unverifiable from the delivered tree.

**Предложение:** Record the query and result in tasks/2026-09-05-consolidated-fixes/LIVE-CHECKS.md and cite it, or soften to "observed on the live fleet before the fix" without the counts.

---

## Направление: php74-and-portability

**Итог рецензента:** Hunted the php74-and-portability class over 4adfaffa by measurement rather than by reading alone: full php-test.sh runs of both master and HEAD, in the shipped Alpine image (PHP 8.5.10, no tokenizer/posix/pcntl) and on php:7.4-cli, each as uid 1000 and as root, always with a disk-backed TMPDIR and a git-archive export rather than the worktree. The good news first: there is no 8.x-only syntax, no 8.x-only builtin, no new optional-extension dependency in production, no unstable-sort or cross-device-rename hazard, the 32-bit generation counter is genuinely string-safe, and the suite really does run clean on PHP 7.4 -- the version axis of this diff is in good shape. What the runtime axis shows is two things the suite cannot report to you. (1) A new test adds an unguarded token_get_all(), and the shipped image has no tokenizer: plugins/rutracker_check/UpdatePassTest.php goes from green to red there, master 6 failed files -> head 7 as uid 1000, while AGENTS.md still describes the gap as '8 of them in EntrypointsTest'. The project already ships the fix pattern at XMLRPCProxyTest.php:2193. (2) Six new assertions in RemoveWithDataTest and ForumIndexTest establish their scenario with chmod and therefore fail as root -- measured identically on 8.5/Alpine and on php:7.4-cli, whose docker default IS root: RemoveWithDataTest 8 -> 12 and ForumIndexTest 1 -> 3 failing assertions. The same diff applies the correct root guard to two other sites and not to these six, and the 'lies in both directions at once' signature reproduces exactly: on php:7.4-cli, switching from uid 1000 to root repairs PermissionTest and TaskTest while breaking the two suites this change touches. Beyond that, three comments state things that are not true when you open the line they cite: the string-cast rationale in erasedataIdentityDeviceAndInode() (the cast merges identities on the very build it invokes, measured), '/dev/fd the fallback for systems that do not mount procfs' (it is a symlink to /proc/self/fd on this host and in the image, so the second candidate is unreachable), and 'is_executable() answers for THIS process's effective identity' (it answers for the real one -- demonstrated with a euid/ruid split, where it disagrees with the @stat(dir/'.') probe the sibling owner of the same primitive uses, and disagrees fail-open).

**Проверено и чисто:**

- No 8.x-only syntax anywhere in the diff. Extracted the 19,942 added lines and grepped for arrow functions, match expressions, nullsafe ?->, enum/readonly, named arguments, first-class callable syntax (...), constructor property promotion, catch without a variable, throw-as-expression, trailing comma in a parameter list, and $obj::class. Zero hits. The style is uniformly 5.x-compatible (isset()?:, explicit array()).
- No 8.0+ builtin functions. Extracted the 184 distinct function names appearing on added production lines and checked against str_contains, str_starts_with, str_ends_with, fdiv, get_debug_type, preg_last_error_msg, array_is_list, enum_exists, json_validate, mb_str_split, str_increment, array_find/any/all. Zero hits.
- The suite really does run on PHP 7.4. Full php-test.sh on php:7.4-cli as --user 1000:1000: 80 files, only php/PermissionTest.php (3 assertions) and plugins/_task/TaskTest.php (2) fail, both from my container's uid mapping rather than from the diff, and neither file is touched by it. No PHP Fatal or Parse error anywhere in the run.
- No new dependency on an optional extension in production code. The only new posix use is @posix_mkfifo at tests/plugins/rutracker_check/CheckerTest.php:892, correctly guarded by function_exists() and by the @; no pcntl, no sockets, no bcmath. Confirmed php:7.4-cli and php:8.1-cli both carry posix and tokenizer, while the shipped Alpine image carries neither -- which is what makes finding 1 image-specific rather than version-specific.
- tests/php-test.sh's new free-space guard is Alpine-portable. `df --help` in ivanshift/rutorrent:latest is BusyBox v1.37.0 and its usage line includes -P; `df -Pk /tmp | awk 'NR==2 {print $4}'` returns the Available column correctly. The [ -n "$tmp_free_kb" ] test covers a df that fails outright.
- The 'exec ' fixture-leak fixes are real and their comments are true. SCGITransportTest.php:887 and SCGITransportFixture.php:265 both carry it. The related claim in the new AggregateEraseFixture (ErasedataProductionMirror::php(), 'dash refuses exec exec <cmd>') was checked across shells: dash and bash both answer 'exec: exec: not found' with rc 127, busybox ash accepts it -- so the decision to put the prefix in one place and not the other is correct on this host. After every run of mine, pgrep -cf rutorrent-scgi-rpc2 reports only my own shell's command line; no server was leaked.
- 32-bit safety of the generation counter is genuinely handled. filesystem.php:540-575 keeps a generation as 16 fixed-width hex characters end to end -- increment by digit, compare by strcmp -- with no hexdec() and no arithmetic anywhere, and says why in a comment that is accurate (0xFFFFFFFFFFFFFFFF exceeds PHP_INT_MAX and would become a float even on 64-bit).
- random_bytes() at filesystem.php:651-658 is wrapped in catch(Exception), which covers both PHP 7.4's Exception and PHP 8.2+'s Random\RandomException (a subclass of Exception). No 8.x-only Error/ValueError handling was introduced anywhere in the diff.
- The new by-reference third parameter on RuTrackerState::update($name, $mutator, &$failure = null) is safe on 8.0+. Checked all 20 production and test call sites: every one passes either two arguments or a plain variable, so none hits PHP 8.0's fatal 'cannot pass parameter by reference' for an expression.
- Sorting is version-independent. Every new sort()/ksort() call in the diff (28 of them) passes SORT_STRING over string keys or values, so PHP 8.0's change to stable sorting cannot alter any result.
- The ReflectionProperty::setAccessible() deprecation is handled where it matters. Verified it is a no-op-but-silent in 8.1 and newly E_DEPRECATED in 8.5, so the PHP_VERSION_ID < 80100 guards at TestLib.php:293, :380 and UpdatePassTest.php:3579 are load-bearing under the StrictTestSuite error handler and tests/php-test.ini's error_reporting=-1. The ~17 unguarded call sites elsewhere all sit inside child-process source strings run with the default php.ini; measured count of 'Deprecated: ... setAccessible' lines in the 8.5 image run is 20 on master and 20 on head, so the diff adds none.
- The measured number in plugins/create/correct.php's new comment is right. It claims 'each of the six *.sh wrappers that can reach this script'; grep -l correct.php plugins/create/*.sh returns exactly 6 of the 7 wrappers (inner.sh does not reference it).
- No cross-device rename hazard in the new durable-write and capture paths. erasedataWriteDurableFile() stages at dirname($path).'/.'.basename($path).'...' (filesystem.php:505), and the collector's reservation and recovery roots are built from dirname() of the target (collector.php:52, :595-603), so every rename() stays inside one directory and cannot hit EXDEV.
- $erasePendingMaxAttempts was removed from plugins/erasedata/conf.php with no dangling reference: the only remaining occurrence in the tree is the test that pins its absence (RemoveWithDataTest.php:9213).

### 1. [important / other] New tokenizer dependency turns UpdatePassTest.php red in the shipped Alpine runtime, unguarded and unexplained

**Где:** `/home/dev/Documents/my_projects/.rutorrent-worktrees/campaign-integration/tests/plugins/rutracker_check/TestLib.php:85 (loadFunctionDefinition) and tests/plugins/rutracker_check/UpdatePassTest.php:3871 (updPresenceWhitelist), reached from UpdatePassTest.php:3926-3927`

**Заявление:** Both new helpers call token_get_all() with no function_exists() guard. AGENTS.md's 'The Shipped Image As A Test Runtime' section says the image does not load tokenizer and names the resulting failures as '8 of them in EntrypointsTest'. The diff adds a 9th, in a different file, so the documented expectation no longer matches the observed result and a reviewer following AGENTS.md sees a newly-red suite file.

**Основание рецензента:** docker run --rm --user 1000:1000 --network none -v <export>:/w -w /w/tests ivanshift/rutorrent:latest -c 'bash php-test.sh', on a git-archive export of both master and 4adfaffa, TMPDIR bind-mounted to disk. master: 'Failed PHP test files (6)'. 4adfaffa: 'Failed PHP test files (7)' -- the extra file is plugins/rutracker_check/UpdatePassTest.php, 'not ok - the erasedata presence double is bound to removewithdata.php, phrase for phrase and answer for answer / Error: Call to undefined function token_get_all()'. Per-file token_get_all failure counts, master -> head: PluginInitPathsTest 1->1, EntrypointsTest 8->8, ImmortalSeedCategoriesTest 1->1, UpdatePassTest 0->1 (total 10->11). Confirmed `php -m` in ivanshift/rutorrent:latest (PHP 8.5.10, Alpine) lists no tokenizer, posix or pcntl. Same result as root.

**Предложение:** Guard the shared helper, not the call site: TestLib.php is required by every rutracker_check suite, so any future caller of loadFunctionDefinition() inherits the gap silently. The project already has the pattern at tests/php/XMLRPCProxyTest.php:2193-2197 -- `if(!function_exists('token_get_all')) { assert(false, 'tokenizer extension is required for ...'); return; }` -- which fails with a message naming the missing extension instead of a bare undefined-function Error. Either adopt that, or add UpdatePassTest.php to AGENTS.md's list so the image comparison stays arithmetic.

### 2. [important / weak-test] Six new assertions fail as root; the diff's own root guard is applied to 2 of 6 new chmod-based scenarios

**Где:** `/home/dev/Documents/my_projects/.rutorrent-worktrees/campaign-integration/tests/plugins/erasedata/RemoveWithDataTest.php:9701->9706 and :9967->9973/9975/9977; tests/plugins/rutracker_check/ForumIndexTest.php:3628->3639 and :3675->3681`

**Заявление:** Four new RemoveWithDataTest assertions and two new ForumIndexTest assertions establish a refusal scenario with chmod (0555, 0000) or $GLOBALS['profileMask']=0555 and then assert the refusal. root has DAC_OVERRIDE, so the restriction never bites and the assertions fail. The same diff already knows this: RemoveWithDataTest.php:13113-13118 and :13455-13461 carry the guard plus the comment 'chmod cannot restrict root, so under root this scenario cannot be staged at all and a test that ran anyway would pass vacuously.' It was applied to two sites and not to the other four (plus the two ForumIndexTest ones).

**Основание рецензента:** Measured, base vs head, same runner, same image, same TMPDIR. ivanshift/rutorrent:latest (PHP 8.5) as root: failing assertions per file, master -> head: RemoveWithDataTest 8 -> 12, ForumIndexTest 1 -> 3 (whole-suite totals 19 -> 26 failing assertions, 8 -> 9 failing files). php:7.4-cli as root (its default uid): identical delta, RemoveWithDataTest 8 -> 12 and ForumIndexTest 1 -> 3. The 'lied in both directions at once' signature from AGENTS.md reproduces exactly: full suite on php:7.4-cli as --user 1000:1000 fails 2 files (PermissionTest 3, TaskTest 2, neither touched by this diff); the same command as root fails 2 files as well, but a DIFFERENT two (RemoveWithDataTest, ForumIndexTest) while PermissionTest and TaskTest go green. Cost of the guard that IS present, also measured: as root RemoveWithDataTest reports 2744 passed / 12 failed against 2765 passed / 0 failed as uid 1000, i.e. the guard's early `return` also skips ~9 assertions that have nothing to do with permissions (5 of them in testAnUnsearchableQueueDirectoryIsUncertaintyAndNeverAbsence alone).

**Предложение:** Give the six sites one owner instead of two spellings and four omissions: a helper that (a) measures the premise -- ForumIndexTest.php:3637-3641 already does this well with 'the mask really does take the write away' -- and (b) skips visibly when the premise cannot be established, naming root as the reason. That also removes the silent tail-skip: a guard placed at the top of the scenario rather than mid-method stops swallowing the unrelated assertions after it.

### 3. [important / false-comment] erasedataIdentityDeviceAndInode()'s stringification comment is false, and on the build it names the cast merges identities rather than separating them

**Где:** `/home/dev/Documents/my_projects/.rutorrent-worktrees/campaign-integration/plugins/erasedata/filesystem.php:442-444 and :458 (erasedataIdentityDeviceAndInode)`

**Заявление:** The comment reads 'The comparison is string based on purpose: a 64-bit inode on a 32-bit build arrives as a float, and two distinct inodes beyond 2^53 compare equal as floats while their decimal spellings do not.' The second half is not true: (string) on a float renders through the `precision` ini (14 significant digits), so two values that collide as floats also collide as strings. Worse, the cast introduces a collision that raw float comparison does not have -- two floats that are genuinely distinct stringify identically. This value is the deletion authorisation (removewithdata.php:2601-2605 compares it for the capability handle, the /proc descriptor and the manifest base before erasedataDeleteDirectoryReferenceContents() recurses).

**Основание рецензента:** php8.5 -r 'var_dump((string)9007199254740992.0, (string)9007199254740993.0);' -> both "9.007199254741E+15", and (string)$a === (string)$b is true: the case the comment says strings separate, they do not. And php8.5 -r '$a=1.0e17; $b=1.0e17+16.0; var_dump($a===$b, (string)$a===(string)$b);' -> bool(false), bool(true): distinct floats, identical strings. ini_get('precision') is 14 and tests/php-test.ini does not change it. Every caller feeds this live stat()/fstat() output or a capability array whose dev/ino are already this function's own strings, so nothing on a 64-bit build is wrong today -- (string) of an int is exact.

**Предложение:** Either drop the string cast and require is_int() on both fields, failing closed on a float (an identity that cannot be represented exactly is not a proven identity, which is this file's stated contract), or keep strings but render floats with sprintf('%.0F', $v) so the decimal spelling really is lossless -- and rewrite the comment to say which. As written the sentence is the recurring defect class AGENTS.md names.

### 4. [minor / dead-code] /dev/fd is not a procfs fallback on any platform this fork targets, so the second descriptor candidate is unreachable

**Где:** `/home/dev/Documents/my_projects/.rutorrent-worktrees/campaign-integration/plugins/erasedata/filesystem.php:431-438 (erasedataDescriptorCandidates), consumed at :326-360 (acquireDirectoryCapability)`

**Заявление:** The comment says '/proc/self/fd is the normal answer and /dev/fd the fallback for systems that do not mount procfs.' On Linux /dev/fd is a symlink to /proc/self/fd, so it is not a fallback: with procfs absent it resolves to nothing, and with procfs present it is the same directory, so descriptorsNamingIdentity() returns byte-identical answers for both candidates and the loop's second iteration can never produce an outcome the first did not.

**Основание рецензента:** `ls -ld /dev/fd; readlink /dev/fd` on this host -> 'lrwxrwxrwx ... /dev/fd -> /proc/self/fd'. Same inside ivanshift/rutorrent:latest -> '/dev/fd -> /proc/self/fd'. The candidate loop `continue`s on both possible rejections (non-array snapshot, count($opened) !== 1), and both are functions of the same directory contents, so candidate 2 is reachable but never decisive. /dev/fd is a real fdescfs only on BSD/macOS, which the fork does not support (Alpine image plus this Linux host).

**Предложение:** Drop '/dev/fd' and keep the single hardcoded root, or keep it and make the comment true: 'a BSD/macOS fdescfs; on Linux this is a symlink to the first entry and adds nothing.' A one-entry list also removes the per-candidate before/after snapshot bookkeeping at :331-333 and :341-355.

### 5. [minor / duplication] Two spellings of the 'can this directory answer a lookup' primitive, and the newer one is fail-open when the real and effective uid differ

**Где:** `/home/dev/Documents/my_projects/.rutorrent-worktrees/campaign-integration/plugins/erasedata/removewithdata.php:1727-1738 (erasedataDirectoryLookupsAnswer) vs plugins/erasedata/filesystem.php:174-190 (canonicalMissingPath)`

**Заявление:** Both functions answer the same safety question -- 'is this directory really searchable by me, so that a missing child means absent rather than forbidden?' -- with different primitives. filesystem.php proves it with @stat($ancestor.'/.'), which uses the EFFECTIVE ids. removewithdata.php proves it with is_executable($directory), which is access(path, X_OK) and uses the REAL ids. The comment on the latter states the opposite: 'is_executable() answers for THIS process's effective identity rather than for the mode alone.' Where real and effective differ the two disagree, and the disagreement goes the unsafe way for the absence gate: is_executable() says the lookup could have succeeded when it could not, which is fail-OPEN -- the exact failure mode the surrounding comment says it closes.

**Основание рецензента:** docker run --rm php:7.4-cli php -r 'mkdir("/rootonly",0700); mkdir("/rootonly/sub"); posix_seteuid(1000); clearstatcache(); var_dump(is_executable("/rootonly"), is_array(@stat("/rootonly/.")));' -> bool(true), bool(false). A second run showed the same split against a real child: is_executable() true while @stat("/rootonly/child") is false. Not reachable in the shipped image, where php-fpm and the rTorrent-side CLI child each run with real == effective -- so this is a comment-truth and single-owner finding rather than a live defect.

**Предложение:** Give the primitive one owner. Reuse the @stat($dir.'/.') probe filesystem.php already performs (AGENTS.md: 'one owner per safety primitive'), or, if is_executable() stays, replace 'effective identity' with 'the real uid/gid, which equals the effective one in both processes this plugin runs in' so the next reader is not told a guarantee PHP does not give.

### 6. [nit / strange-logic] The new byte-exact scheme check disagrees with the plugin's four sibling scheme validators

**Где:** `/home/dev/Documents/my_projects/.rutorrent-worktrees/campaign-integration/plugins/rutracker_check/announce.php:412 (RuTrackerAnnounce::probeUrl) vs trackers/rutracker.php:36 and trackers/nnmclub.php:212, :279, :453`

**Заявление:** announce.php's new line refuses anything but the byte-exact literals 'http'/'https'; the four sibling validators use preg_match('/^https?$/i', ...). parse_url() preserves the scheme's case, so an announce row spelled 'HTTP://bt.t-ru.org/announce' passes every sibling and is refused only here. The new line is the correct one -- Snoopy's switch is byte-exact, so such a row can never be fetched -- which means the laxer siblings are the ones that admit a URL no request will ever be made from, the same class of defect the new comment describes for udp:// rows.

**Основание рецензента:** php8.5 -r 'var_dump(parse_url("HTTP://bt.t-ru.org/announce"));' -> ["scheme"]=> string(4) "HTTP". php/Snoopy.class.inc:283-318 read in full: switch($parts["scheme"]) with case "dummy" (no socket), case "http", case "https", default -> $this->error = 'Invalid protocol'. So the new comment's claim -- byte-exact literals, no socket for anything else -- is true as written, and 'HTTP' falls to default.

**Предложение:** Not worth changing announce.php. Worth one follow-up: AGENTS.md's rule 'Keep sibling validators consistent -- the announce layer and the scrape layer of the same plugin must not disagree about how strict to be' now has a live counter-example. Either tighten the four /i patterns to match, or normalise once with strtolower() at the single point the scheme is read and note in announce.php:405-412 which of the two the plugin has chosen.

---

## Направление: erasedata-logic

**Итог рецензента:** Read plugins/erasedata as a whole (removewithdata.php 5087 lines, collector.php, filesystem.php, pending.php, manifest.php, update.php, init.php, erase.php, action.php) plus the httprpc entry point, and reproduced every behavioural claim in an exported copy under /home/dev/.cache/rutorrent-tmp/eraselogic (probe1-probe6.php against the shipped test fixture; TMPDIR exported to disk; no leaked processes, target worktree untouched).

Three findings I would call important. (1) On a stock install the web UI's \"remove and delete data\" still goes through plugins/httprpc/action.php to the legacy erasedataRemoveWithData(), which publishes every member's payload manifest on a single $req->success() -- the exact hazard the campaign's own erasedataClassifyEraseOutcomes() was written to prevent, and which php/xmlrpc.php's chunked run() makes reachable for a large multi-select batch. So the new protocol guards the two paths that are not the default one. (2) The producer's `$live` fast path (removewithdata.php:2761) trusts a durable `armed` claim and registers nothing; after a daemon restart, with no full web-UI load to run init.php's re-arm, every later removal times out at the acknowledgement for ever -- measured, along with the accumulation it causes (20 retries of one ratio command leave 20 markers and 20 retained journal records, walking toward the 4095 refusal). Its justifying comment is refuted by the same file's aligned-start comment and by php/settings.php:486. (3) `obligation-unrecoverable` reports a loss that did not happen whenever two generations cover one hash -- measured end-to-end with the payload verifiably deleted and the download verifiably erased.

The rest are smaller: three numbers in comments that do not reproduce (a marker is 92 bytes not 82; a full journal is 1.41 MiB not \"a few hundred kilobytes\"; the first collector-invisible generation is the tenth, not the sixteenth), one test comment whose coverage claim fails a mutation (three cases fail, not none), one duplicated validation proven non-load-bearing by mutation, a dropped test seam, a refusal-set type mix, and some unreachable conditions. Lock ordering, the owned-path retention, the drain-staging promotion guard, force normalisation and per-user isolation all checked out clean.

**Проверено и чисто:**

- Lock ordering. Traced every acquisition: producer takes sorted per-hash locks then the state lock and never waits for a hash lock while holding the state lock (the registration RPC now runs with hash locks held and the state lock released); the drain pass takes hash locks then the state lock; the worker takes worker lock (NB) -> scheduler lock (blocking) -> per-generation hash locks -> state lock; retirement takes only the state lock; the periodic collector takes the scheduler lock NB then hash locks NB. No inverted pair. The one blocking wait that looked risky -- the drain tick's collector taking hash locks BLOCKING (collector.php:1561 with blockingHashLocks) behind a producer that is itself waiting for an acknowledgement -- cannot deadlock, because erasedataAcknowledgeDrainGeneration() runs before the worker lock, so a later tick always releases the waiting producer.
- erasedataCleanupCurrentIdentity()'s fallback comment (collector.php:739-758). It claims the fabricated 'path' is only ever compared for EQUALITY and never for CONTAINMENT. Verified every consumer: erasedataCleanupSuccessorObservationMatches compares $expected['path'] !== $current['path']; erasedataCleanupIdentityMatches compares against $expected['canonical'] and is only reached after `empty($current['exists'])` has continued out; the containment consumer erasedataPathsOverlap() reads erasedataPathIdentity() directly and fails closed. The comment is true.
- "The deployed remove-payload alternative is kept byte-for-byte as the second branch" (removewithdata.php:355). Diffed against master: the alternative `\.[0-9]+\.[A-Za-z0-9._-]+` is character-identical to the pattern master used, and $matches keeps its historic shape because both alternatives are non-capturing.
- Live drain staging cannot be promoted by the collector. erasedataParseCollectorCandidate() refuses any `.tmp` whose name carries a 16-lowercase-hex generation right after the hash, and erasedataStageAdmittedManifest() always writes exactly that shape (<HASH>.<generation>.<pid>.<uniqid>.tmp), so the periodic collector cannot rename a bound drain staging file into a payload-deletion licence. Checked the complementary names too: `<HASH>.<gen>.tmp` with no token matches neither alternative.
- No dead erasedata* functions. Counted production references for every function defined in plugins/erasedata/*.php: the minimum is 2 (definition plus one production caller); nothing is defined for tests only.
- erasedataEraseRequest()/erasedataClassifyEraseOutcomes() position arithmetic. The builder emits 3 commands per element of $hashes as handed in, while the classifier canonicalises and DEDUPLICATES before counting positions, so a caller passing a duplicated hash would let a later member be read as 'accepted' on an earlier member's replies. Checked all three live callers (removewithdata.php:2987, :3900, :5054) -- each passes an already deduplicated set, so it is latent only. Worth a comment, not a fix.
- The collector does not delete the payload of a download rTorrent still holds: presence UNKNOWN and PRESENT-with-unreadable-paths retain the whole job (collector.php:1578-1588, now also logged through the channel $erasedebug_enabled cannot silence), and for a PRESENT download every manifest path is tested with erasedataPathTouchesOwnedPaths() before deletion.
- Force normalisation at the boundary. ErasedataManifestCodec::normalizeForce() accepts only 1, 2, "1", "2"; both doors (action.php:271, erase.php:193) convert once and pass the integer; erasedataAdmissionPartition(), the marker codec, the journal validator and the drain pass each refuse anything that is not already the integer. No coercion path to "delete the download's own files" found.
- Per-user isolation. FileUtil::getProfilePathEx() puts a non-empty user's queue under share/users/<user>/settings, so the state['user'] field is a consistency check rather than a multi-tenancy separator; the admission's unconditional `$state['user'] = $user` cannot take another user's queue over.

### 1. [important / strange-logic] The stock UI removal path never enters the new protocol, and the producer it does use still publishes a payload-deletion licence on one aggregate boolean

**Где:** `plugins/erasedata/removewithdata.php:5055 (erasedataRemoveWithData), reached from plugins/httprpc/action.php:420`

**Заявление:** erasedataClassifyEraseOutcomes() exists (removewithdata.php:2398) precisely because "a producer reading ONE boolean off the request records every member of the batch as erased ... publishes a final manifest for a download rTorrent still holds". The drain producer and the drain pass use it. The legacy httprpc producer -- which, by this change's own comment at removewithdata.php:4962-4968, is the path the web UI really takes, since init.js only falls back to plugins/erasedata/action.php when httprpc is absent -- still does `$eraseSucceeded = ($req !== false) && $req->success();` and then publishes every member's manifest on that one boolean. So on a stock install the ~3800 new lines of admission/drain protocol serve only erase.php (ratio commands) and the no-httprpc fallback, while the path that is actually used carries the exact defect the new machinery was built to prevent.

**Основание рецензента:** Read both functions whole. rXMLRPCRequest::run() (php/xmlrpc.php:177-240) loops over makeNextCall() chunks bounded by rTorrentSettings::maxContentSize() (php/settings.php:554 => 2 MiB below API 11); on an empty answer for a LATER chunk it `break`s with $ret already true from the first chunk, so run() returns true, fault is false, success() is true, and ->val holds only the first chunk's replies. Concrete input: one "remove and delete data" over roughly 2700+ downloads (action.php's own bound contemplates 4096 entries) whose second SCGI call is lost -- every member is published, including the ones never erased. Downstream chain to real damage: the manifest for a live download is retained by the collector while presence is PRESENT (collector.php:1575-1618 + parseOneItem's owned-path retention), but it survives in the queue; when the user later removes that same download WITHOUT data, presence becomes ABSENT, ownedPaths is empty and parseOneItem deletes its payload.

**Предложение:** Wire the existing classifier into erasedataRemoveWithData: replace the single $eraseSucceeded boolean with `$classified = ($req !== false && $req->success()) ? erasedataClassifyEraseOutcomes($erasable, $req) : array();` and publish per member only on `$classified[$h] === 'accepted'`, letting the existing per-hash presence fallback handle the rest. Three lines, no new concept. If instead httprpc is meant to move to erasedataAdmitRemoval(), say so and do it -- but the current split leaves the protected path unused on a default install.

### 2. [important / strange-logic] A durable `armed` phase over a lost schedule wedges every later removal, and only a full web-UI load can clear it

**Где:** `plugins/erasedata/removewithdata.php:2761 (erasedataRemovalAdmissionRun, the $live guard)`

**Заявление:** `$live = $state['phase'] === 'armed' && $state['user'] === $user;` skips the schedule registration entirely. Since a completed admission leaves the phase `armed` and rTorrent's schedule table is volatile, a daemon restart leaves a state that claims a schedule nobody has. The only two registration sites are this producer and erasedataRearmDrainSchedule(), and the latter is called from init.php alone -- i.e. only on a full web-interface load. On a headless install (the erase.php/ratio-command case the plugin exists for) nothing ever re-registers, so every subsequent removal waits the whole 11 s acknowledgement window, logs `drain-no-ack`, retains its obligation and returns false, for ever. The guard's own justification -- "a continuous producer stream would postpone the first tick for ever" -- is refuted by this same file at removewithdata.php:1993-2003, which explains that erasedataDrainScheduleCommand() builds an ALIGNED start (rTorrentSettings::getAlignedStart, php/settings.php:486) so every re-registration of one key resolves to the same absolute fire instant and total postponement is capped at one interval.

**Основание рецензента:** Measured against the shipped fixture in my own copy (/home/dev/.cache/rutorrent-tmp/eraselogic/probe1.php, probe2.php): (1) first admission on a resting queue registers once and publishes; (2) with the schedule table then emptied, the next admission registers 0 schedules, returns false, logs `erasedata: drain-no-ack generation=0000000000000002 members=1 consequence=torrents-retained-own-staging-rolled-back` and leaves a marker; (3) a third attempt behaves identically. probe2 shows the accumulation: 20 retries of ONE ratio command leave 20 *.pending markers and 20 `retained` journal records for the same hash (journal records=21, markers=20, phase=armed) -- so a repeatedly firing ratio command walks the journal to its ERASEDATA_DRAIN_MAX_JOURNAL=4095 ceiling, after which admissions are refused outright with `journal-capacity`. I also mutation-checked the guard: setting `$live = false` fails 4 assertions in 3 cases (testAContinuousProducerStreamDoesNotReRegisterALiveSchedule, testALiveDrainScheduleIsNeverReRegisteredOrPostponed, testTheArmCompareAndSwapLoserRetriesInsteadOfRefusing), so it is pinned -- as the behaviour I am questioning.

**Предложение:** Either drop the $live guard (the aligned start already bounds the postponement it was written against, and re-registering an identical key is idempotent apart from the countdown), or make the producer prove the schedule before trusting the claim: on a `drain-no-ack` under `$live`, downgrade the durable phase to `disarmed` (nothing was erased, only markers stand) so the very next attempt re-registers. Today the only path back is a browser.

### 3. [important / false-comment] `obligation-unrecoverable` reports a loss that did not happen whenever two generations cover one hash

**Где:** `plugins/erasedata/removewithdata.php:3978-4002 (erasedataDrainGenerationPass, settle step)`

**Заявление:** The branch's comment insists the classification is certain -- "The marker is what separates the two" and "This is certainty, not a relaxation" -- with `obligation-complete` for a member whose marker is gone and `obligation-unrecoverable` ("a real loss: an obligation nothing can ever discharge") for one whose marker stands. The separator does not hold when the SAME hash carries markers under more than one generation, which is the ordinary outcome of "it timed out, click again": generation N is served completely (download erased, manifest published, payload deleted), and every other generation for that hash then satisfies gone + no binding + marker-stands and is reported as an unrecoverable loss. The marker discharge itself is right; the operator-facing classification is false, and classified diagnostics are the only signal this plugin gives an operator.

**Основание рецензента:** Measured end-to-end in my copy (probe6.php): admission A fails at the acknowledgement (marker for generation 1 left), admission B is retried, then one drain tick runs. Result: `payload gone: yes`, tick returns published=1 and unrecoverable=1, and the log carries `erasedata: obligation-unrecoverable generation=0000000000000003 ... consequence=download-gone-no-manifest-marker-discharged` for a request that was fully served. probe3.php scales it: 5 markers for one hash produce published=1 and unrecoverable=4, i.e. four fabricated losses for one successful removal. The same member also gets two contradictory notes in one pass -- `paths-unknown ... obligation-retained` immediately followed by `obligation-unrecoverable ... marker-discharged`.

**Предложение:** Before classifying a gone, unbound member as a loss, check whether any OTHER generation of the same hash was published in this tick or carries a final manifest in the queue listing already read into $final; if so, take the `obligation-complete` branch. Failing that, at least stop claiming certainty in the comment and name the ambiguity.

### 4. [minor / false-comment] "from the sixteenth generation on" is wrong in both directions -- measured, it is the tenth, and it is not monotone

**Где:** `plugins/erasedata/removewithdata.php:358-366 (erasedataParseCollectorCandidate)`

**Заявление:** The comment justifying the new hex-generation alternative says "a generation holding a hex letter does not match [0-9]+, so without this branch every manifest admitted from the sixteenth generation on would be invisible to the collector". Generations start at 0000000000000000 and are incremented by one (erasedataGenerationIncrement, filesystem.php:556; the only caller is removewithdata.php:2740), so the first admitted generation is ...0001 and the first one carrying a letter is the TENTH (...000a). It is also not "from ... on": generations 16-25 spell 0000000000000010-19 and match the historic alternative perfectly well.

**Основание рецензента:** Ran both regexes over sprintf('%016x',$i) for i=1..30: old pattern fails only at i=10..15 and i=26..30; it passes at 16..25. The code change itself is correct and necessary (a letter-bearing generation's published manifest would be invisible to the collector while erasedataPublishedGenerations() still counts it as published, and erasedataClassifyRetirementCandidate() classes it `final` so the queue could never retire).

**Предложение:** Replace with the measured sentence, e.g. "the tenth generation (000...a) is the first whose hex spelling carries a letter, and every later generation that carries one is invisible to the historic pattern".

### 5. [minor / false-comment] Two measured-looking numbers in comments do not reproduce: a marker is 92 bytes, not 82, and a full journal is 1.41 MiB, not "a few hundred kilobytes"

**Где:** `plugins/erasedata/pending.php:40 and plugins/erasedata/removewithdata.php:1380`

**Заявление:** pending.php:40 states the four-field marker "is 82 bytes"; the encoder produces 92. removewithdata.php:1380 justifies the 8 MiB ERASEDATA_DRAIN_STATE_MAX_BYTES with "A full journal of ERASEDATA_DRAIN_MAX_JOURNAL single-hash records is a few hundred kilobytes"; a full 4095-record journal of single-hash records encodes to 1.41 MiB with a short queue path, and more with a realistic settings path. Neither breaks anything (92 < 4096, 1.41 MiB < 8 MiB) but both are exactly the class the project's own rule forbids.

**Основание рецензента:** probe4.php: erasedataEncodePendingMarker() over a canonical record returns strlen 92 ("version=1\ngeneration=<16>\nhash=<40>\nforce=1\n" = 10+28+46+8). probe5.php: a valid state with 4095 single-hash `retained` records json_encodes to 1,482,536 bytes = 1.41 MiB and passes erasedataValidateDrainState().

**Предложение:** Write the measured numbers (92 bytes; ~1.4 MiB, more with a long queue path), or drop the arithmetic and keep the qualitative point.

### 6. [minor / weak-test] A test comment's coverage claim does not reproduce: three cases fail when the re-registration guard is removed, not one

**Где:** `tests/plugins/erasedata/RemoveWithDataTest.php:10117-10121 (testALiveDrainScheduleIsNeverReRegisteredOrPostponed)`

**Заявление:** The comment says "Nothing else in this file fails when either re-registration guard is removed: every other case either arms once or never counts the registrations." Measured, removing the producer's guard fails four assertions across three cases. The claim is the kind of statement a later reviewer uses to decide the case is the sole guardian of that behaviour, and it is false.

**Основание рецензента:** Mutated `$live = $state['phase'] === 'armed' && ...` to `$live = false` in an exported copy and ran the file with the shipped runner loop: exit 0 but 4 `Failed:` lines -- testAContinuousProducerStreamDoesNotReRegisterALiveSchedule ("five producers arm the drain schedule exactly once, not five times"), testALiveDrainScheduleIsNeverReRegisteredOrPostponed ("a second admission on a live schedule re-registers nothing") and testTheArmCompareAndSwapLoserRetriesInsteadOfRefusing (two assertions). Baseline for the same file with the mutation reverted: 2765 passed, 0 failed (and PendingQueueTest 120/0).

**Предложение:** Either delete the sentence or correct it to name the three cases. Note also that this file's runner only PRINTS `Failed:` -- php-test.sh's grep is what turns it into a non-zero status, so anyone measuring coverage by exit code alone will measure nothing (that is what RunNamedCases.php exists for).

### 7. [minor / duplication] An identical validation is run twice back to back with nothing in between, and nothing pins the second run

**Где:** `plugins/erasedata/removewithdata.php:787-793 (erasedataPublishExactStagedFile)`

**Заявление:** Lines 788-790 refuse unless erasedataCleanupCommittedPairStillMatches($tmp,$token,...) holds; line 791 then assigns the result of the very same call to $ret and returns it. No state changes between them (the clearstatcache is after), so the second call can only repeat the first. It is not free: each call re-opens and re-reads both the .tmp artifact and the .list token, with four fstat/lstat pairs.

**Основание рецензента:** Read the function whole. Mutation: replaced the second call with `$ret = true;` in an exported copy -- RemoveWithDataTest 2765 passed / 0 failed and PendingQueueTest 120 passed / 0 failed, identical to the unmutated baseline, so no test observes the second evaluation.

**Предложение:** `return(true);` after the guard, or drop the guard and return the single call's result.

### 8. [minor / strange-logic] The injected filesystem seam is silently dropped on the cleanup recovery path

**Где:** `plugins/erasedata/removewithdata.php:1070 (erasedataCleanupGenerationArtifacts) and :1160 (erasedataRecoverObsoleteCleanupLocked), called from plugins/erasedata/collector.php:1695`

**Заявление:** Both functions take no ErasedataFilesystemOps parameter, so every read they perform -- erasedataReadExactCleanupArtifact, erasedataReadExactCleanupToken, erasedataCleanupCommittedPairStillMatches, erasedataPublishExactStagedFile -- constructs a fresh default ops object. The collector calls them while holding $this->filesystem, which in the tests is the scripted ErasedataCollectorFixture. So a fixture that scripts a rename/unlink/stat failure for this path does not actually reach it, while every sibling call site (erasedataCancelObsoleteCleanupGenerationLocked, erasedataPublishObsoleteCleanup, the collector's own cleanup loop) threads the seam correctly.

**Основание рецензента:** Grepped every call: collector.php:1695 passes $index but no filesystem; removewithdata.php:1160's signature ends at (..., &$reason = null, $index = null); the inner calls at 1188/1198/1205 omit the optional $filesystem argument. Harmless in production (the default object is the same class); it is the test seam that is not honoured.

**Предложение:** Add the optional `?ErasedataFilesystemOps $filesystem = null` parameter to both and thread it, the way the neighbouring cancel/publish helpers already do.

### 9. [minor / strange-logic] The force-2 preflight pushes a raw hash into the refusal set the partition documents as tokens only

**Где:** `plugins/erasedata/removewithdata.php:2688 (erasedataRemovalAdmissionRun) vs the contract at :1921-1940 (erasedataAdmissionPartition)`

**Заявление:** erasedataAdmissionPartition() documents that "F carries a token rather than the value it was handed: a refusal must be reportable without echoing whatever arrived", and builds entries such as 'invalid-hash:string'. The force-2 capability preflight then appends the bare 40-hex hash to the same array, so the 'refused' list the door returns (and action.php JSON-encodes straight to the browser) mixes classified tokens with request values under one key.

**Основание рецензента:** Read both. The appended value is a canonical [0-9A-F]{40} hash, so nothing unsafe is echoed -- the defect is that the array's documented shape no longer holds and a consumer cannot tell a token from a hash without re-parsing.

**Предложение:** Append a token, e.g. 'descriptor-unavailable:'.$hash or a separate 'refused_capability' key, and keep F's element type single.

### 10. [nit / dead-code] Three unreachable conditions left in the drain state validator and the generation pass

**Где:** `plugins/erasedata/removewithdata.php:1681-1682, :1696-1697, :3612`

**Заявление:** 1681-1682 calls erasedataGenerationCompare twice on the same pair and tests `!== -1 && !== 0`; both operands were validated as generations three lines earlier, so the compare can never return false and the whole test is `> 0`. 1696-1697 likewise re-calls the comparator to test `=== false` on operands both already proven valid. 3612 computes `$final = is_array($entries) ? erasedataPublishedGenerations($entries) : array();` immediately after an early return that already proved $entries is an array.

**Основание рецензента:** Read each site with the guards immediately preceding it: erasedataGenerationIsValid() is applied to $state['generation'], $state['acknowledged'] and the loop's $generation before every one of these calls; the scandir failure at 3605-3611 returns before 3612.

**Предложение:** Collapse to one comparator call per pair and drop the impossible arms; drop the second is_array.

### 11. [nit / false-comment] The diagnostic digest omits the generation although the comment says any change at all makes a new digest

**Где:** `plugins/erasedata/removewithdata.php:3410-3432 (erasedataDrainReportGroup)`

**Заявление:** The digest is built from $note[0] (reason), $note[2] (members), the hash, the file and $note[3] (consequence) -- $note[1], the generation, is not in it. For the per-generation groups the key is the generation so it does not matter, but for the 'tick' and 'retire' groups it does: a retirement refusal whose generation moved on but whose reason and candidate count did not stays suppressed, so the last line printed keeps naming a stale generation. The comment claims "Any change at all -- another reason, another member, another consequence, another count -- is another digest".

**Основание рецензента:** Read the function and the two group keys used at 4790 ('tick') and 4860 ('retire'); the notes those groups carry are built with $state['generation'] as $note[1].

**Предложение:** Either add $note[1] to the digest parts, or narrow the sentence to the four fields it really covers.

---

## Направление: rutracker-logic

**Итог рецензента:** I read plugins/rutracker_check as a whole (check.php, detector.php, announce.php, forumindex.php, metafetch.php, state.php, runstate.php, updatepass.php, fetcherror.php, trackers/rutracker.php, trackers/nnmclub.php, conf.php) plus php/Torrent.php, and hunted by defect class rather than by file. Baseline on a scratch export was clean (18/18 rutracker_check suites, exit 0, TMPDIR on disk), and I ran 30 targeted mutations against it.

No verdict-level defect survived. The layered decision logic is coherent: every path I could reach that writes a verdict has evidence behind it, the deletion counter's freshness rule and its capping are consistent between the two readers of chk-del, the announce budget's read-only and reserving views now agree on an unreadable document, and layer 3's new reason vocabulary does not leak third-party text. 24 of 30 mutations were caught by a named test, several by 4-6 tests each, so the load-bearing parts of this change are genuinely pinned.

What I did find is concentrated in three classes. (1) One false comment: AGENTS.md:128 still cites announce.php:434 for hasValidSuccessSchema(), which this very commit moved to 487 -- and AGENTS.md is edited by this commit. (2) Dead code the commit introduced or documented rather than removed: the settled-deletion guard's `$probeDecision !== 'allow'` conjunct became provably always true the moment this commit added the 'unbuildable' assignment (proved both ways by mutation); four fetchDump() failure literals cannot be produced at all; and updatepass.php's `$record !== null` re-test is kept with a justification the code does not support. (3) Weak tests: most of the new fetchDump reason vocabulary is unasserted (collapsing the whole reservation-reason expression to one word failed exactly one test), the new anchoring on chk-del is unpinned on both of its readers, and NNMClub's created-by/creation-date snapshot cannot change anything on this fork and would go unnoticed if deleted.

Two notes on numbers, since the house standard asks for them: deletionGate()'s arithmetic claims all reproduce exactly (3600->3240, 600->540, 66->60, 61->60, 60->60), but the 28-cycle-gap measurement that justifies the change is recorded nowhere in the repository, unlike the 1261-forum figure in the same commit which cites its survey.

**Проверено и чисто:**

- classify()'s new two-question row loop (detector.php:169-201): a look-alike row now increments $foreign AND $enabled, and the 'alive' gate re-asks the anchored isTrackerRow(). Verified by mutation -- reverting the foreign count to TRACKER_PATTERN fails 'a look-alike row cannot write the message RuTracker is excused by'.
- detector.php's TLD-enumeration comment, claim by claim. 'Exactly three other sites ask a different question with TRACKER_PATTERN' -> grep finds exactly rutracker.php:189 (describeCounters), updatepass.php:290 (hostOf, confirmed by reading it), detector.php:184 (classify). 'It answers in six functions' -> grep for isTrackerHost/isTrackerRow/isForeignRow finds exactly extractTopicId, ruTrackerRowUrl, download_torrent's layer-2 gate, metafetch begin(), metafetch pump(), and classify() twice. 'No copy of the list is left' inside the plugin -> confirmed; the one copy elsewhere in the tree (plugins/loginmgr/accounts/RUTracker.php:34 FORUM_HOSTS) is exactly what the comment's own border caveat describes, and it carries a cross-reference plus RuTrackerDomainListTest.
- buildUrl()'s new byte-exact scheme guard is consistent with the fetcher it protects: parse_url does NOT case-fold a scheme (verified by running it), and Snoopy's switch at php/Snoopy.class.inc:283 compares the literals 'http'/'https', falling through to 'Invalid protocol' otherwise. The sibling NNMClub scrape builder lowercases via rebuildTrackerUrl(), so the two do not disagree.
- probeDecision()/reserveProbe() now agree on an unreadable announce.json -- both answer 'unstorable'. judge(null) returns 'unstorable'; the $readable flag reaches it. Mutation reverting the flag fails 'the read-only budget view refuses a document nobody can read, exactly as the reservation does'.
- RuTrackerFetchError::classify()'s eight patterns against Snoopy's eight assignments: grep of php/Snoopy.class.inc gives exactly 8 error strings at :314, :351, :357, :659, :796, :798, :800, :802, in the same order as the $classes array, and each pattern matches its string. The 'Snoopy initialises $error to ""' claim checks out (var $error = "" at :71). Whitespace normalisation, the non-string guard, and the shared-corpus agreement between makeClient() and guestFetch() are all pinned (three separate mutations each fail named tests).
- conf.php's $rutrackerSweepCooldown domain paragraph, item by item, against canonicalNonnegativeInteger(): '', booleans, negatives, floats, '1e3', ' 24', '024', '+24', arrays, objects and a decimal past PHP_INT_MAX all answer null; unset/null takes the default with NO warning (isset() is false for null). sweepAllowed()'s '>' and missSuppresses()'s '<=' are as described, and the claim that a negative cooldown makes markMiss() prune the record it just wrote is true (markMiss compares `$now - $missedAt > missWindow($record)`, and 0 > negative).
- state.php's cycle-lock comment reversal is the right way round: cycleLockPath() does call self::dir(), and dir() uses FileUtil::getSettingsPathEx('') which skips the '/users/<user>' suffix (php/utility/fileutil.php:89-102), so the lock really is outside every profile. The old 'Deliberately NOT dir()' sentence was the false one.
- The askedAlready comment's replacement claim: exactly two of the seven handlers register one pattern as both filters (tapochek.net, tr.anidub.com). The old 'five of the seven' was wrong; the new rutracker and toloka examples match the actual registerTracker() arguments.
- updatepass.php's SWEEP_COLUMNS comment: the d.multicall at :912-917 asks for hash, REPLACEMENT_MARKER_KEY, INHERIT_KEY, REPLACING_KEY in that order, and slot 3 is consumed for the predecessor-pointer path at :927.
- state.php's reportUnreadable() rationale: save() really does have a single production caller (forumindex.php:636) and it is the staged-name write; update() is the only refusal site; the $reported map is keyed by full path so one wedged document costs one ungated line per process. Both the visibility and the 'nothing silently discards the document' properties are pinned.
- collectWanted()'s shared definition: runCrawl() does return before markSweep() when the wanted set is empty, so the drift the comment blames for a wasted process per cycle is real. crawlWanted() filtering and the persistence of undatable-miss repairs are both pinned (two mutations, two named failures).
- markSweep()'s refusal classifier: 'unreadable'/'unlockable' are decided before the mutator so $claimed means nothing there, 'unwritable' after it so $claimed is a true verdict -- matches state.php update()'s actual ordering. Mutation collapsing it fails 'a crawl window that could not be recorded is not reported as another crawl holding it'.
- adoptStub()'s 'never harvests' contract: the $arrived branch does not call pump(). Mutation adding the harvest back fails two named cases.
- chk-forum's two readers now share canonicalForumId() (resolveForum and registrationTime), while writeForumMapping() deliberately keeps the raw-byte compare-and-swap. The asymmetry with chk-topic (canonicalPositiveInt32 without trim) is real but self-healing: a non-canonical chk-forum makes resolveForum() answer null with $known true, which queues the topic, which puts it in topicsAwaitingForum()'s $alsoWanted, and the crawl's CAS then rewrites it canonically. Mutation removing the trim fails three named cases across two suites.
- The NNMClub SCRAPE_RESULT_FAILED fall-through and the searchtor scheme inheritance: parseAuthUrl() really does limit the scheme to http/https (case-insensitively) so credentialScheme()'s downgrade guard is sound, and both changes are pinned (three and two named failures respectively).
- crawlFailureReason()'s $details map is keyed by the formatted detail string, not by list index, so `array_keys()` yields the details rather than 0 -- my first read of it was wrong.
- Torrent::touch()'s $built guard is heavily pinned: removing it fails 9 assertions in tests/php/TorrentMetaTest.php.
- No leaked fixture servers: `pgrep -cf rutorrent-scgi-rpc2` answered 1, and `pgrep -af` showed that one match was my own shell command line, exactly as AGENTS.md warns. Nothing to kill. Scratch trees under /home/dev/.cache/rutorrent-tmp removed; the target worktree was never written to (git status clean).

### 1. [minor / false-comment] AGENTS.md's own citation of announce.php:434 was invalidated by this commit

**Где:** `AGENTS.md:128 (the "Verify Against the Live Service" rules block); target is plugins/rutracker_check/announce.php:487 RuTrackerAnnounce::hasValidSuccessSchema()`

**Заявление:** AGENTS.md names `RuTrackerAnnounce::hasValidSuccessSchema()` (`plugins/rutracker_check/announce.php:434`) as "the reference shape" for validating third-party answers. In this tree line 434 is inside `entryFor()`'s canonicalisation loop, not that function. announce.php grew 53 lines above it in this same commit (probeDecision's $readable flag, reserveProbe's $failure block, buildUrl's scheme guard), moving the function to 487. AGENTS.md is itself edited by this commit (+61 lines), so its cites were in scope.

**Основание рецензента:** `git show master:plugins/rutracker_check/announce.php | grep -n hasValidSuccessSchema` -> 434; `grep -n hasValidSuccessSchema plugins/rutracker_check/announce.php` at HEAD -> 487. `git diff master..HEAD -- AGENTS.md` shows one hunk at @@ -187,6 +187,67 @@, so line 128 was carried through unchanged and unre-checked.

**Предложение:** Either update to :487 or drop the number and cite the function by name only -- a name survives the next refactor, a line number does not, and this is the third time a moved line has outlived its citation here.

### 2. [minor / dead-code] The settled-deletion guard's `$probeDecision !== 'allow'` conjunct is now provably always true

**Где:** `plugins/rutracker_check/trackers/rutracker.php:879, in RuTrackerCheckImpl::download_torrent()'s layer-3 "row missing" branch`

**Заявление:** Inside `if (!$trackerConfirmed) { ... }`, `$probeDecision` can no longer be 'allow'. Every exit from the layer-2 block that leaves it as 'allow' either returns (answer 'registered' -> STE_UPTODATE) or sets `$trackerConfirmed = true` (the final `else`); the two remaining exits overwrite it with 'unbuildable' (buildUrl null) or 'inconclusive' (answer 'uncertain'). The 'unbuildable' assignment added by this very commit is what closed the last gap -- in master that path did keep 'allow', which is the defect being fixed. So the fix is now expressed twice and only one half does work. `$probeDecision` is still live in the log line two statements below, so the variable is not dead; only the conjunct is.

**Основание рецензента:** Mutation in a scratch export (/home/dev/.cache/rutorrent-tmp, TMPDIR set): replacing the condition with `if (self::deletionConfirmedOnce($hash, $settledToken))` leaves all 18 tests/plugins/rutracker_check suites at exit 0. Inverting it to `$probeDecision === 'allow' &&` fails 6 named cases, so the branch itself is well covered -- it is specifically the conjunct that discriminates nothing.

**Предложение:** Either drop the conjunct (the `!$trackerConfirmed` guard already carries the meaning) or keep it and say in the comment that it is a restatement of an invariant the block above establishes, not a test that can fail. The current comment reads as though it separates paths, which invites a future reader to preserve a condition that cannot fire.

### 3. [minor / dead-code] Four of fetchDump()'s new failure literals cannot be produced

**Где:** `plugins/rutracker_check/forumindex.php:513 ('reservation-refused'), :514 ('reservation-' . 'unstored'), :701 ('publish-refused'), :700-ish ('publish-' . 'unstored') in RuTrackerForumIndex::fetchDump()`

**Заявление:** All four are `?:` fallbacks for a null that cannot occur. `RuTrackerState::update()` (state.php:339-385) assigns `$failure` on every one of its four `return false` sites, so `$stateFailure`/`$publishFailure` are never null when `$reserved`/`$stored` is false -- killing 'reservation-unstored' and 'publish-unstored'. The reservation closure leaves `$reservation === null` on exactly two early returns, and both set `$refusal` ('reservation-retired', 'generation-exhausted') -- killing 'reservation-refused'. The publish closure leaves `$published === false` on exactly two early returns, and both set `$publishRefusal` ('reservation-superseded', 'document-unpromoted') -- killing 'publish-refused'.

**Основание рецензента:** Read all three functions whole: state.php update() sets 'unlockable' (openShared false), 'unlockable' (flock false), 'unreadable' (!$readable) and 'unwritable' (!$stored) before each return; forumindex.php:407 and :478 are the only closure paths that return without assigning `$reservation`, and each assigns `$refusal` first; forumindex.php:666 and :678 are the only closure paths that return without setting `$published`, and each assigns `$publishRefusal` first.

**Предложение:** Drop the four fallbacks, or keep them and mark them as unreachable defaults so nobody spends a session working out which fault produces 'publish-refused'. The docblock currently enumerates the reason vocabulary as if the whole set occurs.

### 4. [minor / weak-test] Most of the new fetchDump reason vocabulary is unpinned and free to drift

**Где:** `tests/plugins/rutracker_check/ForumIndexTest.php:3706-3803, against plugins/rutracker_check/forumindex.php fetchDump()'s $failureReason`

**Заявление:** fetchDump() can now emit around a dozen distinct tokens. Only five are asserted anywhere in the suite: dump-refused (two status forms), dump-empty, dump-malformed, reservation-superseded and reservation-unreadable. Never asserted: reservation-retired, generation-exhausted, reservation-unwritable, reservation-unlockable, cache-lost (both the 304-stale site at :574 and the durable-answer site at :596), document-unsaved, document-unpromoted, and every publish-* form. Since the whole point of the change is that layer 3 prints these words to an operator, an unasserted word can be renamed or mis-wired with the suite green.

**Основание рецензента:** Mutation: replacing the entire reservation-reason expression (forumindex.php:512-514) with the literal `'unavailable'` failed exactly one test -- 'a forumindex document nobody can read is visible at the shipped debug default', which asserts `reservation-unreadable`. Every other rutracker_check suite stayed at exit 0. `grep -rn "reservation-retired|generation-exhausted|cache-lost|document-unsaved|document-unpromoted|publish-" tests/plugins/rutracker_check/` returns only comment text.

**Предложение:** One table-driven case in the style of 'fetchDump says which failure it was' that walks the remaining reasons would pin the vocabulary at roughly the cost of the four cases already there; alternatively, assert the reason set is a subset of a named constant list so a typo cannot ship.

### 5. [minor / dead-code] NNMClub's created-by/creation-date snapshot cannot change anything on this fork, and nothing would notice its removal

**Где:** `plugins/rutracker_check/trackers/nnmclub.php:362-384 (snapshot) and :425-432 (restore), in NNMClubCheckImpl::patchAuthInTorrent()`

**Заявление:** Between the snapshot and the restore the only writers are `Torrent::announce()` and `Torrent::announce_list()`, whose sole route to those keys is `touch()` -- and this commit's own php/Torrent.php change makes `touch()` return immediately unless `$built`, which a torrent decoded from NNMClub's served bytes never is. So the restore provably puts back exactly what is already there. The comment states this honestly, and the upstream-handoff rationale is real, but the result is ~30 lines (comment included) in the replacement path that no test can distinguish from their absence. Contrast metafetch.php's own $authored, which IS load-bearing: datePublishedTorrent() uses it to decide whether reg_time supplies the date.

**Основание рецензента:** Mutation deleting the whole restore loop (`foreach ($authored as $key => $value) {...}`): all 18 tests/plugins/rutracker_check suites exit 0. php/Torrent.php:684-686 at HEAD: `if( !$this->built ) return;` at the top of touch(); `$built` is set only in the constructor's `build()` branch.

**Предложение:** If it is kept for the upstream install, pin it with a test that drives patchAuthInTorrent() against a Torrent double whose setters do stamp the two keys -- otherwise the block is protecting a scenario nothing in this repository can reach or check, and a later cleanup will delete it with a green suite as justification.

### 6. [nit / dead-code] The `$record !== null` re-test kept by this commit is dead, and the reason given for keeping it does not hold

**Где:** `plugins/rutracker_check/updatepass.php:1332, in the $resuming expression inside inspectMarkedRow()`

**Заявление:** The comment added here says "The $record !== null re-test is defensive: a null record has already returned above. Keep it -- every branch under it writes." The first half is right; the justification is not. `$satisfied`, computed two lines earlier at :1329, already dereferences `$record['run']['started']` and `$record['run']['open']` unconditionally, and `$exists = ruTrackerChecker::torrentExists($record['old'])` at :1318 does the same. A null `$record` would have fataled before the conjunct is evaluated, so it guards nothing for any branch, writing or not.

**Основание рецензента:** Read inspectMarkedRow() whole: `if ($record === null) { ...logDebug...; return; }` closes at :1288, and :1318/:1329 dereference `$record` before :1332.

**Предложение:** Drop the conjunct, or replace the justification with the true one (it is a redundant restatement of the early return), so the next reader is not told a guard is load-bearing when the code above already made it impossible to reach with null.

### 7. [nit / weak-test] The new strictness on chk-del is not load-bearing under test, and neither is its sibling's

**Где:** `plugins/rutracker_check/trackers/rutracker.php:377 (deletionConfirmedOnce) and :429 (confirmDeletion)`

**Заявление:** This commit tightened deletionConfirmedOnce()'s matcher from `/^([0-9]+):/` to `/^([0-9]+):([0-9]+)$/D` so it insists on the whole canonical pair, explicitly so that the two readers of chk-del cannot answer differently about one stored value. No test distinguishes the anchored form from an unanchored one -- on either reader. A stored `"3:100 junk"` would be a settled deletion to a relaxed deletionConfirmedOnce() and a restart-from-zero to a strict confirmDeletion(), which is precisely the disagreement class the change exists to close.

**Основание рецензента:** Mutation A: `'/^([0-9]+):([0-9]*)/'` in deletionConfirmedOnce -> all 18 suites exit 0. Mutation B: dropping `$/D` from confirmDeletion's identical pattern -> all 18 suites exit 0. (By contrast the freshness rule beside it is well pinned: stubbing deletionRunStatus to 'unbroken' fails 4 named cases, and deleting the settled-token re-assert fails 3.)

**Предложение:** One case feeding a chk-del with trailing bytes through both readers and asserting they agree would pin the invariant the commit message claims.

### 8. [nit / other] The measurement behind deletionGate()'s 10 % shortening exists nowhere in the repository

**Где:** `plugins/rutracker_check/trackers/rutracker.php:255-289, RuTrackerCheckImpl::deletionGate() and its comment`

**Заявление:** The one number that justifies shortening the deletion confirmation gate -- "those 28 neighbouring cycle gaps spanned 3582-3618 s around a median of exactly 3600, and 12 of them therefore came in under the hour" -- appears only in this comment and, restated, in the test's own comment. It is attributed to "the user's log", which is off-tree. The house rule is that numbers must be measured and re-runnable; the sibling number introduced in the same commit (the 1261-forum walk, forumindex.php:1887) does cite its survey and is traceable, which makes the contrast visible.

**Основание рецензента:** `grep -rn '3582\|3618' tasks/ plugins/ tests/` finds only trackers/rutracker.php:260 and tests/plugins/rutracker_check/RuTrackerHandlerTest.php:2011. `grep -rn '1261' tasks/` finds tasks/2026-09-05-consolidated-fixes/S01-S03-ANALIZ.md:217 citing design section 2.5. The arithmetic in the comment does check out independently: gate(3600)=3240, gate(600)=540, gate(66)=60, gate(61)=60, gate(60)=60.

**Предложение:** Record the 28 gaps (or the query that produced them) in tasks/2026-09-05-consolidated-fixes/ the way the 1261 figure is recorded, so a later reviewer asked to re-derive the tolerance has something to re-derive it from.

### 9. [nit / duplication] deletionRunStatus() computes an 'unreadable' detail one of its two callers ignores in favour of a hand-copied duplicate

**Где:** `plugins/rutracker_check/trackers/rutracker.php:316 ($detail) vs :466 (confirmDeletion's hard-coded copy) vs :401-403 (deletionConfirmedOnce interpolating it)`

**Заявление:** deletionRunStatus() sets `$detail = 'an unreadable healthy-verdict timestamp'`. deletionConfirmedOnce() prints `'cannot be checked against ' . $runDetail`; confirmDeletion() prints the byte-identical phrase as a literal and never reads `$runDetail` on that path. The commit's whole argument for extracting the helper is that both deletion paths must answer identically for the same two stored fields, and the one clause they still spell twice is free to drift.

**Основание рецензента:** Read both call sites: :464-467 is `'... deletion count ' . $count . ' cannot be checked against an unreadable healthy-verdict timestamp; deferring'` with no `$runDetail`; :400-404 interpolates it. The strings match today, character for character.

**Предложение:** Interpolate `$runDetail` in confirmDeletion's line too, the way its sibling already does.

---
