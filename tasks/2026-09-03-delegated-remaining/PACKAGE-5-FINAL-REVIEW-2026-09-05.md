# Package 5 — independent final review and remediation

Final production verdict: **APPROVED**, SHA-256 `c6992d5a02f342943cc7cf076301bccf989660d02ebc8c8677158b775cf45231`.

The two independent reports below are retained verbatim in chronological order.
Their intermediate FIX_REQUIRED verdicts are historical and superseded by the final
APPROVED section of the second report. All 42 original worker scenarios have an
explicit coverage/disposition entry. The parent subsequently adjusted only the
new shell fixture: an explicit argv receipt path works with both quiet `run.sh`
and the full harness's textual directory substitution. No production change
followed the final reviewer-approved production hash.

Final integration and container evidence:
[PACKAGE-5-14-INTEGRATION-2026-09-05.md](PACKAGE-5-14-INTEGRATION-2026-09-05.md).

---

# Package 5 final independent review: FIX_REQUIRED

Reviewed candidate: `153f8e459e834925a6824acff346439e96c93ef9` in
`.worktrees/up-retrackers-recovery`; integration baseline `72d1885c`.
Reviewer made no production/test/index/ref changes and ran no live-service probes.
Review scope: the six owned files, final delta from `8fa05ad2`, their coordinator,
codec, staging, callback and lifecycle boundaries, binding contract and retained
runtime evidence. No child agents were used.

## Important findings

### I1. Transient reconciliation failures become a permanent sleep

`plugins/retrackers/update.php:9237` implements `holdUnknownLease()` as an infinite
`usleep` with no RPC. `holdUnknownStage()` delegates to it. Several dispatch
helpers make only one receipt/observation attempt; `run()` then invokes this hold.
Consequently a delayed accepted callback or a recovered transport can never be
observed. This is especially damaging after old erase: the source is absent and
the candidate is never dispatched despite eventually available `eb+ed+ex`.

Affected paths include old commit (`9561`, `10354`), la/ra first receipt invalid
or begin absent (`9680`), ca begin/exit (`9997`), handoff release (`9316`, `10190`),
and terminal cleanup (`10041`, `10170`). Once la/ra begin has been observed, its
existing fence loop does poll; this does not cover the first-read/begin gap.

Binding contract requires read-only unbounded reconciliation, not repeated
mutating dispatch (lines 1751-1797, 1989-2008, 2076-2095, 2143-2157).
Unknown arms are explicitly different and remain restart-required; this finding
does not authorize phase dispatch after an unknown arm.

Independent `probe.php <candidate> release` calls the real `run()` with a
successfully adopted no-change handoff. Release read sequence is transport
failure, then recovered same-ID empty marker/ack. A two-second watchdog reports:
`scalar_reads=1`, recovered sample still queued, one release dispatch, zero
cleanup dispatches, live `wa`, reason `handoff-release-pending`. No production
file is changed; only test doubles receive RPC. `probe.php ... commit` separately
shows exact surviving `wa/eb/ex/ed` but one failed receipt read returns
`commit-completion-pending` into the permanent hold route.

Required correction: separate one-shot dispatch from read-only reconciliation;
continue polling reads until the approved terminal proof. Preserve capabilities
and `wa` while pending, never resend a phase mutation.

### I2. Known terminal old-commit outcomes never retire their lease

`handleCommitFailureBeforeArm()` (`9428`) accepts only `commit-skipped` and five
builder failures. `run()` (`10343-10356`) sends every other old-commit failure to
the permanent hold, including terminal `quiesce-changed`,
`partial-quiesce-ambiguous`, `commit-absent-ambiguous` and `foreign-generation`.
Thus a confirmed completed callback that correctly preserves a user/hook change
leaves `wa` and phase receipts forever, preventing normal DONE teardown.

The contract's response-lost table and terminal-retirement requirement explicitly
separate terminal quarantine from pending dispatch (1770-1790, 2165-2198).
The object must remain untouched; both never-dispatched load handles can close,
followed by capability-first terminal cleanup.

Independent `probe.php ... terminal` returns `quiesce-changed`, exactly one
commit dispatch/read and surviving `wa/eb/ex`, but
`eligible_for_dispose_in_run=false`. Static control flow then unconditionally
selects the sleep-only hold. The tested helper result is terminal under the
binding table; no invented daemon response is needed to infer the routing bug.

### I3. Unknown la/ca/ra arm replies still authorize a phase

`armPostErasePhase()` (`9639-9648`) treats later arm-key presence (and marker
capability for la/ra) as permission after a false/unknown arm reply.
`probe.php ... arm` reports `unknown_arm_authorized_dispatch=true` for all three
phases, with null failure. `testTask5LostArmReplyIsReconciledOnceWithoutRetryRevokeOrCleanup`
at `tests/plugins/retrackers/UpdateTest.php:9577` explicitly expects these true
results and therefore confirms the incorrect contract.

Contract lines 1331-1337 require the exact `RETRACKERS_PHASE_ARMED` reply for
**every** ea/la/ca/ra phase; unknown arm must retain `wa`, never dispatch and remain
visible restart-required. This is a protocol violation even though the callback
is non-yielding. The existing ea behavior is correct. Preserve the test's
one-shot/no-revoke safety assertions and intentionally supersede its authorization
expectation.

### I4. Source topology and resume eligibility are missing before erase

Contract lines 717-750 require source-metainfo normalized ordinary topology to
equal the live topology, reject runtime extras before old mutation, allow only
the exact bounded synthetic DHT exception, and inspect
`libtorrent_resume.trackers` extra/group/state eligibility.

Production `sourceTrackerState()` (`2310-2385`) merely validates the live snapshot
against itself; it accepts `extra=1`, and treats any `dht://` row as synthetic
without the specified exact type/extra/multiplicity checks. The final build
(`2865-2874`) only sees final tracker projection plus live snapshot. Neither the
original topology nor resume tracker data reaches it. `prepare()` (`5683-5718`)
preserves the original bytes but does not perform or expose this eligibility
check. `libtorrent_resume` has no production-specific inspection anywhere in the
file; `runtime-tracker-topology-mismatch` occurs only in the diagnostic whitelist.

Independent `probe.php ... topology` calls the exact production source and
candidate projection methods on an extra runtime-only tracker. It reports both
accepted with null failure; source URLs include `user-runtime-only`, final
candidate URLs do not. The full old CAS can therefore legitimately accept the
captured runtime extra and erase it, while successful candidate creation drops
that user-added tracker. Resume-only reinsertion can instead cause a late
creation assertion failure after old erase, where the contract required refusal
before mutation.

The current topology assertion test (`9152`) verifies the **post-load** assertion
against synthetic rows. It cannot prove the required precommit source/resume gate.
Implement the already-approved source/resume comparison with actual loader
provenance and named before-arm rejection tests. Do not infer loader behavior
from the existing synthetic expected rows.

Follow-up exact tagged-source inspection confirms an additional facet of I4:
`candidateTrackerState()` at 2414 invents an extra top-level announce row when a
valid nonempty announce-list excludes it. Both target loaders ignore that
announce instead. Both also filter protocols case-sensitively and advance group
numbers only when inserted rows change `size_group()`, while the candidate uses
a case-insensitive protocol test and literal input group indexes. This can turn
a predictable normalization case into a post-erase creation assertion failure.
Source references were read from public GitHub without git fetch or writes:

- [0.13.8 constructor](https://raw.githubusercontent.com/rakshasa/libtorrent/v0.13.8/src/download/download_constructor.cc), `parse_tracker` / `add_tracker_group` / `add_tracker_single`.
- [0.16.21 constructor](https://raw.githubusercontent.com/rakshasa/libtorrent/v0.16.21/src/download/download_constructor.cc), same methods.
- [0.13.8 tracker list](https://raw.githubusercontent.com/rakshasa/libtorrent/v0.13.8/src/torrent/tracker_list.cc), `insert_url` / `size_group`.
- [0.16.21 tracker list](https://raw.githubusercontent.com/rakshasa/libtorrent/v0.16.21/src/tracker/tracker_list.cc), same methods.
- [0.13.8 resume](https://raw.githubusercontent.com/rakshasa/libtorrent/v0.13.8/src/torrent/utils/resume.cc) and [0.16.21 resume](https://raw.githubusercontent.com/rakshasa/libtorrent/v0.16.21/src/torrent/utils/resume.cc), `resume_load_tracker_settings`.

## Minor finding

### M1. `new.static` is unchanged in the final increment but new to integration

Archived exact PHPStan runs on `8fa05ad2` and `153f8e45` both report only
`new.static` at `4426` / `4508`, from `RetrackersAnonymousStage::create()`.
This is not a new final-increment runtime regression. However `72d1885c` has
neither that class nor the `new static` expression, and repository CI scans
production plugins at level 0 with PHP 7.4. The diagnostic will therefore be new
to the master integration. No concrete production constructor failure was found;
the subclass is intentional test instrumentation. Resolve constructor
consistency/finality without broad suppression before claiming absolute static
GREEN. A new independent PHPStan run was not performed by this reviewer;
the archived JSON and current CI/config/code were inspected directly.

### M2. The old real shell-argv regression test was lost

Old scenario 28 executes `run.sh` with a fake executable and checks exact argv.
The candidate's awkward-path test examines hook escaping; invalid-CLI tests
execute `update.php` directly. Neither executes `run.sh`. Thus changing the shell
wrapper's quoting would no longer fail these tests. The production wrapper itself
matches the approved quoted `cd`/`exec` contract and no current shell defect was
found. Preserve that independent subprocess property in the replacement suite.

## Verification actually performed

- Real `TestCase::run()`, host PHP 8.5, literal `memory_limit=128M`:
  **217 methods / 1044 passed assertions / no failure signal / exit 0**.
  Reviewer `run.php` calls `setUp`, `run`, `tearDown`, and converts printed test
  failure signals into a nonzero exit.
- Full host `bash tests/php-test.sh` outside the PID sandbox: **exit 0**.
  Output includes baseline deprecation warnings; no failing test result.
- Frozen sequence through the same real runner: **12 methods / 40 assertions**.
- Recomputed frozen sorted-name SHA:
  `0ee7b35f9cda898d00e963b7e23aff02351e3653db21bbf2e99e31a34d5c7044`.
- Recomputed class-through-EOF SHA:
  `f0dac045fa3b9e98172132977e05fa14b7f091d1b9779a989d8b1d047fecc8f3`.
- Six-path diff from `4682a761`; `git diff --check` passes.
- All 16 lifecycle pairs / 32 RAW files independently read and matched against
  both identical samples, manifest RAW/BODY/request sizes and hashes, exact
  production request, current restricted decoder and historical classifier.
  Reviewer `evidence.php` is read-only and creates no replacement evidence.
- Retained 0.9.8 and 0.16.21 loss/late/active worker logs and lab driver inspected:
  they exercise real callback execution and hide the reply **after** send
  completes. Their fresh receipt reads succeed. They do not disprove I1's delayed
  first receipt / transient read case. Their late callbacks are replayed after
  retirement, not while the callback still lacks a begin/tail proof.
- Initial approval timeout for the focused host run was retried once; the retry
  succeeded. No rejected action was bypassed. Early exploratory sandbox
  terminal probes hit a staging boundary and are not counted as evidence; the
  final direct helper probe above has no staging ambiguity.

The archived PHP 7.4/8.1/8.5 acceptance and memory results were read as supporting
evidence, not represented as new independent container runs. Since required
production corrections are outstanding, the exact corrected candidate needs a
fresh focused matrix and a final review of those changes before approval.

## Crosswalk of all 42 master worker scenarios

The master suite contains exactly 42 literal `$tests[...]` registrations. The
candidate has 217 unique public test methods plus the separate frozen 12-method
sequence suite. Counts are not an arithmetic union: several old expectations
encode the superseded sendTorrent/hash-return protocol.

Current-test keys below refer to exact methods in `UpdateTest.php`:

- A: `testRunCandidateSuccessUsesOneExactWholeTransactionOrder`
- B: `testTask4AtomicCommitBuilderFreezesEveryOuterAndInnerCasDimension`
- C: `testSourceSelectionAndInitialOwnershipLifecycleGuardsAreFailClosed`
- D: `testTask5OmittingAnyBaselineCreationFieldBreaksExactCandidateAndRollbackPlans`
- E: `testTask5CandidateReadyAckZeroConfirmsDespiteMutablePostEventDrift`
- F: `testTask5AbsentCandidateIsAmbiguousAndNeverAuthorizesRollback`
- G: `testTask5RollbackRequiresExactCleanupDoneExitAndFreshAbsence`
- H: `testTask5RollbackTerminalFailuresNeverCleanupOrThirdLoad`
- I: `testTask5WorkerNeverAddsDStartAfterLoadStart`
- J: `testMetainfoAuthorityUsesOneCapturedBytesTorrentAfterHashGateAndNeverRereadsPath`
- K: `testCandidateRewriteChangesOnlyTrackersAndPreservesRawProtectedSpans`
- L: `testFinalCandidateRescanRejectsInfoTrackerAndProtectedSpanMutations`
- M: `testBencodeScannerRejectsMalformedTrailingNoncanonicalAndDuplicateKeys`
- N: `testRunReleasesAuthenticatedPrivateAndMetaNameExclusionsWithoutPreparing`
- O: `testDirectAdapterNeverRetriesNullMalformedFaultOrDelayedRawReplies`
- P: `testSourceMissingTargetHasADedicatedTerminalClassification`
- Q: `testWorkerFailureRecorderPersistsOnlyCanonicalHashAndClosedReason`
- R: `testTask4RunDisposesEveryReachablePreFenceFailure`
- S: `testTheFunctionalInsertActionNeverStopsClosesOrErasesADownload`
- T: `testTheFunctionalInsertActionOrdersItsReceiptsAroundTheLaunch`
- U: `testAnAwkwardScriptOrPhpPathCannotChangeTheLaunchArgvShape`
- V: `testCliValidationAcceptsOnlyAnAuthenticatedDenseHandoff`
- W: `testInvalidCliArgvExitsBeforeLoadingOrWritingRuntimeDependencies`
- X: `testTask5RollbackUsesOriginalBytesAndDistinctRaRbRfFencePath`
- Y: `testTask4ResponseLostReconciliationNeverMutatesOrRedispatches`
- Z: `testRunPartialCandidateUsesOneCleanupAndRollbackOrder`
- AA: `testAllThreeInsertVariantsShareTheMarkerAndAckHead`
- AB: `testTask5InitAndDoneWiringUsesImportOnlyAndAvoidsLegacyHooks`

| # | Exact master scenario | Current coverage / approved disposition |
|---|---|---|
| 1 | happy path loads candidate once without rollback | A, E. Old custom3 stamp and request-count expectations superseded by dedicated marker protocol. |
| 2 | erase is one generation-checked daemon commit | B, Y. Expanded two-CAS/receipt protocol. |
| 3 | started handoff rejects a stopped live generation | C, B. |
| 4 | stopped handoff rejects a started live generation | C, B. |
| 5 | candidate and rollback start state follows the immutable handoff | D, X, Z. State preservation retained; absence-triggered rollback setup superseded by G. |
| 6 | candidate send false is not processed | E, F, G. Local send return is not success or rollback authority; exact fence/ready/ack decides. |
| 7 | candidate wrong hash is not processed | L, E, F. sendTorrent return-hash API is removed; wrong captured info hash rejected before erase. |
| 8 | candidate confirmation requires exact canonical hash | V, E, F. Exact canonical request target and native ID typed boundaries retained; casefolded local send result no longer exists. |
| 9 | source hash mismatch fails before erase | J and `testBencodeScannerRequiresOneRawInfoValueWithTheExpectedSha1`. |
| 10 | candidate hash mismatch fails before erase | L. |
| 11 | malformed metainfo fails before erase | M, J, R. |
| 12 | unconfirmed erase never falls through to d.start | Y, I. Eventual reconciliation missing: I1. |
| 13 | confirmed absence rolls back immutable original once | Intentionally rejected by F. Approved owned cleanup prerequisite G; X retains exact rollback bytes/state. |
| 14 | confirmed present after unconfirmed send does not blind-load | E, F, G; send result alone never authorizes second load. |
| 15 | unknown presence does not blind-load | O, Y, F, G. Eventual pending loop is missing: I1. |
| 16 | clean empty presence is unknown and does not rollback | P and strict RAW scalar plan; F/G do not authorize blind rollback. |
| 17 | missing fault after transport failure is unknown | O, P; raw boundary refuses missing transport before parsing any attached fault. |
| 18 | partial missing-hash fault is unknown | P uses exact captured family/code/type/message; no substring matching. |
| 19 | rollback failure remains unsuccessful | H. Rollback requires G first. |
| 20 | rollback confirmation requires exact canonical hash | V, H; exact target/typed native ID plus ready/ack/fence replaces local returned-hash proof. |
| 21 | unknown presence after erase issues neither rollback load nor d.start | O, F, G, I. |
| 22 | failed rollback never starts an unconfirmed hash | H, I. |
| 23 | confirmed-present unconfirmed send does not issue redundant d.start | E, I. |
| 24 | one immutable byte snapshot survives source replacement and rollback | J, K, X. Old absence-triggered rollback setup intentionally superseded by G. |
| 25 | private and legacy meta-name exclusions leave the live torrent untouched | N tests exact private/config encodings and exact uppercase-hash `.meta` identity. |
| 26 | malformed and unexpected failed reads are sanitised early exits | O, Q, R; safe marker-only disposal after adoption is now required. |
| 27 | one hook hands immutable state and generation to the worker without stopping | S, T; user-hash binding and durable wh/wp precede launch. |
| 28 | run.sh executes worker with exact argv handover | Missing direct shell subprocess coverage: M2. U checks hook escaping and W calls PHP directly; neither proves shell argv. Current wrapper itself is correct. |
| 29 | started handoff remains untouched after initial transport fault | O, C, R, I. Reserved handoff disposal is approved; user state untouched. |
| 30 | initial transport failure has one classified diagnostic | O, Q, R; reason spelling moved to closed `initial-transport` vocabulary. |
| 31 | initial transport failure outranks an attached fault | O: null transport result returns before restricted RAW parsing. |
| 32 | initial RPC fault is classified without its secret | Q and `testSourceScalarTopLevelFaultRemainsAnRpcFault`. |
| 33 | mixed missing-hash fault leaves started generation untouched | P, O, I; exact family fault matching only. |
| 34 | only exact known missing-hash faults skip recovery | P. Old loose punctuation variants and total silence superseded by exact captured wire fault and approved `initial-absent` classification. |
| 35 | failed transport with attached missing-hash text stays non-mutating | O, P, R. |
| 36 | decorated missing-hash faults remain uncertain and non-mutating | P, O, I. |
| 37 | stopped snapshot does not start after transport fault | C, O, I. |
| 38 | invalid or missing snapshot fails before RPC or mutation | V, W and `testCliValidationRejectsNonStringsAndTrailingNewlines`. |
| 39 | invalid CLI snapshot exits before dependency loading | W. Silent invalid input remains side-effect-free; new explicit CLI returns nonzero. |
| 40 | missing hash snapshot remains quiet | P/Q. Literal quiet-log expectation intentionally superseded by required `initial-absent`; stale hash never becomes a raw exceptional RPC log. |
| 41 | successful flow has no extra restart | A, I. |
| 42 | single hook preserves transaction legacy and service branches | AA, AB preserve transaction/legacy ordering. Service label/marker guards are explicitly P3 and excluded from P5. Old one-key/no-second-hook expectation is superseded by F/S pair and acknowledged deletion of both historical keys. |

The old shell subprocess regression property is missing (M2); other old safety
properties are represented after separating superseded expectations. This does
not close the new protocol obligations I1-I4.

## Verdict

Critical: none confirmed. Important: I1-I4. Minor: M1-M2.
**FIX_REQUIRED** for `153f8e45`. Prior increment reviews and passing suites do not
override the concrete production/control-flow and contract failures above.

---

# Package 5 bounded remediation re-review

## Final superseding verdict: APPROVED

The remaining post-event regression below is fixed in these final SHA-256 files:

- update.php: `c6992d5a02f342943cc7cf076301bccf989660d02ebc8c8677158b775cf45231`
- UpdateTest.php: `7698eb19bab92f297130b0b3c81a64f69bfd27031f7966304cfe9815d576b02b`

`sourceTrackerState` now takes initial=false by default; only sourceEligible and
initial obligation build pass true. Post-event canonical tuples retain ordinary
HTTP/UDP extras and DHT extras. Only one exact synthetic dht:// type3 extra0 row
is represented by the DHT presence flag, so extra rows cannot disappear from a
partial-cleanup tuple. The new candidate/rollback HTTP-extra/DHT-extra regression
also asserts that a claim with extra rows cannot become candidate-partial.

Independent original staging probe rerun: both baseline and post-event extra
return candidate-confirmed, exit0. A separate pure classifier route produced the
same result. After an automatic-approval timeout, the single permitted retry
succeeded; no test permission blocker remains.

Fresh full focused real TestCase::run, assertions enabled and 128M limit, outside
the PID sandbox: **221 methods / 1097 assertions / exit0**. This includes the six
new post-event assertions and the retained initial negative eligibility cases.
Final PHP lint and git diff --check pass. No Critical, Important or Minor findings
remain within this bounded re-review. This approves these exact six-path package
contents for integration, not an unrelated integration delta or an unexecuted
runtime matrix. Parent independently owns the final container/runtime gates.

The historical findings and intermediate hashes below are retained as evidence,
not as unresolved blockers.

Candidate HEAD remains `153f8e459e834925a6824acff346439e96c93ef9`.
Reviewed dirty file SHA-256:

- update.php: `153cb9f18a8ee2d5254aaed62f6b9de96b9a6750712ecd8bb5a35baaac51ae0e`
- UpdateTest.php: `50558053f3b4db2d70d200d326fa984e1a571b85671483e85603a2c7e3427bdb`

## Verdict at these hashes: FIX_REQUIRED

One Important regression remains. No Critical or additional Minor findings.
The six original findings in REVIEW-153f8e45.md have been addressed, subject to
the new shared-helper regression below.

### Important: initial eligibility restrictions leak into post-event confirmation

`sourceTrackerState()` now rejects ordinary tracker `extra=1` at update.php:2531
and adds initial-only synthetic DHT restrictions at 2522. But `capturedTuple()`
calls this helper at 2994, and candidate, rollback and cleanup observation
classifiers all call `capturedTuple()` before recognizing authenticated ready/ack.
Consequently a valid tracker inserted by an authoritative sibling/user action
after load is classified unconfirmed rather than successfully confirmed/released.
This contradicts binding lines 2022-2034: ready+ack and hashing_failed=0 succeeds
independently of post-event mutable state.

Independent read-only `post-event-probe.php` uses the existing obligation fixture
and adds one valid HTTP tracker with group=2, extra=1, enabled=1 after authenticated
ready/ack. Baseline result is `candidate-confirmed`; with the post-event row it is
`candidate-unconfirmed`. Only doubles and disposable anonymous staging are used.
The current 220-method suite passes because its mutable-drift case changes scalar
fields, not tracker extras. Keep initial eligibility strict but separate it from
post-event canonical tuple parsing; add candidate and rollback drift assertions.

## Independently verified corrections

- One-shot commit/load/cleanup/release/terminal cleanup calls are outside
  `awaitRead`; retry closures contain reads only. Production run/finish paths
  pass wait=true. Unknown arms require exact sentinel and intentionally do not
  reconcile into dispatch. Known terminal old outcomes close both unused
  descriptors and retire without target release/restart.
- Post-lf/rf one-snapshot terminal classification is consistent with narrower
  binding rules. The ca completion read can wait for actual fresh absence.
  Terminal suffix after wa removal retains the permitted bounded unconfirmed
  result; it does not reopen mutation authority.
- Source topology projection follows tagged libtorrent v0.13.8/v0.16.21 loader
  semantics already independently read: valid announce-list authority, empty or
  unsupported tiers do not consume a group, six ASCII trim characters,
  case-sensitive supported schemes. Final strict normalization refusal is
  intentional. Resume deleted/non-source URL refusal, including nonordinary DHT,
  follows binding 734-742 rather than introducing an unapproved repair policy.
- Optional typed descriptors come from the existing bencode scanner, not a
  second grammar. Only announce-list and resume trackers descend into retained
  children. Descriptor nodes are bounded at 16,384; info and other resume data
  remain raw. `projection-bound-probe.php` measured 16,383 leaves plus a tier
  accepted at 12 MiB peak and the next leaf refused at 14 MiB under 128M.
- Real `RetrackersMetainfoAuthority::prepare()` receives the captured snapshot
  from run and applies sourceEligible before candidate building/staging on the
  changed path. No-change does not enter destructive work.
- final protected anonymous-stage constructor fixes new.static without changing
  subclass construction; focused fixtures continue to instantiate the stage.
- The added real run.sh subprocess test restores old scenario 28's safety
  property: an awkward PHP path, empty/quoted argv and child exit23 survive.
  The 42-row integration crosswalk in the original report remains applicable;
  arm authorization expectation/name is intentionally superseded by the binding.

## Fresh verification

Ran real `TestCase::setUp/run/tearDown`, not a hand-picked helper loop:

`php -c tests/php-test.ini -d memory_limit=128M review5/run.php <candidate>`

Executed outside the PID sandbox; **220 methods / 1091 assertions / exit0**.
Assertions are enabled by the explicit ini. The new post-event probe exposes a
coverage gap despite this GREEN. Sequence suite rerun: **12 / 40 / exit0**.
PHP lint on both dirty files, shell syntax, and git diff --check pass.
The full host harness was independently run on the original candidate; this
bounded pass reran focused/sequence checks only. Parent owns current container
matrix execution. No production/test/index/ref edits or live-service calls were
made by the reviewer; only artifacts in this review directory were written.
