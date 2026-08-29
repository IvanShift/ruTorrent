# `up/erasedata-remove-payload` (A) — independent design review

Дата: 2026-08-29. Current base: `upstream/master=755404f3`. Fork и прежние
findings использованы как гипотезы; review был read-only.

## Verdict: APPROVED design boundary

A является одним cohesive package на точной границе **8 production + 2 test
paths**. Это одобрение scope/дизайна, не ещё не существующего isolated code.

Если разработку нужно разделить, допустим только stacked order:

1. **A1 reader/collector:** legacy-compatible parse, identity owner,
   fail-closed deletion, exact retention/retry и classified diagnostics;
2. **A2 producer/entrypoints:** unique staged v2 publication, exact force,
   capability preflight и erase только после durable publish.

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
- ordinary nonblocking per-hash lock contention не логируется как permanent;
- scheduler-lock open и index scan failures классифицируются unconditional;
- repaired transient job завершается на следующем pass.

Boolean deletion result недостаточен: parser/mutation layers должны возвращать
structured reason. Минимальный pinned vocabulary:
`rpc-unknown`, `unreadable-manifest`, `active-path`, `unsafe-path`,
`unlink-failure`, `rmdir-failure`, `directory-reference-unavailable`,
`legacy-force-limited`.

## Dependencies

- `up/httprpc-refusals` — delivery/evidence parent, но не production-symbol
  dependency A;
- SCGI и `up/xmlrpc-proxy-policy` — independent siblings;
- позже `up/httprpc-erasedata-contract` ждёт **оба** A и proxy-policy;
- Ratio B и obsolete C — independent children после A;
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
- обычный busy hash lock не является retained failure.

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

Mutations должны убить: erase after failed publish, direct final-name write,
UNKNOWN collapse, consume after failure, hash-only publication/logging, lexical
instead of physical identity, CLI-gate bypass, no-preflight, missing fallback,
existence-only fd acceptance, force coercion, `eLog` routing, retry suppression,
raw leakage и silent index/lock failure.

Каждый named RED обязан реально выполниться без preceding fatal; после restore
нужен свежий GREEN на focused non-root permission run, PHP 7.4/8.1/8.5
матрицах, PHPStan, exact ten-path scope и whole-file review.

## Acceptance condition

A готов к RED-first реализации на этой границе после явного user approval.
Merge/handoff возможен только без v3/C, httprpc consumer, Ratio, SCGI и
`php/xmlrpc_path.php` hunks. Агент push не выполняет.
