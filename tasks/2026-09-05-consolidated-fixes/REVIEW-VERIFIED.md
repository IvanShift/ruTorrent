# Финальное ревью: результаты ПОСЛЕ состязательной проверки

Ревью схлопнутого коммита кампании (61 файл, +20945/-1016), семь направлений.
Каждая находка проверялась отдельным агентом с задачей её **опровергнуть**.

## Итог

| | |
|---|---:|
| Сырых находок | 75 |
| confirmed-lower-severity | 36 |
| refuted | 24 |
| confirmed | 11 |
| deliberate-and-documented | 4 |

**Ни одной находки уровня important не пережило проверку.**
Выжившие: 47 — все `minor` или `nit`.

Треть заявленного (24 из 75) опровергнута, ещё 4 оказались намеренными и задокументированными решениями.

Это существенно для оценки самой кампании: второй проход по 20 тысячам строк не нашёл ни одного серьёзного дефекта.

---

## Выжившие находки

### 1. [minor] "is 82 bytes" is 92 bytes — measured

**Вердикт:** confirmed-lower-severity

Could not refute; the claim is pure arithmetic over literals I read at their definitions.

Location is right: pending.php:40 carries "version=1\n + generation=<16>\n + hash=<40>\n + force=<1>\n is 82 bytes." directly above ERASEDATA_PENDING_MARKER_MAX_BYTES (line 43). erasedataEncodePendingMarker() (62-82) returns exactly those four newline-terminated lines and nothing else, and erasedataDecodePendingMarker() (87-125) enforces the same shape (exactly four lines, trailing newline, nothing after), so the sentence is a claim about the record the encoder actually emits.

Field widths verified at source, not assumed: erasedataGenerationIsValid() (filesystem.php:548) is /^[0-9a-f]{16}$/D = 16 chars; erasedataIsCanonicalPendingHash() (pending.php:131) is /^[0-9A-F]{40}$/D = 40 chars; force is refused unless integer 1 or 2 (line 76) so one decimal char; version is the constant 1, one char.

Measured by hand twice (Bash was rate-limited for the whole session, so no php -r; the computation is string-length arithmetic over literals, which needs no runtime). Per line: 10 + 28 + 46 + 8 = 92. Independently: key names 7+10+4+5=26, four '=' = 4, values 1+16+40+1=58, four newlines = 4, total 92.

The origin of the error closes off any alternate reading: 28+46+8 = 82 exactly, i.e. the author summed the last three lines and omitted "version=1\n" (10 bytes) — the very line the sentence lists first. No spelling of the four named fields yields 82.

Not deliberate: read all of AGENTS.md and the 35-line header comment on pending.php; neither mentions a marker size, and no constant or test keys off 82. AGENTS.md explicitly names this defect class ("a replacement comment that is itself false... the check that pays is: did anyone open the line the new sentence cites").

Severity downgraded from important to minor. The sentence exists only to justify the 4096 ceiling on the next line as generous; that conclusion is identical at 92 (still ~2.3% of the cap). Nothing derives a buffer, a constant or an assertion from 82, so a maintainer acting on this sentence reaches the same decision either way. It is a false factual claim in a load-bearing comment, so not a pure nit — but a 10-byte arithmetic slip with no downstream consequence is not important.

The suggested replacement is correct and protects nothing that the current wording does.

**Исправление:** Replace line 40's figure: "// version=1\n + generation=<16>\n + hash=<40>\n + force=<1>\n is 92 bytes. The" — leaving lines 41-43 (the 4096 ceiling and the reasoning about it) untouched.

### 2. [minor] Docblock cites collector.php:1093-1094 for force-2 deletion; those lines are the manifest-retained logger

**Вердикт:** confirmed-lower-severity

Checked directly in /home/dev/Documents/my_projects/.rutorrent-worktrees/campaign-integration.

Cited location is right and the code there is not what the comment says. collector.php:1080-1096 is private function manifestLog($hash, $path, $reason); lines 1093-1094 are exactly `$this->manifestLogState[$key] = true;` and `FileUtil::toLog('erasedata: manifest retained '.$hash.' generation='.$generation.' '.$reason);`. The entire function derives a generation from basename($path), dedups on hash|generation|reason and writes one log line. It never touches the filesystem, a base path, a force value or a deletion helper. No reading of it supports "whole-base-path deletion".

Where force-2 deletion actually is: collector.php:1129-1130 defines `$force_delete = ($manifest['force'] === 2 && empty($manifest['legacy'])) && $this->enableForceDeletion;` inside parseOneItem(), and collector.php:1221-1248 is the branch that acts on it — `Retain active forced directory $base_path` (1227), `erasedataCompleteForcedDirectory($base_path, $item, $this->filesystem)` (1233), `Successfully forced delete dir $base_path` (1238), `FAIL force delete dir $base_path` (1242), plus the residual-existence re-check at 1245-1246. So the reporter's evidence reproduces exactly.

Deliberate? No. I found no comment, test, or AGENTS.md note justifying the 1093-1094 pointer. The opposite: AGENTS.md:128 uses the same `plugins/rutracker_check/announce.php:434` citation convention, and AGENTS.md:249 names this precise defect class ("the check that pays is ... did anyone open the line the new sentence cites"). Note the substantive sentence around the citation is TRUE — force 2 does delete the whole base path — so a maintainer is misdirected about WHERE, not about WHAT.

Severity downgraded from important to minor. The falsehood is a stale line pointer inside an otherwise correct sentence, in a test docblock rather than production code, and the real branch is in the same file, findable by searching `$force_delete`. AGENTS.md:241 says explicitly that the heavy treatment "is not worth its cost on a comment". Worth fixing on the next touch of this file; not worth blocking on.

Caveat on the fix: the suggested range 1223-1242 stops short of the residual-existence check at 1245-1246, and a bare line number into a 1,300-line file is what went stale in the first place. Name the function so the pointer survives the next edit.

Bash was rate-limited throughout this session, so I could not run the suite or `git show master:...` to establish whether 1093-1094 was correct in an earlier revision. That is provenance only: the sentence as it stands in commit 4adfaffa misdirects regardless, and every claim above rests on direct reads of the tree under review.

**Исправление:** Prefer naming the code over a bare line range, since a line pointer into a 1,300-line file is what went stale here:

  // Force 2 is whole-base-path deletion -- the $force_delete branch of
  // parseOneItem() in collector.php (guard at the top, base-path removal
  // via erasedataCompleteForcedDirectory() in the is_multi block), so a
  // member whose marker asked only for its own files could have its base
  // directory deleted.

If line numbers are kept for consistency with the house convention, they must be collector.php:1129 and 1221-1248 (not 1223-1242 — that range drops the residual `erasedataPathExists($base_path)` re-check at 1245-1246 that completes the branch).

### 3. [minor] "from the sixteenth generation on" is measurably wrong: the first letter-bearing generation is the tenth, and the failures are intermittent, not a threshold

**Вердикт:** confirmed

Location verified: plugins/erasedata/removewithdata.php:355-366. Generation supply chain verified: ERASEDATA_DRAIN_ZERO_GENERATION='0000000000000000' (:1398) seeded at :1538, and the sole producer is erasedataGenerationIncrement($state['generation']) at :2740, a plain string +1 over '0123456789abcdef' (filesystem.php:556). So admitted generations are contiguous from ...0001.

Measured by running the real increment loop and both patterns over '<40hex>.<gen>.tok.list' (PHP 8, own copy under /home/dev/.cache/rutorrent-tmp/verify-31): the historic alternative fails at ordinals 10-15 and 26-31 and PASSES at 16-25 (0000000000000010-19). First failure is the tenth admitted generation, 000000000000000a. The new pattern matches all. So "sixteenth" is wrong, and "from ... on" is wrong: letter-bearing generations recur in bands (…a-f, …1a-1f, …2a-2f), they are not a threshold crossed once. Under no counting convention is 16 right -- even counting the never-admitted ...0000 first, ...000a is the eleventh.

Not deliberate: grep for "sixteenth" hits only this line; nothing in AGENTS.md or the tests explains or pins it. AGENTS.md:247 names "a replacement comment that is itself false" as the recurring defect class here and requires opening the line a new sentence cites.

The rest of the comment I checked and it is TRUE: the branch is genuinely necessary (old pattern returns 0 for ...000a), and "Both alternatives are non-capturing, so $matches keeps its historic shape" holds -- the added group is one (?:…|…) with no capturing subgroups, so $matches stays [full, hash, list|tmp], which line 383's $matches[count($matches)-1] === 'tmp' depends on.

Severity minor, not important: no behaviour is affected. Above a nit because this comment is the only documentation of the boundary -- the parser test (tests/plugins/erasedata/RemoveWithDataTest.php:9411) uses generation '0000000000000001', all digits, so it never exercises the hex-letter branch -- and a maintainer trusting the sentence would believe generations 1-15 are safe under the historic pattern (false: 10-15 are not) and that generations >=16 all need the new branch (false: 16-25 do not), the second of which produces exactly a test that passes with the production change reverted.

**Исправление:** Replace the false clause with a measured one keyed on the value, not only the ordinal:

		// The deployed remove-payload alternative is kept byte-for-byte as the
		// second branch. The first is purely additive and exists because an
		// admitted manifest carries its 16-lowercase-hex generation as the
		// component right after the hash: a generation holding a hex letter
		// does not match [0-9]+. Generations start at 0000000000000000 and are
		// incremented by one, so the first such name is 000000000000000a (the
		// tenth admitted), and letter-bearing generations then recur in bands
		// (...000a-f, ...001a-f, ...) -- this is not a threshold crossed once,
		// and 0000000000000010-19 still match the historic pattern. Without
		// this branch every manifest whose generation carries a letter would be
		// invisible to the collector and its payload would never be deleted.
		// Both alternatives are non-capturing, so $matches keeps its historic
		// shape.

Two notes for whoever applies it: (1) the sentence above is measured, not inferred -- running the production increment from the zero state, the historic alternative fails at ordinals 10-15 and 26-31 and passes at 16-25; (2) while here, consider that no test currently pins the hex-letter branch (RemoveWithDataTest.php:9411 uses '0000000000000001'), so a case at 000000000000000a would make the branch load-bearing under mutation. That is a separate item, not part of this comment fix.

### 4. [minor] chk-forum drift guard: the positive half is satisfied by the comment above the call, and metafetch's reader has no behavioural pin for a non-canonical value

**Вердикт:** confirmed-lower-severity

I tried to refute this and could not refute the mechanism; I did refute part of the framing and most of the severity.

WHAT REPRODUCED (measured in /home/dev/.cache/rutorrent-tmp/verify-17, git-archive of 4adfaffa, host PHP 8.5, TMPDIR disk-backed; baseline all 18 rutracker_check suites rc=0):

1. The guard really is a whole-file strpos, and comment text satisfies it. ProjectionContractTest.php:386 asserts strpos($source, 'RuTrackerRpcValue::canonicalForumId(') !== false over the entire file. metafetch.php:776 and rutracker.php:215 are comments containing 'RuTrackerRpcValue::canonicalForumId()', which contains that needle. I mutated BOTH readers to intval(trim(...)) so that grep showed the identifier surviving ONLY on those two comment lines — ProjectionContractTest still printed "ok - neither reader of chk-forum spells the rule itself", 9 tests, 0 failures. Mechanism confirmed exactly as claimed.

2. Metafetch's reader is not pinned behaviourally. metafetch.php:777 -> $forumId = intval(trim((string) $req->val[0])); survives ALL 18 rutracker_check suites, every one rc=0. Control $forumId = 999; fails 2 MetaFetchTest cases, so the line is genuinely exercised. Grep of tests/ confirms the only chk-forum values ever fed to registrationTime() are '1106', '' and "  1106\n" — never a non-canonical one.

3. The sibling IS pinned, so the contrast is real, but the reviewer's number is wrong: rutracker.php:217 -> intval(trim($forum)) fails 8 RuTrackerHandlerTest cases, not 6 (I measured both the (string)-cast and bare-$forum spellings: 8 each).

4. The comment at :374-376 ("If a third reader appears ... this fails and names the file") is false as written: $readers at :377-380 is a hardcoded two-entry list, so a chk-forum reader in a third file is invisible. Also the failing assertions name the FUNCTION ('registrationTime must not re-spell...'), not the file.

WHAT REFUTES THE FRAMING AND THE SEVERITY:

5. The guard is NOT worthless — its negative half has teeth against the exact drift it was written for. Re-spelling metafetch.php:777 as RuTrackerRpcValue::canonicalPositiveInt32(trim(...)) — the historical shape the header comment at :331-338 describes — fails it: "RuntimeException: registrationTime must not re-spell the chk-forum rule beside the shared one". The finding reports only the vacuous half.

6. The production change is NOT revertible-green. MetaFetchTest 'a chk-forum stored with transport whitespace still dates the replacement' feeds "  1106\n" and pins the trim half behaviourally; drop trim() and it fails. Only the canonicality half is unpinned. This is not the campaign's "test passes with its production change reverted" class in full.

7. The residual risk is small. If metafetch's reader drifted to intval(), a stored '007'/'0'/'+7'/'7abc' becomes a bogus forum id, cachedDump() reads that forum's cached rows, and — topic ids being unique across rutracker forums — the topic is absent, so registrationTime() returns null and 'creation date' is left unset: the same observable outcome as refusing. No wrong date is produced. The cost is reader divergence and a false "no reg_time in the dump cache" debug line, which is the diagnostic defect the register names, not a user-visible one.

8. The title over-generalises: "a reader can stop calling the shared predicate with the whole suite green" holds for metafetch only. Do it in rutracker.php and 8 tests fail. And "reinstating exactly the '007' fetched as forum 7 defect the guard's own comment names" is loose — that phrase is test line 365, and forumindex.php:1354's '007' is about chk-TOPIC in the writer path, not chk-forum in metafetch.

SUGGESTION QUALITY: the behavioural case is cheap, correct and mirrors RuTrackerHandlerTest:372. "The file already has guardedFunctionBody-style helpers elsewhere" is right about the suite, not the file: epFunctionBodyCalls() lives in EntrypointsTest.php:156 and does ignore comments (it matches T_STRING only). But adopting it here has an unstated cost AGENTS.md records: the shipped Alpine image loads no tokenizer, 8 EntrypointsTest cases already fail there for that reason, and ProjectionContractTest currently runs clean everywhere. Strip comments instead of tokenising.

Net: real, substantiated, worth a small fix; not important. No production defect, one genuinely false sentence in a test comment, and one guard assertion that proves less than its message claims.

**Исправление:** Three small changes, none of them urgent:

1. Pin metafetch's reader behaviourally, the way RuTrackerHandlerTest:372 already pins resolveForum. Add one MetaFetchTest case modelled on 'a chk-forum stored with transport whitespace still dates the replacement' (~line 1996) that queues '007' (and '0', '+7', '7abc') as the predecessor's chk-forum with forum 1106's dump published, and asserts meta('creation date') === null — no forum resolved, no dump consulted. That case fails under intval(trim(...)) and is what actually closes the gap.

2. Make the source guard's positive half mean something without adding a tokenizer dependency: strip comments from $source before the strpos (e.g. preg_replace of //-to-EOL and /*...*/ , or assert the needle appears on a line whose trimmed form does not start with // or *). Do NOT reach for epFunctionBodyCalls()/token_get_all() here — AGENTS.md records that the shipped image loads no tokenizer and that 8 EntrypointsTest cases already fail there for exactly that reason; ProjectionContractTest currently runs clean in that runtime and should stay that way.

3. Fix the comment at :374-376. Either drop the "If a third reader appears" clause, or derive $readers by scanning plugins/rutracker_check for files mentioning 'chk-forum' and asserting the set equals the two known readers — that is what would actually catch a third one. Either way replace "names the file" with "names the function", which is what the assertion messages do.

Keep the negative assertion at :388 as it is: it is the half with teeth (it catches the historical canonicalPositiveInt32(trim( re-spelling, verified).

### 5. [minor] The deletion-gate test pins the tolerance only from above; elapsed 3240 is untested and the test comment is one-sided

**Вердикт:** confirmed-lower-severity

Locations are right. tests/plugins/rutracker_check/RuTrackerHandlerTest.php:2017 probes only elapsed 3599 (must advance) and 3239 (must not) against interval 3600, so any gate in [3240, 3599] passes — that part of the finding reproduces.

I mutated plugins/rutracker_check/trackers/rutracker.php in my own export at /home/dev/.cache/rutorrent-tmp/verify-16 (git archive of 4adfaffa) and ran all 18 tests/plugins/rutracker_check/*Test.php on PHP 8.5.4, disk-backed TMPDIR, no leaked scgi processes:
- divisor 10 -> 2000 (gate 3599): SURVIVES, 18/18 rc=0. Matches the reviewer.
- divisor 10 -> 100 (gate 3564, 36 s tolerance): SURVIVES.
- divisor 10 -> 5 (gate 2880): KILLED — "not ok - a scheduled cycle that arrives seconds early still advances the deletion count".
- drop the subtraction entirely, return max(MIN_DELETE_INTERVAL, $interval) = 3600, which is the ACTUAL pre-fix behaviour: KILLED, same test.
- drop max(self::MIN_DELETE_INTERVAL, ...) from deletionGate(): SURVIVES.

Two sentences the finding rests on are therefore false. (1) "gate becomes 3600-1 = 3599 ... i.e. essentially the pre-fix behaviour": the pre-fix gate is 3600, not 3599, and that mutant fails the named test. The test does kill a revert of M02. (2) "3239 ... pins no edge at all": 3240 is exactly the smallest gate the second assertion permits and exactly the production value; gate 2880 fails on that half. So the comment at :2016 ("the second half below pins the other edge at 3240 s") is accurate in the direction it constrains and silent about the other — one-sided, not false. Nobody is sent wrong about a fact; they are only left believing the protection is two-sided.

What survives: the tolerance's magnitude is pinned only to the band 1-360 s, so a narrowing (divisor raised) is invisible, and the "advance" case uses 1 s early where the cited real defect is an 18 s early arrival (observed range 3582-3618). Adding elapsed 3240 -> must advance closes that cheaply and breaks nothing. The separate mutation C result shows deletionGate()'s own max(MIN_DELETE_INTERVAL, ...) is untested, but confirmDeletion():415 already floors $interval to 60, and real intervals are $updateInterval*60, so only $interval == 60 reaches that guard (gate 54 vs 60) — nearly inert, worth at most a sentence.

Severity: the finding claimed "M02's whole substance has no test" and rated it important; the revert-killing and widening-killing mutants refute that, and AGENTS.md's rule (a mutation proving the new test is load-bearing) is satisfied by the shipped test. What remains is a half-pinned boundary plus a one-sided comment clause: minor.

**Исправление:** Keep the existing two cases and add the missing upper side of the same boundary: with interval 3600 and chk-del '1:' . ($now - 3240), the count must advance (one d.set_custom writing '2:' . $now). That single block turns the band [3240, 3599] into the exact value 3240 and would kill divisor 10 -> 100 and 10 -> 2000, which currently survive. Optionally add one small-interval case for deletionGate()'s own floor (interval 60: elapsed 54 must NOT advance, which distinguishes gate 60 from gate 54) — noting confirmDeletion() already floors $interval to 60, so only $interval == 60 reaches that guard at all. Then reword the comment at :2016 to say what the pair actually pins, e.g. "the tolerance is a tenth of the interval: 3599 must advance and 3239 must not, which brackets the gate at 3240 s" — the current clause is one-sided rather than wrong, so this is a precision edit, not a correction of a false claim.

### 6. [minor] Two comment numbers are wrong: the marker is 92 bytes (comment says 82) and a full journal is ~1.4 MiB (comment says "a few hundred kilobytes")

**Вердикт:** confirmed

Both sub-claims reproduce against the shipped code.

(1) plugins/erasedata/pending.php:40 states "version=1\n + generation=<16>\n + hash=<40>\n + force=<1>\n is 82 bytes". Running the branch's own erasedataEncodePendingMarker() standalone returns strlen 92, for force=1 and force=2, for generation 000000000000000a and ffffffffffffffff. The schema is fixed-width (erasedataGenerationIsValid pins [0-9a-f]{16} at filesystem.php:546-549; erasedataIsCanonicalPendingHash pins 40 hex), so every marker in the system is exactly 92 bytes. Per-field: 10 + 28 + 46 + 8 = 92. There is no reading that yields 82. The sentence is FALSE, not imprecise.

(2) plugins/erasedata/removewithdata.php:1379-1381 justifies ERASEDATA_DRAIN_STATE_MAX_BYTES = 8388608 with "A full journal of ERASEDATA_DRAIN_MAX_JOURNAL single-hash records is a few hundred kilobytes". I constructed a 4095-record single-hash state, confirmed the branch's own erasedataValidateDrainState() accepts it (returns true), and measured json_encode(): 1,425,194 bytes = 1.36 MiB with a 53-char queue path. The shape is incompressible because erasedataValidateDrainJournalEntry() requires one staging entry per hash carrying the full <listPath>/<40hash>.<16gen>.<pid>.<uniqid>.tmp path, slashes doubled by json_encode escaping (~348 bytes/record). Path-length sweep: "/q" -> 1.14 MiB; a realistic rtorrent session dir -> 1.31 MiB; a rutorrent settings dir -> 1.38 MiB. "A few hundred kilobytes" is low by roughly 4x.

Deliberateness: none. No test asserts either number (grep over tests/ for PENDING_MARKER_MAX_BYTES and marker byte counts finds nothing), and AGENTS.md:247-250 names precisely this defect class ("a replacement comment that is itself false ... the check that pays is did anyone open the line the new sentence cites"). Both comments are additions of this branch -- both appear as + lines in git diff master..HEAD (the second wraps across two lines, which is why a naive single-line grep of the diff misses it).

One correction to the reviewer's own evidence, which matters under this project's rule that quoted numbers must be measured: their "1,482,536 bytes = 1.41 MiB with a short queue path" does not reproduce. A genuinely short path gives 1,195,874 bytes (1.14 MiB); 1.41 MiB needs a ~90-char listPath or a longer staging suffix. The magnitude claim stands; the specific figure is mislabelled, so the fix must not swap one unreproducible number for another.

Severity: minor is honest and I would not raise it. Neither error is load-bearing -- 92 < 4096 (44x headroom) and 1.4 MiB < 8 MiB (5.9x headroom), so both ceilings' conclusions survive untouched. The cost is confined to a maintainer who sizes a test or trims a cap from the comment.

The suggestion is an improvement and breaks nothing: these are comments only, no behaviour depends on them. But the second number should not be written as a precise figure, since it scales with the queue path length.

Nothing in the worktree was modified; probes live in /home/dev/.cache/rutorrent-tmp/verify-32/. pgrep -af rutorrent-scgi-rpc2 matched only my own shell, so nothing was leaked or killed.

**Исправление:** pending.php:40 -- state the measured value and the fact that it is fixed: "version=1\n + generation=<16>\n + hash=<40>\n + force=<1>\n is exactly 92 bytes for every marker. The ceiling is generous enough for a future field and small enough that a marker can never become a memory ceiling of its own."

removewithdata.php:1379-1381 -- do not substitute another precise figure; the size scales with the queue path length (1.14 MiB with a 2-char path, 1.36 MiB with a 53-char one, more with a long settings path). Prefer either the qualitative point with no arithmetic, or a bounded phrasing: "A full journal of ERASEDATA_DRAIN_MAX_JOURNAL single-hash records is over a megabyte -- about 1.4 MiB with a typical queue path, and larger as the path grows; the ceiling keeps several times that headroom and is still small enough that the state can never become a memory ceiling of its own."

### 7. [minor] `obligation-unrecoverable` calls a served removal a loss when another generation covers the hash; the comment's certainty claim is refuted by its own example

**Вердикт:** confirmed-lower-severity

LOCATION IS RIGHT. In 4adfaffa, plugins/erasedata/removewithdata.php: the comment block runs 3947-3976 ("The second is a real loss" 3958, "This is certainty, not a relaxation" 3973), the branch 3977-4008, "The marker is what separates the two" 3981, `obligation-complete` 3989, `obligation-unrecoverable` 3998. The code there does exactly what the finding says: for a member that is gone, unbound and whose staging object is gone, the ONLY discriminator is `erasedataPendingMarkerStands($listPath, $hash, $generation)` -- this generation's marker. `$final` is consulted only as `$final[$hash][$generation]`; other generations of the same hash are read into `$final[$hash]` and never looked at. `erasedataDrainWorkerJobs()` groups jobs by generation and runs one pass per generation, so one hash really can be settled under several generations in one tick.

REPRODUCED, twice, in /home/dev/.cache/rutorrent-tmp/verify-30 (probe30.php, probe30b.php; TMPDIR disk-backed; `pgrep -cf rutorrent-scgi-rpc2` = 1, which is my own shell matching the pattern, nothing leaked).
- probe30 (two live generations): gen1 marker standing from a failed admission, gen2 staged/erased/published. One tick: `unrecoverable=1`, log `obligation-unrecoverable generation=0000000000000001 ... consequence=download-gone-no-manifest-marker-discharged`, and the same tick's collector deleted the payload -- the request WAS served.
- probe30b, which is the decisive one: it is the comment's OWN sentence, driven through the production admission door. Generation 1 wins (manifest published, collector consumed it, payload deleted, marker gone, queue clean). The user clicks again; `erasedataRemovalAdmissionRun()` writes generation 2's marker at step (6) and then refuses at `paths` because the download is gone (`ADMIT LOG: erasedata: paths ... admission-refused-obligations-retained`). The next tick prints `obligation-unrecoverable generation=0000000000000002`. That is verbatim "the shape a refused admission leaves behind when it wrote its markers and then failed, and its download was erased by the batch that won" -- and nothing was lost, because the batch that won published a manifest and the collector deleted the payload. So the comment names this case and then classifies it as "a real loss". The certainty sentences are false as written: the marker separates "this generation's obligation record still stands" from "it does not", which is not the same as "the data was lost" versus "it was erased". A third case exists that the enumeration does not admit.

NOT DELIBERATE-AND-DOCUMENTED. AGENTS.md says nothing about it. The accompanying test `testACompletedMemberIsNotReportedAsAnUnrecoverableLoss` (tests/plugins/erasedata/RemoveWithDataTest.php:12548) pins only the single-generation case: both members under generation 0000000000000005, separated by the marker. Its docblock repeats the same two-case taxonomy. So the campaign fixed one false-loss path and left a second while asserting the classification is exhaustive.

SEVERITY DOWNGRADED, honestly. Nothing behaves wrongly: discharging the standing marker is the correct action in all three cases, and both the comment and the test say so. The consequence token `download-gone-no-manifest-marker-discharged` is clause-by-clause TRUE; only the reason word `obligation-unrecoverable` and the comment's gloss are wrong. `tick['unrecoverable']` is consumed by nothing -- update.php:115 only tests the tick `!== false` -- so the inflated count reaches no decision. Report memory dedupes the line, so no flood. And it needs a failed-then-retried admission, not the everyday path. Real, worth fixing, not blocking: minor.

THE SUGGESTION IS PARTLY UNSOUND. "Take the obligation-complete branch when another generation's final manifest is in `$final`" (a) misses probe30b entirely, where the winning manifest was collected in an earlier tick and the queue holds no record at all that the hash was ever served -- so it buys a partial fix while restoring the appearance of certainty; and (b) can itself be false: force is per generation, so a force-1 publication under generation N is not proof that a force-2 obligation under generation M (whole base path) was met, and calling that one complete would be a new lie in the other direction. The sound part of the suggestion is its second half.

Also confirmed as stated: the same member gets two contradictory notes in one pass -- `paths-unknown ... obligation-retained` (line ~3814, step (2)) immediately followed by `obligation-unrecoverable ... marker-discharged`. Step (2)'s comment knows the member falls through to settle; it does not note that the consequence it just printed is untrue by the end of the same pass.

Checked and fine: the per-generation grouping, the `$unbound` skip, the `isset($final[$hash][$generation])` first branch and the `erasedataStagingObjectIsGone()` guard all do what their comments say; the marker discharge itself is correct; and `erasedataPruneResolvedJournalRecords()` really does keep a record alive while any marker of its generation stands, so the stranded generation is drained rather than forgotten.

**Исправление:** Fix the comment, not the branch. Replace the two certainty sentences with the true enumeration: three members reach here, and the marker of THIS generation separates only the first from the other two -- (1) a member finished under this generation, whose marker was discharged by its completer; (2) a member whose marker of this generation stands but whose data was erased and collected under ANOTHER generation, which is not a loss and is the ordinary residue of a failed admission followed by a successful retry (measured: an admission refused at `paths` after the winning batch published, drives `obligation-unrecoverable` on the next tick with the queue already clean); (3) a genuine loss, where no generation ever published a manifest and the payload is unfindable. Say plainly that the pass cannot tell (2) from (3) -- once the winning manifest is collected and its marker discharged, the queue keeps no record that the hash was served -- and that the marker is discharged in both because the action is the same. Do not add the `$final`-of-another-generation test as the discriminator: it misses every case where the winning manifest was already collected, and it would misreport a force-2 obligation as complete on the strength of a force-1 publication. If a better signal is wanted it needs new durable state (e.g. remembering a served hash across generations), which is a design change, not a comment fix. Separately, either drop the `paths-unknown ... obligation-retained` note for a member the same pass is about to discharge, or give it a consequence that survives to the end of the pass.

### 8. [minor] The `$live` guard's restart interaction is a documented, tested design decision; the residual gap is a CLI-door-only, visible, one-page-load-recoverable stall, not a silent permanent wedge

**Вердикт:** confirmed-lower-severity

WHAT I VERIFIED AS TRUE (by reading, not by re-running the reviewer's probes)

- Location is right. `plugins/erasedata/removewithdata.php:2761` reads `$live = $state['phase'] === 'armed' && $state['user'] === $user;` and the whole `if(!$live){...}` block — schedule RPC plus the arming->armed CAS — is skipped. `$live` is derived from the durable state file alone; nothing probes rTorrent's table (no such API exists on either daemon, as the code says).
- A completed admission really does leave `phase == 'armed'` (2764/2848; `erasedataRetainAdmittedGeneration` at 3082 touches only the *journal record's* phase, never the top-level one).
- Registration sites really are only two: the producer (2798) and `erasedataRearmDrainScheduleRun()` (4662), the latter reached only from `erasedataRearmDrainSchedule()` <- `plugins/erasedata/init.php:31` <- `php/getplugins.php`. `update.php`, `done.php`, `collector.php`, `action.php` register nothing; `erase.php` requires only manifest.php/xmlrpc.php/removewithdata.php, so the CLI door does not re-arm. So on a restart that lands while `phase == 'armed'`, the next admission registers nothing, times out on the 11 s ack, logs `drain-no-ack` and returns false — until a full web-UI load.

WHY THIS IS NOT AN `important` STRANGE-LOGIC FINDING

1. It is the documented design, and the exact failure mode is already named in the code and pinned by a test. `init.php:19-30`: "rTorrent's schedule table is volatile ... This is the one moment that loss is plausible, and it is the only place in the shipped plugin that re-arms." `removewithdata.php:4509-4518` (rearm guard 3) states the interaction verbatim: "Leaving the phase alone made a restart leave a stale `armed` claim standing over a schedule table rTorrent had just emptied, and the producer's own live-schedule guard then trusted that claim, registered nothing and let the first removal after the restart time out with an unscheduled marker behind it." `RemoveWithDataTest.php:12388-12437` (F3, `testStartupRecoveryClearsAStaleArmedClaimWhenNothingIsOwed`) pins that correction *and* measures the consequence the finding describes. The maintainers chose to put the correction in startup recovery rather than in the producer; that is a deliberate placement, not an oversight.

2. "Silent permanent stall / no path back" is false, on both legs of the AGENTS.md rule. Visible: `erasedataDrainDiagnostic()` (1889) writes straight to the ruTorrent log on every attempt, and `testAcceptedScheduleWithNoChildRetainsTheTorrentAndRollsBackOnlyItsOwn` pins "exactly one unconditional drain-no-ack diagnostic". Self-healing: the retained markers + `retained` journal records are exactly the self-describing obligation `erasedataDrainWorkerJobs()` (3266, `$carried = prepared|erase-started|retained`) re-drives with no cap once a schedule exists again, and one page load re-creates it. Nothing is erased, nothing is lost, and for the *web* door the repair is a page reload — which the aligned-start comment at 1993-2003 explicitly made safe because "reloading is exactly what a user does when a deletion looks stuck".

3. The reachable window is much narrower than "every later removal, for ever". The drain tick runs `erasedataRetirementRun()` on every tick (step 6, 4846), and retirement writes `settled`->`disarmed` durably before the `schedule_remove` RPC (4374-4390). With `ERASEDATA_DRAIN_INTERVAL == 5`, `armed` at rest survives only ~1-2 ticks after the last obligation clears. It persists longer only when retirement is refusing — i.e. when the queue is already in a degraded state. So the wedge needs a restart inside that window *and* an install where `getplugins.php` never runs again.

4. The premise "the erase.php/ratio-command case the plugin exists for" overstates it. `erase.php`'s own header calls it the CLI entry point for `plugins/ratio/ratio.php:151`; the primary door is `action.php`. And a UI-less install already depends on init.php for the ordinary `erasedata<User>` collector schedule, which is likewise registered nowhere else (asserted by `testRestartAfterVolatileScheduleLossRearmsTheExactGeneration`, line 11594) — so "recovery happens at plugin init" is the plugin-wide contract, not a quirk of this guard.

5. The claim that the guard's justification is *refuted* by the aligned start is itself imprecise. The comment's "postpone the first tick for ever" is overstated given `getAlignedStart` (php/settings.php:486) — that much is fair, and the tension with the file's own 1993-2003 comment is worth at most a wording nit. But alignment does not make re-registration free: `getAlignedStart` works in whole seconds and maps a registration made *during the fire second* to `$interval` (the 0 -> interval rule), i.e. one slot later. A producer stream that keeps landing in that one-second window each interval can still push the tick, so the guard buys real protection and also saves an RPC per admission. It is pinned by three tests (8118, 10122, 12733) plus the "real-daemon evidence" note at 10113-10120.

WOULD THE SUGGESTION BE AN IMPROVEMENT? Neither variant as written.
- Dropping `$live` reverts a guard backed by measured daemon behaviour and three tests, and re-opens the countdown-restart race above.
- "On a `drain-no-ack` under `$live`, downgrade the phase to `disarmed`" treats an ambiguous signal as proof. A no-ack also occurs when the schedule is perfectly alive and the tick is contended (`worker-busy`, `scheduler-lock`, a hung worker holding the nonblocking pass lock at 4771). Under load that downgrade makes the *next* producer re-register a live key — the exact hazard, triggered precisely where it hurts most.

CORRECTED SUGGESTION (see correctedSuggestion): if the residual is judged worth closing, close it where it actually is — the CLI door — by having a `drain-no-ack` under `$live` fall through to the *conservative* `erasedataRearmDrainScheduleRun()` (which scans, refuses on the zero generation and on unreadable state) instead of blindly rewriting the phase.

THINGS I CHECKED AND FOUND FINE: the `armed`/`arming` CAS loop and its retry comment (2724-2745) match the code; `erasedataRetainAdmittedGeneration`'s refusal to downgrade `erase-started`/`published` is correct and explained; retirement's `retire-unstable`/`retire-unarmed` refusals are all noted, not silent; `done.php` really does leave the drain key alone. I ran no suite (read-only, no process leaked, `pgrep -cf rutorrent-scgi-rpc2` untouched) — every claim above is from the source or from an already-passing shipped test, which is what the finding's own mutation check also demonstrates.

**Исправление:** Do not drop the `$live` guard and do not downgrade the durable phase on a bare `drain-no-ack` (a no-ack is also what a busy or hung worker looks like, and both changes would restart a live countdown under contention). If the residual is worth closing at all — it costs a page load today, and only on an install whose web UI is never loaded after a restart that landed inside the short `armed` window — close it at the door that has no recovery site: after a `drain-no-ack` taken under `$live`, call the conservative `erasedataRearmDrainScheduleRun()` (which takes the state lock nonblocking, runs the retirement scan, refuses on the zero generation and on unreadable state) and let it decide whether to re-register, then let the caller retry. That reuses the one audited re-arm path instead of inventing a second phase transition, and it keeps the "durable claim is trusted" invariant that three tests pin. A cheaper alternative, if the behaviour is accepted as-is: state the residual in the `$live` comment ("a restart that lands on `armed` is repaired by plugins/erasedata/init.php, so a queue driven only by erase.php stalls visibly until the next full UI load") and fix the same comment's overstated "postpone the first tick for ever", which contradicts this file's own aligned-start comment at removewithdata.php:1993-2003.

### 9. [minor] A test comment's coverage claim does not reproduce: two other cases in the same file fail when the producer's re-registration guard is removed

**Вердикт:** confirmed

Reproduced exactly. Baseline run of tests/plugins/erasedata/RemoveWithDataTest.php alone through the shipped php-test.sh loop (git archive of 4adfaffa into /home/dev/.cache/rutorrent-tmp/verify-33, disk-backed TMPDIR, PHP 8.5.4): exit 0, 2765 Passed, 0 Failed. Mutating only plugins/erasedata/removewithdata.php:2761 ($live = $state['phase'] === 'armed' && $state['user'] === $user;  ->  $live = false;): exit 0, 2761 passed, 4 Failed lines across 3 methods, mapped by the >>method>> markers: testAContinuousProducerStreamDoesNotReRegisterALiveSchedule ("five producers arm the drain schedule exactly once, not five times"), testALiveDrainScheduleIsNeverReRegisteredOrPostponed ("a second admission on a live schedule re-registers nothing"), testTheArmCompareAndSwapLoserRetriesInsteadOfRefusing (two assertions).

Stronger than the mutation: the sentence is false by reading alone. The comment at RemoveWithDataTest.php:10119-10121 asserts "every other case either arms once or never counts the registrations", but testAContinuousProducerStreamDoesNotReRegisterALiveSchedule at line 8118 of the same file loops five admissions, counts scheduleRecords('schedule') entries whose key starts with 'erasedata-drain', and asserts the count is 1 -- it counts the registrations, which is precisely what the clause says no other case does.

Not deliberate: no AGENTS.md note or nearby comment licenses it, and AGENTS.md names "a replacement comment that is itself false" as the recurring defect class here, with the paying check being "did anyone open the line the new sentence cites". The same template sentence appears twice more in the file (8184-8187, 9589-9592); only the 10119 instance was measured and only it is claimed false.

Severity stays minor: comment-only, no runtime effect, and it errs by understating coverage, so the harm is a later maintainer treating the other two cases as not load-bearing or trusting the sentence while pruning.

Two corrections to the reviewer's framing, neither changing the verdict: (1) one of the three failing methods IS the case that owns the comment, so there are two genuine counterexamples to "nothing else in this file fails", not three -- the claim is still false; (2) the parenthetical about the runner only printing Failed: and php-test.sh's grep supplying the non-zero status is accurate (my mutated run exited 0 with 4 Failed lines) but is not a new observation -- tests/plugins/erasedata/RunNamedCases.php states it in its own header as the reason that runner exists, so it is context, not a defect.

The suggestion is safe (comment-only), but I prefer deleting the last sentence over naming the three cases: the sentence quantifies over BOTH re-registration guards, and only the producer's $live guard was measured. The rearm counterpart is the if(!$owed) early return at removewithdata.php:4593, which cannot be removed without also removing the stale-armed correction, so any rewritten sentence naming cases would need its own measurement to stay true -- the same trap that produced the false sentence.

Read-only throughout: target worktree git status clean, scratch tree deleted, no scgi-rpc2 processes left (the pgrep -cf count of 1 seen mid-run was my own shell matching the pattern).

**Исправление:** Delete the final sentence of the comment at tests/plugins/erasedata/RemoveWithDataTest.php:10119-10121 ("Nothing else in this file fails when either re-registration guard is removed: every other case either arms once or never counts the registrations."). The first three sentences carry the real-daemon provenance, which is the load-bearing part. If a coverage sentence is wanted, it must be measured per guard and scoped to the guard actually measured, e.g. "Removing the producer's `$live` guard (removewithdata.php:2761) also fails testAContinuousProducerStreamDoesNotReRegisterALiveSchedule and testTheArmCompareAndSwapLoserRetriesInsteadOfRefusing; this case is the one that pins the second-producer and startup-recovery shapes." Do not extend such a claim to the rearm path's `if(!$owed)` guard (removewithdata.php:4593) without measuring it: it cannot be removed in isolation, since the same block performs the stale-`armed` correction.

### 10. [minor] The 32-bit/float rationale comment above erasedataIdentityDeviceAndInode() is false, but the cast it defends is load-bearing and the duplication/unification claim is wrong

**Вердикт:** confirmed-lower-severity

WHAT SURVIVES (and only this). The comment at plugins/erasedata/filesystem.php:442-444 is factually false as written. A decimal spelling is derived from the float, so it cannot recover a distinction the float has already lost, and PHP's default precision=14 makes it strictly coarser. Measured on PHP 8.5 in /home/dev/.cache/rutorrent-tmp/verify-49: 4503599627370491.0 === 4503599627370492.0 is false, while (string) of both is "4.5035996273705E+15" and compares equal. Driving the real functions with float identities reproduces the reviewer's divergence: erasedataIdentityDeviceAndInode() and erasedataSameEntryIdentity() both answer SAME where a raw === answers DIFFERENT. So the sentence would send a maintainer wrong, and by the AGENTS.md rule at lines 245-249 ("the check that pays is did anyone open the line the new sentence cites") that is a real comment defect. It also has a visible precedent: the same reasoning IS correct one screen down at filesystem.php:540-544 for generations, which are BORN as 16 hex digits and never converted. Here it was transplanted onto a value that is born as a number, where it does not hold.

WHAT IS REFUTED, and this is most of the finding.

1. "On a 64-bit build the cast is a no-op and all five agree" is measurably FALSE, at both sites carrying the cast, and it is the load-bearing part of the finding's impact story and its suggestion.
   - erasedataEntryIdentityParts(): its counterpart operand is parsed out of a DIRECTORY NAME by preg_match at filesystem.php:774-778, so $info['identity'] is genuinely array of strings. Ran it: created a real captured-entry root, erasedataCapturedEntryRootInfo() returned dev "2050"/ino "5707566" as strings, erasedataEntryIdentityParts(fresh lstat) === that identity is TRUE only because of the cast; the same comparison with the raw stat ints is FALSE. Remove the cast (or "reject non-integer, fail closed" as suggested) and the checks at filesystem.php:934 and :944 can never match, so every captured entry fails forever — precisely the silent permanent stall AGENTS.md forbids.
   - erasedataIdentityDeviceAndInode(): acquireDirectoryCapability() stores this function's OWN stringified output into the capability array (filesystem.php:363-364). Ran it: $cap['dev'] and $cap['ino'] come back as PHP strings "2050"/"6079711". erasedataRemovalCapabilityStillMatches() (removewithdata.php:2601-2605) compares that array against a fresh @fstat() through the same function; with the cast it matches, with raw === on the two sources it is false on both fields. Deleting the cast permanently refuses the multi-path removal capability.

2. The impact chain is unreachable, so "important" is not honest. Every producer in the plugin hands ints (measured: entryIdentity() returns integer dev/ino), and the one boundary where a wide value could arrive as a float — a decoded manifest — already applies exactly the finding's own preferred rule: ErasedataManifestCodec::normalizeIdentity() (manifest.php:296-306) rejects any dev/ino that is not a nonnegative int. So the "capability handed back for the wrong directory" story and the "4.5035996273705E+15 reservation name no parser can match" story both require float dev/ino that the code cannot produce. The reservation-name concern additionally needs two directories on one device whose inodes differ only beyond precision 14, and acquireDirectoryCapability() still requires the before/after descriptor snapshot to differ by exactly one.

3. The framing is inaccurate in detail. Only ONE function carries the disputed comment (filesystem.php:440-444); erasedataEntryIdentityParts() at :681 has no comment at all, so "the two that carry the justifying comment" is wrong. And "five spellings" both overcounts sameness and undercounts sites: the five compare different things (2-field token used for set membership, 3-field including the type nibble, 4-field lstat+stat plus exists, a tri-state SAME/DISTINCT/UNKNOWN, and a bare 2-field), and there are further dev/ino encodings the finding never mentions (collector.php:91, :300, :792, removewithdata.php:437-438, filesystem.php:845, :859). "Give the plugin one dev/ino comparator and have all five call sites use it" is therefore not a mechanical unification: any single comparator would still have to keep the string normalization the finding argues for deleting.

I found no test that pins the string behaviour, and no AGENTS.md note about it, so the comment is the only rationale — which is why fixing the sentence (not the code) is worth doing. Read-only throughout; experiments in /home/dev/.cache/rutorrent-tmp/verify-49 with TMPDIR under /home/dev/.cache/rutorrent-tmp/verify-49-tmp; no suite run and no processes leaked (note: `pgrep -cf rutorrent-scgi-rpc2` returns 1 because it matches the invoking shell's own command line — the pattern trap the brief warns about; filtering that out leaves nothing).

**Исправление:** Change the comment only; leave all the code alone. Replace filesystem.php:442-444 with the reason the cast actually exists: "String on purpose because the other operand is often already a string: dev/ino are encoded into reservation and captured-entry directory names and parsed back out by regex (filesystem.php:776, collector.php:190), and the capability array this function feeds stores its own stringified dev/ino (filesystem.php:363-364), which erasedataRemovalCapabilityStillMatches() then compares against a fresh @fstat(). Widths are not the concern: ErasedataManifestCodec::normalizeIdentity() already rejects a non-integer dev/ino at the only boundary where one could arrive." Do NOT delete the cast and do NOT unify the comparators into one: the five sites are five different predicates (2-field token, 3-field with type, 4-field lstat+stat with exists flags, tri-state alias, bare 2-field), and two of them require the string normalization to work at all.

### 11. [minor] check.php re-spells the chk-forum rule and the drift guard's comment claims a tripwire it does not have

**Вердикт:** confirmed-lower-severity

Every factual element reproduces; I could not refute it, only shrink it.

CITED LOCATION IS RIGHT. plugins/rutracker_check/check.php:1475-1476 (ruTrackerChecker::createTorrent) reads the predecessor's chk-topic and chk-forum out of the snapshot at :1424-1432 and canonicalises both with the literal `RuTrackerRpcValue::canonicalPositiveInt32(trim((string) $req->val[N]))`. Both lines are NEW in this branch: `git show master:plugins/rutracker_check/check.php` has `$topic = (string) $req->val[4]; $forum = (string) $req->val[5];` and no canonicalForumId anywhere. $forum then flows to buildReplacementAddition() at :1518-1521 and out as `d.set_custom=chk-forum,...` at :572, so this site really is both a canonicalising reader of chk-forum and the writer of the value the guarded readers later read.

THE GUARD REALLY CANNOT SEE IT. tests/plugins/rutracker_check/ProjectionContractTest.php:372-392 iterates a hardcoded two-element $readers map. I ran its two predicates over the whole plugin: check.php shared=0 respelt=2; metafetch.php 2/0; rutracker.php 2/0; forumindex.php and updatepass.php 0/0. In my own copy under /home/dev/.cache/rutorrent-tmp/verify-48 I added check.php to the map and the guard failed: "createTorrent must read chk-forum through the shared predicate; expected true, got false". Baseline before the mutation: 9 tests, 0 failures.

THE COMMENTS ARE FALSE, NOT MERELY IMPRECISE.
- ProjectionContractTest.php:374-377: "If a third reader appears, or one of these stops calling the shared predicate, this fails and names the file." Only the second clause is true. A third reader appearing is exactly what happened — in the same commit as the guard — and the suite is green. Its heading at :331 ("chk-forum has exactly one spelling, and both of its readers use it") is false on the same tree.
- trackers/rutracker.php:214 "One spelling, shared with the only other reader of this custom": chk-forum is read at check.php:1431, forumindex.php:1345 and :1415, metafetch.php:771, rutracker.php:211 — five sites; three of them canonicalise it as a forum id (rutracker, metafetch, check). Two of the five (forumindex) are the deliberate raw-byte CAS sites that runstate.php:128-131 explicitly excludes, so the charitable reading is "the only other canonicalising reader" — and that is still false because of check.php:1476.

NOT DELIBERATE-AND-DOCUMENTED. The 19-line comment at check.php:1456-1474 justifies trimming on a copy and not writing back to the predecessor's customs; it never says why the shared predicate is bypassed, and its stated aim ("'007' names no topic to topicsAwaitingForum() or resolveForum(), so forwarding it writes a value the successor's own readers refuse") is precisely canonicalForumId's contract. Nothing in AGENTS.md or tasks/2026-09-05-consolidated-fixes/*.md records a decision to keep check.php out of the guard.

WHY MINOR, NOT IMPORTANT. There is no defect today: for anything d.get_custom can return (a string), `canonicalPositiveInt32(trim((string)$v))` and `canonicalForumId($v)` are the same function; they differ only for bool/array inputs that cannot reach that line. The finding's failure scenario needs a future loosening of canonicalForumId, a predicate whose docblock states "Canonical or nothing" as settled policy, and LIVE-CHECKS.md:62 records 0 non-canonical chk-forum values on the live fleet. The finding's "a tracker-wide crawl per cooldown" is also overstated: a lost chk-forum costs one crawl, after which forumindex writes the id back. What is left is real but latent — one duplicated rule plus three sentences that would send a maintainer wrong about how much the guard covers.

THE SUGGESTION IS HALF RIGHT, AND ITS SECOND HALF BREAKS THE SUITE. Routing 1476 through canonicalForumId is safe: I applied it in my copy, php -l clean, and the full tests/php-test.sh run exited 0 (49 test files, TMPDIR disk-backed, no leaked rutorrent-scgi-rpc2 processes afterwards). But "then add check.php to the guard's $readers map" does not work: with 1476 fixed and check.php in the map the guard still fails with "createTorrent must not re-spell the chk-forum rule beside the shared one", because line 1475 uses the same substring for chk-topic — which is legitimate, there being no canonicalTopicId. The guard's forbidden-substring test is file-wide and key-blind, so extending it by filename is not available without also touching the chk-topic line.

**Исправление:** Two independent changes, in order of value.

1. The comments, which are the part that is false today.
   - ProjectionContractTest.php:331 and :374-377: drop the "if a third reader appears, this fails" promise and the "both of its readers" heading. Say what the guard actually does: it pins these two named files. Verified false by the tree itself — check.php:1476 is a third canonicalising site in the same commit and the suite is green.
   - trackers/rutracker.php:214: "the only other reader of this custom" is wrong. chk-forum is read at check.php:1431, forumindex.php:1345, forumindex.php:1415, metafetch.php:771 and rutracker.php:211; three of those canonicalise it. Name the two forum-id readers explicitly, or say "the other reader that reads it as a forum id" only after 2 below is done.

2. The duplication. Replace check.php:1476 with
     $forumId = RuTrackerRpcValue::canonicalForumId($req->val[5]);
   Behaviour-identical for every value d.get_custom can return; php -l clean and full suite exit 0 with it applied. Leave check.php:1475 (chk-topic) as it is — there is no shared topic predicate, and note that no other chk-topic reader trims (forumindex.php:1355 and :1428, metafetch.php:763 all call canonicalPositiveInt32 without trim), so that line is a separate question, not part of this one.

   Do NOT simply add check.php to the guard's $readers map: with 1476 fixed it still fails on line 1475's chk-topic spelling, which I reproduced. If the guard is to cover check.php, make it key-specific rather than file-wide — e.g. assert that in every plugins/rutracker_check/*.php containing 'chk-forum', the canonicalisation applied to a value read from that key goes through canonicalForumId — or, if that is more machinery than it is worth, keep the two-file guard and just make its comment stop promising coverage it does not have.

### 12. [minor] Most of the new fetchDump reason vocabulary is unpinned and free to drift

**Вердикт:** confirmed

Location checked and correct: forumindex.php:512-514 is the reservation reason expression, and ForumIndexTest.php:3699-3803 is the "fetchDump: WHY there is no dump" section.

The full emittable set is: reservation-retired (:411), generation-exhausted (:470), reservation-refused / reservation-{unreadable,unwritable,unlockable,unstored} (:512-514, the middle three from RuTrackerState::update()'s $failure at state.php:354/362/372/378), cache-lost (:573 and :596), dump-refused|dump-empty|dump-malformed with status detail (:606/:619), document-unsaved (:642), reservation-superseded (:666), document-unpromoted (:678), publish-{unwritable,unlockable,unreadable,unstored} (:697), publish-refused (:701).

Grep over tests/ confirms exactly five asserted: dump-refused (two status forms), dump-empty, dump-malformed (ForumIndexTest:3711-3717, 1241, 1266; RuTrackerHandlerTest:1454), reservation-superseded (:3770), reservation-unreadable (:3801). The other hits are comment prose (:3702) and unrelated fixture directory names containing the substring "publish-".

Measured rather than inferred. In my own copy at /home/dev/.cache/rutorrent-tmp/verify-42 I renamed nine literals simultaneously (reservation-retired, generation-exhausted, reservation-refused, both cache-lost sites, document-unsaved, document-unpromoted, the 'publish-' prefix, publish-refused), leaving the 'reservation-' . $stateFailure prefix intact so reservation-unreadable still worked. Full tests/php-test.sh over all 129 files returned exit 0. No rutorrent-scgi-rpc2 processes leaked.

Not deliberate: nothing in AGENTS.md or the surrounding comments exempts these words, and the :309-328 docblock treats the vocabulary as the deliverable. It also breaks a norm the same file sets -- sweep()'s sibling vocabulary IS pinned with strictAssertSame (ForumIndexTest:1055, 1062, 1070, 1330, 1367 for tree-transport / tree-malformed / crawl-exception).

Two honest deductions from the finding as filed. First, reservation-refused and publish-refused are unreachable defensive defaults: update() returns false on every failure path, so $reserved === true implies the mutator ran and set either $refusal or $reservation (and $published on the publish path). Those two are not a real coverage gap. Second, the reason is only concatenated into a log line (trackers/rutracker.php:820-824) and passed through remember() (:730) -- a rename cannot change behaviour, only an operator's grep. That is what keeps this at minor and not higher; it is not below minor because the stated purpose of the change is the operator-visible word, the sibling vocabulary is pinned, and the fix is nearly free.

The filed suggestion (a new table-driven case) is workable but not the cheapest correct shape, and the alternative it offers (assert the reason set is a subset of a named constant list) would be worse: dump-* reasons carry a statuses= suffix and reservation-*/publish-* are built by concatenation, so such a check would have to be prefix matching, which cannot catch a mis-wired branch.

**Исправление:** No new test case is needed. Tests that already drive each branch and simply discard the reason exist: ForumIndexTest:1863 (ForumIndexStale304Client -> cache-lost), :1973 (lock-directory open failure -> reservation-unlockable), :1983 (the 1e400 replace failure -> reservation-unwritable), :2063 (fiPoisonForumIndexForReplaceFailure -> publish-unwritable), :2436 (the unreadable counter -> reservation-retired), :3414 (PHP_INT_MAX -> generation-exhausted), plus the promote-failure site. Add a $reason out-parameter and one strictAssertSame to each: one line per site, no new fixtures, and it pins every reachable token. Skip reservation-refused and publish-refused -- both are unreachable defensive defaults, since update() returns false on every failure path and the mutators always set $refusal/$reservation/$published. Do not replace this with a subset-of-a-constant-list check: the dump-* reasons carry a statuses= suffix and the reservation-*/publish-* forms are concatenated, so such a check degenerates into prefix matching and would not catch a token wired to the wrong branch.

### 13. [minor] A third hand-spelling of the chk-forum rule lands in the same commit as the helper, and the drift guard's comment claims it would catch it

**Вердикт:** confirmed-lower-severity

The duplication half is confirmed from the code; the "stale docblock" half is over-stated, and the reviewer missed the sharper instance of the same defect.

CONFIRMED — both sites are new in this commit. `git show master:plugins/rutracker_check/runstate.php | grep -c canonicalForumId` returns 0, so the helper is new. The check.php diff for the same commit is `-$forum = (string) $req->val[5];` -> `+$forumId = RuTrackerRpcValue::canonicalPositiveInt32(trim((string) $req->val[5]));`. So the helper that exists to stop chk-forum being spelled twice, and a third hand-spelling of its exact rule, are in one commit.

LOCATION — off by two lines. The real lines are check.php:1475 (chk-topic) and :1476 (chk-forum), not :1477/:1478. runstate.php:108-131 (docblock) and :133 (function) are exact. The function (createTorrent), the RPC index (val[5] = chk-forum, issued at check.php:1431) and the quoted code all match.

EQUIVALENCE — measured, not inferred. I ran both spellings over 19 inputs (canonical, padded, "007", "0", "-1", "", int, bool, float, null) in /home/dev/.cache/rutorrent-tmp/verify-60/eq.php: `canonicalForumId((string) $v)` differs from `canonicalPositiveInt32(trim((string) $v))` in 0 cases. The reviewer's literal suggestion, `canonicalForumId($req->val[5])` with the cast dropped, differs in 2 (true -> 1 vs null; 22.0 -> 22 vs null). php/xmlrpc.php:214 only ever pushes html_entity_decode() strings into ->val, so production is unaffected either way, but the cast should be kept rather than dropped.

IS IT DELIBERATE? No — the project's own contract test says the opposite. tests/plugins/rutracker_check/ProjectionContractTest.php:330 heads the block "chk-forum has exactly one spelling, and both of its readers use it", and check.php:1465-1474 justifies its canonicalisation by what the successor's readers accept ("'007' names no topic to topicsAwaitingForum() or resolveForum()") — i.e. it is coupled to canonicalForumId's predicate by design, which is exactly the coupling the helper is supposed to own. There is no note anywhere excusing the inline copy.

WHERE THE REVIEWER OVER-REACHES — the docblock is imprecise, not false. "it is here because it has two readers -- resolveForum() and registrationTime() -- which once spelled it differently" reads as provenance, and those two are still the helper's only callers (grep -rn canonicalForumId gives rutracker.php:217 and metafetch.php:777 and nothing else). A maintainer is not sent to a wrong conclusion by that sentence. Calling it "already stale on the commit that wrote it" is a stretch; it does not meet this project's bar for a lying comment.

WHAT THE REVIEWER MISSED, and it does meet that bar. The drift guard's own comment at ProjectionContractTest.php:372-375 says: "If a third reader appears, or one of these stops calling the shared predicate, this fails and names the file." It does not. The test iterates a hardcoded two-file list ($readers = rutracker.php => resolveForum, metafetch.php => registrationTime) and asserts each contains 'RuTrackerRpcValue::canonicalForumId(' and does not contain 'canonicalPositiveInt32(trim('. A third reader appeared in check.php in this very commit, spells the rule by hand, and the suite is green (grep -rn "canonicalPositiveInt32(trim(" finds check.php:1475-1476, runstate.php:138 and the test file itself). That sentence is a false factual claim about the guard's coverage — the defect class this campaign hunts — and it is the half worth fixing.

EXCLUSIONS CHECKED AND CORRECT. forumindex.php:1431-1434 compares `(string) $read->val[1]` verbatim under the forum-map lock for FORUM_WRITE_SUPERSEDED/FORUM_WRITE_CURRENT, so canonicalising there would let " 22" compare equal to "22" — the docblock's "NOT for the stored bytes" is accurate. forumindex.php:1345/1360 likewise keeps raw bytes in $currentForum. updatepass.php mentions chk-forum only in prose and writes it through writeForumMapping(). The neighbouring check.php:1475 is indeed chk-topic (val[4]) and has no helper, so only one of the pair is affected — the reviewer is right there too.

SEVERITY. Minor, not important: nothing is behaviourally wrong today (the two spellings are provably identical), the cost is future drift plus a guard whose comment overstates its reach. Not a nit, because the commit built a guard for precisely this class and the new site slips past it.

AGENTS.md has no rule bearing on this either way (no "spelling"/"predicate"/"single source" entry). No processes leaked; the single pgrep -cf rutorrent-scgi-rpc2 hit was my own shell.

**Исправление:** Two changes, in order of value.

1. Fix the guard's false comment, and preferably its coverage. tests/plugins/rutracker_check/ProjectionContractTest.php:372-375 claims "If a third reader appears... this fails and names the file"; it only inspects a hardcoded two-file list. Either scan every file that contains the literal 'chk-forum' under plugins/rutracker_check/ for 'canonicalPositiveInt32(trim(' (excluding forumindex.php, which is documented as a byte comparator), or reword the comment to what it actually does: "these two files must keep calling the shared predicate; it does not police new call sites."

2. At check.php:1476 use the shared predicate, keeping the cast:
     $forumId = RuTrackerRpcValue::canonicalForumId((string) $req->val[5]);
   This is measured behaviour-identical (0 differences over 19 inputs). Do NOT drop the (string) cast as the original suggestion did — canonicalForumId returns null for bool/float where the current line returns 1 and 22. Leave check.php:1475 (chk-topic) alone; there is no canonicalTopicId, and it is not a forum id.

Leave the runstate.php docblock's enumeration as it is, or, if it is touched at all, say "its two callers" rather than restating a reader count — the sentence is provenance and is not misleading as written.

### 14. [minor] The 'residue' retirement class and the dot-prefixed staging name have no test; both mutations survive

**Вердикт:** confirmed-lower-severity

Reproduced in full in my own copy (git archive export under /home/dev/.cache/rutorrent-tmp/verify-19, disk-backed TMPDIR; target worktree untouched, git status clean; no leaked fixture processes -- pgrep -cf 'rutorrent[-]scgi-rpc2' = 0).

LOCATIONS CORRECT. filesystem.php:505 is `$staging = dirname($path).'/.'.basename($path).'.'.$token.'.tmp';` with the cited comment at 499-504. removewithdata.php:4162-4163 is the `substr($name,0,1)==='.'` -> `'residue'` branch of erasedataClassifyRetirementCandidate().

MEASUREMENTS REPRODUCE EXACTLY. Baseline RemoveWithDataTest.php: 2765 assertions passed, 0 failed, 309 cases -- the finding's numbers to the digit. Mutation A (leading dot removed from the staging name): RemoveWithDataTest, PendingQueueTest, php/ScheduleTest, plugins/ratio/EraseWithDataCommandTest all 0 failures -- SURVIVES. Mutation B ('residue' -> false): same four suites plus plugins/rutracker_check/EntrypointsTest, all 0 failures -- SURVIVES. Control (returning false for 'unknown') fails exactly 3 assertions with exactly the messages quoted, so the sibling branches really are pinned and 'residue' really is the only one that is not. The omission is visible in the test's own invariant string at RemoveWithDataTest.php:9836-9838, which enumerates "pending, staging, final, journal, malformed and unknown" and silently drops residue. grep for 'residue' across tests/plugins/erasedata/ finds only unrelated uses (crash-residue collector cases, "leaves no staging residue" assertions). Nothing in AGENTS.md or in any comment documents the omission as deliberate.

SEVERITY DOWNGRADED to minor. No production defect is demonstrated; the residue class is defense-in-depth rather than a load-bearing guard. A crashed pending-marker durable write leaves residue but no obligation at all (the marker was never renamed into place), so an "empty" verdict there is arguably correct; a crashed manifest-staging write leaves the non-dot <HASH>.<gen>.pending marker, which classifies as 'pending' and refuses retirement on its own. I could not construct a case where residue is the only thing standing between retirement and a wrong "provably empty".

Mutation A's modelled harm is also narrower than the comment at filesystem.php:502-504 claims. Without the dot, the staging of a pending marker is <HASH>.<gen>.pending.<token>.tmp, which does match the collector grammar at removewithdata.php:366 -- but is then refused by the explicit generation-bearing-.tmp guard at 386-388, dot or no dot. The real exposure is the drain worker's unbound-staging scan at removewithdata.php:3640, which would match such a name and strand the hash as `staging-unbound`. So the comment attributes the protection to the wrong consumer, which is a separate (nit-level) observation, not this finding.

SUGGESTION: half correct, one trap and one error. Adding the residue candidate to the array at RemoveWithDataTest.php:9852 does work -- I ran it: unmutated 2767 passed / 0 failed, and under mutation B it fails 2 assertions. But the loop's cleanup is `foreach(glob($queue.'/*') as $stale) @unlink($stale)`, and PHP's glob() does not match dot-prefixed names (verified), so the residue entry survives every later cleanup in that function and contaminates the cases after it; the cleanup must switch to $this->queueEntries(), which uses scandir and does include dotfiles. The reviewer's second half is wrong as written: ErasedataPartialWriteStream (RemoveWithDataTest.php:67-120) never records the path it opened and the writer unlinks the staging on failure, so the dot cannot be observed through it. The staging-name pin belongs where its siblings already are -- a structural assertion on productionFunctionBody('filesystem.php', 'function erasedataWriteDurableFile('), beside the existing fclose/fflush/rename pins at RemoveWithDataTest.php:7151-7160.

**Исправление:** Two cheap additions, both verified here.

(1) Pin the 'residue' class in testRetirementRequiresAStableGenerationAndAProvenEmptyScan (RemoveWithDataTest.php:9852). Add to the $candidates array:

    'residue' => '.'.$this->hash('E').'.0000000000000001.1.list.abc123.tmp',

and, in the SAME edit, change the loop's cleanup from
    foreach(glob($queue.'/*') as $stale) @unlink($stale);
to
    foreach($this->queueEntries() as $stale) @unlink($queue.'/'.$stale);
(and likewise the cleanup at 9872-9873). This is mandatory, not cosmetic: PHP's glob() does not match dot-prefixed names, so without it the residue file survives into the cases below and the test silently stops resetting its own fixture. Measured: unmutated 2767 passed / 0 failed; with erasedataClassifyRetirementCandidate() returning false instead of 'residue', 2 failures ("a surviving residue candidate refuses to scan as empty", "and is recognised as the residue class").

Also update the invariant sentence at 9836-9838 to name residue, since it currently enumerates the other six classes only.

(2) Pin the leading dot structurally, in testDurableWriterRequiresCompleteBytesFlushCloseAndAtomicRename, beside the fclose/fflush/rename pins that already scope themselves to the writer's body (7151-7160):

    $this->assertTrue(is_string($writer) && preg_match(
        '/\$staging\s*=\s*dirname\(\$path\)\s*\.\s*\'\/\.\'/', $writer) === 1,
        'the durable writer stages under a dot-prefixed name, outside the <hash>.<generation>.<...>.tmp grammar');

Do NOT try to observe this through ErasedataPartialWriteStream: that fixture does not record the path it opened, and the writer unlinks its staging on every failure it survives, so there is nothing left to look at.

Optional, separate from the test gap: the comment at filesystem.php:502-504 names the collector as what the dot protects against, but erasedataParseCollectorCandidate() refuses every generation-bearing .tmp at removewithdata.php:386-388 regardless of the dot. The consumer the dot actually keeps the residue away from is the drain worker's unbound-staging scan at removewithdata.php:3640. Worth a one-line correction to the sentence.

### 15. [minor] The float-precision rationale on erasedataIdentityDeviceAndInode() is false, but it is a comment-only defect on unreachable input

**Вердикт:** confirmed-lower-severity

LOCATION AND TEXT: correct. plugins/erasedata/filesystem.php:442-444 carries exactly the quoted sentence, and :458 is `return(array('dev' => (string)$source['dev'], 'ino' => (string)$source['ino']));`.

THE COMMENT IS FALSE, and I reproduced it. On the host php8.5 (PHP_INT_SIZE=8, precision=14; tests/php-test.ini sets only zend.assertions, error_reporting, display_errors, so nothing changes precision for the suite):
- $a=9007199254740992.0; $b=9007199254740993.0 -> $a===$b is true AND (string)$a===(string)$b is true (both "9.007199254741E+15"). Two inodes beyond 2^53 that collide as floats therefore have the SAME decimal spelling, which is the opposite of what the sentence asserts.
- $c=1.0e17; $d=1.0e17+16.0 -> $c===$d false, (string)$c===(string)$d true (both "1.0E+17"). So the cast can merge identities a raw float comparison would keep apart.
The sentence's only coherent reading as a justification for the cast (values arrive as floats; strings separate what floats merge) is therefore false, not imprecise: a maintainer told this would believe the cast is a precision safeguard when for float input it is a precision hazard. Under the charitable reading ("as floats" = compared numerically, input actually int) the second clause becomes true but the first clause is then irrelevant, so the sentence misdescribes the mechanism either way. The premise is also unsupported: PHP populates stat()/fstat() dev and ino as integer zvals on every build (a 32-bit build truncates a 64-bit ino to a 32-bit int rather than promoting it to float) — I could not run a 32-bit PHP here to settle that empirically, so the verdict rests on the reproduced second clause. AGENTS.md:247-249 names "a replacement comment that is itself false" as the recurring defect class and asks for "a shorter true sentence"; nothing in AGENTS.md, the file, or any test documents this as deliberate. The only precision note in the tests (RemoveWithDataTest.php:7094-7097) is about generation hex strings, a different function.

WHY SEVERITY DROPS TO MINOR. The escalation ("this value is the deletion authorisation") is hypothetical. I traced all four production call sites — filesystem.php:293 (@stat), :326 ($expectedIdentity, always a live targetIdentity()/entryIdentity() array from the openDirectoryReference callers at filesystem.php:954, collector.php:311, removewithdata.php:2577), :339 (@fstat), and removewithdata.php:2601-2605 (the capability array, whose dev/ino at filesystem.php:363-364 are this function's OWN strings). Every input has integer dev/ino or already-normalised strings, so the is_float() and is_string() arms at :456-457 are unreachable from production. The genuinely float-capable identities (JSON-decoded journal/manifest records) never reach this function; they are persisted as strings and compared elsewhere (tests/plugins/erasedata/RemoveWithDataTest.php:499, collector.php:299-300). Nothing is mis-authorised on any build PHP produces, exactly as the finding itself concedes.

THE SUGGESTION IS NOT AS ADVERTISED, AND I TESTED IT RATHER THAN ASSUMING. I copied the tree to /home/dev/.cache/rutorrent-tmp/verify-71/tree (TMPDIR=/home/dev/.cache/rutorrent-tmp/verify-71-tmp) and applied branch 1 verbatim — drop the cast, require is_int() on both fields. plugins/erasedata/RemoveWithDataTest.php passes identically to baseline (2765 passed assertions, 0 Failed, exit 0, identical test-name sequence), as do plugins/erasedata/PendingQueueTest.php and php/DeleteDirectoryTest.php. My own prior hypothesis — that is_int() would break the capability round-trip because acquireDirectoryCapability() stores strings — is refuted: the round-trip is self-consistent in either spelling, since the stored value is this function's own output. That cuts both ways: the change is harmless, but it is also unnecessary, and no test pins the cast at all. Branch 2 (sprintf('%.0F')) adds a rendering path for input that cannot occur. The right fix is the one the finding does not name: correct the sentence, and optionally tighten the unreachable arms while saying why.

VERDICT: confirmed as a false load-bearing comment, downgraded from important to minor — comment-only, no reachable behaviour defect, no test pins either shape.

No leaked rutorrent-scgi-rpc2 processes (pgrep -af returned nothing; an earlier `pgrep -cf` count of 1 was my own shell matching the literal pattern). Both worktrees untouched; all experiments under /home/dev/.cache/rutorrent-tmp/verify-71.

**Исправление:** Replace the sentence with the true reason and stop claiming a precision guarantee the cast does not provide, e.g.:

// dev/ino of one identity array, as strings, or false.
//
// Every caller hands in a live stat()/fstat() array, whose dev and ino are
// integers, or an array this function already produced:
// acquireDirectoryCapability() stores its answer at :363-364 and
// removewithdata.php:2601-2605 feeds it straight back. The strings are one
// spelling for both shapes so === can compare them, and (string) of an int is
// exact -- that is the whole of the guarantee. It is NOT a float-precision
// safeguard: (string) of a float renders through precision=14, so it merges
// values a float === keeps apart, not the reverse.

Since no production caller can supply a float or a string, the is_float()/is_string() arms at :456-457 may also be narrowed to is_int() on both fields (verified: RemoveWithDataTest, PendingQueueTest and DeleteDirectoryTest all pass unchanged with that guard) — but only alongside a comment saying the input is proven integral, not as a fix for a precision problem that does not exist here. If float input is ever to be admitted, render it with sprintf('%.0F') and say so; do not leave a bare (string).

### 16. [minor] Six new assertions fail as root; the diff's own root guard is applied to 2 of 6 new chmod-based scenarios

**Вердикт:** confirmed-lower-severity

Every number in the finding reproduced on my own runs, but its severity and its prescription do not survive.

WHAT I MEASURED (ivanshift/rutorrent:latest, --network none, separate `git archive` export per run, TMPDIR inside the container, per-file runner replicating php-test.sh's driver):
- master as root: RemoveWithDataTest.php 1297 passed / 8 failed; ForumIndexTest.php 1 failed ("fetchDump publishes no ETag hint when the dump document did not land").
- 4adfaffa as root: RemoveWithDataTest.php 2744 passed / 12 failed; ForumIndexTest.php 3 failed.
- 4adfaffa as --user 1000:1000, fresh export: RemoveWithDataTest.php 2765 passed / 0 failed; ForumIndexTest.php 112 tests / 0 failures.
The delta is exactly the six sites named: "an acknowledgement that cannot be made durable refuses the tick" (:9705-9706, from the `@chmod($queue,0555)` at :9701), the three at :9971-9977 (from `@chmod($queue,0000)` at :9967), and the two new ForumIndexTest cases at :3604 and :3664 (each dying on its `$GLOBALS['profileMask']=0555` premise line, :3639 / :3681). 2765-2744-12 = 9, which matches the two guards' early `return` skipping 4 assertions in testAnUnsearchableQueueDirectoryIsUncertaintyAndNeverAbsence (:13119,:13121,:13125,:13127 — the 5th the finding counts is :13136, after the finally) and 5 in testAnUnlistableQueueRetainsRatherThanInventingAnEmptyFinalSet. The cited locations are right and the code does what the finding says.

WHY THIS IS NOT "important":
1. Root is not a runner this project supports, and it was never green. .github/workflows/tests.yml runs `bash php-test.sh` on ubuntu-latest as the non-root `runner` user; AGENTS.md's documented image invocation is `docker run --rm --user 1000:1000 ...`. master itself already fails 8 assertions in RemoveWithDataTest and 1 test in ForumIndexTest as root, by the identical DAC_OVERRIDE mechanism, from sites master already ships unguarded (ForumIndexTest.php:384 and :1996 use `profileMask = 0555` the same way). AGENTS.md's own rule for this exact situation — "always run the same suite on the base before calling a failure a regression" — puts these six in the same bucket as the eight already there.
2. The failures are LOUD. Nothing passes vacuously; under the supported runner no coverage is lost. The AGENTS.md rule the finding invokes ("a refusal must be either self-healing or visible") is about silent stalls, and a red assertion is the visible end of that.

WHY THE SUGGESTION WOULD MAKE IT WORSE, and the one real defect I found while checking it: I copied the tree to /home/dev/.cache/rutorrent-tmp/v70-mut and replaced both guard conditions with `if(false)`, then ran the two tests as root. They do not pass vacuously — they fail loudly: testAnUnsearchableQueueDirectoryIsUncertaintyAndNeverAbsence fails 1 assertion ("a state file this process may not look at is uncertainty, never a never-armed queue") and testAnUnlistableQueueRetainsRatherThanInventingAnEmptyFinalSet fails 5. As --user 1000:1000 both are clean with the guards disabled. So the comment the finding quotes as the diff's own knowledge — RemoveWithDataTest.php:13112-13113 and :13454-13455, "chmod cannot restrict root, so under root this scenario cannot be staged at all and a test that ran anyway would pass vacuously" — is FALSE in its second clause, and it is the sole justification for both guards. Those two guards do not prevent a vacuous pass; they convert a loud root failure into a silent pass (`assertTrue(true, 'skipped: ...')`) and swallow 9 unrelated assertions on the way out. Spreading that treatment to the other four sites, as suggested, would hide six more true failures behind six more silent skips.

The finding also mis-censuses the diff. There are three root-handling spellings among the new permission sites, not two: RemoveWithDataTest.php:7268-7277 handles an unsearchable ancestor with an if/else that asserts the correct, different thing in each case and loses no coverage. That is the shape worth copying if root support is actually wanted. It also missed :9876 (`@chmod(...,0500)`, which is uid-independent because 0500 still permits list and search for the owner), so "6 new chmod-based scenarios" undercounts.

Net: a real, small inconsistency, pointing the opposite way from the report. Nothing here blocks the change.

**Исправление:** Do not spread the guard. Either delete the two guards at RemoveWithDataTest.php:13112-13118 and :13454-13461 — measured, that restores 9 assertions under the supported runner's identical semantics and makes the four unguarded sites and these two behave alike (all six then fail loudly under an unsupported root runner, as master's eight already do) — or, if root really is to be supported, restructure all six the way RemoveWithDataTest.php:7268-7277 already does: branch on the measured premise and assert the correct, different outcome in each branch, so no assertion is skipped either way. In either case the sentence "a test that ran anyway would pass vacuously" must go: with the guard removed, root fails 1 assertion in testAnUnsearchableQueueDirectoryIsUncertaintyAndNeverAbsence and 5 in testAnUnlistableQueueRetainsRatherThanInventingAnEmptyFinalSet. If root is to be declared unsupported instead, say so once in AGENTS.md next to the existing `--user 1000:1000` line rather than at six call sites — master's own 8 + 1 root failures are already covered by that statement.

### 17. [nit] "three lines above" points 91 lines away from the only REMOTE_USER assignment

**Вердикт:** confirmed-lower-severity

The cited location is right and the positional claim is false. plugins/erasedata/update.php is 130 lines; its only $_SERVER['REMOTE_USER'] assignment is line 9, inside the isset($argv)/is_array/count>1 guard. The sentence sits at line 100, so "three lines above" is off by 91 lines — lines 97-99 are the tail of the same comment block (about the drain mode word and $argv[2]) and assign nothing. The reviewer's borrowed-phrase theory is plausible: line 5 carries "used to die here, three lines in", which is correct about line 8.

What caps the severity is that the sentence's substantive claim is true. php/utility/user.php:22-26 shows User::getUser() returns getLogin() unless $forbidUserSettings, and getLogin() (line 18-19) derives entirely from $_SERVER['REMOTE_USER']. So the stated reason for preferring $argv[1] over User::getUser() is sound; only the locator is wrong. (Unrelated looseness I am not raising: getUser() answers a sanitized REMOTE_USER — strtolower plus [^a-z0-9\-_] to '_' — not the verbatim value.)

Not deliberate: nothing in AGENTS.md, no test, no adjacent note explains the phrase, and AGENTS.md's "Match The Review Effort To The Change" section names this exact defect class ("a replacement comment that is itself false"), so it is in-class rather than a documented oddity.

Severity downgraded from minor to nit: a maintainer misdirected by the phrase looks up, sees comment prose, and then finds the single REMOTE_USER assignment in a 130-line file — one already made conspicuous by its own five-line comment block. No wrong behavioural conclusion follows, because the claim the sentence makes is correct. Cost is seconds of confusion.

The suggestion needs correcting: "(line 9)" hard-codes an absolute line number into a comment, which is the same rot that produced this finding — that number breaks the next time the guard at the top of the file is edited. A number-free locator is better.

Method note: git was unavailable this session (Bash classifier rate-limited), so I could not independently confirm the reviewer's provenance claim that master carries the line-5 comment but not this one. That claim is not load-bearing for the verdict, which rests entirely on the current file, read in full.

**Исправление:** Replace the clause with a locator that carries no line number: "...a CLI child's User::getUser() answers whatever REMOTE_USER the $argv[1] assignment at the top of this file already set, while the argument the scheduler passed is the one the producer really armed under." Do not write "(line 9)" — an absolute line number in a comment goes stale the next time that guard is edited, which is how the present error reads.

### 18. [nit] "Copied verbatim from php/settings.php:472-484" cites the wrong lines, at both fixture sites

**Вердикт:** confirmed-lower-severity

Verified directly against the target worktree by reading the files (git was unavailable — the Bash safety classifier was rate-limited for the whole session — so I could not confirm the finding's side claim that settings.php is byte-identical on master; that does not affect the verdict, since a comment is judged against the tree it ships in, which I read).

php/settings.php in /home/dev/Documents/my_projects/.rutorrent-worktrees/campaign-integration, numbered: 460 opens getScheduleCommand, 462-481 is its explanatory comment, 482-484 is its three-statement body ($interval = $interval*60; $startAt = self::getAlignedStart(...); return new rXMLRPCCommand(...)). 486 is `static public function getAlignedStart($name,$interval,$now = null)`, 488-497 the body, 498 the close.

tests/plugins/erasedata/CollectorFixture.php:205 reads "the arithmetic is copied verbatim from php/settings.php:472-484", and the code immediately beneath it (207-223) is getAlignedStart carrying the body from 488-497. Line 1559 repeats the identical sentence over the same body emitted as a source string (1563-1573). So the cited range contains neither the copied arithmetic nor anything resembling it — the finding's location, both occurrences, and the code at each are all correct as reported. No comment, test, or AGENTS.md note marks the range as deliberate; AGENTS.md:248-249 in fact names exactly this class ("did anyone open the line the new sentence cites") as the recurring defect here.

Downgraded from minor to nit on harm, not on truth. The load-bearing half of the sentence — that the fixture carries the real core arithmetic rather than an approximation, so an alignment case measures production — is true, and the function it names is spelled out in the code directly below the comment. The correct target sits 2 to 14 lines past the cited range in the same file under a matching signature, so a maintainer is sent to a wrong screen position that self-corrects on the next scroll, never to a wrong conclusion. Nothing behavioural depends on it.

One inaccuracy in the finding's own evidence, which does not change the verdict: the reflow of `if(!isset($schedule_rand)) $schedule_rand = 10;` onto one line happens only at the second site (line 1566); the first copy at 212-213 preserves the source's two-line form. Both copies do differ from the source by spacing ($interval < 1 vs $interval<1) and by wrapping the $offset expression, so "verbatim" is loose at both sites as claimed.

On the suggestion: substituting 486-498 is true today but reinstates the cause — an absolute line citation into a file this change does not touch, which rots on the next unrelated edit to settings.php and would produce the same false comment again. Prefer a symbol citation with no line numbers.

**Исправление:** At both sites, cite the symbol rather than a line range, so the comment cannot rot when settings.php is next edited: "Copied from rTorrentSettings::getAlignedStart() in php/settings.php (reformatted; arithmetic identical)." Per AGENTS.md:237-246 this is a comment-only fix: verify the sentence against the code, run the suite, commit — no implementer/reviewer pair.

### 19. [nit] Evidence document cited for three measured daemon facts is not in this tree

**Вердикт:** confirmed-lower-severity

The observable half of the finding reproduces; the label "false-comment" does not, and the suggested fix is partly unworkable.

VERIFIED
1. Location and text are exactly as reported. plugins/erasedata/removewithdata.php:4108-4132 opens the retirement section with "Three facts, all measured on real 0.9.8 and 0.16.21 daemons (.superpowers/sdd/schedule-semantics-evidence.md), shape every line below".
2. The path is absent. Reading /home/dev/Documents/my_projects/.rutorrent-worktrees/campaign-integration/.superpowers/sdd/schedule-semantics-evidence.md returns "File does not exist".

WHAT THE REVIEWER MISSED, and it changes the verdict
3. `.superpowers/` is deliberately excluded. .gitignore:70-76 of this same worktree reads "# Agent tooling and scratch: sessions, worktrees, skills, task briefs" and lists `.claude/`, `.agents/`, `.codex/`, `.superpowers/`, `tasks/`, `backup/`. So the file is not missing by accident and could never be in a delivered tree; the citation is a pointer into the campaign's own scratch, by the same convention the repo already uses.
4. That convention has committed precedent. AGENTS.md:9 -- a tracked file, not scratch -- says "The local Codex skill for this repository is `.codex/skills/rutorrent-fork/SKILL.md`. Use it together with this file", citing a path under a directory .gitignore:73 excludes. A shipped comment pointing at agent-local provenance is therefore in-house normal, not a new defect introduced here.
5. The suggestion's first branch is wrong. "Commit the evidence file under tasks/" targets another gitignored directory (.gitignore:75); it would need `git add -f` and would contradict the repo's own scratch boundary.

IS ANY SENTENCE FALSE? No. Every factual claim is stated in full in the comment itself, so nothing depends on fetching the doc, and the one claim I could check from this tree holds: "the leading empty target ... is added by rXMLRPCCommand::__construct() only when it is handed the LOGICAL name" -- php/xmlrpc.php:27-31 shows __construct($cmd) doing `$this->command = getCmd($cmd)` and then `rTorrentSettings::get()->patchDeprecatedCommand($this,$cmd)`, i.e. the patch is handed the pre-translation logical name, which is exactly the dependency the comment warns about. Naming the constructor rather than the helper it calls is the entry point a caller actually touches, so it is if anything the more useful of the two names. AGENTS.md's own rule is "when a rule comes from a spec, say so in a comment and say whether it was checked against a capture" -- this comment does precisely that.

CONSEQUENCE. A maintainer who tries the path finds nothing and loses nothing: the facts, their versions and their direction of conservatism are all in the comment, and nothing below depends on the doc. Nobody is sent wrong about behaviour, which is the bar AGENTS.md:237-249 sets for the false-comment class. That is a dangling provenance pointer, i.e. cosmetic. AGENTS.md:241 and :245 also say explicitly that a comment fix does not earn review machinery.

I therefore confirm the observation, refute the "false-comment" classification, downgrade minor to nit, and reject the suggestion as written.

**Исправление:** If touched at all, only the parenthetical changes and only to a dated, self-contained provenance note -- do NOT commit the evidence file, since both `.superpowers/` and `tasks/` are gitignored (.gitignore:70-76) and adding it would require `git add -f` against the repo's own scratch boundary. E.g. "Three facts, all measured on disposable 0.9.8 and 0.16.21 daemons (2026-09, evidence kept with the campaign notes), shape every line below". Not worth a commit on its own; fold it in only if the file is being edited for another reason.

### 20. [nit] openDirectoryReference() alias cited at the wrong lines

**Вердикт:** confirmed-lower-severity

The reviewer's raw evidence reproduces. In plugins/erasedata/filesystem.php, acquireDirectoryCapability() is declared at 324; lines 331-333 are "// Before the handle exists: every descriptor that already names it.", "$before = array();", "foreach($candidates as $index => $root)" — inside that function's body. openDirectoryReference() is declared at 389 (body brace 390, the forwarding "return($this->acquireDirectoryCapability($path, $expectedIdentity));" at 391 — the reviewer's "at 390" is off by one, harmless). So filesystem.php:331-333 is indeed not the alias.

But the comment does not claim it is. Reading the sentence as written (RemoveWithDataTest.php:7327-7334): it names the alias by SYMBOL ("through the openDirectoryReference() alias") and then gives a call-site -> destination pair ("collector.php:309 into filesystem.php:331-333"). collector.php:309 is exactly right — the declaration of erasedataOpenDirectoryReference(), forwarding at 311. And 331-333 lands seven lines inside acquireDirectoryCapability(), the destination the sentence names. A maintainer following the pointer opens the correct file and the correct function; they are not sent to a different function, a different file, or a wrong conclusion.

I also verified the mechanism claim the sentence actually makes, which is true end to end: parseOneItem()'s force branch (collector.php:1223-1248) -> erasedataCompleteForcedDirectory() (633) -> erasedataDeleteRecoveryDirectory() (475) / erasedataCompleteRecoveryLink() (354) -> erasedataOpenDirectoryReference() (309/311) -> ErasedataFilesystemOps::openDirectoryReference() (389) -> acquireDirectoryCapability() (324). Nothing in the sentence misdescribes behaviour.

Calibration on how strictly this house holds line citations: AGENTS.md's own reference pointer, "RuTrackerAnnounce::hasValidSuccessSchema() (plugins/rutracker_check/announce.php:434)", lands in this tree on a comment line about BENCODE_LIMITS ("body cap stop being the bound on decoding cost that it exists to be", const BENCODE_LIMITS at 435) — i.e. the project's own hard-won-rules document already carries a drifted line pointer and tolerates it. The rule AGENTS.md states is about a comment being FALSE ("did anyone open the line the new sentence cites"), and the recurring defect class it names is a replacement comment that misstates behaviour. A pointer that is imprecise-but-inside-the-named-function is not that class.

On the proposed fix: it is accurate, but it adds a THIRD hardcoded line number to a comment whose numbers already rot, and AGENTS.md's Match The Review Effort section explicitly says a comment fix is not worth ceremony and to "prefer a shorter true sentence". The two sourceHas() assertions immediately below (7335-7338) already anchor by symbol name, which does not rot — so if this is touched at all, dropping the numbers is the better edit than adding one.

Verdict: the underlying fact checks out, so I cannot refute it, but the finding's framing ("the alias is cited at the wrong lines" / a maintainer would be sent wrong) overstates it. Imprecise pointer inside the right function, in a comment, with no behavioural claim wrong. Nit; safe to leave.

Note: Bash and Monitor were unavailable for this session (classifier rate-limited), so I could not grep. Everything above was read directly with the file reader; I could not check the comment's separate sub-claim that removewithdata.php "deliberately names neither" symbol, and I did not verify where hasValidSuccessSchema() actually sits in announce.php — only that line 434 is not its declaration.

**Исправление:** If touched at all, drop the rotting numbers rather than add a third — the symbols are the durable pointer and the sourceHas() assertions below already use them:

"...and it reaches ErasedataFilesystemOps::acquireDirectoryCapability() through the openDirectoryReference() alias in filesystem.php."

(The reviewer's own text — "collector.php:309 into filesystem.php:389, which forwards to acquireDirectoryCapability() at filesystem.php:324" — is factually correct if line numbers are kept.)

### 21. [nit] "every function in this file lives inside its own if(!function_exists(...)) guard" — 4 guards hold 5 extra functions

**Вердикт:** confirmed-lower-severity

LOCATION AND FACT: correct. /home/dev/Documents/my_projects/.rutorrent-worktrees/campaign-integration/tests/plugins/erasedata/RemoveWithDataTest.php:12505-12508 reads "Scoped by hand: every function in this file lives inside its own if(!function_exists(...)) guard, so that guard is the only reliable delimiter -- a body taken to the next column-zero \"function \" runs to the end of the file...". I re-derived the pairing myself over plugins/erasedata/removewithdata.php: 102 guards, 107 tab-indented function declarations, 0 column-zero declarations, no classes. Four guards hold more than one function, covering the five the reviewer named -- erasedataReadExactCleanupArtifact(591) holds erasedataReadExactCleanupFile(593)+erasedataReadExactCleanupArtifact(627); erasedataDirectoryLookupsAnswer(1713) holds erasedataDirectoryLookupsAnswer(1732)+erasedataPathLookupAnswers(1742); erasedataEraseRequest(2308) holds erasedataEraseCommandsForHash(2332)+erasedataEraseRequest(2341); erasedataAcquireRemovalCapability(2561) holds three (2567, 2589, 2608). I opened all four regions and read the whole bodies, not the grep hits. So the sentence's universal is literally false.

WHY THE SEVERITY IS NOT "important": the sentence's operative conclusion is TRUE, and it is true at exactly the two places it is used. guardedFunctionBody() has three occurrences in the whole test file -- its definition at 6824 and call sites at 12509 and 12517 -- and both call sites target functions that ARE alone in their guard: erasedataRearmDrainScheduleRun is the only function inside the guard at removewithdata.php:4469 (next guard 4697), and erasedataRearmDrainSchedule the only one inside the guard at 4697 (next guard 4719). Neither appears in the MULTI list. So the extracted bodies are exact, the four assertions at 12511-12520 are checking what they claim, and no test is weakened. The second half of the sentence also measures true: 0 column-zero "function " declarations, so the column-zero delimiter really would run to the end of the file. The over-capture is a hazard only for a future maintainer who points the extractor at 5 of 107 functions; today it harms nothing. Nothing in AGENTS.md or the surrounding tests documents a one-function-per-guard rule, so this is not a deliberate oddity being re-reported -- it is an overreaching sentence. AGENTS.md:237-249 ("It is not worth its cost on a comment... prefer a shorter true sentence") puts this at a one-word fix, not an important finding.

THE SUGGESTION AS WRITTEN MUST NOT BE APPLIED. It says "Five guards do hold more than one function" and then names four (erasedataEraseRequest, erasedataAcquireRemovalCapability, erasedataReadExactCleanupArtifact, erasedataDirectoryLookupsAnswer). The measurement is four guards / five extra functions. Landing it would ship exactly the recurring defect class AGENTS.md:247-249 names -- a replacement comment that is itself false, three rounds in a row. It also asserts "the two used here do [sit in their own guard]", which is true (I checked), but the count next to it is not.

Method note: I read every cited line and re-ran the pairing myself rather than trusting the reviewer's script. I ran no suite (nothing here needs one); pgrep -cf rutorrent-scgi-rpc2 returned 1, and pgrep -af showed that match is my own shell command line, not a leaked fixture server -- nothing to kill.

**Исправление:** Prefer a shorter sentence that claims only what is used, and no count at all:

	// A1: the re-arm builds exactly what its runner reads.
	// Delimited by the guard, and scoped by hand to these two: each is the
	// only function inside its own if(!function_exists(...)) guard. Nothing
	// in this file is declared at column zero, so a body taken to the next
	// column-zero "function " would run to the end of it and be satisfied by
	// any other function's keys. A few guards here do hold more than one
	// function, so this extractor is only ever pointed at one that does not.

If a count is wanted, it must be the measured one: four guards hold more than one function -- erasedataReadExactCleanupArtifact, erasedataDirectoryLookupsAnswer, erasedataEraseRequest and erasedataAcquireRemovalCapability -- covering five extra functions (102 guards, 107 functions). Do not write "five guards".

### 22. [nit] AGENTS.md:128 still cites announce.php:434 after this diff moved hasValidSuccessSchema() to 487

**Вердикт:** confirmed-lower-severity

Every factual claim reproduces. `git grep -n hasValidSuccessSchema master` puts the function at plugins/rutracker_check/announce.php:434 on master, matching the AGENTS.md:128 citation; on HEAD the function is at 487 and line 434 is the closing line of the BENCODE_LIMITS commentary ("// body cap stop being the bound on decoding cost that it exists to be."). The move is caused by this diff: announce.php is +60/-7 and every insertion (logCorruptBudget comment, probeDecision $readable commentary, reserveProbe $failure commentary, the udp-scheme guard) sits above the function, whose body is unmodified — net +53. AGENTS.md gains 61 lines in a single hunk at old line 187, so line 128 is neither edited nor shifted. So the citation is genuinely stale and this branch made it stale.

I downgrade to nit rather than minor for three reasons. First, the sentence's substantive claim is true on HEAD: I read 487-499 and the function requires only `interval` and `peers`, then validates `complete`/`incomplete`/`min interval` only when present — exactly "require only what the protocol guarantees, and validate each optional field only when it is there". A maintainer is not sent to a wrong conclusion about behaviour, only to the wrong line in the right file; this is not the false-comment class the register exists for. Second, the symbol is named in full and is unique in the tree (class RuTrackerAnnounce at announce.php:16), so recovery is one grep and the reader lands 53 lines from the target inside the same class. Third, precise line citations are not a maintained invariant here: AGENTS.md has three, and `php/xmlrpc.php:235` (line 179) is already a closing brace — the $rpcLogFaults block is at 228-233 — and php/xmlrpc.php is untouched by this diff, so that citation was already off on master.

The finding's framing that this is "the exact rot class AGENTS.md itself warns about" overstates: AGENTS.md's rule at line 249 is about opening the line a NEW sentence cites so a replacement comment is not itself false, not about re-anchoring existing citations when code moves.

The suggested fix (:487) is correct today but rebuilds the same fragility; the better fix is to drop the line number entirely, which matches how the rest of AGENTS.md names code and cannot rot. Nothing depends on the number, so removing it breaks nothing.

**Исправление:** Prefer dropping the line number over re-anchoring it: `RuTrackerAnnounce::hasValidSuccessSchema()` (`plugins/rutracker_check/announce.php`) is the reference shape: ... The symbol name is unique and stable, so the pointer cannot rot again on the next insertion above the function. (`:487` is also correct as of 4adfaffa if a line number is wanted.) While in the file, `php/xmlrpc.php:235` at AGENTS.md:179 is likewise off — the `$rpcLogFaults` block is at 228-233 — but that one predates this branch.

### 23. [nit] AggregateEraseFixture header says "Two rules" above a three-item list

**Вердикт:** confirmed

Location verified: tests/plugins/erasedata/AggregateEraseFixture.php:24 is exactly `// Two rules it exists to keep honest:`. The list under it holds three `*` bullets, at lines 26, 29 and 34, with three distinct subjects (the presence table is the sole oracle; a departed client does not cancel the batch; a client that lost its transport is never told the batch succeeded). Bullets 1 and 2 end in `;` and bullet 3 in `.`, so it is one deliberately punctuated three-item list, not a wrapped second item.

Checked for deliberateness and found none: `grep -rn "rules it exists|Two rules|Three rules"` over the whole worktree returns only this line, AGENTS.md never mentions this fixture, and the branch is a single squashed commit so there is no prior version in which the list had two items. No test or note depends on the wording.

Checked whether the fix should instead be deleting a bullet: no. Bullet 3's factual citation reproduces — php/scgitransport.php returns 'closed-before-headers' at line 201 and 'truncated-body' at line 226, both inside the cited 200-230 range — so all three bullets are true and load-bearing.

Severity stays at nit: the sentence is factually false by the project's own standard (a count that does not reproduce), but each rule is stated in full and correctly, so a maintainer is misled only about the number, not about any behaviour. The one risk is someone "fixing" the count by deleting a bullet; changing the word is the safe direction.

**Исправление:** Change "Two rules it exists to keep honest:" to "Three rules it exists to keep honest:" at tests/plugins/erasedata/AggregateEraseFixture.php:24. Do not resolve it the other way — all three bullets are true (bullet 3's cited failure codes 'closed-before-headers' and 'truncated-body' are at php/scgitransport.php:201 and :226) and none is removable.

### 24. [nit] AGENTS.md's leak check answers 1, not 0, when run through a `bash -c` wrapper

**Вердикт:** confirmed

Location is right. `AGENTS.md:204` is inside the "This Machine Will Lie To You About Test Results" section added wholesale by this diff (`git diff master..HEAD -- AGENTS.md` = 61 insertions, 0 deletions), and reads: "Check with `pgrep -cf rutorrent-scgi-rpc2`; a healthy idle machine answers 0."

The pattern itself is correct. `SCGITransportTest::runCopiedRpc2()` (tests/php/SCGITransportTest.php:836) builds its tree as `sys_get_temp_dir().'/rutorrent-scgi-rpc2-'.uniqid()` and launches `php ... -S 127.0.0.1:<port> -t <tree>` (line 893-896), so the literal really is in the server's command line. I confirmed a simulated `php -S ... -t .../rutorrent-scgi-rpc2-fake` is matched by both the current and the suggested pattern (count 1 each), and that killing it by PID returns the count to 0.

The reported behaviour reproduces, but the stated MECHANISM is wrong and that changes how the finding should be worded. `pgrep` does exclude itself: I ran `pgrep -cf "$P"` with `P` assembled from parts, so `pgrep`'s own argv contained the literal `rutorrent-scgi-rpc2` while the invoking shell's argv did not — result 0, exit 1. `bash -c "pgrep -cf $P"` also gave 0. So it is NOT true in general that "the shell that is running the pgrep" matches; an interactive `bash` has argv `bash`, not the pattern, and a plain shell genuinely answers 0 — the sentence is true as written for a human at a terminal.

What is true is narrower: when the whole command is passed as one argv element of a wrapper (`/bin/bash -c '... eval "pgrep -cf rutorrent-scgi-rpc2 ..."'`), that wrapper's command line contains the literal and pgrep counts it. That is exactly how an agent's Bash tool invokes it here. Measured in this session on an idle machine with zero servers: `pgrep -cf rutorrent-scgi-rpc2` → 1, and `pgrep -af` showed the sole match was my own `/bin/bash -c ... eval 'pgrep -cf rutorrent-scgi-rpc2 ...'`. Since AGENTS.md is addressed to agents, the "answers 0" baseline is wrong for its actual primary reader.

Nothing in the repo documents this as deliberate. AGENTS.md carries a second self-matching `pgrep -f` at line 348 (the docker-build wait loop, pre-existing, not part of this diff), which suggests the idiom was used without the hazard in mind rather than in spite of it.

Severity stays at nit and I would not raise it. The error is a constant +1: the count is still monotone in the real leak count, so the check never HIDES a leak, and the spurious match names itself the instant anyone runs `pgrep -af`. Worst case is a short detour, or — if someone acts on the count by killing the single PID — killing their own shell, which is the incident this project has already recorded.

The suggested fix works but is not free. `pgrep -cf '[r]utorrent-scgi-rpc2'` measured 0 on a clean machine and 1 with a simulated leaked server, so it neither self-matches nor loses a real one. However it flips the exit status on a clean machine from 0 (one self-match) to 1 (`pgrep -c` exits 1 when it counts nothing), which will bite anyone who chains it with `&&` or runs it under `set -e`. Given the house rule three lines further down ("prefer a shorter true sentence"), the reason is worth more than the bracket: a maintainer who knows WHY the 1 appears is safe with either pattern.

One thing outside this finding's claim, noted for completeness rather than as a defect: the check covers only the copied-rpc2 `php -S` servers. The other named fixture, `SCGITransportFixture::boot()`, uses a directory prefix of `rutorrent-scgi-` without `rpc2` (line 134), so its peer processes would not be counted. Both now prefix `exec ` and neither leaks today, and the 785-process incident the bullet describes was the `php -S` kind, so the pattern does target the known leaker.

**Исправление:** Keep the sentence true for both readers and carry the reason, e.g.: "Check with `pgrep -cf '[r]utorrent-scgi-rpc2'` — the brackets keep the checking command's own line out of the match, because a tool wrapper passes the whole command as one argv element. A healthy idle machine answers 0 (and `pgrep -c` exits 1 when it counts nothing, so do not chain it with `&&`). Without the brackets an idle machine answers 1: run `pgrep -af` and you will see the single match is your own shell — never kill that PID."

### 25. [nit] CollectorFixture mirror header says "ten" plugin files; pluginFiles() returns eleven

**Вердикт:** confirmed-lower-severity

I could not refute it. The count is wrong, and I verified it by execution rather than by eye.

Location is right. `/home/dev/Documents/my_projects/.rutorrent-worktrees/campaign-integration/tests/plugins/erasedata/CollectorFixture.php:667` reads "...and copies the ten production plugin files byte for byte, so a child really executes the shipped action.php / erase.php / update.php / init.php bytes...". `ErasedataProductionMirror::pluginFiles()` at line 863 returns action.php, collector.php, conf.php, done.php, erase.php, filesystem.php, init.php, manifest.php, pending.php, removewithdata.php, update.php. Running it: count=11; the set is byte-identical to `glob('plugins/erasedata/*.php')` (also 11). So eleven, not ten.

I looked for a reading that would make "ten" true and found none:
- `build()` copies all 11 from `pluginFiles()` plus `php/xmlrpc_path.php` (12 copies total), so the number is not "10 plugin + 1 core".
- `writeAdapters()` (line 1271) writes only `php/util.php` and `php/xmlrpc.php` — nothing under `plugins/erasedata/`, so no copied plugin file is later replaced by an adapter, which would have made ten the surviving count. `conf.php` in particular is read back as real production bytes by the generated `FileUtil::getPluginConf`.
- Nothing else writes into `$mirror->pluginDir`; the only other uses are child-process invocations of update.php / erase.php / action.php / done.php / init.php.

Not deliberate and not pre-existing: the entire `ErasedataProductionMirror` class and this header block are new in 4adfaffa (master's CollectorFixture.php is 733 lines with only three classes, none of them the mirror, and has no `pluginFiles()`). The likely origin of "ten" is that this same commit touches exactly ten files under plugins/erasedata/ (done.php is untouched) — an inferred number, not a measured one, which is precisely the failure mode AGENTS.md warns about.

Severity is where I disagree with the reporter. Nothing derives from the number: no test asserts a count (the three callers in RemoveWithDataTest.php iterate the list and assert per-file properties), no logic branches on it, and the authoritative list sits 196 lines below in the same class. A maintainer reading "ten" takes no wrong action — unlike the behavioural comment lies this register was opened for, which misdirect debugging. It is a false factual claim with no consequence: a nit, not minor.

One caution on the suggested wording. "every *.php in plugins/erasedata" is true today (verified identical above) but `pluginFiles()` is a hand-maintained literal with no test pinning it against the directory, so that phrasing converts a harmless stale number into a false invariant the moment someone adds a plugin file and forgets the list. Dropping the count entirely is the wording that cannot go stale.

No suite run, so no scgi processes were started (`pgrep -cf rutorrent-scgi-rpc2` not applicable); the tree was not modified.

**Исправление:** Delete the count rather than correcting it: "...and copies the production plugin files byte for byte, so a child really executes the shipped action.php / erase.php / update.php / init.php bytes...". "eleven" is accurate today but goes stale on the next added file; "every *.php in plugins/erasedata" asserts a directory/list synchronization that no test enforces, so it would become the next false comment. If that synchronization is wanted, pin it with an assertion comparing pluginFiles() against glob('plugins/erasedata/*.php') and then the wording is safe.

### 26. [nit] Author/creation-date restore blocks in edit/action.php and nnmclub.php are not pinned by any test

**Вердикт:** confirmed-lower-severity

The mutation results reproduce exactly. Deleting the restore loop from plugins/edit/action.php left EditActionSequenceTest at 28 passed / 0 failed; deleting it from plugins/rutracker_check/trackers/nnmclub.php left NNMClubHandlerTest at 30 tests / 0 failures, the named case included. The mechanism is as stated: php/Torrent.php:28 initialises $built=false, only the constructor's build branch (:153) sets it, and touch() (:689) returns before setMeta() when it is false, so on a decoded torrent both loops write back what is already there. So the narrow core -- no test in the suite fails if either block is deleted -- is true.

The finding's FRAMING is what fails. Its premise is that three tests "carry the hunks' names" and so falsely appear to pin them. The names are testAnEditKeepsTheTorrentsOwnAuthorAndCreationDate, testAnEditInventsNeitherKeyForATorrentThatCarriedNone, and 'the passkey patch leaves the guest torrent own author and creation date alone'. None names a snapshot or a restore; each names an observable end-to-end property of the plugin, and each property is true and is verified. The suggested fallback -- "rename the two edit tests so they no longer claim to pin action.php" -- answers a claim the names do not make, and would be wrong on its own terms: the edit tests DO pin action.php.

The claim that these tests attest "to nothing in the two plugin files" is false as written. editSequenceSource() (EditActionSequenceTest.php:52-114) cuts the real editing block out of action.php on disk and eval()s it, with guards on the extent of the cut. My own control mutation -- appending 'X' inside the comment setter in action.php -- produced 5 failed assertions. The tests attest to a great deal in action.php; they merely cannot distinguish "restored" from "never stamped". Likewise the NNM case carries its own explicit guard that the passkey patch really ran.

It is also deliberate and disclosed in the exact place a reviewer must read. EditActionSequenceTest.php:377-381 says in plain words: "On this fork that restore is a no-op -- Torrent::touch() writes nothing on a torrent the class did not build -- and it is what keeps this assertion true against upstream Novik/ruTorrent's Torrent, where every setter stamps both keys." Both production comments (action.php:106-118, nnmclub.php:372-380) say the same, with the upstream-handoff reason. So no maintainer is sent wrong; the sentence explicitly states that the restore's value lies against upstream, i.e. precisely the case the suite cannot reach. AGENTS.md treats re-reporting a documented deliberate oddity as noise.

Severity is not honest at "important". Deleting both blocks changes zero bytes this fork ever writes; the whole exposure is a hypothetical upstream install. The protection against a "dead code" cleanup already exists in a form more robust than a test -- a fourteen-line paragraph naming the consequence of the deletion. The suggestion's supporting parenthetical is also wrong for these two suites: NNMClubHandlerTest and EditActionSequenceTest both use the real php/Torrent.php (TestLib.php:695's TorrentEncoder is a real subclass exposing encode(); only CheckerTest.php:47 defines a Torrent double). The suggestion is nonetheless feasible -- nnmQueueGuestParse() queues the very object the handler patches, so a touch()-overriding subclass would slot in -- and would break nothing; it would add a locally invented stand-in for a foreign class in order to pin a no-op.

Worth noting for context: metafetch.php:632-735 carries the same snapshot/restore pattern and there it IS load-bearing (datePublishedTorrent() chooses a date from the dump's reg_time when the harvest carried none), so the pattern is not uniformly inert.

Verdict: the mutation fact is confirmed; the characterisation of the tests and the severity are not. A nit-level test-coverage gap on a block that is a documented no-op on this fork.

**Исправление:** Leave both blocks and both test names as they are. If the executable pin is wanted despite the zero on-fork exposure, the cheapest honest form is a single new case (not a rename, and not three) that constructs a Torrent subclass overriding touch() to stamp unconditionally -- upstream's shape -- and drives one edit through it, asserting the file's own 'created by'/'creation date' survive. EditActionSequenceTest::replay() already takes the Torrent from the test body, and nnmQueueGuestParse() already queues the object the NNM handler patches, so either suite can take it without new plumbing. Name it for what it pins ("the restore is what survives a setter that stamps, as upstream's does") so it does not repeat the confusion this finding rests on.

### 27. [nit] The duplicate-key guard is verdict-redundant and its two test rows are refused by the arity check — but it is hardening, not dead code, and 5 of the 8 rows share that fate

**Вердикт:** confirmed-lower-severity

Every factual claim in the finding reproduces; the framing and the suggestion are where it overreaches.

LOCATION IS RIGHT. plugins/erasedata/pending.php:104-105 is `if(array_key_exists($key, $fields)) return(false);` inside the parse loop of erasedataDecodePendingMarker(). tests/plugins/erasedata/PendingQueueTest.php:887 is `'a duplicated key' => $good . "force=2\n"`.

THE REDUNDANCY IS REAL, AND PROVABLE. Line 95 requires exactly 4 lines; lines 108-110 require all four of version/generation/hash/force. A 4-line record with a duplicated key has at most 3 distinct keys, so by pigeonhole one required key is missing and the missing-key loop returns false. There is no input on which the guard can change the return value.

MUTATION REPRODUCED. In my own copy (/home/dev/.cache/rutorrent-tmp/verify-20/tree) I deleted lines 104-105 and ran the suite with TMPDIR=/home/dev/.cache/rutorrent-tmp/verify-20-tmp:
- PendingQueueTest.php: exit 0, 120 Passed, 0 Failed — SURVIVED.
- RemoveWithDataTest.php (the other consumer of the decoder): exit 0, 2765 Passed, 0 Failed — SURVIVED.
Direct probe with the guard deleted: "version=1\nhash=<40>\nforce=1\nforce=2\n" -> false, "version=1\nversion=1\ngeneration=…\nhash=…\n" -> false, and no PHP warning is emitted (the missing-key loop at 108 still fires before any $fields[...] read). So removing it is behaviour-identical and warning-identical.

THE TEST ROWS ARE MISLABELLED — AND IT IS WORSE THAN REPORTED. I counted the lines of every row in $broken. Five of eight never reach any check past the arity gate: 'an unknown key' (5 lines), 'a duplicated key' (5), 'a missing version' (3), 'a truncated record' (3), 'trailing rubbish' (6, because $good already ends in "\n"). Only 'an uppercase generation', 'a short generation' and 'a lowercase hash' are 4 lines and reach the field validators. So nothing in the whole suite exercises the duplicate-key guard, and nothing exercises the missing-key loop either.

WHERE THE FINDING OVERREACHES.
1. "dead-code" is the wrong class. The guard is executed on every duplicate line, is O(1), and encodes a contract the file states in prose at pending.php:84-86 ("no unknown key, no duplicate key, no missing key"). It is redundant, not unreachable, and its comment is not false: a record with a duplicate key genuinely is refused. AGENTS.md:127 ("duplicate checks still apply") is the house norm for strict parsers here, so belt-and-braces in a codec is the documented posture, not an oddity.
2. The primary half of the suggestion — "drop the guard" — would be a disimprovement. It turns an explicitly stated invariant into an emergent property of `count($lines) !== 4`, in a file whose own header (pending.php:40-43) budgets bytes for "a future field". A later schema change that made any field optional would silently re-admit duplicates, and nothing in the suite would notice.
3. The assertion message 'a marker with a duplicated key is refused' is TRUE of the bytes it is given (they do carry force twice, and they are refused). It is imprecise about which check fires, not a lie that would send a maintainer wrong. By the project's own FALSE-vs-imprecise split that is a nit.
4. The reviewer's fallback suggestion ("relabel to 'a fifth line'") is only half a fix and would leave a wrong impression of its own: even a correct 4-line duplicate fixture cannot kill the guard mutation, because the missing-key loop returns false anyway. No test can ever pin this guard.

WHAT I CHECKED AND FOUND FINE: the decoder has exactly one production caller (pending.php:284, erasedataPendingObligations) and it passes raw file bytes, so no caller relies on a distinguishable rejection reason; force parsing is separately and properly pinned by RemoveWithDataTest::testForceTwoKeepsItsIntegerTypeThroughEveryDurableRecord with real 4-line fixtures; no other test file references 'duplicated key'. No leaked processes: the single pgrep -cf rutorrent-scgi-rpc2 hit was my own shell (confirmed with pgrep -af, PID 265261), and nothing was killed.

NET: the observation is correct but small, its class label is wrong, and its headline remedy would remove a legitimate guard. Worth acting on only as a test-hygiene nit.

**Исправление:** Keep the guard at pending.php:104-105 — it is cheap hardening that makes the "no duplicate key" clause of the comment at pending.php:84-86 explicit rather than an accident of the arity check, and dropping it would leave that invariant depending on `count($lines) !== 4` alone.

Fix the test instead, at tests/plugins/erasedata/PendingQueueTest.php:885-893. Five of the eight $broken rows ('an unknown key', 'a duplicated key', 'a missing version', 'a truncated record', 'trailing rubbish') are all refused by the same `count($lines) !== 4` gate while their labels name five different mechanisms. Either rename those five so they say what they exercise (e.g. 'a fifth line', 'too few lines'), or better, add genuine 4-line rows that reach the parse loop, e.g.
  'an unknown key in place of force' => "version=1\ngeneration=0000000000000001\nhash=".$h."\nsurprise=1\n",
  'a duplicated key in place of generation' => "version=1\nhash=".$h."\nforce=1\nforce=2\n",
and keep one explicitly arity-labelled row.

Note honestly in the test or in a one-line comment beside the guard that no input can distinguish the duplicate-key guard from the missing-key loop (a 4-line record cannot both duplicate a key and carry all four), so the guard is deliberate belt-and-braces and is not expected to be mutation-killable. Do not claim a test covers it.

### 28. [nit] state.php update()'s flock-failure branch is untested; only the openShared branch pins 'unlockable'

**Вердикт:** confirmed-lower-severity

The factual core reproduces, and more strongly than reported — but it describes an undrivable defensive branch, not a weak test, and the proposed remedy is the wrong shape.

WHAT I VERIFIED (all in my own copy, /home/dev/.cache/rutorrent-tmp/verify-23, a `git archive HEAD` export; the worktree was never touched):

1. Location is right. `plugins/rutracker_check/state.php` assigns `$failure = 'unlockable'` at line 354 (openShared returned false) and at line 362 (flock LOCK_EX returned false). `grep -rn unlockable` over the tree finds exactly one assertion on the value, `tests/plugins/rutracker_check/StateTest.php:463`, plus one classifier read in `forumindex.php:1213` and a comment in `announce.php:301`.

2. The mutation survives — and the reviewer under-measured it. I deleted the line-362 assignment and ran the FULL suite (`bash php-test.sh`, TMPDIR disk-backed): rc=0, 844 `ok -` lines, no `not ok`, no Fatal. So it is not just the four files the reviewer ran; nothing in the suite touches that branch.

3. But the contract it belongs to IS pinned, twice. Restoring line 362 and deleting the line-354 assignment instead: StateTest rc=1 (`not ok - update() says WHY it did not happen...`, 16 tests / 1 failure) AND ForumIndexTest rc=1 (`not ok - a crawl window that could not be recorded is not reported as another crawl holding it`, 112 tests / 1 failure). So the cited test is not weak — it dies the moment the reachable label is removed, and the `forumindex.php:1213` classifier that consumes the label is pinned as well. The AGENTS.md rule the "weak-test" kind invokes ("a test that passes with its production change reverted is worthless") does not fire here: what is uncovered is a second implementation site of an already-pinned contract.

4. That site cannot be driven from the suite. `flock($fp, LOCK_EX)` is called on a handle `openShared()` has already returned non-false for; on a local filesystem flock(2) can only fail with EBADF/EINVAL/EINTR/ENOLCK, none of which PHP can produce here, and the suite fakes no syscalls. The test at 463 correctly reaches the only site it can (dir under a regular file → ENOTDIR in `fopen`). The project already reasons about exactly this limit in the same file: StateTest.php:197 says the single-process cases "would also pass with the flock deleted outright", which is why the two-process barrier case exists.

WHY THE SUGGESTION SHOULD BE DECLINED AS WRITTEN. It asks for a sentence in production source stating that the branch is defensive/uncovered. Two problems. (a) It is a coverage claim, not a behaviour claim, and this file's comments are behaviour claims; a coverage sentence rots silently the first time anyone adds a case, and this campaign's recurring defect class is precisely a replacement comment that is itself false. (b) The premise behind it ("close to unfailable") is a local-filesystem statement, and this very class is written for split scheduler/web-server installs sharing one settings path (see the cross-user umask/chmod reasoning in `openShared()` and `dir()`); on a lock-less mount — NFS without lockd, some FUSE — flock can return false and that label is then the difference between forumindex reporting "another crawl already holds this window" and "unwritten". Labelling the branch "defensive" invites a later reader to trim it. The existing docblock already covers both sites honestly and at the right altitude: "'unlockable' (no guard could be established)".

Net: the observation is true and cleanly reproducible, but it names no defect in the shipped code, the test it calls weak is load-bearing, the uncovered branch is unreachable from any in-process test, and acting on the suggestion would add a rot-prone claim. Nit at most; AGENTS.md's "Match The Review Effort To The Change" explicitly says review is not worth its cost on a comment.

**Исправление:** No change. If anything is done at all, do not add a coverage claim to state.php: the branch is unreachable from any in-process test on a local filesystem but genuinely reachable on a lock-less mount (NFS without lockd), so a comment calling it "defensive" would invite a future reader to trim a guard that still matters for the split-user installs this class is written for. The existing docblock line — "'unlockable' (no guard could be established)" — already covers both assignment sites correctly.

### 29. [nit] erasedataUnlockObligations() promises a return value nothing reads and nothing tests

**Вердикт:** confirmed-lower-severity

The measurable half reproduces; the framing does not, and the suggested fix does not work.

WHAT IS TRUE (verified in /home/dev/Documents/my_projects/.rutorrent-worktrees/campaign-integration)
- The four production call sites are bare statements: pending.php:354, pending.php:364, removewithdata.php:1856 (inside erasedataReleaseAdmissionLocks), removewithdata.php:2700. Read all four; none assigns or tests the result.
- The two test call sites are bare too: PendingQueueTest.php:496 and the generated drain-runner string at :954. No assertion reads it.
- Mutation reproduces, and wider than reported. I exported the tree to my own copy, replaced `return($released)` with `return(true)` at pending.php:391, and ran every erasedata-touching suite, not just PendingQueueTest: php/ScheduleTest, PendingQueueTest, RemoveWithDataTest, ratio/EraseWithDataCommandTest, and the four rutracker_check suites -- all exit=0, zero failures. The accumulation is genuinely unobservable. (No scgi processes leaked; the one `pgrep -cf` hit was my own shell. Target worktree left clean, scratch tree deleted.)

WHY THE FINDING IS OVERSTATED
1. The comment is NOT false, so `kind: false-comment` is wrong. The docblock is at 372-373 (the finding says 370-372; off by two, and 370 is the closing brace of erasedataLockObligations). It reads "Release what erasedataLockObligations() took, innermost first. True only when every lock really came off." I ran the branches: non-array -> false; non-resource handle -> false; failed LOCK_UN -> false; fclose !== true -> false; otherwise true. That is exactly what the sentence says. A maintainer who reads it and then reads the body is told the truth. This is not the campaign's recurring defect class (AGENTS.md, "Match The Review Effort To The Change": a replacement comment that is itself false, i.e. one citing a line that does not do what it claims). Filing it under that class would send the register wrong.

2. It is a package-wide idiom, not a one-off. The sibling release primitive erasedataReleaseDrainStateLock() (removewithdata.php:1837) has the identical shape -- computes a bool release status and returns it -- and I grepped every one of its ~20 call sites: not a single one reads the return either. erasedataReleaseAdmissionLocks() also discards it. Singling out one member of a uniform family as "complexity that is not paying for itself" is inconsistent; acting on it would make the package less uniform, and the 3 lines of accumulation are not complexity worth a register entry.

3. The suggestion does not fix what the finding measured, and its premise is wrong. It proposes asserting the return in testAPartlyTakenLockBatchReleasesEveryLockItHadTaken "(it already builds the partial-release scenario)". It does not, for this purpose: in both refusal branches the unlock happens INSIDE erasedataLockObligations, whose own return is `false` regardless, so no caller ever sees an unlock return there. The only caller-visible unlock in that test is the healthy 3-handle batch at :496, and asserting `=== true` on it passes identically under the `return(true)` mutation -- a vacuous assertion, exactly the worthless-test class the house standard names. Worse, the test harness cannot currently produce a failing release at all: ErasedataLockProbeStream::stream_lock is written as `if ($operation !== LOCK_UN && in_array($this->name, self::$lockFails, true))` (PendingQueueTest.php:1088), i.e. it deliberately never fails LOCK_UN. Making the return observable would mean extending the probe wrapper, not adding an assertion.

4. The void alternative is not obviously better either: erasedataUnlockObligations() is a listed contract entry point (RemoveWithDataTest.php:6705 API block), and a release primitive reporting whether the release succeeded is the shape every other release primitive in this package has.

RESIDUE. What survives is a nit: an accurate comment documents an accurate status value that no current caller consumes -- shared with erasedataReleaseDrainStateLock(). Not a false comment, not minor, and not worth the churn either suggestion would cost.

**Исправление:** Leave the function and the comment as they are. If anyone ever wants the status observable, the honest version is a package-level decision, not a one-function edit: either the release primitives (erasedataUnlockObligations and erasedataReleaseDrainStateLock alike) get a caller that logs a failed release, or both stay status-returning by convention. A test that would actually be load-bearing has to drive a failing LOCK_UN, which first requires dropping the `$operation !== LOCK_UN` exemption in ErasedataLockProbeStream::stream_lock (PendingQueueTest.php:1088); asserting `=== true` on the healthy batch at :496 would survive the `return(true)` mutation and is worth nothing. Do not file this as a false-comment: the sentence at pending.php:372-373 is accurate.

### 30. [nit] AGENTS.md:128 cites announce.php:434 for hasValidSuccessSchema(), which this commit moved to :487

**Вердикт:** confirmed-lower-severity

I tried to refute this and could not refute the core fact, but the finding's own supporting detail is wrong and its severity is overstated.

CONFIRMED, by measurement:
- `git show master:plugins/rutracker_check/announce.php | grep -n hasValidSuccessSchema` -> 434. At HEAD -> 487. AGENTS.md:128 still says `plugins/rutracker_check/announce.php:434`.
- The citation is one commit old: `git log -S 'announce.php:434' -- AGENTS.md` -> f06b1fca "Track rt-lab.sh, ignore operator captures, and name the real announce validator", i.e. master's tip. This commit invalidated a locator that the immediately preceding commit added on purpose.
- The shift is genuinely this commit's doing and entirely above the function: every hunk in `git diff master..HEAD -- plugins/rutracker_check/announce.php` is at @@ -150, -220, -243, -252, -357 (net +53 = 487-434), all above the function. Nothing below it moved.
- `git diff master..HEAD -- AGENTS.md` is one hunk, @@ -187,6 +187,67 @@, pure addition; line 128 was carried through untouched.

REFUTED, one supporting claim: "In this tree line 434 is inside `entryFor()`'s canonicalisation loop". False. `awk 'NR==434'` gives `    // body cap stop being the bound on decoding cost that it exists to be.` — the last line of the BENCODE_LIMITS docblock, 53 lines above the function it used to name. `entryFor()` is at line 126, roughly 300 lines away. The reviewer did not open the line they said was wrong — which is exactly the check AGENTS.md's own new section (added by this same commit) says is the one that pays.

WHY THIS IS A NIT, NOT MINOR:
1. The sentence's substantive claim is TRUE. I read the whole function (announce.php:487-499): it requires only `interval` and `peers`, then validates `complete`, `incomplete`, `min interval` only when `!== null`. That is precisely "require only what the protocol guarantees, and validate each optional field only when it is there." No maintainer is sent to wrong behaviour — only to a wrong offset in the right file, with the unique function name printed in the same parenthetical (`grep -rn hasValidSuccessSchema` returns exactly one definition).
2. This is not the house's tracked defect class. That class is "a replacement comment that is itself false" about behaviour. A drifted locator beside a correct name is a different, much cheaper thing.
3. Stale line numbers are pre-existing background noise in this file, not an invariant this commit broke. AGENTS.md:179 cites `php/xmlrpc.php:235` for the `$rpcLogFaults` logging; lines 233-237 are four `break;` statements — the guard is actually at 228-229. `git diff master..HEAD -- php/xmlrpc.php` is empty, so that one drifted before this campaign and nobody caught it. AGENTS.md documents no convention on line numbers (no "approximate"/"line number" note anywhere in it).

The suggestion is a safe improvement and breaks nothing — the function name is unique and survives refactors — but fixing only :434 leaves :235 wrong, so it should be done as one sweep of all three citations or not called a fix.

**Исправление:** Drop the line numbers from AGENTS.md's file citations rather than re-pinning them, and do all of them in one pass, since two of the three are already wrong:

- AGENTS.md:128 -> `RuTrackerAnnounce::hasValidSuccessSchema()` (`plugins/rutracker_check/announce.php`) — the name is unique in the tree, one grep finds it.
- AGENTS.md:179 -> `php/xmlrpc.php:235` is stale too and this commit did not cause it: `$rpcLogFaults` is at 228-229; 233-237 are `break;` statements. Cite `$rpcLogFaults` in `php/xmlrpc.php` by name.
- AGENTS.md:178 -> `php/xmlrpc.php:99` is currently correct (99 is `global $rpcLogCalls;`, 100 the `toLog`), but it will drift the same way; same treatment.

Per AGENTS.md's own new "Match The Review Effort To The Change" section this is a docs edit: verify the sentence against the code, run the suite, commit — no implementer/reviewer pair.

### 31. [nit] Four of fetchDump()'s new failure literals cannot be produced

**Вердикт:** confirmed-lower-severity

Reachability claim verified correct; the harm claim is false and the primary suggestion is harmful.

VERIFIED. state.php:339-385 update() sets $failure=null on entry and has exactly four returns: 'unlockable' (openShared false), 'unlockable' (flock false), 'unreadable' (!$readable), and `return $stored` preceded by `if (!$stored) $failure='unwritable'`. openShared() (state.php:109-118) returns resource|false and replace() returns strict true/false, so no falsy return leaves $failure null. Hence !$reserved implies $stateFailure!==null and !$stored implies $publishFailure!==null: 'reservation-unstored' and 'publish-unstored' are unproducible. The reservation closure (forumindex.php:365-497) has exactly two returns leaving $reservation===null -- the retirement branch (sets $refusal='reservation-retired') and the $floor>=PHP_INT_MAX branch ('generation-exhausted'); the fall-through sets $reservation=$floor+1>=1. The publish closure (:652-687) has exactly two returns leaving $published===false -- 'reservation-superseded' and 'document-unpromoted'. So 'reservation-refused' and 'publish-refused' are unproducible too.

WHY THIS IS A NIT, NOT A MINOR DEFECT.

1. The stated harm rests on a false premise. The finding says the docblock "enumerates the reason vocabulary as if the whole set occurs". It does not. grep over the whole tree shows 'reservation-refused' appears only at forumindex.php:513 and 'publish-refused' only at :701 (other hits are unrelated erasedata literals). The docblock lists document-unsaved, publish-*, reservation-superseded, document-unpromoted, dump-refused/dump-empty/dump-malformed and reservation-retired -- every explicit literal there is reachable, and publish-* is a glob correctly covering the reachable publish-unreadable/unwritable/unlockable. So no maintainer is sent looking for the fault that produces 'publish-refused', and the comment at :507-512 already explains how the string is composed.

2. The primary suggestion ("drop the four fallbacks") would degrade the code. The docblock states an unconditional guarantee -- $failureReason is "Set on every answer that is not this request's own freshly fetched body arriving intact -- so always when the answer is null". The four ?: defaults are what make that guarantee total rather than dependent on an out-param contract held in a different class in a different file. Dropping the two 'unstored' defaults does not restore null; it yields the truncated tokens 'reservation-' and 'publish-', a malformed vocabulary member reaching the log at trackers/rutracker.php:822. Dropping 'reservation-refused' would let $failureReason stay null on a null answer, breaking the documented invariant outright.

3. Distrusting update()'s out-param is in-house style, not cruft: markSweep() (forumindex.php:1170-1216) spends fifteen lines classifying which of update()'s three failures ran the mutator before deciding $refusal.

4. Zero runtime cost, zero maintenance burden, and no test can be written against them -- a test that reached one would falsify the finding. Tests do cover the reachable neighbours ('reservation-unreadable' at ForumIndexTest.php:3801, 'reservation-superseded' at :3770).

The only defensible residue is the finding's alternative suggestion: a few words marking these as unreachable totality defaults. That is a comment nit.

**Исправление:** Do not remove the fallbacks. If anything, annotate them -- e.g. one line above forumindex.php:511 and :699 saying that RuTrackerState::update() assigns $failure on every false return and both closures assign their refusal before every early return, so these defaults exist to keep the docblock's "always set when the answer is null" guarantee total across the state.php contract boundary rather than because a fault produces them. Removing them yields the truncated tokens 'reservation-'/'publish-' (the 'unstored' pair) or a null $failureReason on a null answer (the 'refused' pair), both of which break the documented invariant.

### 32. [nit] The settled-deletion guard's `$probeDecision !== 'allow'` conjunct is invariantly true, but it is the guard, not a duplicated fix

**Вердикт:** confirmed-lower-severity

LOGICAL CORE: CONFIRMED. Location is right (plugins/rutracker_check/trackers/rutracker.php:879). `$probeDecision` is written in exactly four places (grep: :677 init 'skipped', :700 reserveProbe, :731 'unbuildable', :776 'inconclusive') and `$trackerConfirmed` in exactly two (:672 false, :777 true). Inside `if ($probeDecision === 'allow')` at :714 every exit either returns STE_UPTODATE (:745), overwrites with 'unbuildable' (:731), overwrites with 'inconclusive' (:776) or sets `$trackerConfirmed = true` (:777). So at :850 `!$trackerConfirmed` implies `$probeDecision !== 'allow'`. Reproduced in /home/dev/.cache/rutorrent-tmp/verify-40 (TMPDIR set, no leaked processes): replacing the condition with `if (self::deletionConfirmedOnce($hash, $settledToken))` leaves all 18 rutracker_check suites at 0 failures. The master claim also checks out — master's buildUrl-null path (git show master:...:545-550) left 'allow', and the same conjunct sat at master:649.

WHAT IS WRONG WITH THE FINDING. (1) "The fix is now expressed twice and only one half does work" is false. The conjunct is not part of this commit's fix — it is unchanged from master (master:649). The commit's fix is the single line `$probeDecision = 'unbuildable';`, and the comment at :725-734 says explicitly that this was the deliberate shape: "the decision must stop reading as 'allow' ... and the settled-deletion guard below asks exactly that question." The author chose to keep one guard and make its input truthful rather than delete the guard. That is one fix plus the condition it repairs, not two copies of a fix. (2) "kind: dead-code" mislabels it: the branch is live and taken, `$probeDecision` is live at :896, and this is an invariantly-true conjunct, not unreachable code. (3) The comment is not false anywhere I could check. It never claims the conjunct separates reachable paths; :866-868 states the invariant itself ("A probe that answered 'registered' returns up to date above and one that answered 'unregistered' sets $trackerConfirmed, so neither is here"), and :874-878 argues only for the negative FORM over an enumeration, citing the identical documented rule at :704-708 ("neither must any answer added later that this branch has not been taught yet"). A maintainer reading this is not sent wrong.

WHY THE SUGGESTION SHOULD BE REJECTED. Mutation C, run here: drop the conjunct AND revert `$probeDecision = 'unbuildable';` together — RuTrackerHandlerTest still reports 78 tests, 0 failures. Mutation B (revert only the assignment, keep the conjunct) fails the named case "an announce row that cannot become a probe URL does not read as a probe that ran". So the conjunct is the only thing that makes the campaign's own M06 regression test load-bearing for the classification; without it the assignment's sole remaining effect is a log string, and the test would pass with the production change reverted — precisely the worthless-test class AGENTS.md warns about. Dropping the conjunct would also remove the default-deny that the skip gate at :708 documents as house rule, in a block whose `else $trackerConfirmed = true;` fallthrough means any future answer added before it is the exact defect shape M06 fixed.

SEVERITY: nit. It is a true, small observation about a condition that cannot currently fail, in code that documents why it is phrased that way. AGENTS.md ("Match The Review Effort To The Change") says a comment-level item like this does not earn a change cycle.

**Исправление:** Do not drop the conjunct — measured here, that makes the M06 test pass with the production fix reverted. If anything is done at all, it is half a sentence appended to the comment at rutracker.php:874-878, e.g. "and with 'unbuildable' and 'inconclusive' now named, no path reaching here leaves 'allow' at all: the test is a default-deny against a value added later, not a discriminator today." Leaving the code exactly as it stands is also a defensible answer.

### 33. [nit] The injected filesystem seam is silently dropped on the cleanup recovery path

**Вердикт:** confirmed-lower-severity

MECHANICAL CLAIM: TRUE. removewithdata.php:1070 `erasedataCleanupGenerationArtifacts($index, $oldHash, $newHash, $marker, $replacementRecord, &$reason = null)` and :1160 `erasedataRecoverObsoleteCleanupLocked(..., &$reason = null, $index = null)` take no ErasedataFilesystemOps, and their inner calls (erasedataAnalyzeCleanupIndex:1081, erasedataReadExactCleanupArtifact:1108, erasedataReadExactCleanupToken:1123, erasedataCleanupCommittedPairStillMatches:1124 and :1180, erasedataPublishExactStagedFile:1205) omit the optional argument, so each constructs a fresh default ops object. Collector.php:1695 does call it while holding $this->filesystem. That much reproduces exactly.

BUT TWO LOAD-BEARING PARTS OF THE FINDING ARE WRONG.

1. The contrast is false. The finding says "every sibling call site (erasedataCancelObsoleteCleanupGenerationLocked, erasedataPublishObsoleteCleanup, the collector's own cleanup loop) threads the seam correctly". Two of those three named siblings do exactly what the recovery path does: erasedataPublishObsoleteCleanup (removewithdata.php:924) and erasedataCancelObsoleteCleanupGenerationLocked (:1221) both hold $filesystem, thread it into erasedataBuildCollectorIndex, and then call erasedataCleanupGenerationArtifacts WITHOUT it. Only the collector's inline loop threads the seam. So this is not one path deviating from an established pattern; the shape is uniform across all three callers of that helper, which reads as a boundary the package draws rather than an oversight at one call site.

2. "a fixture that scripts a rename/unlink/stat failure for this path does not actually reach it" is wrong for rename and unlink. The recovery path performs no seamed mutation at all, even if the parameter were threaded: erasedataRecoverObsoleteCleanupLocked calls only erasedataCleanupGenerationArtifacts, erasedataRepairExactCleanupTokenMode (:707 — no $filesystem parameter exists; it uses raw lstat/chmod), erasedataTorrentPresence, erasedataCleanupSuccessorMatches (both RPC) and erasedataPublishExactStagedFile, whose token creation is a raw fopen($listPath,'x')/fstat and which touches the seam only through entryIdentity(). No $filesystem->rename() and no $filesystem->unlink() is on this path. The only scriptable operation actually lost is entryIdentity.

NO PRODUCTION EFFECT (the finding concedes this), and no current test is weakened: I grepped every fixture scenario; no test scripts a filesystem directive aimed at the recovery path. Nothing passes for the wrong reason today.

THE SUGGESTION, APPLIED LITERALLY, BREAKS A PASSING TEST. In my copy (/home/dev/.cache/rutorrent-tmp/verify-35) I added `?ErasedataFilesystemOps $filesystem = null` to both functions, threaded it through all six inner calls plus the three callers, and ran tests/plugins/erasedata/RemoveWithDataTest.php on PHP 8.5 with a disk-backed TMPDIR. Baseline: 2765 Passed, 0 Failed. After threading: 2764 Passed, 1 Failed — `testCleanupCollectorAnalyzesPreparedGenerationsLinearly` (RemoveWithDataTest.php:4446), whose assertion `$reads >= $count && $reads <= $count * 4` is exactly saturated today. I instrumented the counter: the current tree yields READS=20 for $count=5 (4 seam-visible entryIdentity calls per generation: two from the index analyze read, two from the collector loop read); with the seam threaded it becomes READS=30, over the count*4 bound. The same calibration is baked into ordinal-selected scenarios and their comments elsewhere, e.g. RemoveWithDataTest.php:4544-4549 ("The sixth identity read of the staged manifest is the last one before the unlink", 'entryIdentity:6') and :4795-4826 ('entryIdentity:1'/'entryIdentity:2' pinned to the pre- and post-O_EXCL token windows) — those ordinals are counted under the current shape and would have to be re-derived.

So: the observation is real but it is a consistency nit about an optional test-seam parameter, not "strange logic" with a behavioural consequence, and the fix is not a drop-in. Residual value, if anyone acts on it: the seam-visible read count in testCleanupCollectorAnalyzesPreparedGenerationsLinearly undercounts the real artifact reads by one read per generation, so that test bounds only part of the work — still linear, so its assertion message is not false.

No leaked rutorrent-scgi-rpc2 processes (pgrep matched only my own shell). The target worktree is untouched (git status clean); all edits were in my own copy.

**Исправление:** Leave as is, or treat as a nit. If someone does thread `?ErasedataFilesystemOps $filesystem = null` through erasedataCleanupGenerationArtifacts and erasedataRecoverObsoleteCleanupLocked, it must be done at all three callers (collector.php:1695, removewithdata.php:924 and :1221 — the last two drop it today as well), and the change must come with: (a) raising the bound in testCleanupCollectorAnalyzesPreparedGenerationsLinearly from $count * 4 to $count * 6 (measured: READS goes 20 -> 30 for $count = 5), and (b) re-deriving the ordinal-calibrated scenarios and their comments at RemoveWithDataTest.php:4544-4549 and :4795-4826, whose "sixth identity read"/"first token identity read" claims are counted under the current shape. Note also that only entryIdentity becomes scriptable by doing this; the recovery path invokes no seamed rename or unlink, and erasedataRepairExactCleanupTokenMode has no seam parameter at all.

### 34. [nit] Redundant second call to erasedataCleanupCommittedPairStillMatches — real, but pre-existing and untouched by this branch

**Вердикт:** confirmed-lower-severity

Location and code claim are exactly right. removewithdata.php:788-794: the guard at 788-790 refuses unless erasedataCleanupCommittedPairStillMatches($tmp,$token,$tmpCandidate['hash'],$filesystem) holds, and 791-792 assigns the identical call to $ret. Nothing intervenes; the clearstatcache is at 793, after. The callee chain (erasedataCleanupArtifactStillMatches / erasedataCleanupTokenStillMatches -> erasedataReadExactCleanupFile) is pure-read: fopen('rb'), two fstat, two entryIdentity (clearstatcache+lstat), fclose per file, for both the .tmp artifact and the .list token — no by-reference args, no writes, no mode repair — so the second call cannot change any state and even leaves identical stat-cache state. The finding's cost figure (four fstat/lstat pairs, both artifacts re-read and the manifest re-decoded) is accurate.

Reproduced both halves of the evidence in my own copy under /home/dev/.cache/rutorrent-tmp/verify-34 (since deleted; no scgi leak — the single pgrep hit was my own shell command matching the pattern). (1) Instrumented the unmutated code to log whether the second call agreed with the guard: it executes only 7 times across both suites and logged "same" 7/7, never diverging. (2) Mutation `$ret = true;` gave RemoveWithDataTest 2765 passed/0 failed and PendingQueueTest 120 passed/0 failed, exit 0 — identical to my own unmutated baseline of the same two suites. Nothing observes the second evaluation.

No deliberate-oddity defence: no comment on those lines (this file comments its oddities densely elsewhere, e.g. 2430-2435, 2455-2463, 2525-2527), no ErasedataCollectorFixture scenario keyed on a call-occurrence number that the extra call would shift, and no mention in AGENTS.md or tasks/.

The downgrade is scope, and it is what the reviewer missed: this code is not part of the change under review. erasedataPublishExactStagedFile is byte-identical to master (diffed the whole function region; the duplication already sits at master lines 758/760), and `git diff master..HEAD -- plugins/erasedata/removewithdata.php` contains no hunk mentioning CleanupCommittedPairStillMatches — the branch's nearest hunks in that file are ~200 lines above and ~500 below. So it is a genuine but pre-existing, behaviour-neutral redundancy on a rarely-taken publish path, not a defect this squashed commit introduced. Reporting it as a branch finding invites an edit to untouched code on a 61-file change. Nit at most; the suggestion itself is safe if anyone does act on it (dropping the guard's call and returning the single call's result preserves semantics exactly, since a false pair check still yields return(false), and the short-circuit on $token === false is kept by the two remaining conditions).

**Исправление:** If touched at all (it is outside this branch's diff, so leaving it is defensible): collapse to one evaluation —

    if($token === false || !erasedataSameStatIdentity($token['candidate']['stat'], $tokenStat))
        return(false);
    $ret = erasedataCleanupCommittedPairStillMatches($tmp, $token, $tmpCandidate['hash'], $filesystem);
    clearstatcache(true, $listPath);
    return($ret);

Semantics are unchanged (a false pair check still returns false) and one full re-read of both artifacts is saved. Do not fold it into an unrelated behaviour commit; if it is worth fixing it is worth a separate one-line commit against master.

### 35. [nit] The `$/D` anchor on both chk-del readers is unpinned by any test

**Вердикт:** confirmed

Reproduced independently in my own copy (/home/dev/.cache/rutorrent-tmp/verify-45, disk-backed TMPDIR, 18 rutracker_check suites, baseline green; no leaked scgi processes — the single pgrep hit was my own shell).

Location exact: rutracker.php:377 and :429 both carry the identical '/^([0-9]+):([0-9]+)$/D'. git diff master..HEAD confirms the tightening from '/^([0-9]+):/' in this commit. No third parser of chk-del exists — updatepass.php's flushVerdicts treats it as an opaque string for equality/emptiness, check.php:449 writes ''.

Mutations reproduce, all green:
- reader 1 -> '/^([0-9]+):([0-9]*)/' : ALL GREEN
- reader 1 -> '/^([0-9]+):([0-9]+)/' (anchor only, a cleaner mutation than the reporter's): ALL GREEN
- reader 2 (:429) -> drop $/D : ALL GREEN

The disagreement class is real, not merely asserted. I instrumented both readers with chk-del='3:100 junk', chk-stime='': shipped code has deletionConfirmedOnce -> false / token NULL and confirmDeletion -> STE_CANT_REACH_TRACKER writing '1:1000000' (they agree); with reader 1 relaxed, deletionConfirmedOnce -> true with token 'deleting|3/3' while confirmDeletion still restarts at 1 — exactly the split the docblock at :342-357 says must not happen.

Not deliberate-and-documented: nothing in AGENTS.md or in any comment declares the anchor untested by design. EntrypointsTest.php:516 does perform a source-shape check on this same file, but only over $rutrackerAnnounceCap/$rutrackerAnnouncePause clamping, so it catches neither mutation.

Two corrections to the finding as written. (1) The suggestion attributes the invariant to the commit message; commit 4adfaffa's message says nothing about chk-del, the pattern, or the invariant (grepped, empty). The claim lives in the code comment at :372-376 and the docblock at :342-357. (2) The gap must be scoped: capture group 2 itself is load-bearing and well pinned (the reporter's own control — stubbing deletionRunStatus fails 4 named cases). Only the $/D anchor is unpinned, on both readers.

Counter-argument tested and rejected: one could say the anchor guards only a value the plugin never writes (sole writer is confirmDeletion's "$count . ':' . $now"), so the input is unreachable corruption. That does not excuse it here, because the suite already tests exactly that unreachable class for five other spellings ('03:1000', ' 3:1000', '3: 1000', '3:01000', '+3:1000') across two tests, one per reader. Trailing bytes are the one family member omitted from both.

Severity honest at nit: no shipped defect, both readers agree today, the gap is mutation resistance only. The suggested test is cheap, would fail under either mutation, and breaks nothing the current shape protects.

**Исправление:** Add one case to tests/plugins/rutracker_check/RuTrackerHandlerTest.php, alongside the existing 'a non-canonical deletion counter reaches no deletion verdict' and 'a non-canonical deletion counter is not a settled deletion either' pair, feeding chk-del = '3:100 junk' (and, for the /D half, "3:100\n") through both readers: assert deletionConfirmedOnce() answers false with a NULL settled token, and that confirmDeletion() restarts, writing '1:' . $now. That is the missing member of a family the suite already covers for '03:1000', ' 3:1000', '3: 1000', '3:01000' and '+3:1000'. Do not touch capture group 2 or the freshness rule — those are already well pinned; only the $/D anchor on lines 377 and 429 is unpinned. Attribute the invariant to the code comment at rutracker.php:372-376 and the docblock at :342-357, not to the commit message, which says nothing about chk-del.

### 36. [nit] Four of nine rows in testSnoopyFetchErrorIsLoggedAsOneClassifiedToken duplicate the shared corpus without adding an assertion

**Вердикт:** confirmed-lower-severity

The verbatim overlap is real and I confirmed it byte-for-byte: CheckerTest.php:2076-2090 repeats nine strings that appear identically in fetchErrorParityCases() (TestLib.php:342-363), both tests drive makeClient('http://bt4.t-ru.org/ann'), and both parse the same field with the same regex. All four tables are new in 4adfaffa (none of them exists on master). That much I cannot refute.

The conclusion does not follow. I mutated check.php's makeClient logDebug in my own copy at /home/dev/.cache/rutorrent-tmp/verify-51 to leak the last word of $client->error mid-line ("... host=bt4.t-ru.org detail=\"gopher\"\\n transport=no-status ... error=invalid-protocol"). testSnoopyFetchErrorIsLoggedAsOneClassifiedToken FAILS on it; testSharedSnoopyCorpusClassifiesTheSameWayThroughMakeClient PASSES. The parity test's only leak guard is strictAssertLogsClean (TestLib.php:248), which checks plain-ASCII plus one fixed passkey literal, so a leaked scheme, host, IP or errno goes straight through. The $absent column is therefore the sole assertion in the suite for "no fragment of the remote message reaches the log", as distinct from "the error field holds the right token". The two tests are not redundant; only part of their input table is.

The two cited companions are weaker still. NNMClubHandlerTest:1631 (I05) is not the parity rows "with the host names changed": its interpolated values differ (error 28 vs 6, connection failed (0) vs (111)) and it asserts four properties the parity test at :1697 does not -- transport= survives, 'Refusing'/'cURL' never appear, an empty message omits error= entirely, and a successful fetch logs nothing. FetchErrorTest.php:32-42 uses a third set of values and asserts count($seen) === 8, i.e. that the eight messages map to eight DISTINCT tokens -- a property the fifteen-row parity table with its repeated tokens structurally cannot state. The finding's framing, "a change whose stated purpose was to make it exist once", has no source: TestLib.php:325-339 states the purpose as the parity property ("two lists agreeing is a coincidence that has to be maintained"), never as deduplication, and the squashed commit message says nothing about the corpus.

The suggestion would also not be a clean improvement. Moving $absent into the shared table collides on the row that matters most -- "  \t connection   failed\t(111)\nhost bt4.t-ru.org  " embeds the exact host makeClient legitimately prints as host=bt4.t-ru.org on every line, so that row would have to carry null precisely where a leak check would be most valuable -- and it pushes a log-only column into FetchErrorTest, which calls classify() directly and has no log line to check it against.

What genuinely survives: the four rows whose $absent is null (curl-transfer, socket-create, dns-lookup, connect-refused) assert a strict subset of what :2139 asserts for the identical string, and the three-row whitespace block at :2104-2113 is subsumed by the parity table's four whitespace rows while using a weaker strpos check instead of the regex. That is about a dozen redundant lines of test data in one test, not a duplication spanning four files.

**Исправление:** Leave the shared corpus, FetchErrorTest and NNMClubHandlerTest alone -- each of those tables asserts something the parity table cannot. In CheckerTest, the only defensible trim is to drop the four rows whose $absent is null (curl-transfer, socket-create, dns-lookup, connect-refused), which assert a strict subset of what testSharedSnoopyCorpusClassifiesTheSameWayThroughMakeClient already asserts on the identical string, and to drop the three-row whitespace block at :2104-2113, which the parity table's four whitespace rows cover with a stronger assertion. Keep the five rows that carry an $absent value; they catch a mid-line fragment leak that no other test in the tree catches. If anything is worth adding, it is one sentence in the comment at :2052 saying why this list is spelled out again -- that the rows exist to carry the $absent column, not to re-state the token mapping.

### 37. [nit] Two erasedata stream-wrapper fixtures share 39 identical lines; only stream_write/stream_flush differ

**Вердикт:** confirmed

FACTUAL CORE: CONFIRMED, but two of the finding's quantitative claims do not reproduce, and they invert its own cost/benefit.

Measured (file byte-identical between cited commit 4adfaffa and current HEAD 47908adf):
- ErasedataPartialWriteStream = tests/plugins/erasedata/RemoveWithDataTest.php:67-120 (54 lines); ErasedataFlushFailureStream = :131-175 (45 lines). difflib line-level match = 39 identical lines. "About forty" is accurate.
- Both classes do carry rename(); only the first carries the 5-line rationale at :111-115. The evidence sentence is correct.
- The rationale is TRUE and load-bearing. erasedataWriteDurableFile() (plugins/erasedata/filesystem.php:488-536) reaches rename() only on success; both scripted failures fall to @unlink($staging). rename() exists so a MUTATED writer (ignoring $written !== $total, or ignoring fflush) would reach the rename and publish - without it the mutant would still fail and the test would pass for the wrong reason. That argument is symmetric, so the single home is a real asymmetry.

WHERE THE FINDING OVERSTATES:
1. "byte-identical" holds for 8 of the 9 named methods. register() hardcodes the class-name string, and that is precisely the line that forces late static binding in any base.
2. "the differing methods are three lines each" is FALSE. ErasedataPartialWriteStream::stream_write() is 8 lines and needs a `private $wrote` state field the base would not have. The promised "each subclass at its three distinguishing lines" does not reproduce - subclass A stays ~13 lines. Measured net saving of the whole refactor is ~35 lines in a 13,771-line file (0.25%).

THE SUGGESTION CARRIES A RISK I REPRODUCED. Both classes use self::SCHEME in real() and register(). A verbatim lift keeps self::, early-bound to the base const. Run in /home/dev/.cache/rutorrent-tmp/verify-56/lsb.php: real('erasedatapartial://...') returned "tapartial:///home/..." (stripped 7 chars instead of 21) and fopen returned false with no warning. In this fixture that silently converts "the writer refuses a short write" into "the writer refused because it could not open anything" - a test passing for the wrong reason, the exact defect class this register was opened for. Correctable with static::, but a thin payoff for a footgun the current shape cannot have.

SCOPE: the base would serve exactly two users forever. ErasedataOversizeStream (same file, :30) is a read-side counter with no rename/unlink; the PendingQueueTest.php:1038 lock wrapper registers with __CLASS__, which inheritance breaks. And hoisting the rename() comment forces rewriting a currently specific sentence ("the byte count is the only thing between a stalled write and a published record") into a weaker generic one - against the house rule whose measured recurring defect is a replacement comment that is itself false.

No comment or AGENTS.md line declares the duplication deliberate, so this is not deliberate-and-documented. It is a real nit; nit is the honest floor and it was already filed there. My recommendation is that it is optional to act on, and if acted on the numbers in the rationale must be corrected.

Housekeeping: ran no suite; pgrep -cf rutorrent-scgi-rpc2 returned 1, which pgrep -af showed to be my own shell command line self-matching. Nothing leaked, nothing killed. Read-only throughout; the only file written was the experiment under /home/dev/.cache/rutorrent-tmp/verify-56/.

**Исправление:** Keep the severity at nit and correct the rationale before acting: the shared block is 39 lines, not "forty pass-through lines plus two three-line hooks" - ErasedataPartialWriteStream::stream_write() is 8 lines and owns a `private $wrote` field, so the measured saving of a base class is about 35 lines total. If a base is still wanted, it must use `static::SCHEME` in both real() and register() and `stream_wrapper_register(static::SCHEME, get_called_class())`; a verbatim lift that keeps the existing `self::SCHEME` early-binds to the base const, strips the wrong prefix length, and makes every fopen fail silently - which would leave both durable-writer assertions passing for the wrong reason. The cheaper fix that captures the finding's real value is to leave both classes standalone and add one sentence to ErasedataFlushFailureStream's header noting that its rename() is there for the same mutation-guard reason as the neighbouring class's - written specifically for the flush case rather than generically, since a generic replacement is the defect class AGENTS.md warns about.

### 38. [nit] Two unreachable diagnostic branches: arm-unavailable and rearm-unavailable

**Вердикт:** confirmed-lower-severity

The mechanical claim reproduces, but the evidence offered for its importance does not, and both suggested remedies are worse than the code as it stands.

WHAT I CONFIRMED (independently, reading whole functions)
- Locations are right. removewithdata.php:2798-2805 in erasedataRemovalAdmissionRun and :4662-4670 in erasedataRearmDrainScheduleRun both do `$command = erasedataDrainScheduleCommand($user, ERASEDATA_DRAIN_INTERVAL); if($command === false) {...}`.
- erasedataDrainScheduleCommand (:2005-2019) returns false only on `$key === false || $command === false || $interval < 1`. erasedataDrainWorkerCommand (:1958) returns false only when erasedataDrainScheduleKey($user) is false, and that key helper (:1475) is pure — it depends solely on $user and ERASEDATA_DRAIN_MAX_USER_BYTES. So both sub-results are false on exactly one condition.
- Both enclosing functions test that same condition at entry with the same value (:2641, :4536), and I checked by grep over each function body that $user is assigned exactly once in each (from $dependencies['user']) and never rebound before the call site. `$interval` is the literal 5; nothing else in the tree defines ERASEDATA_DRAIN_INTERVAL. getAlignedStart cannot produce a false return — it is fenced by `if(!is_int($start) || $start < 1) $start = $interval;`.
So yes: as configured, neither branch can be entered. That part is correct.

WHY THE SEVERITY IS WRONG
1. The "no test pins either token" evidence is a non-signal. I censused every diagnostic token in the file: 31 distinct tokens, of which 25 are not mentioned anywhere in tests/plugins/erasedata/RemoveWithDataTest.php — including plainly reachable ones such as `queue-unavailable` (mkdir failure), `arm-refused` (RPC fault) and `state-lock`. Unpinned is the norm here, not a marker of dead code, so it supports nothing.
2. The branches implement the file's own stated design rule, written at the top of this very subsystem (:1366-1368): "Every step that cannot be completed leaves either a clean refusal (when the admission was not yet durable) or a self-describing retryable obligation (once it was)." Guarding a documented `false` return at the point of use is that rule applied uniformly, which is why the sibling `arm-refused`/`rearm-refused` branches sit immediately below in identical shape.
3. AGENTS.md:399-403 ("When A Metric Starts Shaping The Code") records that this fork already paid for exactly this trade: functions were merged to hit a line ceiling, and it "cost a diagnostic and shipped a 32-bit availability stall". It states outright that work which adds fail-closed boundaries and diagnostics legitimately adds lines. Deleting 8 lines of diagnostic because the condition is currently impossible is the move that rule was written against.

WHY THE SUGGESTION WOULD MAKE THINGS WORSE
- Option B (drop the entry-point check, keep only this one) is a real regression in erasedataRemovalAdmissionRun. The entry check at :2641 fires before any side effect at all — before the A/F partition, before @FileUtil::makeDirectory($listPath), before the hash capabilities, before the state lock, before the journal record and before the durable erasedataWriteDrainState() that moves the queue to phase `arming` (all of which happen between :2641 and :2798). Relying on the late check instead would turn a clean pre-durable refusal into a refusal that has already created the queue directory and left a durable `arming` generation with a journal record behind it — precisely the "durable obligation" case the header comment says must not be entered by a step that is going to refuse. Same shape in rearm: :4536 refuses before the state lock is taken and still emits `rearm-user`; :4662 refuses only after the lock and the candidate scan.
- Option A (delete both branches) is not free either. rXMLRPCRequest::__construct ignores a falsy $cmds (php/xmlrpc.php:85-95), so `new rXMLRPCRequest(false)` yields a command-less request; success() would then attempt a run and the failure would surface under the neighbouring token `arm-refused` with the message "admission-refused-nothing-staged" — a wrong classification, in a subsystem whose register exists because diagnostics lied.

WHAT IS LEFT: an accurate observation that two of 31 diagnostic tokens are structurally unreachable while the entry-point checks stand. That is worth at most a one-line note, and the correct disposition is to leave the code alone. I would not act on it.

**Исправление:** Leave both branches as they are. If anything is done at all, it is a comment, not a deletion — e.g. noting at :2798 and :4662 that the entry-point key check (:2641 / :4536) already makes this false return unreachable today and the branch is kept so the refusal stays classified if the key rule, the caller set or ERASEDATA_DRAIN_INTERVAL ever changes. Do NOT delete the branches (rXMLRPCRequest silently accepts a falsy command, so the failure would be misreported as `arm-refused` / "nothing-staged"), and do NOT drop the entry-point checks in favour of the late ones: in erasedataRemovalAdmissionRun that moves the refusal from before any side effect to after the queue directory, the hash capabilities, the journal record and the durable `arming` state write.

### 39. [nit] Two long try blocks in removewithdata.php have unindented bodies (nit; the finding's return-count and depth evidence does not reproduce)

**Вердикт:** confirmed-lower-severity

WHAT REPRODUCES

1. The locations are right and the shape is exactly as described. `cat -A` on plugins/erasedata/removewithdata.php confirms: line 2673 `\t\ttry`, 2674 `\t\t{`, body from 2675 at `\t\t` — same indent as the `try` keyword — with the closing `\t\t}` and `\t\tfinally` at 3076. Same at 3743 with `\t\tfinally` at 4101. Both finallys do nothing but `erasedataReleaseRemovalCapabilities($capabilities, $filesystem)`.

2. It is a real deviation, not house style. The third try in the same file — erasedataDrainWorkerRun, line 4806 — indents its body normally (`\t\ttry` / body at `\t\t\t`). Master indents try bodies everywhere I sampled (php/scgitransport.php:34, plugins/rutracker_check/batch_check.php:53 with a nested try at 5 tabs). No precedent for a flat try body in master. Nothing in AGENTS.md (408 lines), in the commit message, or in the comments at 2675 and 3735 explains or authorises the flat form, so this is not a documented deliberate oddity.

3. The length superlative holds. I measured every function in the 27 changed production .php files by brace matching: erasedataDrainGenerationPass 592 lines (3514-4105), erasedataRemovalAdmissionRun 455 lines (2626-3080) — #1 and #2, ahead of createTorrent (403) and rutracker.php download_torrent (376). The finding's 629/466/404/380 are each 10-37 off from a straight function-line count, but the ranking is right.

WHAT DOES NOT REPRODUCE — and this is why the severity has to come down

4. "erasedataDrainGenerationPass has 11 return statements inside its try" is FALSE, twice over. There are exactly 2 returns inside that try (3897 and 4097). The function has 10 returns total; 8 sit BEFORE the try (3528, 3553, 3561, 3573, 3597, 3610, 3709, 3717), where `$capabilities` does not yet exist (initialised at 3740) — so those returns rely on nothing. The "11" is a `grep -c return` over the whole function that also counted the prose word "returning" in the comment at 3622. This is exactly the class the house standard warns about: a count that was inferred, not measured.

5. "Both are also the deepest new code (nesting 6 and 5)" is wrong as a superlative. Max brace depth 6 is shared by erasedataRemovalAdmissionRun, collector.php parseOneItem (191 lines), updatepass.php sweepReplacingRow (166) and nnmclub.php patchAuthInTorrent (80). erasedataDrainGenerationPass is depth 5 — shallower than three other functions the same diff adds. So "#1 and #2 by both metrics" is true for length only.

6. The concern the reviewer attached to drainGenerationPass actually belongs to the other function: erasedataRemovalAdmissionRun has 20 returns inside its try, and those do depend on the finally to close held directory descriptors. Right worry, wrong function.

7. No behavioural defect. I checked both functions for a return that escapes after capabilities are acquired but outside the try: there is none. In admissionRun `$capabilities = array()` is line 2672, immediately before the try, and the function ends at 3080. In drainGenerationPass the initialisation is 3740, three lines before the try. Every acquire is inside the bracket. Cosmetic, not a leak.

ON THE SUGGESTION

Indenting is a safe whitespace-only change and would be a genuine improvement — it restores the file's own convention.

The larger half should be REJECTED. Splitting erasedataDrainGenerationPass at its numbered steps is not the low-friction move the finding implies: the try body touches 37 distinct locals, several mutated across step boundaries ($capabilities, $collected, $published, $retained, $staged, $refusedCapability). The comment at 3737-3739 says outright that "the paths each preflight collected are kept and reused by step (2)". Only steps (2)-(5) are inside the try at all — the (1) probe is above it. Extracting them would force that state through a reference bag, and the finally would still have to span all five calls from the parent — the same 350-line capability lifetime, now with the release even further from the code that acquires it.

BOTTOM LINE: the headline sentence is literally true and I verified it byte-for-byte, so it cannot be called refuted. But the two pieces of evidence that made it sound like a structural hazard — 11 returns depending on an invisible finally, and "deepest new code" — are both wrong, and what is left is two functions whose try bodies are not indented. No behaviour, no false comment, no missing release. A whitespace nit, not a minor finding.

**Исправление:** Indent the bodies of the try blocks at plugins/erasedata/removewithdata.php:2673 and :3743 by one tab, to match the same file's erasedataDrainWorkerRun try at :4806 and master's convention throughout. Whitespace-only, no behaviour change. Do NOT split erasedataDrainGenerationPass at its numbered steps: only steps (2)-(5) are inside the try, 37 locals are live across it and at least six are mutated across step boundaries, so the extraction would push the same capability lifetime into a caller-level finally with state threaded by reference. If a structural change is wanted at all, the better target is erasedataRemovalAdmissionRun — that is the function with 20 returns inside its try, all relying on the finally to close held directory descriptors.

### 40. [nit] Only one of the three guards over ErasedataProductionMirror::pluginFiles() is weakened by the hand-maintained list; the other two are existence checks omission cannot fool

**Вердикт:** confirmed-lower-severity

LOCATION AND MECHANICS: correct. CollectorFixture.php:863 `pluginFiles()` returns a literal array of exactly the eleven names that `ls plugins/erasedata/*.php` prints (action, collector, conf, done, erase, filesystem, init, manifest, pending, removewithdata, update). `grep` over tests/plugins/erasedata shows no glob/scandir of the plugin directory, and `isExact()` (line 910) only reports `unreadable`/`copyFailures` for names that were on the list. So the two copies of the list are genuinely unchecked against each other. The class comment explains the mirror but says nothing about the list; nothing in AGENTS.md documents it as deliberate.

WHERE THE FINDING IS WRONG (2 of its 3 cited consumers): the guards it names are monotone existence assertions, and omitting a file from the search set can only make such an assertion FAIL, never falsely pass.
- :8812 asserts `in_array('update.php', $callers, true)`. update.php is on the list; an unlisted new file changes nothing.
- `productionCallers()` (:6948) is consumed at 8332/8337/8346, 9842, 9985, 12528, 12690 — every one is `in_array(<a named listed file>, $callers, true)` or `count($callers) > 0` ("a production caller exists"). None asserts the caller set is exact or that some file is absent. So productionCallers() is not weakened by the list at all.
Only the universal loop at :8380 (every file "calls no XMLRPCPathResolver method" / "does not require the core resolver") quantifies over the list, and it alone is anti-monotone.

MEASURED, in /home/dev/.cache/rutorrent-tmp/verify-52 (a copy; the target tree untouched):
1. Added plugins/erasedata/prune.php — a standalone file requiring php/xmlrpc_path.php and calling XMLRPCPathResolver::canonical(). Ran tests/plugins/erasedata/RemoveWithDataTest.php through the php-test.sh recipe: exit 0, 2765 "Passed:", zero "Failed:"; testTheFilesystemSliceOwnsEveryIdentityDecision inspected 11 files and never saw prune.php. The residual claim reproduces.
2. Blast radius is much narrower than the finding implies. I then made removewithdata.php `require_once` that same prune.php: 117 assertions failed, including "PHP Warning: require_once(.../worker-reach/plugins/erasedata/prune.php): Failed to open stream" — because the mirror copies only listed files, so a new file the plugin actually loads makes every real-subprocess case fail loudly and php-test.sh's `^Failed:` gate trips. Only a file that nothing in the plugin includes escapes, and such a file is by construction on no code path the plugin executes.
No processes leaked (`pgrep -af 'rutorrent[-]scgi-rpc2'` empty after both runs).

SEVERITY: nit. The real exposure is one regression pin for an already-fixed defect, weakened only for a hypothetical future standalone .php in this one plugin directory that nothing requires. Reported as "minor" with three weakened guards, it overstates its own impact by a factor of three, and the AGENTS.md "validate by COUNT" rule it invokes is about test-name extractors, not this.

SUGGESTION: the glob-derivation half is the worse half. `pluginFiles()` is static and called with no arguments from RemoveWithDataTest, while the mirror's source root is only handed to `build()`; deriving the list would mean plumbing a root into a static accessor, and a stray .php left in a developer's working tree would then be silently pulled into a "byte-verified copy of plugins/erasedata". The finding's second option is the safe one.

**Исправление:** Keep the literal list (it is what makes the mirror a declared, deterministic set rather than whatever is on disk) and add one assertion next to the I6 loop at RemoveWithDataTest.php:8380 comparing it to the shipped directory, e.g.

  $shipped = array_map('basename', (array)glob($this->repositoryRoot().'/plugins/erasedata/*.php'));
  sort($shipped);
  $listed = ErasedataProductionMirror::pluginFiles();
  sort($listed);
  $this->assertTrue(count($shipped) > 0, 'the shipped plugin directory really lists .php files');
  $this->assertEquals(json_encode($shipped), json_encode($listed),
      'the mirror enumerates every shipped plugin file, so a new one cannot be exempt from this guard');

The non-empty check matters: glob() returns array() for a mistyped root, and without it the equality would pass by comparing two empty sets on a wrong path. This closes the one real gap (the universal XMLRPCPathResolver loop) without changing what the mirror copies.

### 41. [nit] New tokenizer dependency turns UpdatePassTest.php red in the shipped Alpine runtime, unguarded and unexplained

**Вердикт:** confirmed-lower-severity

The mechanical observations reproduce, but both claims that carry the severity are wrong.

CITED LOCATION IS ACCURATE. TestLib.php:85 (loadFunctionDefinition) and UpdatePassTest.php:3871 (updPresenceWhitelist) both call token_get_all() with no function_exists() guard; both are new in 4adfaffa. loadFunctionDefinition has exactly two callers, UpdatePassTest.php:3926-3927. `docker run --rm --entrypoint sh ivanshift/rutorrent:latest -c 'php -m'` confirms PHP 8.5.10 Alpine with no tokenizer, so the image failure is real.

CLAIM 1 REFUTED — AGENTS.md is not falsified. The sentence at AGENTS.md:298-302 states a general rule and one example, not an inventory: "every static-structure test that reads the source through token_get_all() fails -- 8 of them in EntrypointsTest". It gives no file total and no per-file list. On master, PluginInitPathsTest and ImmortalSeedCategoriesTest already fail identically and are already unnamed there; `git diff --stat master..HEAD` shows both files untouched by this commit, so the doc was already non-exhaustive by two files before the diff. There was never an arithmetic comparison for the diff to break. The new UpdatePassTest case is an instance of the rule exactly as written, and the next sentence -- "None of it is a code fault ... always run the same suite on the base before calling a failure a regression" -- is precisely the procedure the reviewer used, which yielded the correct conclusion. A maintainer following AGENTS.md is not sent wrong.

CLAIM 2 REFUTED BY MEASUREMENT — the suggested remedy does not deliver its stated benefit. I ran both files inside the image on the head tree, on a disk-backed export:
  === php/XMLRPCProxyTest.php  exit=0  ->  "Failed: tokenizer extension is required for source-aware duplicate key detection"
  === plugins/rutracker_check/UpdatePassTest.php  exit=1  ->  "not ok - ... / Error: Call to undefined function token_get_all()"
php-test.sh:50 marks a file failed on `^Failed:` as well as `^not ok`, so the cited "project pattern" at XMLRPCProxyTest.php:2193-2197 is ITSELF already one of master's 6 red files. Adopting it in TestLib/UpdatePassTest changes the failed-file count from 7 to 7, not 7 to 6. The stated harm ("a reviewer following AGENTS.md sees a newly-red suite file") survives the proposed fix unchanged; only the message text differs.

CONVENTION. Of the pre-existing token_get_all test files, three are unguarded (EntrypointsTest, PluginInitPathsTest, ImmortalSeedCategoriesTest) and one guards (XMLRPCProxyTest). The new code follows the majority and matches the file sitting beside it in the same directory.

RESIDUE. What survives is message quality only: a bare "Call to undefined function token_get_all()" names the missing extension less legibly than an assertion string would. That is a nit with a precedent, not an important finding. The canonical suite (7.4/8.1/8.5, exit 0, 844 cases) and the shipped product are unaffected -- production calls no tokenizer function.

Housekeeping: no SCGI servers leaked by me; the 4 pgrep hits were other agents' shells self-matching the pattern. Scratch tree under /home/dev/.cache/rutorrent-tmp/verify-69 removed.

**Исправление:** Optional polish only, and do not sell it as making the image green: if the bare "Call to undefined function token_get_all()" is judged unhelpful, put the XMLRPCProxyTest-style guard in TestLib.php's loadFunctionDefinition() so the message names the missing extension. Measured: the file stays in php-test.sh's failed list either way (the guard prints "Failed: ..." which ^Failed: matches), so this buys a clearer message and nothing else. Do NOT add UpdatePassTest.php to the AGENTS.md paragraph as a "list" -- that paragraph states a rule with one example and has never enumerated files; it already omits PluginInitPathsTest and ImmortalSeedCategoriesTest on master. If someone wants an inventory there it is a separate docs change against master, not a defect in this diff.

### 42. [nit] classifyFetchError() is a one-line delegate whose docblock gives only a circular reason for existing

**Вердикт:** confirmed

I tried to refute this and could not; every checkable particular holds, and one of the finding's own escape hatches is disproved.

Location and code (check.php:1859-1877). The docblock runs 1859-1873 and the body is exactly `static public function classifyFetchError($error) { return(RuTrackerFetchError::classify($error)); }`. Its last sentence is "The method stays because makeClient() below calls it by this name."

Callers. Full-tree grep (excluding .git) for `classifyFetchError` returns five hits and no more: the definition (1874), one call `self::classifyFetchError($client->error)` inside makeClient (1927), two prose mentions in check.php comments (1837, 1925), and one prose mention in tests/plugins/rutracker_check/TestLib.php:26. NNMClub calls the leaf directly (trackers/nnmclub.php:186, `RuTrackerFetchError::classify(...)`). So: one caller, no test call.

The finding's alternate rationale is FALSE, and that matters for how it should be written up. The handler suite's stub `class ruTrackerChecker` (TestLib.php:831-...) defines STE_*/CHKMSG_* constants, makeClient, transportFailureDetail and fetchStatusDetail — it does NOT define classifyFetchError, and no handler test calls it. The other two stubs (ProjectionContractTest.php:9, ManualEntrypointsTest.php:307) don't either. So "kept as a deliberate public API for the stub" is not available as a better reason; the suggestion should drop that clause.

The suggestion is safe — I ran it. In /home/dev/.cache/rutorrent-tmp/verify-66 (git archive of HEAD) I deleted the docblock+wrapper and made makeClient call RuTrackerFetchError::classify directly. TMPDIR disk-backed. CheckerTest 136/0, NNMClubHandlerTest 30/0, FetchErrorTest 7/0, RuTrackerHandlerTest 78/0, ProjectionContractTest 9/0 — all exit 0, including the parity gate and the passkey/unclassified assertions. Nothing depends on the name. TestLib.php:32 requires fetcherror.php unconditionally (outside the TESTLIB_HANDLER_STUBS guard), so the suites that eval check.php's class body via loadClassDefinition() have the leaf loaded whichever way makeClient spells the call. Scratch tree deleted; `pgrep -cf rutorrent-scgi-rpc2` showed only another agent's verify-68 shells and my own ps — I leaked nothing.

Is the sentence FALSE or merely imprecise? Merely imprecise, which is why this stays a nit and must not be inflated. makeClient really does call it by that name, so a maintainer is not sent wrong — they are just told nothing they can act on: the docblock's job at that spot is to say whether the indirection may be removed, and "because its caller calls it" cannot answer that.

Is it deliberate and documented? Partly, and the documentation is not where a maintainer will find it. The extraction commit 3da75b2f ("give Snoopy error classification one home") states the real reason: "classifyFetchError() stays as a delegate for makeClient(), the handler's private wrapper is gone, and no call site changed name or shape" — i.e. refactor hygiene, keep the de-duplication free of call-site churn. That commit is NOT an ancestor of HEAD (it lives on fix/rutracker-s01-s02 and fix/campaign-integration); it was squashed away, and the delivered message (4adfaffa) does not mention the extraction at all. So on the branch under review that rationale exists nowhere. Not enough to call this "deliberate-and-documented" and dismiss it, and not enough to raise it above nit.

Cost of acting, which the finding understates: deleting the wrapper also requires touching check.php:1837 and :1925 (both name the method in prose) and TestLib.php:26 ("the leaf its classifyFetchError() delegates to"). Three comment edits for one line of indirection. Note also that the rationale is already stated three times — fetcherror.php's class docblock, this wrapper's docblock, and makeClient's inline block all re-tell the "status 0 has several causes / a token cannot carry a passkey" story — so the lower-churn fix is the one-sentence swap, not the deletion.

Nothing else in the finding was wrong: I checked the docblock's factual claims and they hold. "The rule itself lives in fetcherror.php, which the NNMClub guest path requires too" — nnmclub.php:4 requires it, :186 calls it. "Both used to carry their own copy ... the copies had drifted over how they normalised whitespace" — confirmed against 3da75b2f's message and its RED note; master (f06b1fca) has no classifier at all in either file (check.php:1857 there trims, regex-redacts pk/passkey/uk and quotes the raw sentence), so the "used to" is campaign-internal history, invisible from the shipped tree, but it is not a false claim. And :1925's "answers 'unclassified' to anything it does not recognise" matches classify()'s fall-through.

**Исправление:** Prefer the one-line fix over the deletion. Replace the docblock's last sentence with the reason the extraction commit actually gives: "The method stays as a delegate so the de-duplication changed no call site's name or shape; makeClient() below is its only caller and it may be inlined." That answers the question a reader has at that line. If instead the wrapper is deleted, the change is four edits, not one: makeClient's call at :1927, and the prose at check.php:1837, check.php:1925 and tests/plugins/rutracker_check/TestLib.php:26, all of which name the method. Drop the finding's "kept for the handler suite's stub" alternative — TestLib's stub ruTrackerChecker (TestLib.php:831) does not define classifyFetchError, so that reason does not exist. Verified: with the wrapper deleted and makeClient calling RuTrackerFetchError::classify directly, CheckerTest/NNMClubHandlerTest/FetchErrorTest/RuTrackerHandlerTest/ProjectionContractTest are 260 cases, 0 failures.

### 43. [nit] confirmDeletion() spends an RPC on a value it discards when the count is zero

**Вердикт:** confirmed

I tried to refute this and could not; the mechanical core reproduces. Corrections to the framing are below.

WHAT I VERIFIED IN THE CODE (worktree clean, HEAD 47908adf — note: not the 4adfaffa named in the task; file content matches what the finding quotes)
- Location is right. `plugins/rutracker_check/trackers/rutracker.php:459-461` is the comment, `:463` the unconditional `deletionRunStatus()` call, `:464` and `:469` the only two consumers, both gated on `$count > 0`. `$run`/`$runDetail` are not read anywhere else in the function (which runs `:409-509`, not `:409-480` as the evidence line says — a harmless mis-citation). `deletionRunStatus()` (`:311-341`) opens with `readCustom($hash, "chk-stime")`, and `readCustom()` (`:71-79`) builds a fresh `rXMLRPCRequest` per call — no memo, no batching.

WHAT I VERIFIED BY RUNNING (own copy, /home/dev/.cache/rutorrent-tmp/verify-68, TMPDIR exported; `pgrep -cf rutorrent-scgi-rpc2` returned 1, which on inspection was my own shell's command line, not a leaked server)
- Probe calling `confirmDeletion()` directly through the existing `strictInvoke`/`rXMLRPCRequest` doubles: with chk-del unset, the function issues 2 `d.get_custom` requests — `chk-del` then `chk-stime`. Same for the non-canonical `'03:1000'` case (count reset to 0 before the call).
- The result is provably discarded on that path: queueing an UNREADABLE chk-stime alongside an unset chk-del still writes `1:1000000` and returns STE_CANT_REACH_TRACKER, with only the normal progress log line — identical to the readable case.
- Gating it (`$run = 'unbroken'; if ($count > 0) $run = self::deletionRunStatus(...)`) leaves the whole `tests/plugins/rutracker_check/run.php` suite at exit 0, every group "0 failures". So no test pins the unconditional read, and nothing in AGENTS.md or a sibling comment defends it. Note the first test in RuTrackerHandlerTest.php (`:419-421`) queues no chk-stime response for the count-0 case at all — the double's "nothing queued" fallback absorbs the extra request silently, which is why the waste is invisible in the suite.

WHERE THE FINDING OVERSTATES
- "the common path" is too strong. `confirmDeletion()` is reached only from `:907`, i.e. the row is missing from the forum dump AND layer 2 confirmed the deletion; within such a run, `$count == 0` is the first cycle of at least three. So this is one extra `d.get_custom` on a rare path, not a per-cycle cost.
- The comment is NOT false. Both clauses ("the read is part of this function's request sequence either way", "only the ACTION below is conditional on there being a count") describe the code accurately; a maintainer is not sent anywhere wrong. It is circular as a justification — it states a property instead of a reason, exactly as the finding says — but under the house dichotomy this is imprecise, not a lying comment. That keeps it at nit rather than promoting it.
- One defence the finding did not consider, and it is weak: computing `$run` unconditionally means a future consumer added below cannot read an uninitialised/defaulted value. That is a plausible authorial intent, but the comment does not say it, and the two existing consumers already carry their own `$count > 0` guards.

NET: real, reproducible, tiny. Actionable as a nit either way (move the call, or write the actual reason). Not worth more than that.

**Исправление:** Either (a) gate the call — `$run = 'unbroken'; if ($count > 0) $run = self::deletionRunStatus($hash, $lastIncrement, $runDetail);` — which is suite-clean (verified: 0 failures) and then makes the two `$count > 0 &&` clauses at :464 and :469 redundant, so the block reads as one condition instead of three; or (b) keep the unconditional call and replace :459-461 with the real reason if one exists — the only defensible one I can see is "computed on every path so a guard added below cannot silently read a default", which is a maintenance argument, not the request-sequence argument currently written. Do not claim a constant per-torrent request count as the reason: no log line here reports request counts.

### 44. [nit] is_executable() gate: comment says "effective identity" where PHP uses the REAL uid/gid — one wrong word, not a duplication defect

**Вердикт:** confirmed-lower-severity

Locations are right. removewithdata.php:1713-1748 defines erasedataDirectoryLookupsAnswer() = is_dir($d) && is_executable($d); filesystem.php:174-190 is the @stat($ancestor.'/.') block inside canonicalMissingPath().

WHAT I CONFIRMED (the comment half). PHP's is_executable() is access(X_OK), i.e. REAL uid/gid, so the comment's word "effective" at removewithdata.php:1727 is wrong. Two runs, both PHP images:
- real=0, euid=1000, dir /rootonly mode 0700 root-owned: is_executable() -> true, @stat("/rootonly/.") -> false, @stat("/rootonly/child") -> false. (php:7.4-cli and php:8.1-cli, identical.)
- uid==euid==1000 on the same root-owned 0700 dir: is_executable() -> false. This second run is the one that matters for defending the comment: it rules out "mode-only", so the sentence's OPERATIVE claim ("answers for this process's identity rather than for the mode alone") is TRUE. Only the qualifier "effective" is false; it should read "real". This is documented behaviour in the PHP manual for is_readable/is_writable/is_executable, so a maintainer who checks will be contradicted — that is why it is a real correction and not nothing.

WHY IT IS ONLY A NIT, NOT MINOR.
1. Unreachable, and the finder concedes it. There is no seteuid/setuid/posix_seteuid anywhere in the tree (grepped, zero hits). php-fpm and the rTorrent-side CLI child each run real==effective, where access(X_OK) is exactly the kernel's own answer — accurate, and in the one case it is inaccurate (root on a mode-0000 dir) it errs to false, i.e. conservative. I checked the direction of harm at the three call sites (2295, 2466, 2479): a false always means "not gone" / "not finished" / refuse, so only a wrong TRUE is fail-open. No wrong TRUE is producible in either shipped process.
2. The duplication half is weak. filesystem.php:182-187 is not a primitive with an owner — it is four inline lines inside a 90-line canonicalizer, probing an ancestor that walk just derived. There is nothing to "reuse": the suggested fix requires first extracting a new helper, and it has to be extracted in filesystem.php, because removewithdata.php requires filesystem.php (line 336) and not the reverse. AGENTS.md:406 lists "one owner per safety primitive" as an example of a structural target worth preferring over a line-count ceiling, not as a rule that every similar-looking check is a violation. The '/.'-probe idiom also already exists three times outside erasedata (php/utility/fileutil.php:204, php/getplugins.php:272 and :274), all pre-existing and untouched by this branch — so "two spellings" is not the real shape of the codebase, and dedup'ing these two would not give the primitive one owner anyway.
3. The surrounding design is deliberate and tested: RemoveWithDataTest.php:13092 testAnUnsearchableQueueDirectoryIsUncertaintyAndNeverAbsence pins the unsearchable-queue contract and explicitly skips under root ("chmod cannot restrict root ... a test that ran anyway would pass vacuously") — the project has already reasoned about exactly the privileged case the finding's repro depends on.

WOULD THE REFACTOR BREAK ANYTHING. No, but it is not an improvement worth the churn: swapping to the '/.'-probe changes one behaviour, root on a mode-0000 directory (is_executable false -> refuse; stat probe true -> accept). That is more accurate but strictly less conservative, in a gate whose whole reason for existing is conservatism. Not a trade I would take to remove four duplicated lines.

So: the finding's headline ("fail-open") is true only in a configuration that cannot occur here, the duplication is stylistic and its remedy is not the one-line reuse the suggestion implies, and what survives is one incorrect word in a comment.

(No suite run, so no scgi processes leaked; pgrep shows 3 pre-existing that are not mine and I left them.)

**Исправление:** Change one word at plugins/erasedata/removewithdata.php:1727. Replace "is_executable() answers for THIS process's effective identity rather than for the mode alone" with something like: "is_executable() is access(X_OK): it answers for this process's REAL uid/gid rather than for the mode alone -- real and effective are equal in both processes this plugin runs in (php-fpm and the CLI child), so that is the identity that matters here." Leave the code and the second sentence as they are. Do not extract a shared primitive: filesystem.php's probe is an inline step of canonicalMissingPath(), the include direction (removewithdata.php requires filesystem.php) means any shared helper must live in filesystem.php, three older copies of the '/.' idiom in php/utility/fileutil.php and php/getplugins.php would still be outside it, and switching this gate to the '/.' probe makes it less conservative as root on a mode-0000 directory.

### 45. [nit] An erase-force re-normalisation that can never differ (inherited, relocated by this diff)

**Вердикт:** confirmed

I tried to refute this and could not. Every load-bearing claim reproduces.

LOCATION. Actual lines are 5038-5048 of plugins/erasedata/removewithdata.php (the finding says 5037-5046; 5037 is the `return(false)` belonging to the preceding `if(!count($erasable))`). Off by one, not material. `plugins/erasedata/manifest.php:24-33` is exactly the body of `normalizeForce()`.

UNREACHABILITY, statically. `grep -n` over the whole function body (4956-5087) finds only three mentions of the two variables: `function erasedataRemoveWithData($hashes, $forceDelete)` (by value, no `&`), `$normalizedForce = ...normalizeForce($forceDelete)` at 4979, and the re-check at 5038-5039. Neither variable is reassigned; there is no `extract()`, `compact()` or variable-variable in the function. The argument is stronger than the finding states: to *reach* 5038 at all, `$normalizedForce` must be 1 or 2, which means `$forceDelete` is one of the four scalars 1, 2, "1", "2" — PHP scalars are immutable and this one is a by-value local, so the second call is provably the same call. `normalizeForce()` is four `===` identity comparisons and a `return(null)`; `===` invokes no user code (no `__toString`, no operator overloading), so it is pure and total over every PHP value.

UNREACHABILITY, empirically. In my own copy at /home/dev/.cache/rutorrent-tmp/verify-67 I instrumented the site: an append-log on line 5038 itself and a second one as the first statement inside the `if`. Running tests/plugins/erasedata/RemoveWithDataTest.php: the line was **reached 27 times, the branch fired 0 times**.

NO TEST PROTECTS IT. I then deleted the entire block (5038-5048) in my copy and re-ran that suite: 2765 assertions passed, zero `Failed:`/`not ok`/Fatal/Uncaught. The one source-shape assertion that mentions this text (RemoveWithDataTest.php:2803, `strpos($producer, 'ErasedataManifestCodec::normalizeForce($forceDelete)') !== false`) is satisfied by the surviving line 4979, so it stays green.

INHERITED, and dead in master too. `git show master:plugins/erasedata/removewithdata.php | grep -n destructiveForce` → 1358-1359, confirming the finding's provenance claim. I also checked master's `normalizeForce()` in case the guard was once alive: master's version is `if(is_string($value)){ if($value==="1") return 1; if($value==="2") return 2; } return null;` — likewise pure and total, so the block was already unreachable before this diff. `git diff master..HEAD` shows the two lines as a `-`/`+` pair, i.e. the diff relocated them verbatim rather than authoring them; the function is a wholesale delete-and-re-add inside a +3833-line file rewrite.

NOT DELIBERATE, NOT DOCUMENTED. `grep -rn destructiveForce` across the tree hits only these two lines — no test, no doc, no note. AGENTS.md has nothing on re-validation, TOCTOU or defence-in-depth normalisation. And the diff *did* write a 22-line Invariant-13 comment immediately above the first normalisation at 4979 while saying nothing at all about the second one — so a maintainer arriving at 5038 gets no signal that it is a no-op, which is precisely why it reads as a TOCTOU guard.

SEVERITY. nit is honest and I leave it there. It is ten lines of safe-side no-op that the author did not write, in the blast radius of a function this diff moved wholesale. I considered downgrading to none on the grounds that it is pre-existing debt, but the diff both relocated the function and rewrote `normalizeForce()` directly above it, so it is genuinely in scope for this review, and the misleading shape is real.

SUGGESTION. The first half of the reviewer's suggestion (delete it) is correct and safe — verified by deletion above. The second half (keep it and add a comment saying it guards a future non-pure `normalizeForce`) I would not take: a comment asserting a guard against a hypothetical is exactly the class of non-checkable comment this project's register exists to remove, and it would be the fourth such replacement comment this campaign has had to catch.

**Исправление:** Delete lines 5038-5048 of plugins/erasedata/removewithdata.php (the `$destructiveForce` assignment and the whole `if($destructiveForce !== $normalizedForce)` rollback block). Verified safe: with the block removed, tests/plugins/erasedata/RemoveWithDataTest.php still passes 2765 assertions with zero failures, and the source-shape assertion at RemoveWithDataTest.php:2803 is still satisfied by the surviving normalisation at line 4979. Do not take the alternative of keeping it with an explanatory comment — a comment claiming it guards against a future non-pure normalizeForce() is unverifiable and is the class of comment this register was opened to eliminate. If defence in depth is genuinely wanted at the destructive boundary, the honest form is an assertion on a value that can actually vary (e.g. that every staged manifest decodes back to $normalizedForce), not a re-call of a pure function on an unchanged immutable scalar. Note the cited range in the report is off by one: the block starts at 5038, not 5037.

### 46. [nit] Redundant guards in erasedata: 4 of 5 hold as nits, the force-2 comment claim is wrong

**Вердикт:** confirmed-lower-severity

Files unchanged between 4adfaffa and the worktree HEAD (`git diff 4adfaffa..HEAD -- plugins/erasedata/{pending,filesystem,removewithdata}.php` is empty), so working-tree line numbers are the target's.

EVERY CITED LINE NUMBER IS WRONG. The guard is at pending.php:162, not :157 (:157 is the `return(false)` of the hash check); the caller's checks are :151-159, not :150-156. filesystem.php's `!is_array($stat)` is at :343 and `!isset($before[$index])` at :351 — the finding cites :322/:341 in `location` and :341/:349 in `claim`; :322 and :341 are comment lines. The force-2 comment is :3853-3859, not :3855-3861. In a project whose house standard is "numbers must be measured, not inferred", the whole finding was written by inference.

REACHABILITY, sub-claims 1/2/4/5 — CORRECT, verified by reading:
(1) pending.php:162. erasedataQueueRequest re-derives nothing between its four guards (:151-159) and the call at :161; `strtoupper()` of a hex hash is still hex, so erasedataPendingMarkerPath (:198-204) cannot return false. Dead.
(2) removewithdata.php:3612-3613. `$final = is_array($entries) ? ... : array();` sits after the unconditional early return at :3605-3611 on the same predicate. Dead, and the only one of the five with no residual value.
(4) filesystem.php:343. `erasedataIdentityDeviceAndInode()` (:445-447) returns false for a non-array, so `$open !== false` implies `is_array($stat)`. Dead.
(5) filesystem.php:351. `descriptorsNamingIdentity()` is `private` (so unoverridable) and returns only `false` or an array — never null — and the loop at :333-334 iterates the same `$candidates`. `!isset()` can never fire; only the `!is_array()` half can. Dead.

Instrumented all five with trip-wires in /home/dev/.cache/rutorrent-tmp/verify-62/tree and ran PendingQueueTest + RemoveWithDataTest (exit 0, 0 failures): zero trips. Consistent with the static argument.

SUB-CLAIM 3 IS REFUTED, and its suggestion is harmful. The finding says the comment at :3853-3859 "justifies a distinction the guard never gets to make". It does not. The distinction the sentence explains is array_key_exists() vs isset() when the stored value is NULL, and that value really is null on a common production path: erasedataCollectPaths() (:283-323) sets `multi => "0"` for a single-file torrent, `erasedataAcquireRemovalCapability()` (:2567-2570) returns `null` on `empty($paths['multi'])`, and :3778 stores that null in `$capabilities[$hash]`. Spelled `isset()`, every single-file force-2 removal would fall into the `else` at :3869 and be marked descriptor-unavailable with its obligation retained. The comment is TRUE and is the only thing standing between a future "simplify to isset" and that regression — exactly the class of edit this campaign's register was opened for. Deleting the sentence would be the third false replacement comment.

Measured, and more interesting than the finding: the force-2 re-check body executes 4 times across both erasedata suites, and in all 4 the capability is a non-null array. So the null-capability path the comment documents is production-reachable but has NO test coverage anywhere in the suite. That, not the redundant guard, is the real gap here.

SEVERITY. "minor" is too high; nothing here can produce a wrong result, and three of the four live guards consume a documented `X|false` union feeding an API that would fault on false — `is_file($marker)` (string param), `$stat['mode']`, and `erasedataRemovalCapabilityStillMatches(array $paths, ...)` whose FIRST parameter is type-declared `array`, so passing `$collected[$hash] === false` is a TypeError, not a refusal. Removing them converts a local proof into a non-local one across ~250 lines. The finding's own evidence is self-contradictory on this: it reports that its script "flagged 3612 as the only genuine one of eight hits", which contradicts its own item (4), also an is_array pair.

Note the code already documents a deliberate redundant condition twelve lines above one of these (:3746-3751, "The $unbound half of this test changes no outcome and no test pins it... It is here so a member that is going to be retained does not have a directory descriptor opened"). So the author is on record documenting redundancy when it earns its place; these five are undocumented, which is why (2) is fair to raise — but as a nit, not a defect.

**Исправление:** Act on one line only. removewithdata.php:3612-3613 → `$final = erasedataPublishedGenerations($entries);` — the early return at :3605-3611 already narrowed `$entries`, and unlike the other four this ternary guards no typed call, so nothing is lost.

Leave (1) pending.php:162, (4) filesystem.php:343 and (5) filesystem.php:351 alone, or raise them only as a single "defensive union-consumption guards, unreachable today" note; each keeps a `string|false` / `array|false` value from reaching an API that faults on false if an upstream contract ever widens.

Do NOT apply the suggestion for (3). Keep the comment at :3853-3859 verbatim — it is true, and `array_key_exists()` is load-bearing because `erasedataAcquireRemovalCapability()` returns null for every single-file torrent. If anything is wanted here, drop only the `isset($collected[$hash]) &&` half (subsumed by the `is_array()` next to it) and add the missing test: a force-2 single-file member reaching the :3864 re-check with `$capabilities[$hash] === null`, which the suite currently never exercises (measured: 4 executions of that loop body, all with a non-null array capability).

### 47. [none] erasedataWriteDurableFile's post-loop byte-count check is redundant, but the finding's rationale and both of its suggestions are measurably wrong

**Вердикт:** confirmed-lower-severity

Location is right. plugins/erasedata/filesystem.php:509-523 is the loop plus `if($written !== $total) $complete = false;` at 522-523, and the mechanical claim reproduces: `$written` can never exceed `$total`, so a normal loop exit implies equality and 523 never decides the outcome.

I closed the one hole in that argument the reviewer did not check — a userland wrapper over-reporting its write. Probe in /home/dev/.cache/rutorrent-tmp/verify-21/probe.php: a `stream_write` returning `strlen($data)+1000` for a 100-byte write yields `int(100)` from fwrite on 7.4.33, 8.1.34 and host 8.5.4. PHP clamps, so `$written > $total` is unreachable on every version the suite runs. All three production callers (pending.php:184, removewithdata.php:1808 and :2045) write real filesystem paths, never a wrapper, so a partial write there just loops and the next fwrite returns 0 or false into the :515 guard.

What refutes the finding is the mutation table, run on a copy of the tree (RemoveWithDataTest.php, baseline 2765 assertions / 0 failures / exit 0, which matches the reviewer's number):
- A, delete 522-523 (the reviewer's mutation): SURVIVED, 2765 / 0 / exit 0.
- B, delete only the loop's `$complete = false;` at 517, keep 522-523: SURVIVED, 2765 / 0 / exit 0 — and 'a short write is reported as failure, not as success' PASSES (outB.txt:2192).
- C, delete both: FAILS at exactly 'a short write is reported as failure, not as success' and 'a short write publishes nothing under the final name' (outC.txt:2192-2193).

So the finding's decisive sentence — "the test's 'a short write is reported as failure' assertion is pinning the loop guard, not this one" — is FALSE. The test pins the disjunction; each guard alone satisfies it, and 522-523 alone carries the property when the loop guard is mutated away. Writing the proposed one-line comment would plant a false factual claim in the very file whose register exists because comments lied, and the house standard names that defect class explicitly.

The other half of the suggestion is also wrong. The function header at :478 documents this line: "Invariant 15 ... the whole payload is written and the count is checked against what was handed in". `$total = strlen($bytes)` is what was handed in and :522 is the check against it. Dropping the line leaves that sentence with no literal implementation, only the loop's `$written < $total` bound — and removes the guard mutation B shows is independently sufficient. Note also that the same test pins fflush and fclose STRUCTURALLY by regex (RemoveWithDataTest.php:7150-7160) precisely because no behavioural case reaches them; that file is deliberate about guards it cannot drive behaviourally, which makes a two-line redundancy in the same function a documented style, not an oversight.

Kind is also imprecise: :522 executes on every call; only its consequent is unreachable. That is a redundancy, not dead code.

Net: a real 2-line redundancy in a fail-closed durable writer, no behaviour, no false comment, no maintenance trap, and the reviewer already concedes it is fine as belt-and-braces. The only actionable content it carries is harmful. Severity collapses from minor to none. No leaked processes: the two `pgrep -cf rutorrent-scgi-rpc2` hits are other agents' shell command lines, confirmed by `ps` (verify-42's shell and my own ps invocation) — nothing of mine to kill.

**Исправление:** Change nothing. Specifically, do NOT add the proposed comment: it would state that the short-write test pins the loop guard rather than this one, which is measurably false — with the loop's `$complete = false;` deleted, 522-523 alone keeps 'a short write is reported as failure' green (2765 assertions, 0 failures), and only deleting both guards fails it. Do not drop 522-523 either: it is the literal implementation of the header's Invariant 15 sentence "the count is checked against what was handed in", and it is an independently sufficient guard. If a reviewer insists on a note, the only true one is that either guard alone refuses a short write and the test pins the pair — which is not worth two lines of comment.

---

## Опровергнутые и намеренные

- **[refuted]** "RENAMED, from testCrashAfterPartialErase..." names a test that exists nowhere in this history
- **[refuted]** php/settings.php:450-484 starts twelve lines before the comment it cites
- **[refuted]** AGENTS.md leads with "/tmp is a 1.7 GB tmpfs" while this boot has 4.5 GB
- **[refuted]** "the restore is a no-op today" is not proved for the tied-file fallback
- **[refuted]** "eleven torrents dated 8 to 15 hours late" has no provenance in the tree
- **[refuted]** chk-stime has a second writer that the "for nothing else" clause hides
- **[refuted]** erasedataGenerationIncrement's digit-lookup guard is unreachable
- **[refuted]** A loop assertion over a production-derived command list with no non-empty precondition
- **[refuted]** __DIR__ cannot differ from dirname(__FILE__), and is equally wrong under process substitution
- **[refuted]** The stock UI removal path never enters the new protocol, and the producer it does use still publishes a payload-deletion licence on one aggregate boolean
- **[refuted]** The diagnostic digest omits the generation although the comment says any change at all makes a new digest
- **[deliberate-and-documented]** NNMClub's created-by/creation-date snapshot cannot change anything on this fork, and nothing would notice its removal
- **[refuted]** The `$record !== null` re-test is dead, but the comment does not claim otherwise and the finding's stated mechanism is measurably wrong
- **[refuted]** deletionRunStatus()'s 'unreadable' detail is hand-copied at confirmDeletion's call site
- **[refuted]** The measurement behind deletionGate()'s 10 % shortening exists nowhere in the repository
- **[refuted]** The obsolete-cleanup identity predicate and its index key are written twice, once per plugin, with no owner and no gate
- **[refuted]** Three unreachable conditions left in the drain state validator and the generation pass
- **[refuted]** The missing-info-hash whitelist has three copies; the new parity gate binds two of them
- **[refuted]** The force-2 preflight pushes a raw hash into the refusal set the partition documents as tokens only
- **[refuted]** Three spellings of the background PHP spawn, one without the fd redirections
- **[refuted]** The whole new admission/drain/retirement package is off the path the web UI actually takes
- **[refuted]** openDirectoryReference/closeDirectoryReference are pure aliases, and acquireDirectoryCapability's third parameter is test-only
- **[refuted]** TestLib's erasedataExactFileAlias double is unchanged pre-branch code and is already pinned in the unsafe direction by CheckerTest
- **[deliberate-and-documented]** Two grammars for one staging-object name; the looser one produces notes the stricter one silently strips
- **[refuted]** Twenty-three expectations spell the fixture creator and date literally, beside the accessors added to stop exactly that
- **[deliberate-and-documented]** edit/action.php's authorship snapshot/restore is a documented no-op on this fork
- **[deliberate-and-documented]** /dev/fd is not a procfs fallback on any platform this fork targets, so the second descriptor candidate is unreachable
- **[refuted]** The new byte-exact scheme check disagrees with the plugin's four sibling scheme validators
