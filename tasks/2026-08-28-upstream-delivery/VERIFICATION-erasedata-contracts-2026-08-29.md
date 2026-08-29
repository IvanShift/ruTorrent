# A/B/C+P0 container verification checkpoint — 2026-08-29

## Scope and verdict

Это verification artifact для design handoff:

- A `up/erasedata-remove-payload`;
- B `up/ratio-erasedata-contract`;
- combined C+P0 `up/rutracker-check-replacement-transaction`.

Контейнеры подтвердили exact-base defects, donor behavior, существующие suites
и false-green gaps. Они не могут дать GREEN коду, которого ещё нет. Corrected
durable drain/rearm/no-bridge designs остаются implementation work и требуют
собственных named RED → GREEN tests.

## Immutable inputs and isolation

```text
upstream base       755404f3e38af98b6901852b35be10fb9659ffd3
initial checkpoint  edcea5a96332835335199ef6d33e667e32dfbaf6
current handoff     24891da9edddc252835493a4100e966dcd50cdf9
```

Оба дерева экспортировались из Git object database, а не копировались из dirty
worktree:

```sh
verify_root=$(mktemp -d /home/dev/rutorrent-contract-verify.XXXXXX)
mkdir "$verify_root/upstream" "$verify_root/initial" "$verify_root/current"
git archive 755404f3e38af98b6901852b35be10fb9659ffd3 \
  | tar -x -C "$verify_root/upstream"
git archive edcea5a96332835335199ef6d33e667e32dfbaf6 \
  | tar -x -C "$verify_root/initial"
git archive 24891da9edddc252835493a4100e966dcd50cdf9 \
  | tar -x -C "$verify_root/current"
```

Каждый container run использовал `--rm --network none --user 1000:1000` и
read-only `-v <export>:/w:ro`. Live service, RPC daemon, production files и
credentials не использовались.

Images:

```text
php:7.4-cli                  sha256:7bbbb12d14986e855e5213c6b349e97e0f2e3da82536ec87da11a6c66fe2fcb2
php:8.1-cli                  sha256:7699e39d88f66297bc94a8e3ab1ba60cfa68440a7c511599594475133eb863c7
php:8.5-cli                  sha256:568a88bb7a3ed8c914529bdb7ea99c32325bb913a5ee9ddca2a2ef3ad64ada7a
ivanshift/rutorrent:latest   sha256:b9f58df32a5ae70f5b5e796418abbbb6c0e36d9bd9b61c20415c2d12022b8479
rutorrent-rt21:test          sha256:542dc45be35616096b57899a60b36015d4a99bc1a8aa8f3b92b0c338cfeca1f2
```

`rutorrent-rt21:test` использован только как PHP runtime; полный s6/rTorrent
daemon в этой design-only проверке не запускался.

## Reproducible focused runner

TestCase classes запускались тем же lifecycle, который использует harness:

```sh
php -d display_errors=1 -d error_reporting=-1 -r '
require($argv[1]);
$class = $argv[2];
$case = new $class();
try { $case->setUp(); $case->run(); }
finally { $case->tearDown(); }
' <test-file> <test-class>
```

Standalone suites запускались через `php <test-file>`. Перед интерпретацией
каждого результата проверялись exit code, named-test count и отсутствие
preceding fatal.

## A and B results

Initial `edcea5a9` and current `24891da9` (identical A/B focused results):

| Runtime | RemoveWithData | Ratio | Schedule |
|---|---:|---:|---:|
| PHP 7.4 | 204 methods / 1305 assertions | 10 / 61 | 11 / 0 |
| PHP 8.1 | 204 / 1305 | 10 / 61 | 11 / 0 |
| shipped PHP 8.5.9 | 204 / 1305 | 10 / 61 | 11 / 0 |
| `rutorrent-rt21:test` | 204 / 1305 | 10 / 61 | 11 / 0 |

Все rows: exit 0, failed assertions/errors/warnings/deprecations — 0. Exact base
во всех PHP compatibility images: RemoveWithData 9/14, Ratio 6/8, Schedule
11/0. Все 12 frozen A+B PHP paths прошли `php -l` во всех четырёх HEAD
runtimes.

Copied-real missing-helper result on initial `edcea5a9` in PHP 7.4, PHP 8.1 and
shipped PHP 8.5.9 (not the rt21 baseline runtime):

```text
HEAD: exact cat=; no stop/close/execute/custom5/erase
base: d.stop=; d.close=; d.set_custom5=1; d.erase=
```

Disposable mutations on initial `edcea5a9` в `php:7.4-cli`, `php:8.1-cli` и
shipped PHP 8.5.9:

| Mutation | Result | Meaning |
|---|---:|---|
| restore destructive missing-helper fallback | 10/61 PASS | confirmed false-green B gap |
| remove Ratio exact-force guard | 14 failures | existing defense held, prod-unreachable |
| restore CLI default force | 2 failures | A-owned test held |
| restore stale same-hash early exit | 1 failure | A-owned test held |
| remove isolated publish guard | named test, 2 failures + expected child fatal | A/library defense held |
| remove username filter | 10/61 PASS | redundant/refuted hunk |

## C+P0 results

Exact upstream `CheckerTest.php` in shipped PHP 8.5:

```text
31 tests, 0 failures, exit 0
```

Current `24891da9` in each official PHP 7.4/8.1/8.5 image:

```text
AtomicOwnershipTest.php       18 / 0
CheckerTest.php              130 / 0
EntrypointsTest.php           22 / 0
ProjectionContractTest.php     6 / 0
StateTest.php                 15 / 0
UpdatePassTest.php           126 / 0
total                        317 / 0
```

Отдельный `RemoveWithDataTest.php`: 1305 passed / 0 failed / 0 fatal в
official PHP 7.4, official PHP 8.1 и shipped PHP 8.5.9.

Broader offline PHP 8.5 runner `tests/plugins/rutracker_check/run.php`: 19 suite
summaries, 700 tests, 0 failures, exit 0. Initial `edcea5a9` was 312/0 focused
and 695/0 broader; all five new current-commit tests pass.

Shipped image lacks `tokenizer`, so its `EntrypointsTest` produces the expected
8 `token_get_all()` failures. The same immutable export passes 22/0 in official
PHP 7.4/8.1/8.5; this is an image capability gap, not a code regression.

Exact-base probes loaded the real private `cleanupObsoleteFiles()` through the
exact `CheckerTest` harness:

```text
shared=deleted orphan=deleted base=kept requests=0 log=0 third_claim=declared
old=kept base_mode=0500 requests=0 log=0
```

The first output independently confirms reachable third-torrent deletion and
zero fleet ownership requests. The second confirms failed unlink leaves the
file, creates no durable retry and emits no shipped-default diagnostic.

## What remains unverified until implementation

Final-A-based upstream packages ещё не существуют. Current donor `24891da9`
уже содержит token/false/null claim seam и несколько green consumer cases, но
его exact P0 hunk carve/mutation matrix ещё не построены. Остаются непроверены:

- A fixed durable drain generation/state, real child ack before erase,
  `E/P/T1/T0` batch cardinality, journal/crash/retirement/hung-worker paths;
- B startup rearm after volatile schedule loss while erasedata is disabled;
- C+P0 OLD/NEW-aware fleet tri-state, post-capture scan, no-bridge quarantine,
  restore/crash/occupied-name handling и parent lexical overlap;
- exact P0 claim transfer: both sweep consumers, token-only release, bounded
  storage-outage diagnostic, required mutations и exclusion P1 fast/source
  hunks;
- pre-erase C wake/ack, repeating retry after kick/worker loss and rutracker
  init rearm after restart.

Consequently the correct current verdict is **container baseline verified;
implementation not started**, not «100% GREEN». Closure later requires the
frozen named RED/mutation matrices, PHP 7.4/8.1/8.5 and non-root containers,
full daemon/restart probes in disposable `tasks/rt-lab.sh`, PHPStan and
independent whole-file review.
