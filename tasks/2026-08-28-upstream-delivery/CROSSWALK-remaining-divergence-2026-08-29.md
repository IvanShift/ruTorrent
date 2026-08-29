# Crosswalk оставшегося fork divergence — 2026-08-29

Исторический file-level срез: fork behavior snapshot `511ed13f`; текущий
upstream `755404f3`; последний уже слитый в snapshot upstream-tip `fde9863b`.
Current published fork — `24891da9`. Его более поздние 6 production/test paths
не включены в старые delta-числа таблицы: claim/sweep hunks классифицированы в
existing P0, magnet-source/parsed-Torrent hunks — в P1, нового package нет.

## Метод и полный объём

Прямой diff `755404f3..master` непригоден: он показывает 12 ещё не слитых
upstream-commit как удаления форка. Чистый исторический fork-intent измерен как
`fde9863b..511ed13f`, а пересекающиеся пути затем перепроверены по current-base
carve-аудитам.

Итог historical snapshot: **139 путей, `+48,448/-2,816`**, без
неклассифицированного пути на file level. Это не numstat current `24891da9`.

| Группа | Пути | Delta от `fde9863b` | Диспозиция |
|---|---:|---:|---|
| `rutracker_check` | 70 | +29,869/-1,655 | P0/P1, P2/P3, manual entrypoints, foreign handlers; dead registry не отправлять |
| erasedata + Ratio | 12 | +11,347/-210 | A/B, C folded into P0 и httprpc consumer contract |
| SCGI/XMLRPC/httprpc/path | 17 | +1,533/-825 | httprpc, SCGI, consumer integration, 7-path proxy-policy package; whole-file copy запрещён |
| fork task/tooling | 12 | +1,568/-0 | не отправлять |
| rTorrent compatibility | 5 | +1,364/-8 | готовая 0.16.21 characterization + 3-path alias-surface package |
| retrackers | 5 | +1,540/-45 | recovery carve + P3 marker |
| Kinozal/loginmgr | 5 | +636/-28 | готовая локальная ветка/open PR |
| socket/settings | 4 | +450/-22 | отдельная исправляемая ветка; её upstream carve сейчас больше |
| test harness | 3 | +3/-17 | готовая ветка также добавляет новый test-файл |
| history dot-label | 3 | +37/-5 | текущий код отвергнут, заменяется exact-marker P2 |
| cache guard/tests | 2 | +73/-1 | подтверждённый no-op, не отправлять |
| FileUtil evidence в master | 1 | +28/-0 | заменено готовой 7-файловой веткой |

92 fork-only commit — это 74 обычных и 18 merge commit; это не число
оставшихся задач. История содержит уже принятые upstream изменения под другими
SHA, integration merges и последующие remediation.

## Закрыто или готово

- готово локально, не отправлено агентом: FileUtil `79190927`, test harness
  `8eafb529`, rTorrent 0.16.21 `48bc6d4b`, Kinozal `de98a49a`;
- standalone history dot-label branch отвергнута и не отправляется;
- cache `unserialize`, fork tooling, `.gitignore`, `AGENTS.md`, dead run registry,
  Snoopy gzip и старый log-unwritable имеют явный no-send/rejected verdict.

## Открытый счёт

До полного закрытия divergence остаётся **18 implementation packages** из
`PLAN-remaining-queue-2026-08-29.md`; неразобранных carve/verdict-аудитов — 0.
Четыре уже готовые ветки в этот счёт не входят.

Переход от прежних 18 workstream проверен арифметически:
`18 - 5 завершённых audits + 6 successor packages = 19`, затем standalone C
сложен внутрь P0: `19 - 1 = 18`. Residual rTorrent, proxy policy и manual
entrypoints дали по одному package; foreign bucket — три; generic
`sendTorrent() +17/-0` закрыт no-send. Evidence:
`REVIEW-disposition-wave-2026-08-29.md` и
`REVIEW-erasedata-obsolete-jobs-2026-08-29.md`.

## Ownership corrections

Отдельный `php/xmlrpc_path.php` в critical chain не нужен. Endpoint-local
resolver остаётся в двух proxy doors; filesystem identity принадлежит
`plugins/erasedata/filesystem.php` в A. SCGI и A — siblings после httprpc.

Отдельный `up/httprpc-erasedata-contract` обязателен: прежние планы оставляли
без владельца `removewithdata` branch в `plugins/httprpc/action.php`. Его scope:
production hunk `+6/-13` в этом файле и новый copied-entrypoint test. Он должен
запретить implicit force, missing helper и fallback на plain `d.erase`.
Поскольку proxy-policy владеет соседними hunks того же production file,
consumer строится после **обоих** `up/xmlrpc-proxy-policy` и erasedata A, а не
параллельно с proxy package.

## Current-base shields

- всегда сохранять #3209/#3211 proxy parser tests и #3218 anchored init
  requires;
- не копировать stale retrackers suite: upstream #3212 содержит 12 sequence
  tests, которые fork snapshot визуально удаляет;
- shared paths режутся по hunks после final prerequisite tip;
- exact P0/P1, erasedata A/C, retrackers и новые manual/foreign packages нельзя
  заявлять до RED-first carve-а на их final prerequisite tips;
- PHP 7.4 defect #3213 не входит в 139 fork paths, но учитывается отдельным
  обязательным compatibility package.
