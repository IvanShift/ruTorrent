# Crosswalk оставшегося fork divergence — 2026-08-29

Срез: fork behavior snapshot `511ed13f` плюс локальные docs-only commits;
текущий upstream `755404f3`; последний уже слитый в fork upstream-tip
`fde9863b`.

## Метод и полный объём

Прямой diff `755404f3..master` непригоден: он показывает 12 ещё не слитых
upstream-commit как удаления форка. Чистый fork-intent измерен как
`fde9863b..master`, а пересекающиеся пути затем перепроверены по current-base
carve-аудитам.

Итог: **139 путей, `+48,448/-2,816`**, без неклассифицированного пути на file
level.

| Группа | Пути | Delta от `fde9863b` | Диспозиция |
|---|---:|---:|---|
| `rutracker_check` | 70 | +29,869/-1,655 | P0/P1, P2/P3, manual entrypoints, foreign handlers; dead registry не отправлять |
| erasedata + Ratio | 12 | +11,347/-210 | A/B/C и httprpc consumer contract |
| SCGI/XMLRPC/httprpc/path | 17 | +1,533/-825 | httprpc, SCGI, consumer integration, proxy-policy audit; whole-file copy запрещён |
| fork task/tooling | 12 | +1,568/-0 | не отправлять |
| rTorrent compatibility | 5 | +1,364/-8 | готовая 0.16.21 characterization + residual audit |
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

До полного закрытия divergence остаётся **18 рабочих потоков**:

- 13 обязательных реализационных пакетов из
  `PLAN-remaining-queue-2026-08-29.md`;
- 5 отдельных carve/verdict-аудитов: residual rTorrent command surface,
  proxy policy, generic `sendTorrent()` diagnostic, rutracker manual
  entrypoints, foreign tracker handlers.

Если какой-то из пяти аудитов даст no-send, он закроется вердиктом, а не PR.
Поэтому 18 — число открытых workstream, но не обещание 18 новых upstream PR.

## Ownership corrections

Отдельный `php/xmlrpc_path.php` в critical chain не нужен. Endpoint-local
resolver остаётся в двух proxy doors; filesystem identity принадлежит
`plugins/erasedata/filesystem.php` в A. SCGI и A — siblings после httprpc.

Отдельный `up/httprpc-erasedata-contract` обязателен: прежние планы оставляли
без владельца `removewithdata` branch в `plugins/httprpc/action.php`. Его scope:
production hunk `+6/-13` в этом файле и новый copied-entrypoint test. Он должен
запретить implicit force, missing helper и fallback на plain `d.erase`.

## Current-base shields

- всегда сохранять #3209/#3211 proxy parser tests и #3218 anchored init
  requires;
- не копировать stale retrackers suite: upstream #3212 содержит 12 sequence
  tests, которые fork snapshot визуально удаляет;
- shared paths режутся по hunks после final prerequisite tip;
- exact P0/P1, erasedata A/C, retrackers и пяти pending-аудитов нельзя заявлять
  до carve-а;
- PHP 7.4 defect #3213 не входит в 139 fork paths, но учитывается отдельным
  обязательным compatibility package.
