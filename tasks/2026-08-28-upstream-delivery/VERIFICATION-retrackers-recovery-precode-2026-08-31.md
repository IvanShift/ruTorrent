# Package 5 retrackers recovery — pre-code contract correction and RAW audit

Date: 2026-08-31

Implementation parent: `up/scgi-transport=4682a761`

Status: **DESIGN APPROVED — implementation pending**

## Outcome

The former exact-five-path contract is refuted. It required
`plugins/retrackers/update.php` to become safely importable while also freezing
the complete predecessor sequence-test byte-for-byte. Those requirements cannot
both hold: the test needs an explicit import-only sentinel before loading the
new entrypoint.

Package 5 remains one implementation package, but its exact scope is corrected
to six paths:

1. `plugins/retrackers/init.php`;
2. `plugins/retrackers/done.php`;
3. `plugins/retrackers/run.sh`;
4. `plugins/retrackers/update.php`;
5. `tests/plugins/retrackers/UpdateTest.php`;
6. `tests/plugins/retrackers/RetrackersUpdateSequenceTest.php`.

`RETRACKERS_IMPORT_ONLY` is defined before `update.php` is included from both
production importers and both tests. The sequence test explicitly loads
`retrackers.php` and then `update.php`. Only its bootstrap preamble may change;
the class body remains immutable.

Frozen sequence invariants:

- 12 sorted registered method names, SHA-256
  `0ee7b35f9cda898d00e963b7e23aff02351e3653db21bbf2e99e31a34d5c7044`;
- bytes from `class RetrackersUpdateSequenceTest extends TestCase` through EOF,
  SHA-256
  `f0dac045fa3b9e98172132977e05fa14b7f091d1b9779a989d8b1d047fecc8f3`;
- every one of the 12 methods must execute and pass.

The shell wrapper is a direct POSIX handoff:

```sh
cd "$(dirname "$0")" || exit 1
exec "$1" ./update.php "$2" "$3" "$4"
```

It has no `php -r`, environment-mode switch, extra argument, wrapper-owned
backgrounding, or alternate entrypoint.

## Independent current-baseline check

The predecessor sequence suite was executed in isolated `--network none`
containers on PHP 7.4, PHP 8.1, and PHP 8.5. Each run reported:

```text
12 methods / 40 assertions / 0 failure signals / exit 0
```

The sorted-name and class-body hashes above were recomputed from the current
tree. This proves the preservation baseline; it does not close package 5.

## RAW/BODY capture audit

Evidence root retained outside the repository:

```text
/home/dev/rutorrent-pkg5-raw-audit-20260831
```

Frozen evidence identities:

```text
audit-start ruTorrent HEAD  5208727dc1e446e0ab9539578e3f6e1e9f5cea4d
audit-start contract SHA    25990c9f757cd41ddc65318df746a0d19d7d6509a0f81b3583e059a65c8f47d8
AUDIT.md SHA-256            fcfb8595fce927f91059fbb412e964de6b4dc378a35bbc9af90ec618fc177169
MANIFEST.tsv SHA-256        738e303db946ff5503caa882d76e4cf7b43d53c7188171a8bb3461370e74ba33
SHA256SUMS SHA-256          a3d99021d3cddf56baa4d006983a5bac7332c51bb93e3855e35d4de47de54a15
```

The audit-start contract identity is provenance for the capture plan, not
authority for this later six-path correction. `SHA256SUMS` verifies all 298
retained artifacts. The capture directory
contains 112 RAW/BODY pairs. All ten disposable `pkg5raw-*` containers were
removed, no named volume remains, and every capture container used
`NetworkMode=none`.

Exact B5 request:

```text
bytes   1820
sha256  ae96a2e5264798d84e4a35e981bbe99d8337820a93a07ff989e480b329b44210
```

Stable natural missing-ledger bodies, captured twice per family:

| rTorrent | RAW bytes / SHA-256 | BODY bytes / SHA-256 | Natural result |
|---|---|---|---|
| 0.9.8 | 1627 / `f9cac517212137455ed35f3cad86b55c3cd8fe8f2274a2ea2a3ad8c7c4b7b812` | 1563 / `505031b6aa974f339343ab70778eb4430fb9d0e4eed702ecdb6fec32baed04ac` | slots 1–3 succeed; slots 4–5 are missing-ledger faults |
| 0.16.21 | 3476 / `e7eeff916796ecb696c050feeb3273e6ca409660da032af6c14e787fe3c031f1` | 3412 / `f32032540fe36d6a0b5e6a024174da67f6d25d31742b16a159b3e8307b45f927` | slots 1–3 succeed; slots 4–5 are missing-ledger faults |

The archive also pins direct empty/populated `d.multicall2`, stable-pair
`t.multicall`, stable-pair `d.custom.items`, coherent ledger reads,
`method.has_key=0|1`, direct/member faults, a later success after a member
fault, and the 0.16.21 source-reachable partial row. Synthetic ledger captures
are serializer-shape RED evidence only and are never labelled production
recovery states.

## Reachability verdicts

| Claim | Verdict | Evidence |
|---|---|---|
| The missing serializer/transport fixtures cannot be captured before implementation | **REFUTED** | Both daemon families now have retained exact RAW and BODY fixtures for every currently reachable required shape. |
| A production-valid B5 with `rr.receipts.v1`, `pf`/`pv`, extended owner, F/S/D actions, phase and four-field marker can be captured from current code | **UNREACHABLE IN PRODUCTION** | Current production has no such producers. Manual `method.set_key` proves serializer shape only. |
| The complete two-family × eight-state × two-identical-read manifest can be captured before implementation | **UNREACHABLE IN PRODUCTION** | The valid phase/action/epoch producers first appear in package 5. This remains the post-implementation acceptance gate. |
| rTorrent 0.9.8 can provide an XML partial-row response after the measured erase adversary | **UNREACHABLE IN PRODUCTION** | The daemon reproducibly exits 139, `OOMKilled=false`, and returns zero response bytes; inventing XML would be false evidence. |
| Stock rTorrent 0.16.21 exposes a deterministic public RPC producer for an invalid tracker-wrapper row | **UNREACHABLE IN PRODUCTION** | Source contains the branch, but unsupported/inserted wrappers are discarded before visibility. Keep the exact synthetic `[[]]` rejection fixture distinct from the captured empty outer list. |

The first two production-manifest verdicts are full verdicts about the current
product, not excuses to omit acceptance. They become reachable only after the
implementation creates the frozen producers and must then turn GREEN before
package approval.

## Gate

The corrected contract and pre-code fixture set are sufficient to begin
RED-first implementation from `4682a761`. They do not make the branch ready,
mergeable, or publishable. Final acceptance still requires the real producer
matrix, both-daemon runtime scenarios, PHP 7.4 `memory_limit=128M` bounds,
mandatory mutations, the six-path diff, and independent whole-file review.
