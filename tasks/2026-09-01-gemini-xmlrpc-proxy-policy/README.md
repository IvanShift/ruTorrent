# Gemini implementation brief: package 14 `xmlrpc-proxy-policy`

Status: `READY FOR DELEGATED RED-FIRST IMPLEMENTATION / REQUIRES INDEPENDENT FINAL REVIEW`

Prepared against:

```text
ruTorrent upstream base: 495e2a54a657efcc132dc1456db8d7e680304a8a
required merged parent:  7e77ebf0  (#3228 httprpc refusals)
fork donor snapshot:     4d779ff93cc7c30859cb30de8d6f5148c9b52a36
rTorrent 0.9.8 source:   6154d169
rTorrent 0.16.21 source: 109a20c09c3cab9eb13c2d96ea79362ac6c318fc
```

This is a self-contained assignment. Before doing anything, read completely:

1. this file;
2. repository-root `AGENTS.md`;
3. `.codex/skills/rutorrent-fork/SKILL.md`;
4. `tasks/2026-08-28-upstream-delivery/REVIEW-xmlrpc-proxy-policy-2026-08-29.md`.

The review is design provenance; this file is the executable task. If they
contradict, stop and report the exact contradiction rather than inventing a
third policy.

## 1. Objective and current production defect

Build a clean upstream candidate for package 14 from the exact base above.

The current `sanitize` mode is not a complete security boundary. On supported
legacy rTorrent, `UNTRUSTED_CONNECTION` does not protect the command map, so
forwarding a dangerous call as “untrusted” may still execute it. The current
proxy also leaves structural gaps:

- four registered verbose `load.*` methods bypass the canonical load owner;
- direct evaluators and persistent command carriers can pass through;
- direct multicalls can carry executable grammar in filter/result slots;
- `system.multicall` recursion preserves scanner/parser differentials;
- direct directory setters can escape `$topDirectory` on legacy rTorrent;
- malformed XML and malformed owned calls can fall back to raw forwarding;
- plugin-local defaults can ignore the common policy file;
- diagnostics can expose caller-controlled method/path/argument text.

The final `sanitize` policy must fail closed for every owned executable shape,
while retaining raw/untrusted compatibility only for well-formed, ordinary,
non-owned methods. This is production code. Work RED first.

## 2. Base, authority and stop conditions

Before creating a branch:

```sh
git fetch upstream master
git rev-parse upstream/master
git merge-base --is-ancestor 7e77ebf0 upstream/master
git cat-file -e 4d779ff93cc7c30859cb30de8d6f5148c9b52a36^{commit}
git status --short
```

Expected upstream SHA is exactly:

```text
495e2a54a657efcc132dc1456db8d7e680304a8a
```

If upstream moved, return `BASE_DRIFT` with the new SHA and the seven-path diff
from `495e2a54`; do not silently rebase the contract.

Stop without editing if:

- an exact object is missing or #3228 is not an ancestor;
- the target worktree/branch contains unrelated work;
- a baseline count/hash below differs on the exact base;
- a frozen policy decision is ambiguous;
- satisfying the contract requires an eighth path;
- a required runtime cannot be produced without changing this repository;
- making tests green would require deleting an upstream parser contract.

Never use production credentials or a live service for mutating probes.

## 3. Isolated worktree

Do not switch the primary checkout:

```sh
BASE=495e2a54a657efcc132dc1456db8d7e680304a8a
BRANCH=gemini/xmlrpc-proxy-policy
WORKTREE=/home/dev/Documents/my_projects/ruTorrent/.worktrees/gemini-xmlrpc-proxy-policy

test ! -e "$WORKTREE"
! git show-ref --verify --quiet "refs/heads/$BRANCH"
git worktree add -b "$BRANCH" "$WORKTREE" "$BASE"
cd "$WORKTREE"
test "$(git rev-parse HEAD)" = "$BASE"
test -z "$(git status --porcelain)"
```

If `tests/node_modules` is absent, a temporary symlink to the primary
checkout's existing directory is allowed. Never commit it; remove only the
symlink before finalizing.

Do not touch these user files in the primary checkout:

```text
logs_90471600543.zip
logs_90485329911.zip
logs_90525388665.zip
rutorrent-app-errors.log
```

## 4. Exact seven-path scope

Final changes may exist only in:

```text
conf/xmlrpc_proxy.php
php/xmlrpc_proxy.php
plugins/httprpc/action.php
plugins/httprpc/conf.php
tests/php/XMLRPCProxyTest.php
tests/php/XMLRPCProxyContractFixture.php
tests/php/XMLRPCProxyEntrypointTest.php
```

Explicit exclusions include:

```text
rpc2.php
php/xmlrpc.php
php/xmlrpc_path.php
php/scgitransport.php
tests/php/XMLRPCProxyContractTest.php
tests/php/XMLRPCProxyRejectionTest.php
plugins/erasedata/**
plugins/rutracker_check/**
tasks/**
AGENTS.md
.codex/**
.gitignore
docker-rutorrent/**
```

Run the excluded contract/rejection tests, but do not edit them. Exercise the
real `rpc2.php` through a copied-entrypoint test. The fork donor is evidence and
a hunk source only: do not overwrite upstream files wholesale, especially the
fixture, because the fork historically lost upstream #3209/#3211 contracts.

## 5. Non-goals

Do not:

- write a second rTorrent command-language parser;
- recursively sanitize `system.multicall`;
- change SCGI transport/framing/timeouts/limits;
- edit `rpc2.php` or add a shared path resolver;
- implement the later httprpc-erasedata consumer;
- change erasedata behavior;
- filter `passthrough_unsafe`;
- remove ordinary raw/untrusted compatibility from `sanitize`;
- expand safe-command config merely to pass tests;
- treat root `/` as confinement without explicit opt-in;
- couple local-torrent-path and root-output permissions;
- add PHP 8-only syntax, PHPUnit or Composer;
- push, open a PR, merge to `master`, or alter an existing branch.

## 6. Exact-base preservation baseline

Extract test names by reflection, as the runner does, not by grep. Normalize
each set as unique names beginning with `test`, `SORT_STRING`, joined by LF with
no final LF, then SHA-256.

Expected exact-base sets:

| Artifact | Count | SHA-256 |
|---|---:|---|
| `XMLRPCProxyTest` | 79 | `bd23e5f565b43e7b3ab9f3a7547125401595db70995e0df12a9a1fd282157b0e` |
| `XMLRPCProxyContractTest` | 6 | `812d504552cf6d666d0e0fb5fdc56aa561d14c12873663b5b2aefd777efdc9fb` |
| `XMLRPCProxyEntrypointTest` | 8 | `ceb5099a223ba6115d56ab407d555059bf62fcd9ec068b4cbed15af2763636c9` |
| fixture top-level keys | 70 | `cb825f0b593fefe09cfe31367fc92ced41d92764dc93578d7893b60f703866d0` |

Abort on duplicate or empty extraction. Save sorted sets outside the repo for
final subtraction.

These ten #3209/#3211 parser tests must survive by exact name and behavior:

```text
testPreQuotedValueIsKeptAsOneArgument
testCrossSeedQuotedLoadParamsAreKept
testQuotedDollarPrefixedArgumentIsDropped
testUnclosedQuoteIsDropped
testQuotedDirectoryInsideTheBoundaryIsKept
testAnEscapedCommaDoesNotSeparateArguments
testAnEscapedCommaAtTheEndOfAValueIsKept
testAnUnescapedCommaStillSeparates
testABackslashThatIsNotBeforeACommaIsKept
testATrailingBackslashIsStillJustAValue
```

Run the three focused files plus unchanged `XMLRPCProxyContractTest` before
editing. Repeat socket-opening suites outside a sandbox if they report
`Operation not permitted`; that message is not product evidence.

## 7. Frozen mode and precedence policy

`XMLRPCProxy::decide()` owns policy. Both doors must use its exact payload and
trust bit without recomputation.

Fixed precedence:

1. mode short-circuit;
2. direct exact/family denial, including direct directory setters;
3. structural load/direct-multicall owner;
4. configured safe names inside those owners;
5. elevated exact-shape owner;
6. ordinary unknown fallback.

| Mode/input | Exact result |
|---|---|
| `off`, any bytes | reject, payload `''`, `trusted=false`, zero send |
| invalid mode | same terminal behavior as `off` |
| `passthrough_unsafe`, any bytes | original bytes, trusted, one send |
| `sanitize`, valid owned structure | canonical rebuilt result |
| `sanitize`, invalid owned structure | terminal reject, never raw fallback |
| `sanitize`, well-formed ordinary method | original bytes, untrusted |
| `sanitize`, malformed XML/no method | terminal reject |

`off` runs before parsing. `passthrough_unsafe` runs before any deny.

## 8. Exact direct deny policy

Exact evaluator deny set:

```text
catch
branch
try
and
or
less
greater
equal
match
```

Matching is exact. Required no-overblock examples:

```text
if
not
compare
catch.extra
branching
trying
```

Persistent/direct carriers:

```text
exact:  p.call_target
prefix: directory.watch.
exact:  view.filter
        view.filter.temp
        view.sort_new
        view.sort_current
        view.event_added
        view.event_removed
```

`directory.watch.` covers legacy `added` and modern `added`/`ready`. Do not
overblock `view.filter_on`, `view.sort`, `view.set`, or `directory.watchful`.

Always deny direct calls to:

```text
d.directory.set
d.directory_base.set
```

They remain eligible only as rebuilt command slots with path-boundary checks.

Final prefix deny set is exactly:

```text
execute
method.
import
try_import
schedule
log.
network.scgi
session.path.set
directory.default.set
system.env
system.shutdown
```

Do not leave evaluator names in this prefix set. Configured safe names never
override a deny.

## 9. One owner for eight registered dot-loads

Owned URI loads:

```text
load.normal
load.start
load.verbose
load.start_verbose
```

Owned raw loads:

```text
load.raw
load.raw_start
load.raw_verbose
load.raw_start_verbose
```

Unsupported underscore spellings `load_start`, `load_raw_start`, `load_raw`
are ordinary unknown methods: raw/untrusted on this supported boundary.

For each owned load:

- structurally parse the outer call;
- canonicalize/re-emit the first two value slots;
- parse every trailing command with the existing accepted argument parser;
- keep only exact configured-safe commands whose arguments fully rebuild;
- strip denied/unsafe/unconfigured trailing commands while retaining a safe
  base load;
- terminally reject an owned shape that cannot be parsed/re-emitted;
- never raw-forward original bytes as fallback.

URI recognition must match rTorrent case-sensitively: `http://`, `https://`,
`ftp://`, `magnet:?`. Local URIs reject without local-path opt-in. That opt-in
does not grant root output permission.

Raw loads never interpret metainfo as a path. After base64 decode/re-encode,
the torrent bytes must be identical, including binary/NUL and wrapped base64
cases. Trust is true only when every retained slot is rebuilt.

## 10. Six all-or-nothing direct multicalls

Owned direct multicall set:

```text
d.multicall
d.multicall2
d.multicall.filtered
t.multicall
f.multicall
p.multicall
```

Classify and rebuild every target/view/filter/result slot according to the real
method shape. In `d.multicall.filtered`, the filter after target/view is an
executable slot; every fixture must include filter plus at least one result.

Policy is all-or-nothing:

- all slots parse/rebuild: send canonical rebuilt outer call;
- any unknown/malformed/unrebuildable executable slot: reject the outer call;
- never drop a member and return a shortened row;
- never raw-forward the original call untrusted.

Adversarial grammar must include `$execute=...`, `(execute,...)`, nested
parentheses, unclosed quotes, malformed escaping, unsafe filtered filter, mixed
safe/unsafe members and control bytes in an inner name. Fault/log identity is
always the normalized outer multicall name, never an inner name.

## 11. `system.multicall`

In `sanitize`, reject `system.multicall` unconditionally, even if all nested
members look benign. Do not selectively recurse. Compatibility is available
only through the explicit `passthrough_unsafe` mode.

## 12. Exact elevated shapes

| Methods | Canonical shape |
|---|---|
| `d.open`, `d.start`, `d.stop`, `d.delete_tied` | `hash` |
| `d.custom1.set` … `d.custom5.set` | `hash, text` |
| `d.custom.set` | `hash, text, text` |
| `d.priority.set` | `hash, int` |
| `network.xmlrpc.size_limit.set` | `empty, size` |

Rules:

- `hash`: exactly 40 hex, re-emitted uppercase;
- `text`: XMLRPC string data, never command grammar;
- `int`: signed canonical decimal, maximum 18 digits excluding sign;
- `size`: positive canonical decimal, maximum 18 digits, clamp to `16777216`;
- `empty`: required empty target string.

Wrong type/count/lexical form/range for these exact methods is terminal reject,
not raw/untrusted fallback. Cover arrays, base64, whitespace, leading zero,
plus sign, overflow, wrong count, negative size and empty/nonempty inversions.

## 13. Config and independent path switches

Required load order:

```text
conf/xmlrpc_proxy.php
  -> unset-only defaults in plugins/httprpc/conf.php
  -> plugins/httprpc/conf.local.php
  -> conf/users/<user>/plugins/httprpc/conf.php
```

Plugin config imports common config before defaults. Every default is
unset-only. Existing local/per-user loaders remain later authority.

The switches are independent and need all four boolean quadrants:

```text
$XMLRPCProxyAllowLocalPaths
$XMLRPCProxyAllowRootDirectory
```

Setter-slot root matrix:

| `$topDirectory` | root opt-in | httprpc | copied `rpc2.php` |
|---|---:|---|---|
| bounded path | false/true | only resolved inside boundary | same |
| `/` or empty | false | strip from load; reject direct multicall | existing pre-decision 503 |
| `/` or empty | true | explicit root boundary accepted | endpoint enabled with root `/` |

Direct directory setters remain denied in every row. Use the existing
endpoint-local resolver; do not edit/create `php/xmlrpc_path.php`. Validate
deepest existing ancestor for missing tails and reject symlink escapes or an
unanswerable boundary.

## 14. Doors, refusals and trust

Doors:

1. raw/default branch of `plugins/httprpc/action.php`;
2. production `rpc2.php`, exercised through a copied-real test because the file
   itself is excluded.

Both doors must send exactly `decision['payload']` with exactly
`decision['trusted']`. A reject makes zero SCGI sends, returns HTTP 403, XMLRPC
fault `-501`, names only the normalized outer method and explicitly exits.

Preserve package 3 outcomes:

```text
unreadable body -> classified HTTP 400
empty body      -> classified HTTP 400
policy refusal  -> HTTP 403 / XMLRPC -501
transport fault -> neutral HTTP 500
success         -> exact raw response
```

Do not change non-proxy handlers in `action.php`.

## 15. Bounded classified diagnostics

Each decision returns exactly one classified record, regardless of whether the
caller logs it.

- formatted proxy message before logger/timestamp prefix: maximum 512 bytes;
- normalized outer method: maximum 96 bytes;
- allowed method bytes: `[A-Za-z0-9_.:-]`;
- every other byte becomes `?`;
- truncation/omitted-count markers are inside the 512-byte limit.

Routine fault/log text must never contain raw payload, argument, path, URI,
metainfo, inner method, raw daemon fault, guessed daemon outcome, CR/LF/TAB or
other request control bytes. Raw transcripts remain only in existing opt-in
core diagnostics.

## 16. RED-first execution

### A. Freeze base

- extract all four baseline SETS/counts/hashes;
- prove the ten parser tests exist;
- run focused base suites;
- record current result for every policy row.

### B. Add tests and witness natural RED before production edits

Required RED/preservation matrix:

1. four verbose dot-loads bypass canonical rebuilding;
2. each of nine evaluators is measured individually;
3. exact near-miss no-overblock cases expose current/future prefix mistakes;
4. `p.call_target`, legacy/modern watch names and six view carriers individually;
5. both direct directory setters;
6. `$...`, parentheses and malformed grammar in all six multicall families;
7. unsafe `d.multicall.filtered` filter;
8. benign and malicious `system.multicall` forwarding;
9. malformed XML/no method raw forwarding;
10. wrong-shape exact elevated calls falling back raw/untrusted;
11. common/default/local/per-user and root policy gaps;
12. attacker path/control/data in diagnostics;
13. malformed owned load reaching fallback;
14. mixed safe/unsafe direct multicall not being all-or-nothing;
15. both-door trust/status/fault/exit behavior.

Existing safe rows may be preservation GREEN. Record actual behavior rather
than weakening code to manufacture RED. A fatal before the named test is not
evidence.

### C. Freeze the new test surface

Before implementation, reflect complete new test SETS and execute complete
fixture key SET; pin unique exact counts/hashes outside the repo and prove all
ten upstream parser names remain.

### D. Implement in ownership order

1. mode/terminal decision helpers;
2. normalized bounded diagnostics;
3. exact evaluator/carrier/directory/family denies;
4. eight-load canonical owner;
5. six direct-multicall all-or-nothing owner;
6. unconditional `system.multicall` reject;
7. exact elevated owner;
8. config precedence/root option;
9. httprpc and copied-real rpc2 door integration;
10. fixture refresh from actual corrected behavior.

Run focused tests after every layer.

## 17. Test quality

Tests must:

- execute copied production entrypoints where endpoint behavior is claimed;
- assert send count, payload bytes, trust, HTTP status, fault code and exit;
- use exact names plus near-miss names;
- cover explicit/implicit string, base64 and integer carriers;
- cover binary/NUL metainfo;
- cover missing/extra/duplicate/malformed parameter shapes;
- cover all four root/local switch quadrants;
- use real symlink/missing-tail fixtures;
- prove diagnostics are single-line, bounded and leak-free;
- assert normalized outer identity for nested refusal;
- compare nonempty unique SETS, not grep counts.

Do not rename/delete a failing upstream parser test or replace the fixture
wholesale with the fork version.

## 18. Mandatory mutation campaign

Use a disposable mutation worktree or mutate one semantic, run the named test,
restore exact bytes, then prove GREEN. For every row report mutation, expected
test, exit, proof it ran, fatal scan, and recovery exit.

Required mutations:

1. remove each of 8 owned dot-load names individually;
2. remove each of 9 evaluator denies individually;
3. remove `p.call_target`, watch prefix and each of 6 view carriers individually;
4. allow each direct directory setter individually;
5. convert evaluator exact matching to prefix and catch near-miss overblock;
6. let configured safe names override denial;
7. restore malformed/no-method raw fallback;
8. let malformed owned load raw-forward;
9. let each invalid elevated shape raw-forward;
10. remove common config import;
11. overwrite common config from a default;
12. invert common/local or local/per-user precedence;
13. classify before `off`;
14. apply denies inside `passthrough_unsafe`;
15. swap URI/raw load classes;
16. alter one raw metainfo byte;
17. inspect only direct-multicall outer name;
18. skip filtered filter slot;
19. allow member dropping/partial multicall;
20. restore direct-multicall raw fallback;
21. restore selective/raw `system.multicall`;
22. invert/drop trust bit in httprpc;
23. invert/drop trust in copied rpc2;
24. couple root and local switches in each direction;
25. hard-code `/` allowed without opt-in;
26. remove terminal exit;
27. expose inner rather than outer method;
28. allow control/path/value data into diagnostic;
29. exceed 96-byte method or 512-byte message bound;
30. remove any of the ten parser tests, with SET subtraction catching each;
31. empty/truncate/duplicate fixture/test extraction.

When a group names members, mutate every member and report rows individually;
one representative is insufficient.

## 19. Static, focused and full verification

Run lint for all seven PHP files and:

```sh
git diff --check
```

Run through real TestCase runner semantics:

```text
XMLRPCProxyTest
XMLRPCProxyContractTest      (unchanged file, changed fixture)
XMLRPCProxyEntrypointTest
XMLRPCProxyRejectionTest     (preservation)
```

Full gates:

```sh
cd tests
bash php-test.sh
npm test -- --runInBand
```

Run socket-opening tests with loopback permission. If PHPStan 2.2.9 image is
already local, run level 0 on all seven changed PHP paths; do not pull it just
to claim an optional result.

## 20. PHP/container matrix

Final code must parse and pass on PHP 7.4, 8.1 and 8.5. Use non-root official
containers with no external network:

```sh
for image in php:7.4-cli php:8.1-cli; do
  docker run --rm --user "$(id -u):$(id -g)" --network none \
    -v "$PWD:/w" -w /w/tests "$image" bash php-test.sh
done
```

Run full host PHP 8.5 and the focused four-class proxy suite in
`ivanshift/rutorrent:latest` with the worktree bind-mounted. Identify image IDs.
If shipped-image extensions are missing, compare exact base and candidate;
never suppress diagnostics in product code. Root-user permission fixtures are
not valid denial evidence when root bypasses filesystem modes.

## 21. Disposable rTorrent 0.9.8/0.16.21 runtime gates

Use only disposable labs, preferably `tasks/rt-lab.sh`. If versioned images are
absent, build them from the parameterized `docker-rutorrent` Dockerfile using
exact tag SHAs without editing either repo. Wait for the actual image artifact;
do not background a build and trust wrapper exit.

Required on both versions:

- capture exact trust-header behavior;
- exercise all eight loads with safe/unsafe trailing commands;
- prove evaluator/carrier/direct-directory rejects make zero daemon sends;
- exercise six multicalls and the filtered filter slot;
- reject benign and malicious `system.multicall`;
- prove unsafe mode sends original bytes trusted;
- prove ordinary well-formed unknown stays raw/untrusted;
- prove both doors pass the exact trust bit;
- record status/fault plus before/after daemon state for mutating cases;
- tear labs down and prove no task containers remain.

Use throwaway torrents/paths only. Re-check official source registrations and
shapes for evaluators, carriers, watch/view methods, multicalls and legacy/
modern trust behavior. Search both `CMD_*` and `CMD2_*`; literal grep is only a
floor, so cross-check `system.listMethods`.

## 22. Whole-file self-review

Answer with evidence:

1. Can malformed owned/elevated shapes reach unknown fallback?
2. Can safe config override denial?
3. Are evaluator matches exact and carrier matches no broader than frozen?
4. Do `if/not/compare` remain ordinary compatibility behavior?
5. Are all 8 dot-loads owned and underscore spellings unowned?
6. Can raw bytes be path-interpreted or changed?
7. Is every multicall slot, including filter, rebuilt?
8. Can a multicall silently lose a member?
9. Can any `system.multicall` pass in sanitize?
10. Are direct setters denied but slot setters boundary-checked?
11. Are root/local switches independent?
12. Is config order common → defaults → local → per-user?
13. Can either door invert trust or send after reject?
14. Does every reject return empty/untrusted and terminal 403/-501?
15. Can inner method/path/value/control data reach logs/faults?
16. Are both byte bounds applied after normalization?
17. Do full base SETS and ten parser contracts survive?
18. Is final diff exactly seven paths with no second parser/resolver?
19. Is every construct PHP 7.4-compatible?
20. Did each mutation fail in its named test rather than a fatal?

Do not finalize if any answer is uncertain.

## 23. One-commit candidate

Stage only the seven paths and create exactly one non-merge commit:

```sh
git add \
  conf/xmlrpc_proxy.php \
  php/xmlrpc_proxy.php \
  plugins/httprpc/action.php \
  plugins/httprpc/conf.php \
  tests/php/XMLRPCProxyTest.php \
  tests/php/XMLRPCProxyContractFixture.php \
  tests/php/XMLRPCProxyEntrypointTest.php
git diff --cached --check
git diff --cached --name-status
git commit -m 'Enforce the complete XMLRPC proxy policy'
```

Amend corrections into the same commit. Final proof:

```sh
BASE=495e2a54a657efcc132dc1456db8d7e680304a8a
FINAL=$(git rev-parse HEAD)
test "$(git rev-parse HEAD^)" = "$BASE"
test "$(git rev-list --count "$BASE..$FINAL")" -eq 1
test -z "$(git status --porcelain)"
git show --check --stat --oneline "$FINAL"
git diff --name-status "$BASE..$FINAL"
git diff --numstat "$BASE..$FINAL"
```

The diff must list exactly seven allowed paths. Do not push, open a PR, merge
to master, remove the candidate worktree or switch the primary checkout.

## 24. Required Gemini report

Return one Markdown report with exactly these sections.

### `STATUS`

One of:

```text
READY_FOR_REVIEW
BASE_DRIFT
MISSING_OBJECT
WORKTREE_COLLISION
SCOPE_VIOLATION
CONTRACT_DRIFT
BASELINE_DRIFT
RED_NOT_REPRODUCED
TEST_NOT_SENSITIVE
RUNTIME_BLOCKED
RUNTIME_DRIFT
REGRESSION
ENVIRONMENT_BLOCKED
AUTHORITY_REQUIRED
```

No `READY_FOR_REVIEW` if a mandatory gate was skipped/red.

### `REFS`

Base, ancestor, donor, candidate parent/final, branch, worktree, image IDs.

### `SCOPE`

Exact name-status/numstat/stat and proof excluded files equal base.

### `BASELINE SETS`

All base/final counts/hashes, duplicate checks, bidirectional SET subtraction,
ten parser-name checks.

### `NATURAL RED`

Every section-16 row: named test, wrong base behavior, exit, executed/no-fatal
proof.

### `IMPLEMENTATION`

Ownership layers, terminal boundaries, switches and both doors.

### `FOCUSED AND FULL TESTS`

Exact commands, PHP/image versions, exits and counts.

### `MUTATIONS`

One row per actual member mutation, named RED, fatal scan, recovery GREEN.

### `RUNTIME 0.9.8`

Image/source/API/method/trust evidence, full matrix, zero-send and teardown.

### `RUNTIME 0.16.21`

Same fields.

### `DIAGNOSTICS`

Maximum byte lengths and leak scans.

### `SELF-REVIEW`

Answers to all 20 questions.

### `GIT STATE`

One commit/exact parent/clean worktree/no push/PR/master change; primary files
untouched; labs removed.

### `DEVIATIONS`

Every deviation, or `none`.

## 25. Definition of done

Ready for independent review only when:

- exact base/ancestor/worktree and seven-path scope are proved;
- natural RED precedes implementation;
- modes and precedence are exact;
- all evaluator/carrier/directory/family denies are complete without overblock;
- 8 loads share one canonical owner with exact raw-byte retention;
- 6 direct multicalls are all-or-nothing including filtered filter;
- `system.multicall` always rejects in sanitize;
- elevated shapes reject invalid and canonicalize valid calls;
- config precedence and all four switch quadrants are pinned;
- both doors preserve trust, status, fault, zero-send and exit;
- diagnostics are single, bounded, classified and leak-free;
- base SETS and ten parser contracts survive;
- every required mutation is sensitive and recovers;
- PHP 7.4/8.1/8.5, full PHP/Jest and both disposable rTorrent versions pass;
- final branch is one clean non-merge commit directly on exact base;
- no push/PR/master integration/live mutation/unrelated cleanup occurred;
- the complete section-24 report is returned.

`READY_FOR_REVIEW` is not acceptance. Another model will review all seven files
and independently rerun load-bearing RED, mutations, containers and runtime
gates before approving or amending the one candidate commit.
