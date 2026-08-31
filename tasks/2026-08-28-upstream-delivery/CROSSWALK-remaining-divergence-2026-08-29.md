# Crosswalk оставшегося fork divergence — 2026-08-29

Текущие refs, package gates и upstream PR находятся в
`STATUS-18-PACKAGES-2026-08-31.md`; этот файл сохраняет историческую
file-level декомпозицию и ownership границы.

Исторический file-level срез: fork behavior snapshot `511ed13f`; текущий
upstream `755404f3`; последний уже слитый в snapshot upstream-tip `fde9863b`.
Current published fork — `24891da9`. Его более поздние 6 production/test paths
не включены в старые delta-числа таблицы: claim/sweep hunks классифицированы в
existing P0, magnet-source/parsed-Torrent hunks — в P1, нового package нет.

## Post-sync ref boundary — 2026-08-30

Historical divergence arithmetic ниже остаётся frozen на своих refs. Отдельная
contract task branch теперь содержит merge `4b3cd799` с
`upstream/master=52903333`; exact `755404f3..52903333` добавляет только #3220
и #3202 в трёх package-lock/filedrop paths и не меняет ownership ни одного из
18 packages. Шесть approved contracts получили post-sync appendices; полный
evidence — `VERIFICATION-upstream-sync-contracts-2026-08-30.md`.

Локальный/published `master` остаётся `5da21546`: merged upstream
`php/Torrent.php` production-reachable и parse-fails на обещанном PHP 7.4 из-за
native `mixed`. Это уже учтённый package 1, а не новая строка crosswalk.
Следовательно, task merge не является master-ready до реализации package 1
или явного решения владельца изменить runtime floor; никакой push не выполнен.

## Implementation closure — 2026-08-30

Предыдущая граница описывает исторический contract checkpoint. Packages 1 и 2
после отдельной implementation authority закрыты:

- PHP74: historical `up/php74-torrent-properties` = `286dd24b`, exact `+14/-9`
  в трёх paths, integrated fork commit `acbf5691`, принят upstream как #3224;
- socket/settings: `up/setsettings-socket-alloc` = `d548016b`, exact
  `+1229/-19` в четырёх paths, direct parent current upstream `f19c9d86`,
  integrated fork commit `f547b2f3` после exact sync `ed71bee5`.

Current upstream `f19c9d86` подтверждён `git ls-remote`; #3224/#3225/#3226
refresh integrated as `7a78c606`. Package and integration reviews are APPROVED;
no push/deploy was performed for socket. Current count: **16 implementations /
0 audits / 5 ready or locally integrated handoffs + 1 accepted closure**.

## Implementation closure — 2026-08-31

Package 3 `up/httprpc-refusals` закрыт на exact current predecessor:

- clean branch `c7a431aa`, direct parent `f19c9d86`, one commit, exact 5 paths
  `+437/-14`;
- fork integration `48825583`, exact 4-path delta поверх `d553bd47`; existing
  richer `php/xmlrpc_proxy.php` behavior сохранён без redundant net change;
- candidate и фактическая integration независимо **APPROVED**, PHP
  7.4/8.1/8.5 focused matrix, PHPStan и full Jest GREEN; broad PHP RED
  base-equal и относится к отдельному `rRetrackers` test bootstrap.

Evidence: `VERIFICATION-httprpc-refusals-2026-08-31.md`. Package 4 SCGI также
закрыт: runtime `4682a761`, test-only delivery head `33934444`, direct parent
`c7a431aa`, three linear commits, exact 7 paths `+1584/-51`; fork integration
`19086b5f` + separate test adaptation `3ff4860c`. Reviews, recorded full and
stable focused PHP 7.4/8.1/8.5,
PHPStan and real 0.9.8/0.16.21 UNIX probes are GREEN. Evidence:
`VERIFICATION-scgi-transport-2026-08-31.md`.
Current count: **14 implementations / 0 audits / 7 ready or locally
integrated handoffs + 1 accepted closure**. Exact httprpc branch опубликована
владельцем как `origin/up/httprpc-refusals=c7a431aa`; SCGI branch, fork
master и deploy не опубликованы.

Package 5 pre-code revalidation исправила contract scope с 5 до exact 6 paths:
четыре production paths + два tests. Дополнительный path —
`RetrackersUpdateSequenceTest.php`, где меняется только import/bootstrap
preamble; 12-name SET и class-through-EOF hash остаются frozen. Это не новая
package строка и не меняет current count 14. Reachable RAW/BODY matrix и
natural missing-ledger B5 сняты в isolated 0.9.8/0.16.21 labs; production-success
B5 и 2×8×2 manifest остаются post-implementation gate. Evidence:
`VERIFICATION-retrackers-recovery-precode-2026-08-31.md`.

Последующие broad-harness blockers закрыты без изменения crosswalk arithmetic:
`c4fef63f` — test-only retrackers bootstrap, `76b0c0f5` — PHP 7.4 NUL-safe
Torrent path probes. Для второго готова upstream-clean branch
`up/php74-binary-metainfo=a1e60e69`, exact 2 paths `+36/-3` на `f19c9d86`;
владелец опубликовал её как upstream PR #3229; PR теперь **merged** как
`upstream/master=781cee4e`, а ancestry-only sync fork master — `d06e0651`.
Fork full harness последовательно GREEN на PHP
7.4/8.1/8.5: 65 files,
4152 success signals, zero failures. Это package-1 follow-up, не новая
implementation line. Evidence:
`VERIFICATION-php74-binary-metainfo-2026-08-31.md`.

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
| retrackers | 5 | +1,540/-45 | historical divergence bucket; current recovery contract is exact 6 paths because sequence-test preamble is an explicit target; later P3 marker package separate |
| Kinozal/loginmgr | 5 | +636/-28 | готовая локальная ветка/open PR |
| socket/settings | 4 | +450/-22 | historical fork delta; final approved carve `d548016b` is `+1229/-19`, ready and locally integrated |
| test harness | 3 | +3/-17 | готовая ветка также добавляет новый test-файл |
| history dot-label | 3 | +37/-5 | текущий код отвергнут, заменяется exact-marker P2 |
| cache guard/tests | 2 | +73/-1 | подтверждённый no-op, не отправлять |
| FileUtil evidence в master | 1 | +28/-0 | заменено готовой 7-файловой веткой |

92 fork-only commit — это 74 обычных и 18 merge commit; это не число
оставшихся задач. История содержит уже принятые upstream изменения под другими
SHA, integration merges и последующие remediation.

## Закрыто или готово

- готово или локально интегрировано: FileUtil `76485317` (#3231), test harness
  `8eafb529`, rTorrent 0.16.21 `cbacef8e` (#3230), Kinozal `c39d499d`, socket
  `d548016b`/`f547b2f3`, httprpc `c7a431aa`/`48825583`, SCGI
  `33934444` (runtime `4682a761`)/`19086b5f` + `3ff4860c`;
- PHP74 `286dd24b`/`acbf5691` принят upstream как #3224; follow-ups #3225 и
  #3229 также приняты, а #3229 входит в local ancestry sync `d06e0651`;
- standalone history dot-label branch отвергнута и не отправляется;
- cache `unserialize`, fork tooling, `.gitignore`, `AGENTS.md`, dead run registry,
  Snoopy gzip и старый log-unwritable имеют явный no-send/rejected verdict.

## Current approved contract status

Package 4 SCGI is implemented and independently APPROVED from final package 3.
Package 5 retrackers is partially implemented through Task 4B at `9fef4d66`
and independently APPROVED at that boundary; Tasks 5–8 remain. Packages 6
erasedata A and 14 XMLRPC proxy policy are DESIGN APPROVED — implementation
pending.
Their exact scopes are 7, 6, 8 production + 2 test and 7 paths respectively.
Retrackers uses final SCGI as immediate parent; P3 waits final retrackers +
final P1. Historical retrackers approval authority is commit
`14683d93bc54dbab89d6abce636d2e749e8492ba` / contract SHA-256
`922a7bad8caed5c6cdd0ce02112ff4729be9fbb6798ba5ee208440fc1edbfc17`;
its exact-five scope is superseded by the six-path correction in
`VERIFICATION-retrackers-recovery-precode-2026-08-31.md`. Current corrected
authority: commit `d60f479b746e165c51c75d9c5b763435ca273539`, contract SHA-256
`6232b0cf6e5d9c36eaed49648cdd372e579c305b58d90cbefe631cf5ad59a535`,
pre-code verification SHA-256
`cc7de16b20b1752dd95464a6a98ca034bd22aceaceacf03cdae3bb3d8a21acef`.
Final verification and cleanup authority is commit
`f1e6d4ed7ee5c1095b24dab27adde72493f76cc0` / archive SHA-256
`f2a08d8b1f36b43d2490f87da8d859916c804e8396ac09b7c3600f34d64bee16`;
cleanup report SHA-256 is
`c416448e396b1a96424aa791a5211dcb3cb78b4ec5ae3cd6cd67c9d1b75f1bea`.
Exact eight-container cleanup is GREEN, but it closes no implementation package
and does not make retrackers production B5+EPOCH or successor behavior GREEN.

## Открытый счёт

До полного закрытия divergence остаётся **14 implementation packages** из
`PLAN-remaining-queue-2026-08-29.md`; неразобранных carve/verdict-аудитов — 0.
Семь ready/integrated handoff в этот счёт не входят; ещё один закрытый package
уже принят upstream.

Переход от прежних 18 workstream проверен арифметически:
`18 - 5 завершённых audits + 6 successor packages = 19`, затем standalone C
сложен внутрь P0: `19 - 1 = 18`. Packages 1–4 затем реализованы:
`18 - 4 = 14`. Residual rTorrent, proxy policy и manual
entrypoints дали по одному package; foreign bucket — три; generic
`sendTorrent() +17/-0` закрыт no-send. Evidence:
`REVIEW-disposition-wave-2026-08-29.md` и
`REVIEW-erasedata-obsolete-jobs-2026-08-29.md`.

Design approval itself decrements neither implementations nor fork divergence;
accepted implementation evidence for packages 1–4 changed the arithmetic.
The current count is 14 implementations / 0 audits / 7 ready or locally
integrated owner handoffs outside the count + 1 accepted upstream closure.

## Ownership corrections

Отдельный `php/xmlrpc_path.php` в critical chain не нужен. Endpoint-local
resolver остаётся в proxy doors; filesystem identity принадлежит A. Final
httprpc = `c7a431aa`; SCGI, XMLRPC proxy policy и A — sibling packages от него.
Retrackers имеет immediate parent final SCGI и не является sibling
implementation from httprpc.

Отдельный `up/httprpc-erasedata-contract` остаётся обязательным, сохраняет exact
two-path scope и historical production hunk `+6/-13` в
`plugins/httprpc/action.php` плюс новый copied-entrypoint test. Он строится
после обоих final XMLRPC policy + A и запрещает implicit force, missing helper
и fallback на plain `d.erase`. P3 строится после обоих final retrackers + P1.
Эти edges не расширяют scopes предшественников.

## Current-base shields

- всегда сохранять #3209/#3211 proxy parser tests и #3218 anchored init
  requires;
- не копировать stale retrackers suite: upstream #3212 содержит 12 sequence
  tests, которые fork snapshot визуально удаляет;
- shared paths режутся по hunks после final prerequisite tip;
- exact P0/P1, erasedata A/C и новые manual/foreign packages нельзя называть
  ready до RED-first carve-а на их final prerequisite tips;
- approved retrackers design остаётся действующим, но implementation branch
  нельзя называть ready до RED-first six-path carve от final SCGI,
  production-success B5/2×8×2 producer gates и exact predecessor
  class/test-set preservation;
- PHP 7.4 defect #3213 не входит в 139 fork paths, но учитывается отдельным
  обязательным compatibility package.
