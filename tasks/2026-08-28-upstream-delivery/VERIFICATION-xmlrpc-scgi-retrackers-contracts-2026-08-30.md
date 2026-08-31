# XMLRPC / SCGI / retrackers — сводный verification and cleanup archive

> Historical archive only. Its SCGI/queue statuses and retrackers exact-five
> authority describe the 2026-08-30 checkpoint. Current SCGI closure is in
> `VERIFICATION-scgi-transport-2026-08-31.md`; the corrected retrackers
> six-path authority and pre-code capture boundary are in
> `VERIFICATION-retrackers-recovery-precode-2026-08-31.md`. Do not use the
> exact-five rows below as the current implementation contract.

Дата архива: 2026-08-30. Frozen repository identity:
`14683d93bc54dbab89d6abce636d2e749e8492ba` на branch
`codex/retrackers-contract-finish` с clean tracked/index tree до authoring.

## Status

**EVIDENCE ARCHIVED; EXACT EIGHT-CONTAINER CLEANUP COMPLETE**

Cleanup verdict: **GREEN**. Seven frozen running containers were stopped and
all eight frozen container objects were removed by corrected immutable full ID.
Successful postchecks found every old ID, exact name and published host port
absent. Both images, all eight anonymous volumes and the bridge network remain.

Этот документ архивирует research/design evidence и свежую characterization
текущего predecessor code. Он не является implementation/capture acceptance,
не закрывает ни один implementation package, не даёт implementation, merge,
deployment или push authority. Все **18 packages общей implementation queue**
остаются pending; retrackers recovery — один package этой queue.

| Contract | Design/research verdict | Current implementation/capture verdict |
|---|---|---|
| SCGI transport | `DESIGN APPROVED — implementation pending`; exact seven paths | **BLOCKED — implementation does not exist**. First missing API: nine-argument `rSCGITransport::send()` returning selected `RESPONSE_RAW` / `RESPONSE_BODY` string. Unblock only by exact seven-path implementation, witnessed natural RED, deterministic short/zero/false-write and held-open-frame tests, corrected GREEN, mutations, PHP 7.4/8.1/8.5 and real TCP/UNIX daemon gates. |
| XMLRPC proxy policy | `DESIGN APPROVED — implementation pending`; exact seven paths | **BLOCKED — implementation does not exist**. First missing policy/test: unconditional terminal `system.multicall` refusal plus copied-real `tests/php/XMLRPCProxyEntrypointTest.php`. Unblock only by exact seven-path policy, natural RED, corrected GREEN, mutation gates and both-door/both-daemon zero-send/trust captures. |
| Retrackers recovery | `DESIGN APPROVED — implementation pending`; exact five paths | **BLOCKED — implementation does not exist**. First missing producer is production `rr.receipts.v1`, followed by `pf`/`pv`, extended owner/key2 and the request-specific V2 codec/final SCGI adapter. Unblock only by all contract RED/GREEN/mutations and the production two-family/eight-state/two-read manifest. |

The fresh green suites below characterize the current donor/predecessor only.
No donor or characterization suite is target GREEN for these approved
successor contracts.

## Exact contract authorities

| Authority | Lines / identity | SHA-256 | Frozen status/scope |
|---|---:|---|---|
| `REVIEW-scgi-transport-2026-08-29.md` | 317 | `c784e87577e1ecb7faa809e0acb5da677e3921f59d1a15aa33f39e2c459424fe` | `DESIGN APPROVED — implementation pending`; seven paths |
| `REVIEW-xmlrpc-proxy-policy-2026-08-29.md` | 379 | `63990b091cd8620bfb2553a8f0a6b2e0df9424c76828d98a9da20a4f636a5004` | `DESIGN APPROVED — implementation pending`; seven paths |
| `REVIEW-httprpc-refusals-2026-08-29.md` | 172 | `ba0beb54359e1ee4d4ba93131e98a4969914b109e7223c8c16e8db83cf584373` | approved corrected design; five-path prerequisite code absent |
| `REVIEW-xmlrpc-path-ownership.md` | 47 | `5654d9c012008b10af33e5cd4aa9bc2e976a13e7aad081b38b4d85cfe364f926` | SCGI does not own `php/xmlrpc_path.php` |
| `REVIEW-retrackers-recovery-2026-08-29.md` | 2,800; approval commit `14683d93bc54dbab89d6abce636d2e749e8492ba` | `922a7bad8caed5c6cdd0ce02112ff4729be9fbb6798ba5ee208440fc1edbfc17` | `DESIGN APPROVED — implementation pending`; five paths; overall queue remains 18 pending |

SCGI's seven-path scope remains `conf/config.php`, `php/scgitransport.php`,
`php/xmlrpc.php`, `rpc2.php`, `tests/php/SCGITransportFixture.php`,
`tests/php/SCGITransportTest.php`, and `README.md`.

Proxy's seven-path scope remains `conf/xmlrpc_proxy.php`,
`php/xmlrpc_proxy.php`, `plugins/httprpc/action.php`,
`plugins/httprpc/conf.php`, `tests/php/XMLRPCProxyTest.php`,
`tests/php/XMLRPCProxyContractFixture.php`, and new
`tests/php/XMLRPCProxyEntrypointTest.php`.

Retrackers' five-path scope remains `plugins/retrackers/init.php`,
`plugins/retrackers/done.php`, `plugins/retrackers/run.sh`,
`plugins/retrackers/update.php`, and
`tests/plugins/retrackers/UpdateTest.php`. `plugins/retrackers/guard.php` is
explicitly outside that package.

## Evidence authorities and provenance boundary

The routing inventory, its independent correction and the anomaly review were
read in full:

| Evidence | SHA-256 | Authority |
|---|---|---|
| `task-3-container-verification-inventory.md` | `c4103a504cb10bc3e3ed7535ad38a152b4278317fb2099d80a13a0ccf60f00a3` | evidence routing proposal; its preservation proposal and one-nibble `contract-rt21` ID are superseded |
| `task-3-container-verification-audit.md` | `7050376a50e788f30ee6c255d06698f05a33288105f79c614fb45b340453f54c` | independent current-code/container audit; its one-nibble `contract-rt21` ID is superseded |
| `task-3-runtime-anomaly-review.md` | `230c00f6aaf74271c8f2251ddc9823cdc268996cf46f666926d919ba64d60142` | independent CLEAN reproduction of the PHP 7.4 allocation nondeterminism and exec/noexec 42/42 versus 41/42 runner effect |
| `task-3-verification-brief.md` | `4b51e2634770308fcaa95582c7fc7267d7b9a3dfdf8d84ab17721c6cf8893aaf` | approval-HEAD fresh-matrix and authoring authority |
| `task-3-verification-adversarial-review.md` | `ae4479894f9e9098bc1301a38c01ee7213cbbaa305c764feb40d538359db9309` | independent P1 discovery: emitted-diagnostic false-green and mutable-name cleanup ABA window |
| `task-3-corrected-classifier-manifest.md` | `daca277da6776f81a1ff965d6f8d6e519e5adc760c8688160295352e190f7bc7` | ignored 15-cell full-output digest/count/policy manifest for the corrected classifier |
| `task-3-container-id-correction.md` | `b2a75c125d2218123fb6cdaef11738e36c4289b2b5f02aa676868f3ac497c0e0` | positive Docker correction of the `contract-rt21` one-nibble transcription defect; first preflight stopped before mutation |
| `task-3-exact-container-cleanup.md` | `c416448e396b1a96424aa791a5211dcb3cb78b4ec5ae3cd6cd67c9d1b75f1bea` | exact stop-7/remove-8 transcript with positive Docker/ID/name/port and retained image/volume/network postconditions |

Applicable wire/source reports remain historical evidence with these exact
identities; they are not fresh 2026-08-30 successor implementation captures:

| Report | SHA-256 | Admissible claim |
|---|---|---|
| `task-1-xml-capture-report.md` | `adb9aeb623a4b9e1aa6fb314bfd9a05fdb55c5aeecac65b14d75b6bf7b1ded57` | exact two-family XML declarations/layouts, direct/member success/fault wrappers, i4/i8 |
| `task-1-array-capture-report.md` | `d441d35d3bd8b865b3e9c75322f2098011fb2d7172fa03f4092fb9e6b9ccaf0a` | request-specific row/list/struct shapes and empty/direct/member distinctions |
| `task-1-lf-command-report.md` | `cc6ec96a96d6ed4cf4a94fff7119e0adb984ccf378565992297b898e7d606364` | exact generic LF round-trip; CR/NUL/leading-dollar refusal boundary |
| `task-1-rtorrent-source-audit.md` | `35cb7cd3bbb66846a1b615ac8b11510000d3aa984cd1a5c1460e820e05666b29` | source ownership of wrappers, arrays, faults, widths and recursion |
| `task-1-historical-source-audit.md` | `17f24297387bf45334da4ef942388ed53b896a202e90c7a26dc3c2a05d003674` | no `method.get_key`; recursive event values; arbitrary `d.multicall2` width |
| `task-1-historical-scan-capture.md` | `322a42c88ae58024f250c8e1d2ae2a6835b9f1e29106f0b76dc266f8fb1d6bf6` | event-map variants, recovery width four, fault/partial-row behavior |
| `task-1-historical-combined-capture.md` | `8a413aa5457c6f086caf24ac98745e3544776d41245c4e6ee75a3053a3bfa0a4` | stable old five-slot V1 serializer shape; populated values synthetic/noncanonical |
| `task-1-b5-capture-preflight.md` | `eaba086a2e5ee240bdb9e720572946e574d787fc1c59961e05322d917b497709` | B5 call reachability, owning-builder byte pins and required manifest |
| `task-1-b5-epoch-capture.md` | `9aa5dbe0f259acdc9f50712b3566b8bb5c89d3d9d6a063ae86990d132d0ed136` | honest natural B5 RED/BLOCKED boundary; no fabricated producer |

The old combined BODY identities remain V1 RED/provenance only:

```text
0.9.8 empty      b9a8f7504aa9c2112a2591df8f97d153936bcdeaa393d256b944e96e791b2ac4
0.9.8 populated  e0fd0e152f473c2cde4d4c5f309460f288e00f0a025eb208fafa0eb67f88c6cb
0.16.21 empty     db548d9f026175cc87107b07ae79c79b3dfa4203b53c27bf35b5e488a7ab9f34
0.16.21 populated 3a3592dbb52793745ec458fc81301b607ba7dc02d5222e10e525f37b7886f723
```

## Exact daemon and image provenance

| Family | Image/source/binary identity | Serializer/runtime ownership |
|---|---|---|
| rTorrent 0.9.8 | `rutorrent-rt098:contract`, image `sha256:2fce8587d588652af5ee2308243bc0803b8a82ec81bf5377940801a131c283b7`; source `6154d1698756e0c4842b1c13a0e56db93f1aa947`; Debian rTorrent `0.9.8-1`, libtorrent `0.13.8-2`, xmlrpc-c `1.33.14-11`; daemon binary SHA-256 `aaf80e13724d5b870841bfc1d068a0b84b2423eafb3239b748e6ef913726ae05` | family `0x01`, UTF-8 declaration, structural CRLF, i4 void/fault values; image has no PHP runtime |
| rTorrent 0.16.21 | `rutorrent-rt21:test`, image `sha256:542dc45be35616096b57899a60b36015d4a99bc1a8aa8f3b92b0c338cfeca1f2`; source `109a20c09c3cab9eb13c2d96ea79362ac6c318fc`; libtorrent `306fbbf008183dcfd55fae0a83fff95302c64987`; daemon binary SHA-256 `d4a7d1065feb23a189118678c6f50dcdb0dd5acadcad374df95b1e1e8111dc93` | family `0x02`, TinyXML2 compact XML, i8 values/faults |

Historical daemon captures used disposable labs tied to these exact families.
The fresh approval-HEAD matrix below is a PHP predecessor rerun, not a new
daemon acceptance run. No old bridge-networked container was reused.

## B5 natural RED and pure-codec vectors

The measured current B5 request and both byte-identical family pairs were:

| Object | Bytes | SHA-256 | Result |
|---|---:|---|---|
| exact replacement-five request | 1,820 | `ae96a2e5264798d84e4a35e981bbe99d8337820a93a07ff989e480b329b44210` | issued twice per family |
| 0.9.8 BODY | 1,563 | `505031b6aa974f339343ab70778eb4430fb9d0e4eed702ecdb6fec32baed04ac` | both reads byte-identical |
| 0.16.21 BODY | 3,412 | `f32032540fe36d6a0b5e6a024174da67f6d25d31742b16a159b3e8307b45f927` | both reads byte-identical |

Slots 1–3 succeeded; slots 4–5 naturally faulted because current tracked code
has no `rr.receipts.v1` producer. Therefore no V2 phase, digest or `BOOTSTRAP`
was accepted. Manual `method.set_key`, hand-written actions or placeholder
hashes remain shape-only RED and cannot close producer provenance. All eight
states × two families × two reads remain post-implementation **BLOCKED**.

The approved contract's independently reproduced eight values are
semantic-empty pure-codec known-answer vectors only, not response BODY hashes,
production captures or producer evidence:

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

Future owning-builder pins remain `F_A` 2,391 bytes /
`766b4e30c55ae061e6c93dfd10a75ef6f9cdb24acdddf5614b2c405cb2b3259e`,
`F_B` 2,389 /
`dcce092c9ae54f02e95eae4782439944fe28eda77bbc7af45c67beda7daa8936`,
`S` 463 /
`c1ec79a6767399434309847606d9dda5f5d5eaf5d3d849e42c72b32a9dae83bb`,
and `D` 587 /
`db46e1e6ec1e6857edddc610f5ef40e632390a2a7792901d185ea015ef9f404f`.
They constrain the future real builder and are not accepted state captures.

## Fresh approval-HEAD PHP/container matrix

The matrix ran the exact approval HEAD
`14683d93bc54dbab89d6abce636d2e749e8492ba`, using these immutable images:

| Entrypoint/image | Exact image ID | Reported runtime |
|---|---|---|
| `php:7.4-cli` / `php` | `sha256:7bbbb12d14986e855e5213c6b349e97e0f2e3da82536ec87da11a6c66fe2fcb2` | PHP 7.4.33 |
| `php:8.1-cli` / `php` | `sha256:7699e39d88f66297bc94a8e3ab1ba60cfa68440a7c511599594475133eb863c7` | PHP 8.1.34 |
| `ivanshift/rutorrent:latest` / `php85` | `sha256:b9f58df32a5ae70f5b5e796418abbbb6c0e36d9bd9b61c20415c2d12022b8479` | PHP 8.5.9 |

The normal ephemeral-root acceptance command captured the complete output and
classified it. A direct `TestCase::run()` process exit is not a gate because
that harness prints failed assertions without changing the PHP exit status.
The mandatory output-aware command shape was:

```sh
set -eu

verification_worktree=/home/dev/Documents/my_projects/ruTorrent/.worktrees/retrackers-contract-finish
verification_diagnostic_re='(^|[[:space:]])(PHP[[:space:]]+)?(Warning|Notice|Deprecated|Strict Standards|Parse error|Fatal error|Recoverable fatal error|Core (warning|notice|error)|Compile (warning|error)|User (warning|notice|deprecated)|Startup):'

expected_php85_proxy_diagnostics() {
  for verification_line in 147 485 588 629 724 869; do
    printf 'Deprecated: Method ReflectionProperty::setAccessible() is deprecated since 8.5, as it has no effect since PHP 8.1 in /w/tests/php/XMLRPCProxyTest.php on line %s\n' "$verification_line"
    printf 'PHP Deprecated:  Method ReflectionProperty::setAccessible() is deprecated since 8.5, as it has no effect since PHP 8.1 in /w/tests/php/XMLRPCProxyTest.php on line %s\n' "$verification_line"
  done | LC_ALL=C sort
}

classify_emitted_diagnostics() {
  verification_diagnostic_policy=$1
  verification_diagnostic_output=$(printf '%s\n' "$verification_output" |
    grep -Ei "$verification_diagnostic_re" || true)

  case "$verification_diagnostic_policy" in
    none)
      [ -z "$verification_diagnostic_output" ] || return 1
      ;;
    php85-xmlrpcproxy-reflection)
      verification_sorted_diagnostics=$(printf '%s\n' \
        "$verification_diagnostic_output" | LC_ALL=C sort)
      verification_expected_diagnostics=$(expected_php85_proxy_diagnostics)
      [ "$verification_sorted_diagnostics" = \
        "$verification_expected_diagnostics" ] || return 1
      ;;
    *)
      return 1
      ;;
  esac
}

classify_php_case() {
  verification_expected_methods=$1
  verification_expected_passed=$2
  verification_diagnostic_policy=$3

  printf '%s\n' "$verification_output"
  [ -n "$verification_output" ] || {
    echo 'empty PHP test output' >&2
    return 1
  }
  [ "$verification_raw_status" -eq 0 ] || {
    echo "raw PHP/container exit: $verification_raw_status" >&2
    return 1
  }
  classify_emitted_diagnostics "$verification_diagnostic_policy" || {
    echo 'unexpected PHP diagnostic output' >&2
    return 1
  }
  if printf '%s\n' "$verification_output" |
    grep -Eiq '^Failed:|failed with error|Uncaught'
  then
    echo 'classified PHP test failure' >&2
    return 1
  fi

  verification_method_opens=$(printf '%s\n' "$verification_output" |
    grep -Ec '^>>test.*>>$' || true)
  verification_method_closes=$(printf '%s\n' "$verification_output" |
    grep -Ec '^<<test.*<<$' || true)
  verification_passed=$(printf '%s\n' "$verification_output" |
    grep -Ec '^Passed:' || true)
  [ "$verification_method_opens" -eq "$verification_expected_methods" ] &&
    [ "$verification_method_closes" -eq "$verification_expected_methods" ] &&
    [ "$verification_passed" -eq "$verification_expected_passed" ] || {
      echo "unexpected methods/assertions: $verification_method_opens/$verification_method_closes/$verification_passed" >&2
      return 1
    }
}

classify_retrackers_case() {
  printf '%s\n' "$verification_output"
  [ -n "$verification_output" ] || {
    echo 'empty retrackers test output' >&2
    return 1
  }
  [ "$verification_raw_status" -eq 0 ] || {
    echo "raw retrackers/container exit: $verification_raw_status" >&2
    return 1
  }
  classify_emitted_diagnostics none || {
    echo 'unexpected retrackers PHP diagnostic output' >&2
    return 1
  }
  if printf '%s\n' "$verification_output" |
    grep -Eiq '^not ok - |^Failed:|failed with error|Uncaught'
  then
    echo 'classified retrackers test failure' >&2
    return 1
  fi

  verification_ok=$(printf '%s\n' "$verification_output" |
    grep -Ec '^ok - ' || true)
  verification_summary=$(printf '%s\n' "$verification_output" |
    grep -Fxc '42 tests, 0 failures' || true)
  [ "$verification_ok" -eq 42 ] && [ "$verification_summary" -eq 1 ] || {
    echo "unexpected retrackers count/summary: $verification_ok/$verification_summary" >&2
    return 1
  }
}

run_current_matrix() {
  verification_php_entrypoint=$1
  verification_php_image=$2

  for verification_suite in \
    'php/SCGITransportTest.php SCGITransportTest 25 58' \
    'php/XMLRPCProxyTest.php XMLRPCProxyTest 75 194' \
    'php/XMLRPCProxyContractTest.php XMLRPCProxyContractTest 7 849' \
    'php/XMLRPCProxyRejectionTest.php XMLRPCProxyRejectionTest 7 17'
  do
    set -- $verification_suite
    if verification_output=$(docker run --rm --network none \
        --entrypoint "$verification_php_entrypoint" \
        -v "$verification_worktree:/w:ro" -w /w/tests \
        "$verification_php_image" \
        -d zend.assertions=1 -d error_reporting=-1 \
        -d display_errors=1 \
        -r 'require $argv[1]; $c=$argv[2]; $t=new $c(); $t->setUp(); $t->run(); $t->tearDown();' \
        "$1" "$2" 2>&1)
    then
      verification_raw_status=0
    else
      verification_raw_status=$?
    fi
    verification_diagnostic_policy=none
    if [ "$verification_php_image" = \
        sha256:b9f58df32a5ae70f5b5e796418abbbb6c0e36d9bd9b61c20415c2d12022b8479 ] &&
      [ "$2" = XMLRPCProxyTest ]
    then
      verification_diagnostic_policy=php85-xmlrpcproxy-reflection
    fi
    classify_php_case "$3" "$4" "$verification_diagnostic_policy"
  done

  if verification_output=$(docker run --rm --network none \
      --entrypoint "$verification_php_entrypoint" \
      -v "$verification_worktree:/w:ro" -w /w/tests \
      "$verification_php_image" \
      -d zend.assertions=1 -d error_reporting=-1 \
      -d display_errors=1 \
      -f plugins/retrackers/UpdateTest.php 2>&1)
  then
    verification_raw_status=0
  else
    verification_raw_status=$?
  fi
  classify_retrackers_case
}

run_current_matrix php \
  sha256:7bbbb12d14986e855e5213c6b349e97e0f2e3da82536ec87da11a6c66fe2fcb2
run_current_matrix php \
  sha256:7699e39d88f66297bc94a8e3ab1ba60cfa68440a7c511599594475133eb863c7
run_current_matrix php85 \
  sha256:b9f58df32a5ae70f5b5e796418abbbb6c0e36d9bd9b61c20415c2d12022b8479
```

Key safety properties were `--rm`, `--network none`, exact immutable image ID,
read-only worktree bind, explicit assertions/error reporting, raw-exit capture,
failure-marker and emitted-diagnostic rejection, non-empty output and exact
method/assertion/test counts, with no old task-owned container. Every fresh run
container disappeared after exit. A zero raw exit without classifier success
is not PASS.

All three runtimes freshly produced the same predecessor results:

| Suite | Registered tests | Assertions/result | Raw/classifier exit | Emitted diagnostics |
|---|---:|---:|---:|---|
| `SCGITransportTest` | 25 methods | 58 Passed | 0 / 0 | zero on all runtimes |
| `XMLRPCProxyTest` | 75 methods | 194 Passed | 0 / 0 | zero on PHP 7.4/8.1; exact allow-listed 12 on PHP 8.5 |
| `XMLRPCProxyContractTest` | 7 methods | 849 Passed | 0 / 0 | zero on all runtimes |
| `XMLRPCProxyRejectionTest` | 7 methods | 17 Passed | 0 / 0 | zero on all runtimes |
| retrackers `UpdateTest` | 42 tests | 0 failures | 0 / 0 | zero on all runtimes |

The corrected classifier was rerun over every complete captured output after an
independent review reproduced the earlier warning false-green. Its negative
controls rejected all 16 sampled diagnostic spellings, a synthetic retrackers
warning, and an incomplete allow-list; its positive controls and all 15 real
suite/runtime cells passed. The sole exception is deliberately exact and
test-only: on PHP 8.5, `XMLRPCProxyTest.php` emits both the `PHP Deprecated:` and
`Deprecated:` form for each `ReflectionProperty::setAccessible()` call at lines
147, 485, 588, 629, 724 and 869, exactly 12 lines total. The allow-list compares
the complete sorted diagnostic multiset byte-for-byte. A missing line, extra
line, changed path/line/message, warning, notice, startup/parse/fatal diagnostic
or the same deprecation in another suite is RED. This current test-harness
deprecation is characterization debt, not successor implementation GREEN.

PHP lint passed for all nine audited PHP paths in all three runtimes;
`sh -n plugins/retrackers/run.sh` passed. Proxy suites kept their exact counts
in the stricter diagnostic runs. The tracked/index tree remained clean and no
fresh run container remained.

### Read-only-root `/tmp` execution diagnosis

A stricter diagnostic used `--read-only` and tmpfs `/tmp`. Docker's implicit
tmpfs option was `noexec`, so `UpdateTest` returned 41/42 on every runtime. The
only failure was exact test
`run.sh executes worker with exact argv handover`, status 126. A minimal mount
probe showed `/tmp` included `noexec`; executing the fake worker returned
`Permission denied`. An otherwise identical tmpfs mounted
`rw,exec,nosuid,nodev` returned 0, and UpdateTest returned 42/42 on PHP
7.4/8.1/8.5.

The corrected strict runner requirement is therefore explicit:

```text
--read-only --tmpfs /tmp:rw,exec,nosuid,nodev,size=256m
```

This is a runner mount requirement, not a code change. `--read-only` plus a
`noexec` temporary filesystem is not a valid runner for a suite deliberately
testing an executable temporary worker.

### PHP 7.4 predecessor memory diagnosis

With read-only root, executable `/tmp` and default `memory_limit=128M`, current
PHP 7.4 SCGI characterization is nondeterministic at
`testSCGITransportLengthlessBodyOver64MiBRejected`. Isolated repeats returned
PASS, PASS, then exit 255 with `Allowed memory size ... exhausted`, attempting
65,019,368 bytes at current `php/scgitransport.php:238`. With
`memory_limit=256M`, the complete PHP 7.4 suite returned 25 methods / 58 Passed /
exit 0. The measured PHP 8.1 and PHP 8.5 complete runs returned 25/58/0 at
their default 128M.

This is a real limitation of the predecessor characterization, not an approved
design regression and not successor GREEN. The final SCGI contract already
requires missing `Content-Length` rejection before body accumulation, no
simultaneous full response representations and PHP 7.4 runtime/cap mutations.
The literal PHP 7.4 `memory_limit=128M` accepted-body/bounded-failure gate is a
combined final-parent/retrackers-consumer requirement. Those missing final
bytes/tests must be implemented rather than concealing the old memory behavior
with a donor-GREEN claim.

## Current tracked-code gap

The fresh matrix ran these current bytes, whose behavior intentionally remains
the predecessor contract:

| Current artifact | SHA-256 | Static acceptance gap |
|---|---|---|
| `php/scgitransport.php` | `b137068e3ba27a1d6536f3e78268797ed9fda59bec019c2dc7b24f85809b521f` | seven arguments, array return, `MAX_BODY_BYTES`, SimpleXML ownership, whole-request concatenation, lengthless-body acceptance; final nine-argument raw/body API absent |
| `tests/php/SCGITransportFixture.php` | `24a61e99d1832aa06479b5050df987020d7f8968634bfa2bb38aee0c8ad9f0b3` | predecessor fixture |
| `tests/php/SCGITransportTest.php` | `8663480edf406ecb4f13cccd68488f93757f2a9e4fd263f9655eb84d004379e4` | 25 methods pin predecessor behavior |
| `conf/xmlrpc_proxy.php` | `480c8cec35f7ce761ef86cb705a21ffb22a219ba3d64145d8c6ee32cc3dbdab4` | final policy config absent |
| `php/xmlrpc_proxy.php` | `cc146f48331cc6e6128e8a736692d4478629d3ac7e5e6b309c91d429a149a185` | harmless `system.multicall` still forwarded; raw fallbacks remain |
| `tests/php/XMLRPCProxyTest.php` | `ced9f1cc5c11fb7b60a3ada72cf6d8e639429815b6a9b7722ce6be416058c8ce` | 75 predecessor methods |
| `tests/php/XMLRPCProxyContractFixture.php` | `7bd8de953012cd687ccd16b2271f34a8c88cc7516c199381ae85584cc86775cf` | explicitly preserves harmless untrusted `system.multicall` |
| `tests/php/XMLRPCProxyContractTest.php` | `9995973e61804353c97181f8eec24c112d5ed23c7f6cd042e39c6cfa8a1e3028` | 7 predecessor methods |
| `tests/php/XMLRPCProxyRejectionTest.php` | `6380d1b26e57fb03e5fed24540fbb3b49431b9dd1c5dbfdddc59473fbc35d944` | 7 predecessor methods |
| `plugins/httprpc/action.php` | `01c8f91962ebfe0d27fbf28a99875d347c09f98539e5265106e2349bb6b688d1` | old false/empty diagnostic and daemon-down wording |
| `rpc2.php` | `e10434e679646cc5c9265123def69f8a7ba995f8810c4910c4f44a27cdaee3cb` | false/empty conflation and old transport wording |
| `plugins/retrackers/init.php` | `ed496e6d3730e10866a05d0bc20fcfa0c431d576501c0bb567bacce5f9b9d949` | legacy `guard.php`, key1-only hook |
| `plugins/retrackers/done.php` | `f3729eeb94fb509f15823093b0f5be5fd82e0f5e95f8a56f3207b79d10811011` | key1 `cat=` overwrite rather than two-key delete |
| `plugins/retrackers/run.sh` | `2c3bfe6966b8c9b84f4fd694de09703f92521a0c09b0173c94d2f67c1b5a2de4` | predecessor shell wrapper |
| `plugins/retrackers/update.php` | `0b7238ea2b34ae58bbd9d67a26bf4c8766c7d1906d76abf417672e8a95ee92e3` | `RETRACKERS_TEST_MODE`, `file_get_contents`, generic `sendTorrent`, three-field marker; no ledger/pf/pv/V2/final adapter |
| `tests/plugins/retrackers/UpdateTest.php` | `9f170dbc74cf5455515bca86ed92ba2cf40ff9d6c261387cc417b55b0adadc2c` | 42 characterization tests |

`tests/php/XMLRPCProxyEntrypointTest.php` is absent. Current static test counts
remain SCGI 25, proxy 75, proxy contract 7, proxy refusal 7 and retrackers 42.

The exact missing implementation boundaries are:

1. SCGI: final nine-argument string API, classified framing, segmented writes,
   exact `Content-Length` ownership and raw/body consumer split;
2. proxy/refusals: unconditional `system.multicall` rejection, exact evaluator/
   carrier policy, all-or-nothing canonical rebuild, corrected both-door
   refusal/transport control flow and copied-real entrypoint suite;
3. retrackers: no legacy `guard.php` dependency in the five-path package,
   key1/key2 actions, four-field marker, production `rr.receipts.v1`, `pf`/
   `pv`, extended owner/lifecycle callbacks, bounded raw-metainfo/V2 codec and
   final-parent SCGI adapter.

Natural RED, corrected GREEN, mandatory mutations, non-empty exact test-name
sets and real daemon/runtime captures are required later for every package.
They cannot be executed before the successor tests and producers exist.

## Exact old-container pre-cleanup archive

The independent read-only audit recorded the following task-owned containers.
They are old bridge-networked, host-port-published, stateful labs, not isolated
final acceptance labs. The `*-21` labs use writable anonymous volumes and none
has a read-only final-worktree bind. All have `restart=no`.

| Exact name | Full container ID | Exact image ID | State / health | Network / host port |
|---|---|---|---|---|
| `audit-meta-098` | `bf51cc5fb83ecaf5fc24b9a33f650a9b3f8bd72ad40b7dcb772d3ad9c8320ef2` | `sha256:2fce8587d588652af5ee2308243bc0803b8a82ec81bf5377940801a131c283b7` | exited 255 / n/a | bridge / 18198 |
| `audit-meta-21` | `6943e0992a477fdac633a0edb13aee4e411fece32d3cc3d0c2bd4b2c5d257c35` | `sha256:542dc45be35616096b57899a60b36015d4a99bc1a8aa8f3b92b0c338cfeca1f2` | running / healthy | bridge / 18221 |
| `contract-rt098` | `be7f05ae8476455d4124a4387b222014026c8d87c3be842bb9e19df857ed2201` | `sha256:2fce8587d588652af5ee2308243bc0803b8a82ec81bf5377940801a131c283b7` | running / n/a | bridge / 19098 |
| `contract-rt21` | `7b4da94742d1d50b49660dbaa1449415ca340c8634aa82718b074d0e00bcafc8` | `sha256:542dc45be35616096b57899a60b36015d4a99bc1a8aa8f3b92b0c338cfeca1f2` | running / healthy | bridge / 18081 |
| `fence-rt098` | `56555ff6abe77a156abd87de5e4ca7d51af6b59fc1c250102eba295225a5b839` | `sha256:2fce8587d588652af5ee2308243bc0803b8a82ec81bf5377940801a131c283b7` | running / n/a | bridge / 29099 |
| `fence-rt21` | `41e59556c3d564cc65f0c4c35fded788d13c497d840e542611a084e408942d65` | `sha256:542dc45be35616096b57899a60b36015d4a99bc1a8aa8f3b92b0c338cfeca1f2` | running / healthy | bridge / 28081 |
| `proof-stage-098` | `967249108f946c4ee13e4ab217083a6ce36154c954d2089bdf847fce93f2efc7` | `sha256:2fce8587d588652af5ee2308243bc0803b8a82ec81bf5377940801a131c283b7` | running / n/a | bridge / 29500 |
| `proof-stage-21` | `5826d41ea6a9d5eee78dff0f3a9bf211d2b3f5d86817a6fe4f0caecaea3e7383` | `sha256:542dc45be35616096b57899a60b36015d4a99bc1a8aa8f3b92b0c338cfeca1f2` | running / unhealthy | bridge / 29521 |

No container was started, stopped, restarted, removed, repaired or executed
into while authoring the original evidence commit. Cleanup remained pending at
that historical point; the completed outcome below supersedes that old status.

The first cleanup preflight later obtained successful Docker info and a full
container snapshot, then stopped before its first state-changing command when
the old evidence's `contract-rt21` ID failed positive name-to-ID comparison.
Fresh inspection proved the container's real ID has `...c8634aa...`, not the
copied `...c8636aa...`; all seven intended running targets remained running and
the eighth remained exited. `task-3-container-id-correction.md` supersedes only
that one nibble. Stopped/removed counts for that aborted attempt are both zero.

## Frozen exact cleanup rule — executed

Cleanup was allowed to start only after this evidence commit was independently
readable. It first required a successful `docker info`. Immediately before
deletion, re-resolve every exact name and require equality to the full name→ID
table above. Any mismatch aborts cleanup. The mutation is bound to the frozen full
IDs, never to mutable names, so a replacement container cannot become a target
through an ABA rename/recreate window.

Stop only the seven frozen currently-running container IDs:

```sh
docker stop --time 10 \
  6943e0992a477fdac633a0edb13aee4e411fece32d3cc3d0c2bd4b2c5d257c35 \
  be7f05ae8476455d4124a4387b222014026c8d87c3be842bb9e19df857ed2201 \
  7b4da94742d1d50b49660dbaa1449415ca340c8634aa82718b074d0e00bcafc8 \
  56555ff6abe77a156abd87de5e4ca7d51af6b59fc1c250102eba295225a5b839 \
  41e59556c3d564cc65f0c4c35fded788d13c497d840e542611a084e408942d65 \
  967249108f946c4ee13e4ab217083a6ce36154c954d2089bdf847fce93f2efc7 \
  5826d41ea6a9d5eee78dff0f3a9bf211d2b3f5d86817a6fe4f0caecaea3e7383
```

Then remove exactly all eight frozen IDs, without `-v`, wildcard, prefix or
force escalation:

```sh
docker rm \
  bf51cc5fb83ecaf5fc24b9a33f650a9b3f8bd72ad40b7dcb772d3ad9c8320ef2 \
  6943e0992a477fdac633a0edb13aee4e411fece32d3cc3d0c2bd4b2c5d257c35 \
  be7f05ae8476455d4124a4387b222014026c8d87c3be842bb9e19df857ed2201 \
  7b4da94742d1d50b49660dbaa1449415ca340c8634aa82718b074d0e00bcafc8 \
  56555ff6abe77a156abd87de5e4ca7d51af6b59fc1c250102eba295225a5b839 \
  41e59556c3d564cc65f0c4c35fded788d13c497d840e542611a084e408942d65 \
  967249108f946c4ee13e4ab217083a6ce36154c954d2089bdf847fce93f2efc7 \
  5826d41ea6a9d5eee78dff0f3a9bf211d2b3f5d86817a6fe4f0caecaea3e7383
```

Images, anonymous volumes and networks remain untouched. If stop/remove fails,
stop and inspect that exact target; do not broaden authority. The exited
`audit-meta-098` is not stopped, and unhealthy `proof-stage-21` is not repaired
or restarted first.

After removal, require another successful `docker info`, then a successful
full `docker ps -a --no-trunc` snapshot and archive that command's own zero
status. Parse that one snapshot to require all eight frozen IDs absent and all
eight exact names absent. A different ID under any exact name is an out-of-scope
replacement: fail the gate and leave it untouched. Do not interpret generic
nonzero `docker inspect` as absence; daemon, permission and transport failures
are not `not found`. Also require all eight former host ports absent from a
successful `ss -ltnH` snapshot and the repository still clean. Archive every
command, stdout/stderr and exit status.

## Exact cleanup outcome

The corrected execution at repository HEAD `822d15fd048b3127565fcf501f0aba6169524cba`
completed with overall status 0:

```text
pre Docker:  server 29.5.0, containers 15, running 9
pre mapping: 8/8 exact names -> corrected full IDs; 1 exited + 7 running
stop:        7/7 full IDs, status 0; all 8 then positively exited
remove:      8/8 full IDs, status 0; no -v, wildcard, prefix or force
post Docker: server 29.5.0, containers 7, running 2
absence:     8/8 old IDs, 8/8 exact names and 8/8 host ports absent
retained:    2/2 images, 8/8 anonymous volumes, bridge network
repository:  clean porcelain status
```

The successful full post-removal `docker ps -a --no-trunc` snapshot contained
seven unrelated containers and none of the exact names or IDs. A successful
`ss -ltnH` snapshot contained 17 unrelated listeners and none of ports 18198,
18221, 19098, 18081, 29099, 28081, 29500 or 29521. The complete command/output/
status transcript and retained volume IDs are frozen in
`task-3-exact-container-cleanup.md`, SHA-256
`c416448e396b1a96424aa791a5211dcb3cb78b4ec5ae3cd6cd67c9d1b75f1bea`.

Container writable layers were deleted with the container objects and are not
recoverable as those objects. The retained images can create new containers;
all eight anonymous volumes and their volume data remain. No unrelated Docker
object was removed.

## Remaining acceptance gates

The later immutable implementation package must contain, at minimum:

1. SCGI natural RED and corrected GREEN for held-open exact frame, deterministic
   short/zero/false writes, <=65,536-byte slices, stable failures, cap/cap+1,
   raw/body modes, no SimpleXML ownership, PHP 7.4 runtime/cap mutations, TCP
   0.9.8 and UNIX 0.16.21; the combined final-parent/retrackers-consumer gate
   additionally requires literal PHP 7.4 `memory_limit=128M` accepted-body and
   bounded-failure coverage;
2. proxy natural RED and corrected GREEN for all eight dot-loads, six direct
   multicalls, every evaluator/carrier, unconditional `system.multicall`,
   malformed/no-method, both real entry doors, exact trust bit and zero daemon
   bytes for denial;
3. retrackers natural B5 RED followed only after real producer implementation
   by `BOOTSTRAP`, `IDLE_EMPTY_CURRENT`, `IDLE_CURRENT`, `FIRST_INIT_OWNER`,
   `SECOND_INIT_OWNER`, `DONE_OWNER`, `CONTAIN_OWNER`, and `CONTAINED`, on both
   daemon families with two byte-identical reads per state and exact action/
   marker/epoch/token/local-id provenance;
4. isolated slot-4/slot-5 faults, partial/empty rows, family/type/cap/ABA and
   claim/action/lifecycle/containment mutations through the production seam;
5. PHP 7.4/8.1/8.5 lint/runtime, the combined consumer's literal PHP 7.4
   `memory_limit=128M` bounds, full exact test-name/count guards, byte-for-byte
   12-method retrackers predecessor preservation, exact implementation path/
   range/numstat, raw `info`/resume/unknown metainfo preservation and
   independent whole-file review.

Until those producers, APIs, tests and captures exist, the current three
implementation/capture verdicts remain **BLOCKED — implementation does not
exist**. This evidence archive and the completed exact cleanup do not reduce
the overall queue below 18, approve a branch, or convert historical/current
characterization into GREEN.
