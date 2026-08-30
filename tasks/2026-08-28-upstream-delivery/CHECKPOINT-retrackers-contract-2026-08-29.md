# Checkpoint контракта `up/retrackers-recovery` — 2026-08-30

## Статус остановки

Работа остановлена по прямому указанию пользователя после завершения уже
запущенных round-4 reviewers. Новые agents, исправительный цикл, approval,
control-doc updates, upstream sync и implementation не запускались.

Точный immutable candidate:

```text
branch     codex/retrackers-contract-finish
HEAD       391dc531b58fe2b94f76ca5ac171e67d6c12ffcf
parent     b36af579048c5442681b5426b36fdf1ad73d6149
commit     Freeze retrackers profile binding contract
path       tasks/2026-08-28-upstream-delivery/REVIEW-retrackers-recovery-2026-08-29.md
lines      2715
sha256     f651b8ddc6875fab3ff505dcf5cfdcd86351c6ed4cd1f47199124dcfa288055b
scope      one tracked Markdown, +396/-276
status     CANDIDATE / NOT APPROVED
```

До checkpoint commit tracked/index tree был clean. `git diff --check
HEAD^..HEAD` прошёл. От main checkpoint `5da21546` ветка содержит четыре exact
candidate commits:

```text
2cd01051 Close retrackers contract blockers
7c4eeea2 Freeze retrackers array response schemas
b36af579 Complete retrackers historical scan contract
391dc531 Freeze retrackers profile binding contract
```

Main repository до интеграции остаётся на
`master=origin/master=5da21546f41304bb1cef91f8664b6316d2876cbf`.
Локальный ещё не обновлённый `upstream/master` —
`755404f3e38af98b6901852b35be10fb9659ffd3`; fresh fetch/merge не выполнялся.
Unrelated main-worktree `rutorrent-app-errors.log` не читался и не стейджился.
Push не выполнялся.

Все **18 implementation packages** остаются открытыми. В этом проходе менялись
только task Markdown и ignored evidence; PHP, shell, tests и production behavior
не менялись.

## Что перепроверено независимо

### rTorrent source и wire boundary

Проверены exact source owners:

```text
rTorrent 0.9.8   6154d1698756e0c4842b1c13a0e56db93f1aa947
rTorrent 0.16.21 109a20c09c3cab9eb13c2d96ea79362ac6c318fc
xmlrpc-c          1.33.14
```

Source audit подтвердил отсутствие `method.get_key`, direct ownership
`method.list_keys`/`method.get`, heterogeneous recursive event-map values и
direct width-four `d.multicall2` recovery rows. Поэтому исходники rTorrent
были обязательной частью исследования: presence-only tails и caller allow-list
не доказывают action bytes.

Финальный выбранный design — replacement-five
`HistoricalBindingSampleV2` + persistent epoch:

```text
1 event.download.inserted_new method.list_keys
2 event.download.inserted_new method.get
3 RecoveryRows4 direct d.multicall2
4 rr.receipts.v1 method.list_keys
5 rr.receipts.v1 method.get
```

Два complete samples идут back-to-back. Same-sample `pf:<userHash>:<actionHash>`
bind-ит action, `pv:<epoch>` закрывает ABA/post-sample callback race, а один
`to:<token>:(i|d|c):<userHash>` bind-ит lifecycle owner. Fresh full-service и
exclusive-current-writer остаются обязательными prerequisites; stable
self-consistent trusted forgery явно находится вне гарантии.

### Честная контейнерная граница

Свежий exact 1,820-byte B5 request имеет SHA-256
`ae96a2e5264798d84e4a35e981bbe99d8337820a93a07ff989e480b329b44210`.
На fresh `--network none` 0.9.8 и 0.16.21 два reads были byte-identical:

```text
0.9.8   BODY 1563  505031b6aa974f339343ab70778eb4430fb9d0e4eed702ecdb6fec32baed04ac
0.16.21 BODY 3412  f32032540fe36d6a0b5e6a024174da67f6d25d31742b16a159b3e8307b45f927
```

Slots 1-3 success, slots 4-5 natural missing-ledger faults. Ни BOOTSTRAP, ни
остальные семь V2 phases не фабриковались direct setters. Текущий tracked code
не имеет production producers для `rr.receipts.v1`, `pf/pv`, extended owner,
paired safety/defer actions, four-field marker или V2 consumer. Поэтому verdict
**BLOCKED относится только к post-implementation capture acceptance**, а не к
design/contract approval.

Основные ignored evidence:

```text
task-1-historical-source-audit.md      17f24297387bf45334da4ef942388ed53b896a202e90c7a26dc3c2a05d003674
task-1-historical-scan-capture.md      322a42c88ae58024f250c8e1d2ae2a6835b9f1e29106f0b76dc266f8fb1d6bf6
task-1-historical-combined-capture.md  8a413aa5457c6f086caf24ac98745e3544776d41245c4e6ee75a3053a3bfa0a4
task-1-historical-codec-design.md      749c90118535ad022565de6fbeff59e0a4c39df05de0effceee7ac41a67187f9
task-1-profile-binding-adversarial.md  54135228d6917786f5fba6993d26fc6ef5c44a0d93d211f82f416aaba752e88e
task-1-profile-binding-design.md        c9aec09b80a55f50fde621128a46115c8dd3e263b1d6571ae4f6960be1e9c1eb
task-1-b5-epoch-capture.md              9aa5dbe0f259acdc9f50712b3566b8bb5c89d3d9d6a063ae86990d132d0ed136
task-1-b5-capture-preflight.md          eaba086a2e5ee240bdb9e720572946e574d787fc1c59961e05322d917b497709
task-1-fix-round3-report.md             b835aa30c0c109686898794b74fc46336eb423dae4eef958a084aaa527a812e1
```

## Round-4 independent review verdicts

Оба reviewers полностью прочитали один exact HEAD/candidate и независимо дали
`CHANGES REQUIRED`. Approval запрещён.

### SPEC — один P1

Report `task-1-review-spec-round4.md`, SHA-256
`37b890b82534929ec94658b199aefb4bc9eda74b9857fae16cadd3e4d20c8f37`.

Candidate lines 951-986 называют typed-hash domains, но не определяют exact
child streams. Затем `HistoricalBindingDigestV2` использует неопределённые
`EventKeyDigestV1`, `EventMapDigestV1` и `RecoveryRowsDigestV1`. Producer и test
могут использовать один ошибочный helper и дать false GREEN без независимого
known-answer vector.

### ADVERSARIAL — тот же P1 и один P2

Report `task-1-review-adversarial-round4.md`, SHA-256
`82e495305b801136ab356593ee0baa8fd72241553ffffd106552b160621e398d`.

P1 дополнительно требует определить `raw32(x)` как raw 32 digest bytes и
`FamilyTag` как ровно один raw byte `0x01|0x02`.

P2: candidate lines 302-313 корректно допускают только observable check
`newPv != sampled current oldPv`, а reuse более раннего уже отсутствующего epoch
исключают CSPRNG assumption. Но RED/mutation wording около 2272-2274,
2472-2481 и 2609-2613 говорит просто `reused/non-reverting pv`, что может
потребовать несуществующий history store или ложное обещание detection.

Других P0/P1/P2 reviewers не нашли: replacement-five ordering/wrappers,
fault consumption, V1 RED, profile binding, two-profile containment, done,
exclusive-writer boundary, callback prefixes, bounds, approval split, exact
five paths, raw metainfo, 12-test predecessor и 18-packages-pending boundary
сочтены coherent.

## Точный минимальный fix на продолжение

Тот же sole editor должен менять ровно candidate Markdown и сохранить
`CANDIDATE / NOT APPROVED`.

1. Вернуть self-contained formulas:

```text
StringDigest = SHA256("HS1-S\0" || U32(len(bytes)) || bytes)
I4Digest     = SHA256("HS1-4\0" || U32(len(lexeme)) || lexeme)
I8Digest     = SHA256("HS1-8\0" || U32(len(lexeme)) || lexeme)
ArrayDigest  = SHA256("HS1-A\0" || U32(child-count) ||
                      SHA256("HS1-AC\0" || raw32(child-digest)...))
StructDigest = SHA256("HS1-O\0" || U32(member-count) ||
                      SHA256("HS1-OC\0" ||
                        (U32(len(name)) || name || raw32(child-digest))...
                        sorted by full decoded name bytes))
EventKeyDigestV1 = SHA256("HS1-K\0" || U32(key-count) ||
                          (U32(len(name)) || name)... sorted)
EventMapDigestV1 = StructDigest(complete EventActionMap)
RecoveryRowDigestV1 = SHA256("HS1-R\0" || U32(len(hash)) || hash ||
  U32(len(local-id)) || local-id || U32(len(marker)) || marker ||
  U32(len(ack)) || ack)
RecoveryRowsDigestV1 = SHA256("HS1-RS\0" || U32(row-count) ||
                              raw32(row-digest)... sorted by (hash,local-id))
```

`U32` — four-byte big-endian. `raw32(x)` — exact raw 32 digest bytes, never
hex text. `FamilyTag` — one raw byte `0x01` or `0x02`.

2. Добавить independent empty semantic known-answer vectors. Controller
пересчитал их отдельно Python `hashlib` и PHP `hash(..., true)`; sole editor и
reviewers обязаны перепроверить, а не принимать как authority:

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

Это pure codec semantic vector, не production BODY/BOOTSTRAP capture.

3. Везде заменить ambiguous `reused pv` на exact rejection
`newPv == sampled current oldPv`. ABA mutation использует три explicit distinct
epochs и должна падать при omitted mandatory rotation. Рядом явно сказать:
reuse любого более раннего уже absent epoch не observable и исключается только
frozen negligible 128-bit CSPRNG assumption; history store не существует.
`non-reverting` qualification должна означать protocol never deliberately
restores the sampled current epoch, а не детектирование всей history.

4. Commit остаётся CANDIDATE. Затем оба тех же reviewers заново полностью
читают новый exact SHA. Только два `CLEAN` разрешают отдельный approval commit.

## После двух CLEAN, но не сейчас

1. Отдельный `DESIGN APPROVED — implementation pending` commit.
2. Сводная контейнерная verification и отдельный control-doc commit.
3. Интеграция isolated branch в main.
4. Только затем fresh fetch/merge upstream и новый delta-аудит всех затронутых
   контрактов/README/PLAN/CROSSWALK.
5. Остановиться без push и без 18 implementation packages, если пользователь
   не даст новое явное указание.

Подготовленные ignored planning reports:

```text
task-3-container-verification-inventory.md c4103a504cb10bc3e3ed7535ad38a152b4278317fb2099d80a13a0ccf60f00a3
task-3-control-update-map.md               f85f14241294634f4f55dbcee5762f38a48583116e18b8b58c61c3fe36c50957
```

Они не являются approval и применяются только после фактических two-CLEAN и
approval commit. README/PLAN/CROSSWALK пока намеренно остаются stale.

## Disposable container checkpoint

Старые task-owned containers не удалялись в этом проходе:

```text
audit-meta-098
audit-meta-21
contract-rt098
contract-rt21
fence-rt098
fence-rt21
proof-stage-098
proof-stage-21
```

Fresh `b5epoch-cap-098-20260830-c1` и
`b5epoch-cap-1621-20260830-c1` были удалены и proven absent своим capture
report. При будущей сводной verification старые exact task-owned names нужно
сначала инвентаризировать, затем после сохранения evidence удалить только их и
доказать absence; unrelated containers не трогать.
