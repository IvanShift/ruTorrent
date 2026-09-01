# Gemini implementation brief: package 13 `rtorrent-alias-surface`

Status: `READY FOR DELEGATED IMPLEMENTATION / REQUIRES FINAL CODE REVIEW`

Prepared against:

```text
ruTorrent upstream base: 495e2a54a657efcc132dc1456db8d7e680304a8a
fork donor snapshot:     774a6bf2d2f83df9e1f2a87788a093de08a73963
rTorrent v0.16.20:       dd3ddf7c391ada92e4ba86fb6afd8a6cc01446b8
rTorrent v0.16.21:       109a20c09c3cab9eb13c2d96ea79362ac6c318fc
```

This is a self-contained assignment for Gemini. Read this file, the repository
root `AGENTS.md`, and `.codex/skills/rutorrent-fork/SKILL.md` completely before
running commands or editing files.

## 1. Objective

Build a clean upstream candidate for package 13, `rtorrent-alias-surface`, from
the exact `upstream/master` base above.

The package must characterize the complete PHP and JavaScript rTorrent alias
maps and fail CI if a future edit:

- maps an alias to a command absent from a stock modern rTorrent daemon;
- silently loses, adds, or duplicates an alias at a version gate;
- changes the exact three legacy alias-to-target pairings that are available
  only when rTorrent starts with deprecated commands enabled;
- gives an alias a malformed shape or invalid `prm` value;
- introduces a production sender for any of the three deprecated-only aliases;
- corrupts, truncates, duplicates, or reorders the captured daemon registry.

There is no confirmed production incompatibility in the current alias tables.
Do not invent one. This is a characterization and regression-prevention package,
not a production rewrite.

The only non-test edits are English comment corrections documenting the exact
socket-command boundary between rTorrent 0.16.20 and 0.16.21. They must not
change executable behavior.

## 2. Why the historical three-path contract is not authoritative anymore

An older package plan listed only these paths:

```text
php/settings.php
tests/js/rtorrent.spec.js
tests/php/RtorrentCompatibilityTest.php
```

That plan predates merged upstream PR `#3236`. The current upstream base now
also contains a contradictory statement in `tests/php/SocketAllocLimitsTest.php`:
it says that `system.sockets.available_alloc` does not exist before rTorrent
0.16.21.

This has been independently remeasured against official rTorrent source:

- rTorrent 0.16.20 already registers `system.sockets.reserved_alloc`,
  `system.sockets.available_alloc`, and the generic category's `min_alloc`;
- rTorrent 0.16.20 does not register the per-category
  `*.max_alloc.limit`/`*.min_alloc.limit` getters;
- rTorrent 0.16.21 adds those per-category limit getters;
- ruTorrent's complete six-command settings-page preflight therefore correctly
  remains gated at 0.16.21.

The updated final scope is four paths. Treat this as an explicit contract
refresh, not optional scope expansion.

## 3. Exact final scope

The final candidate commit may modify exactly these paths:

```text
php/settings.php
tests/php/SocketAllocLimitsTest.php
tests/js/rtorrent.spec.js
tests/php/RtorrentCompatibilityTest.php
```

Allowed changes by path:

| Path | Allowed final change |
|---|---|
| `php/settings.php` | Comment-only correction next to `getSocketAllocCategory()`; no executable token changes |
| `tests/php/SocketAllocLimitsTest.php` | Comment/docblock-only correction distinguishing the 0.16.20 aggregate values from the 0.16.21 six-value probe |
| `tests/php/RtorrentCompatibilityTest.php` | Complete PHP alias-map characterization, shared daemon registry fixture, fixture integrity, exact deprecated pairing, dormancy scan, shape/count tests |
| `tests/js/rtorrent.spec.js` | Complete JS alias-map characterization using the PHP fixture, defensive fixture parsing, fixture integrity, exact deprecated pairing, shape/count tests |

Forbidden final changes include, but are not limited to:

```text
php/methods-*.php
js/content.js
js/rtorrent.js
AGENTS.md
.codex/**
.gitignore
tasks/**
plugins/rutracker_check/**
any docker-rutorrent file
```

The alias tables in `php/methods-*.php` and `js/content.js` may be edited only
inside disposable mutation worktrees. They must be byte-for-byte unchanged in
the final candidate relative to the upstream base.

## 4. Non-goals

Do not:

- delete the three deprecated-only aliases merely to make the missing set
  empty;
- enable rTorrent deprecated commands or change its launch flags;
- add compatibility aliases based only on release notes or literal source grep;
- change the socket allocation implementation accepted through `#3236`;
- change PHP/JS production command mappings;
- build or modify a Docker image;
- contact a live/production rTorrent instance;
- fix unrelated test failures or reformat unrelated code;
- add generated captures, logs, archives, or scratch files to Git;
- create a PR, push a branch, merge to fork `master`, or switch the primary
  checkout.

## 5. Authoritative evidence and precedence

Use evidence in this order:

1. A fresh `system.listMethods` answer from the specified stock rTorrent
   0.16.21 lab image.
2. Official tagged rTorrent source at the exact commits recorded above.
3. The current ruTorrent alias-loading code at the exact upstream base.
4. The numeric expectations and historical 0.16.20 capture in this contract.
5. The fork donor snapshot only as a starting implementation to review.

Never promote the donor over a fresh runtime/source contradiction. If the
runtime contradicts this brief, stop and report the raw difference; do not grow
an allow-list until the discrepancy is understood.

Official source references:

```text
https://github.com/rakshasa/rtorrent/blob/v0.16.20/src/command_local.cc
https://github.com/rakshasa/rtorrent/blob/v0.16.21/src/command_local.cc
https://github.com/rakshasa/rtorrent/blob/v0.16.20/src/main.cc
```

Important source-reading rule: extracting literal `CMD_*` registrations is a
floor, not the full command surface. Some socket-category and string commands
are composed at runtime. Both `CMD_*` and `CMD2_*` families matter. Final
compatibility claims must be checked against `system.listMethods`.

## 6. Verified contract

### 6.1 PHP alias-loading ladder

The PHP characterization must replay the actual loading ladder from
`rTorrentSettings::obtain()` rather than maintaining a third hand-written map:

```text
inline seed:              iVersion > 0x806
methods-pre-0.9.0.php:    iVersion < 0x900
methods-0.9.4.php:        iVersion >= 0x904
methods-0.10.2.php:       iVersion >= 0x0a02
methods-0.16.0.php:       iVersion >= 0x1000
methods-0.16.16.php:      iVersion >= 0x1010
methods-0.16.18.php:      iVersion >= 0x1012
```

The inline seed contains two aliases and is load-bearing. The old upstream test
helper replays only the required files and therefore undercounts the effective
map by two. Parse the inline seed from `php/settings.php` and apply it at the
real gate; do not duplicate its values in the test.

Expected PHP effective-map shape:

| Packed version | Label | Keys | Unique targets |
|---:|---|---:|---:|
| `0x809` | 0.8.9 | 3 | 3 |
| `0x908` | 0.9.8 | 292 | 283 |
| `0x0a02` | 0.10.2 | 307 | 296 |
| `0x1000` | 0.16.0 | 307 | 296 |
| `0x1010` | 0.16.16 | 310 | 297 |
| `0x1012` | 0.16.18 | 310 | 296 |
| `0x1015` | 0.16.21 | 310 | 296 |

### 6.2 JavaScript alias-map shape

`js/content.js` carries a hand-written JavaScript mirror. Exercise it through
`correctContent()` and `theRequestManager.map()` at each real gate.

Expected JavaScript effective-map shape:

| Packed version | Label | Keys | Unique targets |
|---:|---|---:|---:|
| `0x809` | 0.8.9 | 4 | 4 |
| `0x908` | 0.9.8 | 299 | 285 |
| `0x0a02` | 0.10.2 | 314 | 298 |
| `0x1000` | 0.16.0 | 314 | 298 |
| `0x1010` | 0.16.16 | 317 | 299 |
| `0x1012` | 0.16.18 | 317 | 297 |
| `0x1015` | 0.16.21 | 317 | 297 |

JavaScript has one extra target, the empty string. It is a local identity
mapping, not an rTorrent daemon method.

### 6.3 Captured daemon registry

The PHP test owns one embedded registry fixture:

```text
source: stock rTorrent 0.16.20 system.listMethods response
rows: 982
unique rows: 982
order: LC_ALL=C sorted
empty rows: 0
```

Do not describe this fixture as a complete rTorrent 0.16.21 registry. A fresh
stock 0.16.21 capture historically returns 1027 names. The 982-name fixture is
a conservative baseline only after proving it is a subset of that fresh
0.16.21 answer.

The JavaScript suite must extract this one PHP fixture defensively; it must not
embed a second copy. A missing heredoc marker must cause a named assertion or
explicit exception, never an accidental `Cannot read properties of null`.

Both PHP and JavaScript tests must pin all four fixture invariants: exact count,
uniqueness, sorted order, and non-empty names.

### 6.4 Exact deprecated-only set

For stock rTorrent 0.16.18 through 0.16.21, the effective modern alias maps are
allowed to target exactly these unregistered daemon names:

```text
dht.throttle.name
dht.throttle.name.set
throttle.ip
```

They are registered only behind rTorrent's `method.use_deprecated` path, which
stock modern daemons expose only when launched with `-D`. Their exact legacy
key-to-target pairing is:

```text
get_dht_throttle -> dht.throttle.name
set_dht_throttle -> dht.throttle.name.set
throttle_ip -> throttle.ip
```

These aliases preserve the legacy 0.9.8 mapping baseline. Their production
verdict is:

```text
UNREACHABLE IN STOCK MODERN PRODUCTION / NO SEND
```

That verdict is valid only while no production source sends any of the three
alias keys. The PHP suite must scan every owned production PHP/JS root,
including root-level PHP entrypoints and `lang/*.js`, while excluding test code
and `node_modules`. It must not exclude the two definition files wholesale: a
new sender could be added later in another hunk of the same file.

For each deprecated alias key, exactly two production-source occurrences are
allowed and must match the exact definition shapes: one in
`php/methods-0.9.4.php` and one in `js/content.js`. Any third occurrence,
including one elsewhere in either definition file, is a sender violation. An
unreadable candidate source file is a failure, never a silent `continue`.

The listing must prove it includes at least `rpc2.php`, `env_check.php`,
`lang/en.js`, `php/settings.php`, and `js/content.js`, and that the total scan is
non-vacuous.

Do not label the aliases “broken”, and do not hide a fourth missing target in a
broad allow-list.

### 6.5 Alias-entry shape

At every PHP version gate, every effective entry must be:

```text
an array
with both name and prm keys
name: non-empty string
name: contains no "=" (getCommand appends it where appropriate)
prm: strict integer 0 or 1
```

Validate shape before calling code that assumes the target is a string. A bad
entry must produce a readable named failure, not a fatal that prevents later
tests from running.

## 7. Git and workspace safety

The primary checkout contains user-owned diagnostic files. Leave them exactly
where they are and never stage, copy, inspect, delete, or rename them:

```text
logs_90471600543.zip
logs_90485329911.zip
logs_90525388665.zip
rutorrent-app-errors.log
```

Work only in a separate worktree and branch:

```text
worktree: .worktrees/gemini-rtorrent-alias-surface
branch:   gemini/rtorrent-alias-surface
base:     495e2a54a657efcc132dc1456db8d7e680304a8a
```

The fork's current `master` may advance after this document is committed. The
fixed donor is the object `774a6bf2...`, not “whatever master points to”. Never
cherry-pick the fork merge that contains it.

The final candidate must be one non-merge commit whose direct parent is the
exact upstream base. Do not push it. The primary checkout must remain on its
original branch and untouched.

## 8. Mandatory stop conditions

Stop without guessing and return the specified status if any condition holds:

| Condition | Status to return |
|---|---|
| `upstream/master` is not the exact base SHA after fetch | `BASE_DRIFT` |
| donor/base commit object is unavailable | `MISSING_OBJECT` |
| branch or worktree path already exists | `WORKTREE_COLLISION` |
| a final change outside the four-path scope appears | `SCOPE_VIOLATION` |
| official source/runtime contradicts the three-name exception | `CONTRACT_DRIFT` |
| immutable image `sha256:7cea0d1725862165173c39041ab1fbf9f39233a475ac6a7ce7cad7e07a7b0e97` is absent or does not run rTorrent 0.16.21/PHP 8.5 | `ENVIRONMENT_BLOCKED` |
| port 18121 is occupied | `RUNTIME_BLOCKED` |
| fresh 0.16.21 registry is empty, duplicated, or not 1027 names | `RUNTIME_DRIFT` |
| 982-name fixture is not a subset of the fresh registry | `FIXTURE_DRIFT` |
| any required mutation remains green | `TEST_NOT_SENSITIVE` |
| a required PHP/Jest/container check is red for candidate but green on base | `REGRESSION` |
| a required local executable or preinstalled dependency directory is absent | `ENVIRONMENT_BLOCKED` |
| a requested operation needs push, production access, or destructive cleanup | `AUTHORITY_REQUIRED` |

For every stop, preserve the candidate/worktree, print exact SHAs and the
smallest raw diff/evidence needed for review, and do not make compensating edits.

## 9. Execution plan

Perform the following tasks in order. Task 10 creates one provisional candidate
commit so mutations can run in a separate worktree. If tests must be strengthened,
amend that same commit; never stack a second candidate commit.

### Task 1: Establish the exact base and isolated worktree

From the primary checkout, perform read-only checks first:

```sh
bash -euo pipefail <<'REFS'
cd /home/dev/Documents/my_projects/ruTorrent
git status --short --branch
git fetch upstream master

BASE_SHA=495e2a54a657efcc132dc1456db8d7e680304a8a
DONOR_SHA=774a6bf2d2f83df9e1f2a87788a093de08a73963

test "$(git rev-parse upstream/master)" = "$BASE_SHA"
git cat-file -e "$BASE_SHA^{commit}"
git cat-file -e "$DONOR_SHA^{commit}"
git merge-base --is-ancestor 2c765c5a upstream/master
git merge-base --is-ancestor e3acc7ff upstream/master
REFS
```

The two ancestor checks prove the prerequisites accepted through upstream PRs
`#3230` and `#3236` are already in the base.

Run every environment preflight before creating the branch/worktree. This keeps
an absent tool/image from leaving a half-started worktree that collides with a
retry:

```sh
bash -euo pipefail <<'PREFLIGHT'
cd /home/dev/Documents/my_projects/ruTorrent
BASE_SHA=495e2a54a657efcc132dc1456db8d7e680304a8a

for tool in php node npm python3 docker ss git curl; do
  command -v "$tool" >/dev/null || {
    echo "ENVIRONMENT_BLOCKED: missing $tool"
    exit 1
  }
done

php -r 'exit((PHP_VERSION_ID >= 80500 && PHP_VERSION_ID < 80600) ? 0 : 1);'

JS_DEPS=/home/dev/Documents/my_projects/ruTorrent/tests/node_modules
test -d "$JS_DEPS" || {
  echo 'ENVIRONMENT_BLOCKED: primary tests/node_modules is absent'
  exit 1
}
npm --prefix /home/dev/Documents/my_projects/ruTorrent/tests ls --depth=0

PRIMARY_LOCK=$(git hash-object \
  /home/dev/Documents/my_projects/ruTorrent/tests/package-lock.json)
BASE_LOCK=$(git rev-parse "${BASE_SHA}:tests/package-lock.json")
test "$PRIMARY_LOCK" = "$BASE_LOCK" || {
  echo 'ENVIRONMENT_BLOCKED: shared node_modules belongs to a different lockfile'
  exit 1
}

LAB_HELPER=/home/dev/Documents/my_projects/ruTorrent/tasks/rt-lab.sh
test -f "$LAB_HELPER" || {
  echo 'ENVIRONMENT_BLOCKED: primary tasks/rt-lab.sh is absent'
  exit 1
}

RT_IMAGE=sha256:7cea0d1725862165173c39041ab1fbf9f39233a475ac6a7ce7cad7e07a7b0e97
docker info >/dev/null
docker image inspect "$RT_IMAGE" >/dev/null
rt_help=$(docker run --rm --entrypoint rtorrent "$RT_IMAGE" -h 2>&1)
printf '%s\n' "$rt_help" | grep -Fq \
  "Rakshasa's BitTorrent client version 0.16.21."
docker run --rm --entrypoint php85 "$RT_IMAGE" -r \
  'exit((PHP_VERSION_ID >= 80500 && PHP_VERSION_ID < 80600) ? 0 : 1);'

docker image inspect php:7.4-cli >/dev/null
docker image inspect php:8.1-cli >/dev/null

curl -fsSL --connect-timeout 10 --max-time 60 \
  https://raw.githubusercontent.com/rakshasa/rtorrent/dd3ddf7c391ada92e4ba86fb6afd8a6cc01446b8/src/command_local.cc \
  > /tmp/gemini-rt-01620-command_local.cc
curl -fsSL --connect-timeout 10 --max-time 60 \
  https://raw.githubusercontent.com/rakshasa/rtorrent/109a20c09c3cab9eb13c2d96ea79362ac6c318fc/src/command_local.cc \
  > /tmp/gemini-rt-01621-command_local.cc
curl -fsSL --connect-timeout 10 --max-time 60 \
  https://raw.githubusercontent.com/rakshasa/rtorrent/dd3ddf7c391ada92e4ba86fb6afd8a6cc01446b8/src/main.cc \
  > /tmp/gemini-rt-01620-main.cc
curl -fsSL --connect-timeout 10 --max-time 60 \
  https://raw.githubusercontent.com/rakshasa/rtorrent/109a20c09c3cab9eb13c2d96ea79362ac6c318fc/src/main.cc \
  > /tmp/gemini-rt-01621-main.cc
test -s /tmp/gemini-rt-01620-command_local.cc
test -s /tmp/gemini-rt-01621-command_local.cc
test -s /tmp/gemini-rt-01620-main.cc
test -s /tmp/gemini-rt-01621-main.cc

set +e
listener_output=$(ss -ltn 2>&1)
listener_code=$?
set -e
if [ "$listener_code" -ne 0 ]; then
  printf '%s\n' "$listener_output" >&2
  echo 'ENVIRONMENT_BLOCKED: cannot inspect listening ports' >&2
  exit 1
fi
if printf '%s\n' "$listener_output" | grep -q ':18121 '; then
  echo 'RUNTIME_BLOCKED: port 18121 is occupied'
  exit 1
fi
if docker ps -a --format '{{.Names}}' | grep -qx 'gemini-rt-alias-21'; then
  echo 'RUNTIME_BLOCKED: lab container name already exists'
  exit 1
fi
PREFLIGHT
```

Do not pull or build a substitute. The immutable ID above is already installed
in the prepared environment and has been independently measured as rTorrent
0.16.21 with PHP 8.5. A mutable tag is not an equivalent oracle.

Before creating anything, prove there is no collision:

```sh
bash -euo pipefail <<'COLLISION'
cd /home/dev/Documents/my_projects/ruTorrent
test ! -e .worktrees/gemini-rtorrent-alias-surface
test -z "$(git branch --list gemini/rtorrent-alias-surface)"
COLLISION
```

Create the worktree without switching the primary checkout:

```sh
bash -euo pipefail <<'WORKTREE'
cd /home/dev/Documents/my_projects/ruTorrent
BASE_SHA=495e2a54a657efcc132dc1456db8d7e680304a8a
git worktree add \
  -b gemini/rtorrent-alias-surface \
  .worktrees/gemini-rtorrent-alias-surface \
  "$BASE_SHA"

cd .worktrees/gemini-rtorrent-alias-surface
test "$(git rev-parse HEAD)" = "$BASE_SHA"
test -z "$(git status --porcelain)"
WORKTREE
```

Record, but do not modify, the initial branch, HEAD, and status of the primary
checkout. At the end they must match.

For every command in Tasks 2–13, use this absolute worktree as the tool working
directory; do not rely on `cd` persisting across agent tool calls:

```text
/home/dev/Documents/my_projects/ruTorrent/.worktrees/gemini-rtorrent-alias-surface
```

### Task 2: Read and remeasure before importing donor code

Read these current-base files completely:

```text
php/settings.php
php/methods-pre-0.9.0.php
php/methods-0.9.4.php
php/methods-0.10.2.php
php/methods-0.16.0.php
php/methods-0.16.16.php
php/methods-0.16.18.php
js/content.js
tests/php/RtorrentCompatibilityTest.php
tests/php/SocketAllocLimitsTest.php
tests/js/rtorrent.spec.js
tests/php/TestCase.php
tests/php-test.sh
```

Then independently inspect the relevant official source at the exact rTorrent
commits. At minimum, report the registration lines for:

```text
system.sockets.reserved_alloc
system.sockets.available_alloc
system.sockets.<category>.max_alloc.limit
system.sockets.<category>.min_alloc.limit
dht.throttle.name
dht.throttle.name.set
throttle.ip
method.use_deprecated
```

Use the four commit-addressed files fetched to `/tmp` during preflight. Do not
replace them with a moving branch or release-note summary.

Do not copy a line-number claim from donor comments: line numbers drift between
tags. Cite tag/commit, file, symbol, and enclosing gate in the report.

Expected source result:

- 0.16.20 has the two aggregate allocation values and generic `min_alloc`;
- 0.16.20 lacks the two per-category hard-limit getter families;
- 0.16.21 has those getter families;
- the exact three legacy targets are behind the deprecated-command gate.

If the result differs, return `CONTRACT_DRIFT` before editing.

### Task 3: Run the unchanged-base baseline

The base must be measured before candidate edits. Do not call a candidate
failure a regression unless the same command was green on this exact base.

Install no new dependencies. A Git worktree does not copy the ignored
`tests/node_modules` directory. Reuse the primary checkout's already-installed
dependencies through one task-owned symlink:

```sh
JS_DEPS=/home/dev/Documents/my_projects/ruTorrent/tests/node_modules
test -d "$JS_DEPS" || {
  echo 'ENVIRONMENT_BLOCKED: primary tests/node_modules is absent'
  exit 1
}
BASE_SHA=495e2a54a657efcc132dc1456db8d7e680304a8a
test "$(git hash-object tests/package-lock.json)" = \
  "$(git rev-parse "${BASE_SHA}:tests/package-lock.json")"
test ! -e tests/node_modules
ln -s "$JS_DEPS" tests/node_modules
```

Do not run `npm install`, and remove only this symlink before the final Git-state
check. Never edit anything below the shared dependency directory.

Focused JavaScript baseline:

```sh
cd tests
npm test -- --runInBand js/rtorrent.spec.js
cd ..
```

Focused PHP baseline. Running `php tests/php/RtorrentCompatibilityTest.php`
alone executes zero tests and is forbidden as evidence. Use the real runner
shape and scan printed failures:

```sh
set +e
php_out=$(php -c tests/php-test.ini -r '
require "tests/php/RtorrentCompatibilityTest.php";
$test = new RtorrentCompatibilityTest();
$test->setUp();
$test->run();
$test->tearDown();
' 2>&1)
php_code=$?
set -e
printf '%s\n' "$php_out"
test "$php_code" -eq 0
! printf '%s\n' "$php_out" | grep -qE \
  '^Failed:|^not ok|failed with error|PHP (Fatal|Parse) error|Uncaught'
```

Also run the current socket-focused file through the same runner pattern with
`SocketAllocLimitsTest`.

Record exact test counts and output markers. A command that loads a file but
prints no `>>test...>>` marker is vacuous and not acceptable.

### Task 4: Capture the stock 0.16.21 runtime registry

This task may mutate only a disposable lab container. Never query a live
instance and never add a torrent.

The immutable image, port, name, and helper were checked before worktree
creation. Run the start, capture, assertions, and teardown in one shell so the
trap cannot be lost between agent tool calls. Never bind-mount the checkout
directly over `/rutorrent/app`:

```sh
bash -euo pipefail <<'LAB'
LAB_HELPER=/home/dev/Documents/my_projects/ruTorrent/tasks/rt-lab.sh
RT_IMAGE=sha256:7cea0d1725862165173c39041ab1fbf9f39233a475ac6a7ce7cad7e07a7b0e97
LAB_NAME=gemini-rt-alias-21
lab_owned=0

cleanup_lab() {
  status=$?
  if [ "$lab_owned" -eq 1 ]; then
    REPO="$PWD" sh "$LAB_HELPER" down "$LAB_NAME" || true
  fi
  exit "$status"
}
trap cleanup_lab EXIT INT TERM

printf 'image-id=%s\n' "$(docker image inspect --format '{{.Id}}' "$RT_IMAGE")"
printf 'repo-digests=%s\n' \
  "$(docker image inspect --format '{{json .RepoDigests}}' "$RT_IMAGE")"

# The helper removes a same-named container during `up`; preflight proved the
# name absent, so from this point that exact name is task-owned.
lab_owned=1
REPO="$PWD" sh "$LAB_HELPER" \
  up "$RT_IMAGE" 18121 "$LAB_NAME"

python3 - <<'PY'
from pathlib import Path
import xmlrpc.client

rpc = xmlrpc.client.ServerProxy(
    "http://127.0.0.1:18121/plugins/httprpc/action.php",
    allow_none=True,
)
version = rpc.system.client_version()
api = rpc.system.api_version()
raw = rpc.system.listMethods()

if str(version) != "0.16.21":
    raise SystemExit(f"expected rTorrent 0.16.21, got {version!r}")
if str(api) != "26":
    raise SystemExit(f"expected API 26, got {api!r}")
if not isinstance(raw, list) or not raw:
    raise SystemExit("system.listMethods returned no usable list")
if any(not isinstance(name, str) or not name for name in raw):
    raise SystemExit("system.listMethods contains a non-string or empty name")
if len(raw) != len(set(raw)):
    raise SystemExit("system.listMethods contains duplicate names")
if len(raw) != 1027:
    raise SystemExit(f"expected 1027 methods, got {len(raw)}")

methods = sorted(raw)
Path("/tmp/gemini-rt21-methods.txt").write_text(
    "\n".join(methods) + "\n",
    encoding="utf-8",
)
print(f"version={version} api={api} methods={len(methods)}")
PY

REPO="$PWD" sh "$LAB_HELPER" down "$LAB_NAME"
lab_owned=0
trap - EXIT INT TERM
test -z "$(docker ps -a --format '{{.Names}}' | grep -x "$LAB_NAME" || true)"
LAB
```

Expected observation is exactly `version=0.16.21 api=26 methods=1027`. The
Python assertions make any other observation non-zero. The trap removes the
task-owned container on every failure path. Record image ID/repo digest and the
assertion output. Keep `/tmp/gemini-rt21-methods.txt` as local evidence only.
Never commit it. Do not proceed to donor import without a valid capture.

### Task 5: Import only the two test donors, then review them as untrusted code

Generate a two-file patch from the fixed donor object, not fork `master`:

```sh
BASE_SHA=495e2a54a657efcc132dc1456db8d7e680304a8a
DONOR_SHA=774a6bf2d2f83df9e1f2a87788a093de08a73963

git diff --binary "$BASE_SHA..$DONOR_SHA" -- \
  tests/js/rtorrent.spec.js \
  tests/php/RtorrentCompatibilityTest.php \
  > /tmp/gemini-rtorrent-alias-tests.patch

git apply --check /tmp/gemini-rtorrent-alias-tests.patch
git apply /tmp/gemini-rtorrent-alias-tests.patch
```

The patch is a donor, not an answer. Re-read every added line and correct at
least these known weaknesses before testing:

1. The PHP fixture must explicitly assert 982 rows, 982 unique rows, sorted
   order, and no empty names.
2. The JS fixture extractor must check that the heredoc match exists before
   dereferencing it.
3. JS must assert fixture count, uniqueness, sorted order, and no empty names.
4. JS must pin each deprecated alias key to its exact target; comparing only a
   set of targets is insufficient because a swapped pairing can stay green.
5. No newly added donor comment, assertion, or test identifier may reference a
   fork-only task path or introduce a new description of the upstream
   repository as “the fork”. Do not rewrite unrelated wording already present
   on the exact base merely to satisfy this rule.
6. The PHP source-listing docblock must describe the actual allow-listed source
   roots, not claim it uses `git ls-files` when it does not.
7. Preserve exactly one `sort($paths);` after building the source list; do not
   mistake overlapping review output for a duplicate in the donor.
8. Replace the donor's directory-only scan with an exhaustive owned-source
   scan: root-level `*.php`, plus PHP/JS below `php`, `plugins`, `js`, `conf`,
   and `lang`; exclude test code and `node_modules`, not production roots. It
   must scan more than 100 files and assert the five sentinel paths in section
   6.4 are present.
   Rename the helper to `ownedProductionSources()` so its name matches the
   implementation; it must not claim Git-backed enumeration when containers do
   not provide Git.
9. Do not skip unreadable files. Collect and fail on them.
10. Do not exclude `php/methods-0.9.4.php` or `js/content.js` wholesale. For
    each deprecated alias key, validate the two exact definition occurrences
    and reject every extra occurrence anywhere in production sources.
11. A malformed alias entry must be collected and named without causing a fatal
   before the named shape test runs.
12. Use PHP 7.4-compatible syntax in all PHP test additions.

Do not mechanically shorten the large fixture. Its completeness, provenance,
and one-copy ownership are the point of the package.

### Task 6: Implement the PHP characterization contract

In `tests/php/RtorrentCompatibilityTest.php`, implement or refine helpers and
named tests that prove all of the following:

- the require-file gate ladder is parsed from `php/settings.php`;
- both `>=` and `<` gates are understood;
- the inline alias seed is parsed from `rTorrentSettings::obtain()` and applied
  at the real `> 0x806` boundary;
- a parse that returns too few gates or zero seed aliases fails explicitly;
- the embedded registry has exact, unique, sorted, non-empty 982-name shape;
- every effective modern target belongs to that registry except the exact
  three deprecated-only targets;
- the exact key-to-target pairing is pinned for each of the three aliases;
- effective key and unique-target counts match every row of the PHP table in
  section 6.1;
- every entry has the valid shape in section 6.5;
- no production sender contains the deprecated-only alias keys;
- the source scan covers root-level PHP and the `php`, `plugins`, `js`, `conf`,
  and `lang` trees, is non-vacuous, names unreadable inputs, and includes the
  five sentinel paths from section 6.4;
- each deprecated key occurs in production source exactly twice, in the exact
  PHP and JS definition shapes, with no whole-file exclusion.

Use stable, descriptive method names. The expected load-bearing names are:

```text
testStockDaemonMethodFixtureIsCompleteUniqueAndSorted
testEveryAliasTargetIsANameTheDaemonRegisters
testDeprecatedOnlyAliasKeysAreNotSentByProductionCode
testEveryAliasEntryIsWellFormedAtEveryVersionGate
```

If the donor uses a different name for the new fixture-integrity test, rename it
to the exact name above so mutation evidence can target it unambiguously.

The registry test must cover `0x1012`, `0x1013`, `0x1014`, and `0x1015`. Older
versions have deliberately removed legacy port targets, so the 0.16.20 registry
is not the right existence oracle for them; their shape remains covered by the
all-gates test.

### Task 7: Implement the JavaScript characterization contract

In `tests/js/rtorrent.spec.js`:

- continue to exercise production `correctContent()` and
  `theRequestManager.map()`;
- isolate each version run and restore global aliases/version in `finally`;
- read the 982-name fixture from the PHP test;
- fail explicitly if start/end heredoc markers cannot be found;
- assert exact count, uniqueness, sorted order, and non-empty strings;
- pin the exact missing-target set for `0x1012` and `0x1015` to:

  ```text
  ""
  dht.throttle.name
  dht.throttle.name.set
  throttle.ip
  ```

- pin the three exact JS alias-key pairings independently of that set;
- pin all version-gate key/target counts from section 6.2.

Use these stable test names for load-bearing behavior:

```text
uses a complete unique sorted shared daemon-method fixture
maps every alias to a name the daemon registers
keeps the deprecated-only aliases paired with their exact targets
keeps the alias table the same size at every version gate
```

The fixture helper may cache the parsed list, but it must return data in a form
that cannot hide duplicates before the uniqueness assertion.

### Task 8: Correct socket-boundary documentation without behavior changes

Rewrite the comment above `rTorrentSettings::getSocketAllocCategory()` in
`php/settings.php` so it says, in concise English:

- before 0.16.19, an over-budget `adjust_alloc` could terminate the daemon;
- from 0.16.19, it returns a normal XMLRPC fault;
- 0.16.20 exposes shared allocation totals and the generic minimum, but not
  per-category hard-limit getters;
- 0.16.21 adds the per-category limit getters used by the UI's complete
  six-value preflight;
- the fault/rollback path remains the final safety boundary because values can
  change between pre-read and `adjust_alloc`.

Do not retain the donor sentence saying ruTorrent “still does not pre-flight”;
that became false after upstream `#3236`.

Rewrite only the opening docblock in `tests/php/SocketAllocLimitsTest.php` to
state the same boundary. A suitable core sentence is:

```text
The complete six-value probe is available only from rTorrent 0.16.21.
rTorrent 0.16.20 already exposes available_alloc, but not the per-category
limit getters required by the settings-page validation.
```

Preserve the existing executable tests and their 0.16.21 version gate.

After editing, prove both files have comment-only changes. Review the tokenized
or whitespace-stripped executable regions, not just the visual diff. If any
executable token changes, revert that hunk and return `SCOPE_VIOLATION` if it
cannot be separated.

Use this executable token-equivalence gate:

```sh
set -e
BASE_SHA=495e2a54a657efcc132dc1456db8d7e680304a8a
git show "$BASE_SHA:php/settings.php" > /tmp/gemini-base-settings.php
git show "$BASE_SHA:tests/php/SocketAllocLimitsTest.php" \
  > /tmp/gemini-base-socket-test.php

php -r '
function executableTokens($path) {
    $result = array();
    foreach (token_get_all(file_get_contents($path)) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], array(T_COMMENT, T_DOC_COMMENT, T_WHITESPACE), true)) {
                continue;
            }
            $result[] = array($token[0], $token[1]);
        } else {
            $result[] = $token;
        }
    }
    return $result;
}
$pairs = array(
    array("/tmp/gemini-base-settings.php", "php/settings.php"),
    array("/tmp/gemini-base-socket-test.php", "tests/php/SocketAllocLimitsTest.php"),
);
foreach ($pairs as $pair) {
    if (executableTokens($pair[0]) !== executableTokens($pair[1])) {
        fwrite(STDERR, "Executable tokens changed in ".$pair[1]."\n");
        exit(1);
    }
    echo "comment-only: ", $pair[1], "\n";
}
'
```

### Task 9: Compare candidate maps and fixture with the fresh runtime

First run the focused tests from Tasks 3, 6, and 7. Only after they are green,
extract the candidate's effective PHP 0.16.21 target set:

```sh
php -c tests/php-test.ini -r '
require "tests/php/RtorrentCompatibilityTest.php";
$test = new RtorrentCompatibilityTest();
$method = new ReflectionMethod("RtorrentCompatibilityTest", "makeSettings");
if (PHP_VERSION_ID < 80100) {
    $method->setAccessible(true);
}
$settings = $method->invoke($test, 0x1015);
$targets = array();
foreach ($settings->aliases as $entry) {
    if (!is_array($entry) || !isset($entry["name"])) {
        fwrite(STDERR, "malformed alias entry\n");
        exit(2);
    }
    $targets[$entry["name"]] = true;
}
$targets = array_keys($targets);
sort($targets, SORT_STRING);
echo implode("\n", $targets), "\n";
' > /tmp/gemini-php-alias-targets.txt
```

The file must contain 296 unique sorted non-empty targets. Compare it with the
fresh registry captured in Task 4:

```sh
wc -l /tmp/gemini-php-alias-targets.txt
LC_ALL=C sort -c /tmp/gemini-php-alias-targets.txt
test "$(LC_ALL=C sort -u /tmp/gemini-php-alias-targets.txt | wc -l)" -eq 296
printf '%s\n' \
  dht.throttle.name \
  dht.throttle.name.set \
  throttle.ip \
  > /tmp/gemini-expected-missing.txt
comm -23 \
  /tmp/gemini-php-alias-targets.txt \
  /tmp/gemini-rt21-methods.txt \
  > /tmp/gemini-actual-missing.txt
diff -u \
  /tmp/gemini-expected-missing.txt \
  /tmp/gemini-actual-missing.txt
```

`/tmp/gemini-actual-missing.txt` must contain exactly:

```text
dht.throttle.name
dht.throttle.name.set
throttle.ip
```

A successful `diff -u` is silent and returns exit code 0. Any printed diff or
non-zero exit is `CONTRACT_DRIFT`.

Extract the PHP fixture without changing the test file:

```sh
php -r '
$source = file_get_contents("tests/php/RtorrentCompatibilityTest.php");
if (!preg_match("/<<<\x27LISTMETHODS\x27\\R(.*?)\\RLISTMETHODS/s", $source, $m)) {
    fwrite(STDERR, "LISTMETHODS fixture not found\n");
    exit(2);
}
echo rtrim($m[1], "\r\n"), "\n";
' > /tmp/gemini-rt20-fixture.txt

test "$(wc -l < /tmp/gemini-rt20-fixture.txt)" -eq 982
LC_ALL=C sort -c /tmp/gemini-rt20-fixture.txt
test "$(LC_ALL=C sort -u /tmp/gemini-rt20-fixture.txt | wc -l)" -eq 982
comm -23 \
  /tmp/gemini-rt20-fixture.txt \
  /tmp/gemini-rt21-methods.txt \
  > /tmp/gemini-fixture-not-in-rt21.txt
test ! -s /tmp/gemini-fixture-not-in-rt21.txt
```

This proves the embedded 0.16.20 capture is a conservative subset of the fresh
0.16.21 registry. It does not prove every 0.16.21 method belongs in the fixture,
and the expected 45 new names must not be appended.

Confirm the lab removed in Task 4 has not reappeared before proceeding:

```sh
test -z "$(docker ps -a --format '{{.Names}}' | grep -x 'gemini-rt-alias-21' || true)"
```

### Task 10: Prove test sensitivity with isolated named mutations

Natural RED does not exist because the package characterizes a currently valid
surface. Mutation testing is therefore mandatory, not optional.

First create the single provisional candidate commit. This is needed because a
second worktree can check out only a Git object, not the uncommitted state of the
candidate worktree:

```sh
git add \
  php/settings.php \
  tests/php/SocketAllocLimitsTest.php \
  tests/js/rtorrent.spec.js \
  tests/php/RtorrentCompatibilityTest.php

git diff --cached --check
test "$(git diff --cached --name-only | wc -l)" -eq 4
git diff --cached --name-status
git commit -m "Test the complete rTorrent alias surface"

CANDIDATE_SHA=$(git rev-parse HEAD)
test "$(git rev-parse HEAD^)" = \
  495e2a54a657efcc132dc1456db8d7e680304a8a
```

Create one detached disposable mutation worktree:

```sh
MUTATION_WORKTREE=/home/dev/Documents/my_projects/ruTorrent/.worktrees/gemini-rtorrent-alias-mutation
test ! -e "$MUTATION_WORKTREE"
git worktree add --detach "$MUTATION_WORKTREE" "$CANDIDATE_SHA"
test "$(git hash-object "$MUTATION_WORKTREE/tests/package-lock.json")" = \
  "$(git hash-object /home/dev/Documents/my_projects/ruTorrent/tests/package-lock.json)"
ln -s /home/dev/Documents/my_projects/ruTorrent/tests/node_modules \
  "$MUTATION_WORKTREE/tests/node_modules"
```

Run every mutation there, one at a time. Never stack mutations and never commit
a mutation. After every RED, restore the disposable tree exactly to the
candidate object and prove it is clean before the recovery GREEN:

```sh
MUTATION_WORKTREE=/home/dev/Documents/my_projects/ruTorrent/.worktrees/gemini-rtorrent-alias-mutation
cd "$MUTATION_WORKTREE"
CANDIDATE_SHA=$(git rev-parse HEAD)
git restore --source="$CANDIDATE_SHA" --staged --worktree -- .
test "$(git rev-parse HEAD)" = "$CANDIDATE_SHA"
test -z "$(git status --porcelain)"
```

A mutation is valid only if:

1. the intended named test prints its start marker;
2. that named test prints a failure and the wrapper returns non-zero;
3. output contains no earlier `PHP Fatal`, `PHP Parse error`, `Uncaught`, or
   Jest bootstrap failure;
4. the same focused suite is green again after discarding the mutation;
5. the next mutation starts from the unchanged candidate SHA.

The PHP `TestCase` runner prints failures but does not itself return non-zero.
Use `tests/php-test.sh` or the focused wrapper from Task 3 and explicitly grep
failure markers. A fatal before the named test runs proves nothing.

Required mutation matrix:

| # | Temporary mutation | Required named RED | What it proves |
|---:|---|---|---|
| 1 | In `php/methods-0.16.18.php`, change one real target such as `network.listen.port.range` to `network.listen.port.range.typo` | `testEveryAliasTargetIsANameTheDaemonRegisters` | Full PHP target registry is enforced |
| 2 | In the same table, change `set_port_range` `prm` from `1` to `2` | `testEveryAliasEntryIsWellFormedAtEveryVersionGate` | Strict entry shape is enforced |
| 3 | In root entrypoint `rpc2.php`, temporarily add syntactically valid dead code `if(false) new rXMLRPCCommand("get_dht_throttle");` | `testDeprecatedOnlyAliasKeysAreNotSentByProductionCode` | Root-level production sources are scanned and any additional occurrence is rejected |
| 4 | Remove the inline PHP seed entry for `d.set_peer_exchange` | `testEveryAliasEntryIsWellFormedAtEveryVersionGate` count assertion | Inline seed participates in the effective map |
| 5 | In `php/methods-0.9.4.php`, change `get_dht_throttle` to map to registered target `cat` | `testEveryAliasTargetIsANameTheDaemonRegisters` exact pairing assertion | Pairing is pinned, not merely target-set membership |
| 6 | Remove one fixture row, for example `add_peer` | `testStockDaemonMethodFixtureIsCompleteUniqueAndSorted` | Fixture truncation is detected |
| 7 | Duplicate a fixture row while keeping 982 total rows by replacing another row | `testStockDaemonMethodFixtureIsCompleteUniqueAndSorted` | Fixture uniqueness/order are enforced independently of count |
| 8 | In `js/content.js`, change one modern target to an unregistered `.typo` target | `maps every alias to a name the daemon registers` | Full JS target registry is enforced |
| 9 | Remove the JS seed entry for `d.set_peer_exchange` | `keeps the alias table the same size at every version gate` | JS counts are non-vacuous |
| 10 | Swap/change `get_dht_throttle` to registered target `cat` without changing table size | `keeps the deprecated-only aliases paired with their exact targets` | JS exact pairing is enforced |
| 11 | Remove one row from the shared PHP fixture and run only focused Jest | `uses a complete unique sorted shared daemon-method fixture` | JS independently validates its shared input |
| 12 | Replace one fixture row with a duplicate and run only focused Jest | `uses a complete unique sorted shared daemon-method fixture` | JS cannot hide duplicates by constructing a `Set` too early |
| 13 | In `js/content.js`, add a separate syntactically valid `void "get_dht_throttle";` outside the alias-definition object | `testDeprecatedOnlyAliasKeysAreNotSentByProductionCode` | Definition files are not excluded wholesale; a third occurrence in the same file is rejected |

If a proposed mutation accidentally changes a string that does not exist, abort
that mutation and choose another confirmed entry; never report an unapplied
mutation as evidence.

For each row, record:

```text
mutation number
candidate SHA
changed file and exact temporary diff
command
exit code
named start marker
named failure marker
fatal/parse/bootstrap scan result
post-reset GREEN command and result
```

If any required mutation remains green, strengthen the candidate test, rerun
the normal focused suite in the candidate worktree, amend the single candidate
commit, remove/recreate the mutation worktree at the new SHA, and repeat the
full mutation matrix. Do not waive a mutation because “the code looks covered”.

After all 13 mutations and recovery GREENs, remove only the task-owned mutation
worktree and retain the candidate worktree:

```sh
cd /home/dev/Documents/my_projects/ruTorrent/.worktrees/gemini-rtorrent-alias-surface
MUTATION_WORKTREE=/home/dev/Documents/my_projects/ruTorrent/.worktrees/gemini-rtorrent-alias-mutation
unlink "$MUTATION_WORKTREE/tests/node_modules"
git worktree remove "$MUTATION_WORKTREE"
test ! -e "$MUTATION_WORKTREE"
```

### Task 11: Run the candidate verification matrix

Run all commands from the candidate worktree. Record exit codes and concise
test counts, not only “passed”.

Syntax and diff hygiene:

```sh
php -l php/settings.php
php -l tests/php/SocketAllocLimitsTest.php
php -l tests/php/RtorrentCompatibilityTest.php
node --check tests/js/rtorrent.spec.js
git diff \
  495e2a54a657efcc132dc1456db8d7e680304a8a..HEAD \
  --check
```

Focused JavaScript:

```sh
cd tests
npm test -- --runInBand js/rtorrent.spec.js
cd ..
```

Full JavaScript:

```sh
cd tests
npm test -- --runInBand
cd ..
```

If the full Jest suite has an unrelated base failure, run the identical command
on a disposable exact-base worktree and report a base/candidate comparison.
That worktree also needs the verified dependency symlink; create and remove it
explicitly:

```sh
bash -euo pipefail <<'BASELINE'
BASE_COMPARE=/home/dev/Documents/my_projects/ruTorrent/.worktrees/gemini-alias-base-compare
base_created=0
deps_linked=0

cleanup_base() {
  status=$?
  if [ "$deps_linked" -eq 1 ] && [ -L "$BASE_COMPARE/tests/node_modules" ]; then
    unlink "$BASE_COMPARE/tests/node_modules"
  fi
  if [ "$base_created" -eq 1 ]; then
    git worktree remove --force "$BASE_COMPARE" || true
  fi
  exit "$status"
}
trap cleanup_base EXIT INT TERM

test ! -e "$BASE_COMPARE"
git worktree add --detach "$BASE_COMPARE" \
  495e2a54a657efcc132dc1456db8d7e680304a8a
base_created=1
test "$(git hash-object "$BASE_COMPARE/tests/package-lock.json")" = \
  "$(git hash-object /home/dev/Documents/my_projects/ruTorrent/tests/package-lock.json)"
ln -s /home/dev/Documents/my_projects/ruTorrent/tests/node_modules \
  "$BASE_COMPARE/tests/node_modules"
deps_linked=1

# Base RED is evidence, not a shell-control failure. Capture it first, then
# clean the disposable worktree before classifying candidate versus base.
set +e
base_out=$(cd "$BASE_COMPARE/tests" && npm test -- --runInBand 2>&1)
base_code=$?
set -e
printf '%s\n' "$base_out"
printf 'base-jest-exit=%s\n' "$base_code"

unlink "$BASE_COMPARE/tests/node_modules"
deps_linked=0
git worktree remove "$BASE_COMPARE"
base_created=0
trap - EXIT INT TERM
BASELINE
```

Compare the captured candidate and base failures by test name and message.
Candidate RED with base GREEN is `REGRESSION`; equal base/candidate RED remains
an explicitly reported base failure. Never call either case “candidate full
suite is green”.

Full PHP harness on the host:

```sh
cd tests
bash php-test.sh
cd ..
```

The host full harness above is the PHP 8.5 full-suite lane; Task 1 pinned the
host to PHP 8.5.x. Run the full harness in the two already-installed downlevel
containers as well. Use the invoking user's numeric UID/GID so permission
fixtures are not invalidated by root-owned files:

```sh
for image in php:7.4-cli php:8.1-cli
do
  docker image inspect "$image"
  docker run --rm --network none \
    --user "$(id -u):$(id -g)" \
    -v "$PWD:/w" -w /w/tests \
    "$image" bash php-test.sh
done
```

Finally run the two changed PHP test classes under PHP 8.5 inside the immutable
shipped image. Do not interpret its unrelated full-harness gaps as package
failures; this command intentionally runs only the load-bearing classes:

```sh
RT_IMAGE=sha256:7cea0d1725862165173c39041ab1fbf9f39233a475ac6a7ce7cad7e07a7b0e97
set +e
php85_out=$(docker run --rm --network none \
  --user "$(id -u):$(id -g)" \
  --entrypoint php85 \
  -v "$PWD:/w" -w /w/tests \
  "$RT_IMAGE" -c php-test.ini -r '
require "php/RtorrentCompatibilityTest.php";
require "php/SocketAllocLimitsTest.php";
foreach (array("RtorrentCompatibilityTest", "SocketAllocLimitsTest") as $class) {
    echo "Test: ", $class, "\n";
    $test = new $class();
    $test->setUp();
    $test->run();
    $test->tearDown();
}
' 2>&1)
php85_code=$?
set -e
printf '%s\n' "$php85_out"
test "$php85_code" -eq 0
printf '%s\n' "$php85_out" | grep -q \
  '>>testStockDaemonMethodFixtureIsCompleteUniqueAndSorted>>'
! printf '%s\n' "$php85_out" | grep -qE \
  '^Failed:|^not ok|failed with error|PHP (Fatal|Parse) error|Uncaught'
```

Do not pull a missing image during this task. Every mandatory image was checked
before worktree creation. The shipped image lacks some test-only extensions,
which is why it supplies a focused PHP 8.5 container lane while the host runs
the complete PHP 8.5 harness.

Optional PHPStan is required only if the exact image is already local:

```sh
if docker image inspect ghcr.io/phpstan/phpstan:2.2.9 >/dev/null 2>&1; then
  docker run --rm --network none \
    --user "$(id -u):$(id -g)" \
    -v "$PWD:/app" -w /app \
    ghcr.io/phpstan/phpstan:2.2.9 \
    analyse --no-progress --level=0 \
    php/settings.php \
    tests/php/RtorrentCompatibilityTest.php \
    tests/php/SocketAllocLimitsTest.php
else
  echo 'PHPStan image absent: optional check not run'
fi
```

Finally inspect the exact scope:

```sh
git diff --stat 495e2a54a657efcc132dc1456db8d7e680304a8a
git diff --name-status 495e2a54a657efcc132dc1456db8d7e680304a8a
git diff --numstat 495e2a54a657efcc132dc1456db8d7e680304a8a
git status --short
```

The name-status output must contain exactly the four paths from section 3.
There must be no generated fixture, log, patch, archive, task document, or
worktree metadata in the diff.

### Task 12: Independent self-review before finalizing

Review the complete files, not only added hunks. Answer each question with
evidence:

1. Can either PHP or JS fixture parsing return an empty collection and still
   pass?
2. Can duplicate registry names disappear before the uniqueness assertion?
3. Can the deprecated aliases be swapped while preserving the missing target
   set?
4. Can a production sender hide outside the scanned roots?
5. Does the scan exclude anything broader than tests and the two definition
   tables?
6. Does every version gate include the real inline PHP seed where applicable?
7. Are PHP and JS counts independently pinned?
8. Does any comment call the 982-name fixture a full 0.16.21 registry?
9. Does any newly added comment reference fork-only task history, introduce a
   new “the fork” description, or rely on unstable source line numbers?
10. Are both socket edits genuinely comment-only?
11. Did any mutation fail because of a fatal/bootstrap problem rather than the
    named assertion?
12. Does the final diff contain any production alias-table change?

If any answer is uncertain, do not finalize. Resolve it, amend the single
candidate commit, then repeat all affected normal and mutation checks; otherwise
return a blocker.

### Task 13: Finalize exactly one local candidate commit

Task 10 already created the only candidate commit. Stage only allowed follow-up
corrections made while strengthening tests or completing self-review. If the
index is non-empty, amend the same commit; never create a second one:

```sh
git add \
  php/settings.php \
  tests/php/SocketAllocLimitsTest.php \
  tests/js/rtorrent.spec.js \
  tests/php/RtorrentCompatibilityTest.php

if ! git diff --cached --quiet; then
  git diff --cached --check
  git diff --cached --name-status
  git diff --cached --stat
  git commit --amend --no-edit
fi

# Remove only the symlink created in Task 3, never its target directory.
test -L tests/node_modules
unlink tests/node_modules
```

Verify the commit structure:

```sh
FINAL_SHA=$(git rev-parse HEAD)
PARENT_SHA=$(git rev-parse HEAD^)
test "$PARENT_SHA" = 495e2a54a657efcc132dc1456db8d7e680304a8a
test "$(git rev-list --count "$PARENT_SHA..$FINAL_SHA")" -eq 1
test -z "$(git status --porcelain)"
git show --check --stat --oneline "$FINAL_SHA"
git diff --name-status "$PARENT_SHA..$FINAL_SHA"
```

Do not push. Do not merge the commit into fork `master`. Do not remove the
worktree; it must remain available for independent review.

## 10. Required final report from Gemini

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
RUNTIME_BLOCKED
RUNTIME_DRIFT
FIXTURE_DRIFT
TEST_NOT_SENSITIVE
REGRESSION
ENVIRONMENT_BLOCKED
AUTHORITY_REQUIRED
```

Do not use `READY_FOR_REVIEW` if any mandatory check was skipped or red.

### `REFS`

Report:

```text
upstream base SHA
donor SHA
candidate parent SHA
candidate final SHA
branch name
worktree absolute path
rTorrent 0.16.21 image ID/repo digest
```

### `SCOPE`

Paste exact outputs of:

```text
git diff --name-status base..final
git diff --numstat base..final
```

State separately that production alias tables and runtime behavior are
unchanged.

### `SOURCE EVIDENCE`

For 0.16.20 and 0.16.21, identify official commit, source file, registration,
and gate for the socket commands and three deprecated-only commands.

### `BASELINE`

Give commands, exit codes, named test counts, and any known base failure.

### `RUNTIME ORACLES`

Report:

```text
0.16.21 client version
API version
raw method count
unique method count
fixture row/unique/sorted checks
fixture-minus-runtime diff
PHP-targets-minus-runtime exact diff
lab teardown result
```

### `CANDIDATE TESTS`

For every focused/full PHP/Jest/lint/container command, give exit code and test
count. Clearly separate green, equal-to-base failures, and skipped/blocked.

### `MUTATIONS`

Provide a 13-row table matching Task 10. Each row must name the failing test,
exit code, fatal/bootstrap scan result, and post-reset green result.

### `SELF-REVIEW`

Answer all 12 questions from Task 12.

### `GIT STATE`

Report final candidate status and confirm:

```text
one non-merge commit
direct parent is the exact upstream base
no push
no PR
no fork-master integration
primary checkout unchanged
user diagnostic files untouched
lab container removed
candidate worktree retained
```

### `DEVIATIONS`

List every deviation from this brief. Write `none` only if there are none.

## 11. Definition of done

The delegated implementation is ready for our review only when all of the
following are true:

- exact upstream base and prerequisite ancestry proved;
- candidate isolated in the named worktree;
- only the four allowed files changed;
- both production-file changes are comment-only;
- PHP and JS full alias surfaces are characterized at every named gate;
- inline PHP seed participates in the map;
- shared 982-name fixture is complete, unique, sorted, and non-empty;
- fresh stock 0.16.21 runtime returns the expected registry and contains the
  entire 0.16.20 fixture;
- modern PHP target difference is exactly the three deprecated-only targets;
- exact deprecated key-to-target pairings are pinned in PHP and JS;
- no production sender exists for those aliases;
- all 13 mutations produce the intended named RED and recover to GREEN;
- focused and full verification matrix is green, or any base-equal unrelated
  failure is explicitly demonstrated on the exact base;
- full PHP harness is green on PHP 7.4 and 8.1 containers and PHP 8.5 host;
- both changed PHP test classes are green in the immutable PHP 8.5 shipped
  image;
- final branch contains exactly one non-merge commit based directly on the
  specified upstream SHA;
- no push, PR, master integration, production call, or unrelated cleanup was
  performed;
- the complete report in section 10 is returned.

`READY_FOR_REVIEW` is not acceptance. After Gemini returns, another model will
perform a whole-file review, independently rerun load-bearing mutations and
container/runtime checks, and either amend the candidate or reject it.
