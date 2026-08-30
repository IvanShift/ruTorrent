# Актуальный план оставшейся upstream-очереди — 2026-08-29

Этот документ заменяет старую очередь 5–11 из
`../2026-08-28-upstream-rebuild/PLAN.md`. Текущая upstream-база — `f19c9d86`;
historical estimates ниже сохраняют собственные refs. Whole-file copy из fork
master запрещён там, где upstream уже менял общий файл.

## Post-sync execution checkpoint — 2026-08-30

Research/contracts branch синхронизирована с
`upstream/master=529033335e66e1acd4084b73030f5880035ce1c0` merge-коммитом
`4b3cd79925e7b73ea25feb1658a34e6b698c9855`; backup pre-sync tip —
`backup/retrackers-contract-pre-upstream-20260830` на `329bcc8f`. Шесть
approved contracts повторно проверены, их scopes/dependencies не изменились и
ни одна строка таблицы ниже не закрыта.

Этот merge остаётся task-branch checkpoint. Он не fast-forward-ится в
локальный `master`, потому что exact upstream `Torrent.php` нарушает
документированный PHP 7.4 floor native типом `mixed`. Исправление уже является
package 1 и требует отдельной implementation authority; оно не подмешивается в
контрактный commit. Следующая допустимая кодовая работа начинается с package 1
RED/TDD либо другого явно выбранного пакета по его dependency gate. Push не
разрешён.

## Implementation progress checkpoint — 2026-08-30

Packages 1 и 2 реализованы отдельными approved ветками:

- package 1: historical `up/php74-torrent-properties` = `286dd24b`, exact 3
  paths `+14/-9`, локальная fork integration `acbf5691`, принят upstream как
  #3224; follow-up #3225 также принят;
- package 2: `up/setsettings-socket-alloc` = `d548016b`, direct parent current
  upstream `f19c9d86`, exact 4 paths `+1229/-19`; локальная fork integration
  `f547b2f3` после prerequisite sync `ed71bee5`.

Обе package-ветки прошли independent whole-branch review. PHP74 принят upstream;
socket branch не push-илась. Accepted #3224/#3225/#3226 refresh зафиксирован в
fork как `7a78c606`. Текущая очередь: **16 open implementations / 0 audits / 5
ready or locally integrated handoffs + 1 accepted upstream closure**.
Evidence package 2:
`VERIFICATION-setsettings-socket-alloc-2026-08-30.md`.

## Уже готовые handoff

Пять веток готовы или уже локально интегрированы: FileUtil, test harness,
rTorrent 0.16.21, Kinozal и setsettings/socket. PHP74 уже принят upstream. Их
SHA, scope и проверки зафиксированы в `REVIEW-ready-branches-2026-08-29.md`.
Push выполняет только владелец.

## Реестр 18 пакетов — 16 ещё открыты

| # | Пакет | Замороженный scope/оценка | Зависимость | Текущий gate |
|---:|---|---|---|---|
| 1 | `up/php74-torrent-properties` | exact 3 files, `+14/-9` | независим | **CLOSED / UPSTREAM ACCEPTED #3224**: historical candidate `286dd24b`, parent `eeae9f3a`; integrated `acbf5691`; #3225 follow-up included in `7a78c606` |
| 2 | `up/setsettings-socket-alloc` | exact 4 paths, `+1229/-19` | независим | **CLOSED / APPROVED**: `d548016b`, direct parent `f19c9d86`; integrated `f547b2f3`; no push |
| 3 | `up/httprpc-refusals` | exact 5 paths; final numstat после tests | test-harness как evidence gate | corrected design APPROVED; split false/empty 400, terminal 403/500, exact neutral text, copied-entrypoint RED |
| 4 | `up/scgi-transport` | exact 7 paths; historical donor estimate около `+850/-45`, final numstat only from final httprpc tip | после 3 из-за общего `rpc2.php` | DESIGN APPROVED — implementation pending; nine-arg transport, deterministic short-write/framing, trust, PHP74, TCP/UNIX and 64/100 MiB gates |
| 5 | `up/retrackers-recovery` | exact 5 paths; final numstat after RED/implementation | после final 4; P3 после final P1 + 5 | DESIGN APPROVED — implementation pending; guard excluded; B5+EPOCH production capture BLOCKED until real five-path producers, without blocking design approval |
| 6 | `up/erasedata-remove-payload` (A) | exact 8 production + 2 test paths | после 3 по delivery order; не зависит от SCGI API | DESIGN APPROVED — implementation pending; durable generation, fixed repeating pre-erase arm, real child ack, exact batch sets, settle-before-remove and restart rearm |
| 7 | `up/httprpc-erasedata-contract` | 2 пути; production hunk `+6/-13` | после 14 и A | copied real entrypoint; exact force/helper/no-fallback mutations |
| 8 | `up/ratio-erasedata-contract` (B) | exact 2 paths; final numstat после copied-real RED | после final A drain/rearm seam | corrected design independently **APPROVED**; missing-helper no-op/log + Ratio-startup rearm pending A wake; username filter/Ratio force guard исключены |
| 9 | P0+C `up/rutracker-check-replacement-transaction` | exact 20 paths: 11 production + 9 tests | после A | design independently **APPROVED**; C folded, OLD/NEW-aware no-bridge ownership, token/false/null claim gate, pre-erase A drain ack, restart rearm |
| 10 | P1 `up/rutracker-post-api` | exact hunk scope после P0+C | после P0+C | одобрение P0->P1 split и live-capture/lab evidence |
| 11 | P2 `up/rutracker-meta-history-marker` | 3 history paths + entrypoint evidence | после P1 и event-order capture | только producer-owned marker; dot-label запрещён |
| 12 | P3 `up/rutracker-meta-retrackers-marker` | retrackers marker integration | после final P1 и final package 5 | real-daemon command-shape test; current guard запрещён |
| 13 | `up/rtorrent-alias-surface` | 3 paths; existing-hunk snapshot `+1351/-4` до wording fix | после готового `up/rtorrent-0-16-21` | characterization, natural RED нет; mutation gates обязательны |
| 14 | `up/xmlrpc-proxy-policy` | exact 7 paths; final numstat only from final httprpc tip | после 3 | DESIGN APPROVED — implementation pending; eight loads, exact evaluator/carrier deny, six direct-multicall all-or-nothing rebuild, system.multicall refusal, preserve #3209/#3211 |
| 15 | `up/rutracker-manual-entrypoints` | exact 6 focused paths; final numstat после реализации | независим от P0/P1 | collision/short-write/launch/body/worker/UI RED; без crawler/503/raw text |
| 16 | `up/kinozal-checker-resilience` | 2 paths, current snapshot `+260/-146` | после final P1 | endpoint streaks, exact deletion и parsed-object seam |
| 17 | `up/nnmclub-checker-live-contract` | 2 paths, current snapshot `+1142/-231` | после final P1 | captured 67-byte scrape, current-torrent credential, bounded schema |
| 18 | `up/sibling-tracker-verdicts` | 5 paths; current snapshot `+606/-32`, final изменится | после final P1 | safe verdicts плюс AniDUB/Tfile canonical HTTPS/session RED |

### Исправленная схема зависимостей

```text
test-harness
  -> httprpc-refusals
       -> scgi-transport
            -> retrackers-recovery ---------------------> P3
       -> xmlrpc-proxy-policy
       -> erasedata A
            -> ratio B
            -> combined P0+C -> P1 -> P2
xmlrpc-proxy-policy + erasedata A
       -> httprpc-erasedata-contract
                                      P1 ---------------> P3
                                      P1 ---------------> Kinozal checker
                                      P1 ---------------> NNMClub checker
                                      P1 ---------------> sibling trackers

php74-torrent-properties   (independent compatibility lane)
setsettings/socket         (independent browser lane)
rutracker-manual-entrypoints (independent manual lane)
rtorrent-0-16-21 ---------> rtorrent-alias-surface
```

`php/xmlrpc_path.php` не является пакетом/зависимостью. A владеет filesystem
identity в `plugins/erasedata/filesystem.php`; SCGI и A функционально siblings.
Retrackers recovery имеет final SCGI как immediate implementation parent; P3
ждёт both final retrackers and final P1.

## Пять disposition-аудитов завершены

Неразобранных carve/verdict workstream больше нет:

1. residual rTorrent surface стал package 13; production incompatibility не
   найдена, три `-D`-only/no-sender alias target недостижимы в stock production;
2. proxy policy сужен до package 14, а `if`, shared resolver и parser rewrite
   получили no-send/refuted verdict;
3. generic `sendTorrent() +17/-0` опровергнут как diagnostic и закрыт no-send;
4. manual entrypoints пересобраны как package 15 вместо старого mixed 4-path
   snapshot;
5. foreign handlers разделены на packages 16–18; независимая HTTPS/session
   поправка включена в те же пять sibling paths.

Историческая арифметика реестра: `18 - 5 audits + 6 successors - 1 standalone
C fold = 18 packages`. После closure packages 1 и 2: `18 - 2 = 16 open`;
pending audits — 0.
Standalone C удалён из счёта, потому что без P0 он production-unreachable и
оставляет active inline cleanup; доказательства:
`REVIEW-disposition-wave-2026-08-29.md` и
`REVIEW-erasedata-obsolete-jobs-2026-08-29.md`.

## Уже принятые design decisions

- no-proc erasedata force-2: попробовать identity-validated `/proc/self/fd`,
  затем `/dev/fd`; иначе видимо отклонить только этот hash до erase, не
  деградировать в force-1;
- retained v1/v2 jobs: хранить exact manifest, retry каждый pass, один
  classified summary на physical job/invocation без raw path/RPC/payload;
- каждый remove-payload producer до erase durably advances generation, arms
  fixed repeating drain и ждёт real PHP child ack; один NB worker owner
  blocking сериализуется на scheduler/hash locks, periodic pass остаётся NB,
  no-ack сохраняет torrent и откатывает только own prepared staging;
- P0/P1 не копируют 70-путевый fork snapshot; current #3218 anchored init
  requires и #3212 retracker sequence suite сохраняются;
- любые mutating replacement/erase/load probes идут только в disposable
  `tasks/rt-lab.sh`; live endpoint допускает только read-only captures без
  раскрытия passkey.

## Рабочий порядок

1. ~~Реализовать package 1 на exact final predecessor.~~ Завершено:
   `286dd24b`, fork integration `acbf5691`, upstream #3224; follow-up #3225.
2. ~~Повторно review socket, rebase на current upstream и перенести финальные
   fork-owned hunks в `master`.~~ Завершено: final `d548016b` на `f19c9d86`;
   prerequisite sync `ed71bee5`; package integration `f547b2f3`; accepted
   upstream refresh `7a78c606`.
3. After final httprpc, build SCGI, XMLRPC policy and A as separate sibling
   branches. Build the consumer only after XMLRPC + A; B and P0+C only after A.
4. Build retrackers only after final SCGI. Alias surface and manual entrypoints
   remain independent; after final P1 build foreign handlers, then P2/P3 under
   their exact dual prerequisites.
5. После combined P0+C построить P1; от final P1 параллельно строить три foreign
   handler packages, и только после runtime evidence — P2/P3.
6. Для каждой ветки: named RED, production mutation, focused/full матрицы,
   PHPStan/Jest где применимо, exact scope/diff-check, independent whole-file
   review, task text и отдельный commit. Push не выполнять.
