# Актуальный план оставшейся upstream-очереди — 2026-08-29

Этот документ заменяет старую очередь 5–11 из
`../2026-08-28-upstream-rebuild/PLAN.md`. Текущая база — `755404f3`; whole-file
copy из fork master запрещён там, где upstream уже менял общий файл.

## Уже готовые handoff

Четыре однокоммитные ветки готовы на current upstream: FileUtil, test harness,
rTorrent 0.16.21 и Kinozal. Их SHA, scope и проверки зафиксированы в
`REVIEW-ready-branches-2026-08-29.md`. Push выполняет только владелец.

## 13 обязательных реализационных пакетов

| # | Пакет | Замороженный scope/оценка | Зависимость | Текущий gate |
|---:|---|---|---|---|
| 1 | `up/php74-torrent-properties` | 3 файла, `+14/-9` | независим | явное одобрение 3-файлового дизайна, затем TDD/mutations |
| 2 | final `up/setsettings-socket-alloc` | 4 файла; до последней правки `+910/-15` | независим | одобрение lock-through-UI-terminal, RED/fix/re-review/rebase |
| 3 | `up/httprpc-refusals` | 5 файлов, около `+190..230/-3` | test-harness как evidence gate | одобрение дизайна, copied-entrypoint RED |
| 4 | `up/scgi-transport` | 7 файлов, около `+850/-45` | после 3 из-за общего `rpc2.php` | design approval; новый bounded accumulator, не fork-copy |
| 5 | `up/retrackers-recovery` | 4 файла, final numstat после реализации | независимо; P3 зависит от него | design approval; daemon confirmation/rollback, invalid guard исключён |
| 6 | `up/erasedata-remove-payload` (A) | 8 production + 2 test paths | после 3 по delivery order; **не зависит от SCGI API** | carve A1/A2, identity owner, no-fd и visible-retention RED |
| 7 | `up/httprpc-erasedata-contract` | 2 пути; production hunk `+6/-13` | после 3 и A | copied real entrypoint; exact force/helper/no-fallback mutations |
| 8 | `up/ratio-erasedata-contract` (B) | 2 пути, `+168/-2` | после A | helper absence и username hardening должны получить RED либо быть исключены |
| 9 | `up/erasedata-obsolete-jobs` (C) | 5 production + 2 test paths; numstat после carve | после A | dormant API approval либо fold только в P0 |
| 10 | P0 `up/rutracker-check-replacement-transaction` | exact hunk scope после prerequisites | после C | generic destructive transaction, ownership/recovery mutations |
| 11 | P1 `up/rutracker-post-api` | exact hunk scope после P0 | после P0 | одобрение P0->P1 split и live-capture/lab evidence |
| 12 | P2 `up/rutracker-meta-history-marker` | 3 history paths + entrypoint evidence | после P1 и event-order capture | только producer-owned marker; dot-label запрещён |
| 13 | P3 `up/rutracker-meta-retrackers-marker` | retrackers marker integration | после P1 и package 5 | real-daemon command-shape test; current guard запрещён |

### Исправленная схема зависимостей

```text
test-harness
  -> httprpc-refusals
       -> scgi-transport
       -> erasedata A
            -> httprpc-erasedata-contract
            -> ratio B
            -> erasedata C -> P0 -> P1 -> P2
retrackers-recovery ---------------------------> P3
                                     P1 -------> P3

php74-torrent-properties   (independent compatibility lane)
setsettings/socket         (independent browser lane)
```

`php/xmlrpc_path.php` не является пакетом/зависимостью. A владеет filesystem
identity в `plugins/erasedata/filesystem.php`; SCGI и A функционально siblings.

## 5 обязательных carve/verdict-аудитов

Они не считаются готовыми PR и не могут hitchhike в P0/P1:

1. residual rTorrent command surface: 4 пути, `+1,355/-4`;
2. XMLRPC proxy-policy follow-up: 9 пересекающихся путей, размер пока нельзя
   использовать как PR estimate;
3. generic `sendTorrent()` dispatch diagnostic: `php/rtorrent.php`, `+17/-0`;
4. rutracker manual entrypoints: 4 пути, `+1,270/-23`;
5. foreign tracker handlers: 9 путей, `+2,008/-409` current snapshot.

Каждый получает independent current-base audit и затем либо scoped package,
либо полноценный no-send/недостижим verdict. До этого буквальный статус —
**13 mandatory implementations + 5 disposition workstreams = 18 open**.

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
3. После httprpc построить SCGI и A как отдельные successor branches; затем
   consumer integration, B и C.
4. Параллельно завершить retrackers recovery и пять disposition-аудитов.
5. После C построить P0, затем P1; только после runtime evidence — P2/P3.
6. Для каждой ветки: named RED, production mutation, focused/full матрицы,
   PHPStan/Jest где применимо, exact scope/diff-check, independent whole-file
   review, task text и отдельный commit. Push не выполнять.
