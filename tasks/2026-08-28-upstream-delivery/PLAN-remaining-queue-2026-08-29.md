# Актуальный план оставшейся upstream-очереди — 2026-08-29

Этот документ заменяет старую очередь 5–11 из
`../2026-08-28-upstream-rebuild/PLAN.md`. Текущая база — `755404f3`; whole-file
copy из fork master запрещён там, где upstream уже менял общий файл.

## Уже готовые handoff

Четыре однокоммитные ветки готовы на current upstream: FileUtil, test harness,
rTorrent 0.16.21 и Kinozal. Их SHA, scope и проверки зафиксированы в
`REVIEW-ready-branches-2026-08-29.md`. Push выполняет только владелец.

## 19 обязательных реализационных пакетов

| # | Пакет | Замороженный scope/оценка | Зависимость | Текущий gate |
|---:|---|---|---|---|
| 1 | `up/php74-torrent-properties` | 3 файла, `+14/-9` | независим | явное одобрение 3-файлового дизайна, затем TDD/mutations |
| 2 | final `up/setsettings-socket-alloc` | exact 4 paths; current `+910/-15`, final после fix | независим | design APPROVED / branch NOT READY; 4 terminal RED, fix/mutations/review/rebase |
| 3 | `up/httprpc-refusals` | 5 файлов, около `+190..230/-3` | test-harness как evidence gate | одобрение дизайна; copied-entrypoint RED; neutral transport-failure wording |
| 4 | `up/scgi-transport` | exact 7 paths, около `+850/-45` | после 3 из-за общего `rpc2.php` | **CHANGES REQUIRED:** response-cap policy, deterministic short-write, trust bit, PHP74 runtime, UNIX socket |
| 5 | `up/retrackers-recovery` | exact 4 paths, final numstat после реализации | независимо; P3 после final P1+5 | **CHANGES REQUIRED:** marker-based bounded confirmation/rollback; false-green stub rewrite; guard исключён |
| 6 | `up/erasedata-remove-payload` (A) | exact 8 production + 2 test paths | после 3 по delivery order; **не зависит от SCGI API** | design APPROVED; one cohesive A, либо stacked A1 reader → A2 producer; identity/no-fd/retained RED |
| 7 | `up/httprpc-erasedata-contract` | 2 пути; production hunk `+6/-13` | после 15 и A | copied real entrypoint; exact force/helper/no-fallback mutations |
| 8 | `up/ratio-erasedata-contract` (B) | 2 пути, `+168/-2` | после A | helper absence и username hardening должны получить RED либо быть исключены |
| 9 | `up/erasedata-obsolete-jobs` (C) | 5 production + 2 test paths; numstat после carve | после A | dormant API approval либо fold только в P0 |
| 10 | P0 `up/rutracker-check-replacement-transaction` | exact hunk scope после prerequisites | после C | generic destructive transaction, ownership/recovery mutations |
| 11 | P1 `up/rutracker-post-api` | exact hunk scope после P0 | после P0 | одобрение P0->P1 split и live-capture/lab evidence |
| 12 | P2 `up/rutracker-meta-history-marker` | 3 history paths + entrypoint evidence | после P1 и event-order capture | только producer-owned marker; dot-label запрещён |
| 13 | P3 `up/rutracker-meta-retrackers-marker` | retrackers marker integration | после P1 и package 5 | real-daemon command-shape test; current guard запрещён |
| 14 | `up/rtorrent-alias-surface` | 3 paths; existing-hunk snapshot `+1351/-4` до wording fix | после готового `up/rtorrent-0-16-21` | characterization, natural RED нет; mutation gates обязательны |
| 15 | `up/xmlrpc-proxy-policy` | exact 7 paths; numstat после prerequisite | после 3 | common policy/root/local warning/`branch`/mixed diagnostic; сохранить #3209/#3211 |
| 16 | `up/rutracker-manual-entrypoints` | exact 6 focused paths; final numstat после реализации | независим от P0/P1 | collision/short-write/launch/body/worker/UI RED; без crawler/503/raw text |
| 17 | `up/kinozal-checker-resilience` | 2 paths, current snapshot `+260/-146` | после final P1 | endpoint streaks, exact deletion и parsed-object seam |
| 18 | `up/nnmclub-checker-live-contract` | 2 paths, current snapshot `+1142/-231` | после final P1 | captured 67-byte scrape, current-torrent credential, bounded schema |
| 19 | `up/sibling-tracker-verdicts` | 5 paths; current snapshot `+606/-32`, final изменится | после final P1 | safe verdicts плюс AniDUB/Tfile canonical HTTPS/session RED |

### Исправленная схема зависимостей

```text
test-harness
  -> httprpc-refusals
       -> scgi-transport
       -> xmlrpc-proxy-policy
       -> erasedata A
            -> ratio B
            -> erasedata C -> P0 -> P1 -> P2
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

1. residual rTorrent surface стал package 14; production incompatibility не
   найдена, три `-D`-only/no-sender alias target недостижимы в stock production;
2. proxy policy сужен до package 15, а `if`, shared resolver и parser rewrite
   получили no-send/refuted verdict;
3. generic `sendTorrent() +17/-0` опровергнут как diagnostic и закрыт no-send;
4. manual entrypoints пересобраны как package 16 вместо старого mixed 4-path
   snapshot;
5. foreign handlers разделены на packages 17–19; независимая HTTPS/session
   поправка включена в те же пять sibling paths.

Точная арифметика: `18 - 5 audits + 6 successors = 19 open`, из них все 19 —
конкретные implementation packages, pending audits — 0. Доказательства:
`REVIEW-disposition-wave-2026-08-29.md`.

## Уже принятые design decisions

- no-proc erasedata force-2: попробовать identity-validated `/proc/self/fd`,
  затем `/dev/fd`; иначе видимо отклонить только этот hash до erase, не
  деградировать в force-1;
- retained v1/v2 jobs: хранить exact manifest, retry каждый pass, один
  classified summary на physical job/invocation без raw path/RPC/payload;
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
   proxy-policy; B/C зависят от A по таблице.
4. Параллельно строить retrackers recovery, rTorrent alias surface, manual
   entrypoints и proxy-policy после его httprpc prerequisite.
5. После C построить P0, затем P1; от final P1 параллельно строить три foreign
   handler packages, и только после runtime evidence — P2/P3.
6. Для каждой ветки: named RED, production mutation, focused/full матрицы,
   PHPStan/Jest где применимо, exact scope/diff-check, independent whole-file
   review, task text и отдельный commit. Push не выполнять.
