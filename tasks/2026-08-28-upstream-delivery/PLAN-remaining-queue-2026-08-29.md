# Актуальный план оставшейся upstream-очереди — 2026-08-29

Этот документ заменяет старую очередь 5–11 из
`../2026-08-28-upstream-rebuild/PLAN.md`. Текущая база — `755404f3`; whole-file
copy из fork master запрещён там, где upstream уже менял общий файл.

## Уже готовые handoff

Четыре однокоммитные ветки готовы на current upstream: FileUtil, test harness,
rTorrent 0.16.21 и Kinozal. Их SHA, scope и проверки зафиксированы в
`REVIEW-ready-branches-2026-08-29.md`. Push выполняет только владелец.

## 18 обязательных реализационных пакетов

| # | Пакет | Замороженный scope/оценка | Зависимость | Текущий gate |
|---:|---|---|---|---|
| 1 | `up/php74-torrent-properties` | exact 3 files, `+14/-9` | независим | design independently APPROVED; explicit user approval, затем two-stage RED/TDD/mutations |
| 2 | final `up/setsettings-socket-alloc` | exact 4 paths; current `+910/-15`, final после fix | независим | design APPROVED / branch NOT READY; 4 terminal RED, fix/mutations/review/rebase |
| 3 | `up/httprpc-refusals` | exact 5 paths; final numstat после tests | test-harness как evidence gate | corrected design APPROVED; split false/empty 400, terminal 403/500, exact neutral text, copied-entrypoint RED |
| 4 | `up/scgi-transport` | exact 7 paths, около `+850/-45` | после 3 из-за общего `rpc2.php` | **CHANGES REQUIRED:** response-cap policy, deterministic short-write, trust bit, PHP74 runtime, UNIX socket |
| 5 | `up/retrackers-recovery` | exact 4 paths, final numstat после реализации | независимо; P3 после final P1+5 | **CHANGES REQUIRED:** marker-based bounded confirmation/rollback; false-green stub rewrite; guard исключён |
| 6 | `up/erasedata-remove-payload` (A) | exact 8 production + 2 test paths | после 3 по delivery order; **не зависит от SCGI API** | corrected design independently **APPROVED**; durable generation, pre-erase fixed repeating arm, real child ack, exact batch sets, settle-before-remove, restart rearm |
| 7 | `up/httprpc-erasedata-contract` | 2 пути; production hunk `+6/-13` | после 14 и A | copied real entrypoint; exact force/helper/no-fallback mutations |
| 8 | `up/ratio-erasedata-contract` (B) | exact 2 paths; final numstat после copied-real RED | после final A drain/rearm seam | corrected design independently **APPROVED**; missing-helper no-op/log + Ratio-startup rearm pending A wake; username filter/Ratio force guard исключены |
| 9 | P0+C `up/rutracker-check-replacement-transaction` | exact 20 paths: 11 production + 9 tests | после A | design independently **APPROVED**; C folded, OLD/NEW-aware no-bridge ownership, token/false/null claim gate, pre-erase A drain ack, restart rearm |
| 10 | P1 `up/rutracker-post-api` | exact hunk scope после P0+C | после P0+C | одобрение P0->P1 split и live-capture/lab evidence |
| 11 | P2 `up/rutracker-meta-history-marker` | 3 history paths + entrypoint evidence | после P1 и event-order capture | только producer-owned marker; dot-label запрещён |
| 12 | P3 `up/rutracker-meta-retrackers-marker` | retrackers marker integration | после P1 и package 5 | real-daemon command-shape test; current guard запрещён |
| 13 | `up/rtorrent-alias-surface` | 3 paths; existing-hunk snapshot `+1351/-4` до wording fix | после готового `up/rtorrent-0-16-21` | characterization, natural RED нет; mutation gates обязательны |
| 14 | `up/xmlrpc-proxy-policy` | exact 7 paths; numstat после prerequisite | после 3 | **CHANGES REQUIRED:** full 8-method load matrix, evaluator exact-deny, fail-closed direct/system mixed grammar; сохранить #3209/#3211 |
| 15 | `up/rutracker-manual-entrypoints` | exact 6 focused paths; final numstat после реализации | независим от P0/P1 | collision/short-write/launch/body/worker/UI RED; без crawler/503/raw text |
| 16 | `up/kinozal-checker-resilience` | 2 paths, current snapshot `+260/-146` | после final P1 | endpoint streaks, exact deletion и parsed-object seam |
| 17 | `up/nnmclub-checker-live-contract` | 2 paths, current snapshot `+1142/-231` | после final P1 | captured 67-byte scrape, current-torrent credential, bounded schema |
| 18 | `up/sibling-tracker-verdicts` | 5 paths; current snapshot `+606/-32`, final изменится | после final P1 | safe verdicts плюс AniDUB/Tfile canonical HTTPS/session RED |

### Исправленная схема зависимостей

```text
test-harness
  -> httprpc-refusals
       -> scgi-transport
       -> xmlrpc-proxy-policy
       -> erasedata A
            -> ratio B
            -> combined P0+C -> P1 -> P2
xmlrpc-proxy-policy + erasedata A
       -> httprpc-erasedata-contract
retrackers-recovery ---------------------------> P3
                                     P1 -------> P3
                                     P1 -------> Kinozal checker
                                     P1 -------> NNMClub checker
                                     P1 -------> sibling trackers

php74-torrent-properties   (independent compatibility lane)
setsettings/socket         (independent browser lane)
rutracker-manual-entrypoints (independent manual lane)
rtorrent-0-16-21 ---------> rtorrent-alias-surface
```

`php/xmlrpc_path.php` не является пакетом/зависимостью. A владеет filesystem
identity в `plugins/erasedata/filesystem.php`; SCGI и A функционально siblings.

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

Точная арифметика: `18 - 5 audits + 6 successors - 1 standalone C fold = 18
open`, из них все 18 — конкретные implementation packages, pending audits — 0.
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

1. Получить явное подтверждение уже представленных PHP74, socket и httprpc
   designs; реализовать их RED->GREEN параллельно, где worktree не пересекается.
2. Повторно review socket, rebase на current upstream, затем перенести только
   финальные fork-owned hunks в `master` отдельным commit.
3. После httprpc сначала построить proxy-policy; SCGI и A остаются отдельными
   successor branches. Consumer integration строить только после A и
   proxy-policy; B строить только после A durable-drain/rearm gate, combined
   P0+C — после final A и frozen ownership design.
4. Параллельно строить retrackers recovery, rTorrent alias surface, manual
   entrypoints и proxy-policy после его httprpc prerequisite.
5. После combined P0+C построить P1; от final P1 параллельно строить три foreign
   handler packages, и только после runtime evidence — P2/P3.
6. Для каждой ветки: named RED, production mutation, focused/full матрицы,
   PHPStan/Jest где применимо, exact scope/diff-check, independent whole-file
   review, task text и отдельный commit. Push не выполнять.
