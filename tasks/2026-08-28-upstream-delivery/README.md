# Задача: заливка форка в upstream — что залито, что осталось, где проблемы

Срез обновлён **2026-08-29**: `upstream/master` = `755404f3`, опубликованный
`origin/master` = `24891da9`, 101 commit впереди и 12 позади. Исторические
fork-divergence deltas ниже заморожены на behavior snapshot `511ed13f`;
последующий `24891da9` меняет 6 production/test paths. Его claim/sweep hunks
уже классифицированы в existing P0, а magnet-source/parsed-Torrent hunks — в
P1; нового package и изменения счёта 18 нет. Подробный старый план:
`../2026-08-28-upstream-rebuild/PLAN.md`.

## Post-sync contract checkpoint — 2026-08-30

Контрактная ветка `codex/retrackers-contract-finish` объединяет checkpoint
`329bcc8f` с актуальным `upstream/master=52903333` merge-коммитом `4b3cd799`.
Exact post-755 upstream delta — только #3220/#3202 в трёх package-lock/filedrop
paths; прямого пересечения с шестью approved contract scopes нет. Conflict
resolutions сохраняют #3209/#3211 XMLRPC parser semantics, byte-exact #3212
retrackers predecessor и #3218 anchored init paths. Все шесть контрактов
повторно квалифицированы как **DESIGN APPROVED — implementation pending**. На
этом историческом checkpoint очередь была **18 implementations / 0 audits / 4
ready handoffs outside count**; последующий implementation checkpoint ниже
заменяет текущий счёт. Полная запись:
`VERIFICATION-upstream-sync-contracts-2026-08-30.md`.

Adversarial review отдельно подтвердил production-reachable PHP 7.4 parse
failure в upstream `php/Torrent.php` из-за native `mixed`. Это не новая находка
и не новый пакет: defect уже заморожен как package 1
`up/php74-torrent-properties`. Но пока package 1 не реализован по отдельной
команде, post-sync merge **не переносится в локальный `master` и не
публикуется**. Контрактная синхронизация зафиксирована только на task branch;
`master`/`origin/master` остаются на `5da21546`.

## Implementation checkpoint — 2026-08-30

Предыдущий абзац остаётся историческим contract checkpoint. После отдельной
implementation authority packages 1 и 2 завершены:

- `up/php74-torrent-properties` = `286dd24b`, exact 3 paths `+14/-9`, historical
  direct parent `eeae9f3a`; ветка опубликована владельцем, принята upstream как
  #3224 и локально интегрирована в fork как `acbf5691`;
- `up/setsettings-socket-alloc` = `d548016b`, exact 4 paths `+1229/-19`, один
  commit на current upstream `f19c9d86`; scoped, whole-branch и
  master-integration reviews APPROVED, push не выполнялся.

Read-only `git ls-remote` подтвердил `f19c9d86` как текущий
`Novik/ruTorrent:master`. Перед socket package fork `master` получил недостающий
exact upstream delta #3196/#3222/#3223 как `ed71bee5`, сам package — как
`f547b2f3`, а принятые #3224/#3225/#3226 — как `7a78c606`. Последний product
commit локального `master` — `7a78c606`; `origin/master` остаётся `acbf5691`;
push/deploy не выполнялись. Полный evidence:
`VERIFICATION-setsettings-socket-alloc-2026-08-30.md`.

Текущий счёт: **16 implementations / 0 audits / 5 ready or locally integrated
owner handoffs outside count + 1 accepted upstream closure**.

## Implementation checkpoint — 2026-08-31

Package 3 `up/httprpc-refusals` завершён после отдельной RED→GREEN реализации и
двух independent reviews. Upstream-clean branch — `c7a431aa`, один commit
прямо на `f19c9d86`, exact 5 paths `+437/-14`. Fork integration —
`48825583`, exact 4-path delta поверх `d553bd47`: shared refusal helper уже был
в richer fork parser, поэтому `php/xmlrpc_proxy.php` не получил net change.

Focused endpoint/helper matrix прошла на PHP 7.4/8.1/8.5; PHPStan и Jest
зелёные. Broad PHP comparison имеет только exact base-equal известные RED
`rRetrackers` и PHP 7.4 raw-magnet prerequisite; runtime cache пользователя не
удалялся. Владелец затем опубликовал exact branch как
`origin/up/httprpc-refusals=c7a431aa` и открыл upstream PR #3228;
`origin/master` и deployment не менялись. Evidence:
`VERIFICATION-httprpc-refusals-2026-08-31.md`.

Оба broad-harness follow-up теперь также закрыты отдельными commits:
`c4fef63f` исправляет только bootstrap реального `rRetrackers` class в sequence
test, а `76b0c0f5` защищает три PHP 7.4 path probe от binary metainfo с NUL.
Для второго владелец опубликовал upstream-clean branch
`up/php74-binary-metainfo=a1e60e69`, exact 2 paths `+36/-3` на `f19c9d86`,
и открыл upstream PR #3229. На 2026-08-31 PR остаётся `OPEN`, а
`upstream/master` по-прежнему равен `f19c9d86`.
Полный fork harness после обеих правок проходит 65 files / 4152 success signals
на PHP 7.4, 8.1 и 8.5. Raw-metainfo fix является follow-up package 1, а test
bootstrap не меняет production; поэтому счёт очереди не уменьшается. Evidence:
`VERIFICATION-php74-binary-metainfo-2026-08-31.md`.

Package 4 `up/scgi-transport` также закрыт отдельной реализацией. Final
upstream-clean branch — `4682a761`, один non-merge commit прямо на
`c7a431aa`, exact 7 paths `+1569/-51`; fork integration — `19086b5f` плюс
отдельная one-path test-stub adaptation `3ff4860c`. Recorded полные PHP
7.4/8.1/8.5 матрицы, stable focused suite, PHPStan, mutations и реальные
UNIX-SCGI probes на rTorrent 0.9.8 и
0.16.21 зелёные; оба snapshots independently APPROVED. Evidence:
`VERIFICATION-scgi-transport-2026-08-31.md`.

GitHub run `33386106599` не дал публичного failing-test log: PHP 8.1
завершился с `1`, а PHP 7.4 был отменён fail-fast. Поэтому кодовая
заглушка не добавлялась; локальный `95f9ab6f` только отключает
matrix fail-fast, сохраняя красный verdict и оба PHP результата. Evidence:
`VERIFICATION-ci-php-matrix-observability-2026-08-31.md`.

Текущий счёт: **14 implementations / 0 audits / 7 ready or locally integrated
owner handoffs outside count + 1 accepted upstream closure**.

Package 5 pre-code gate перепроверен 2026-08-31. Старый exact-five contract
оказался противоречивым: он требовал import-safe `update.php`, одновременно
запрещая необходимую правку bootstrap sequence-test. Scope исправлен на exact
6 paths: четыре production paths плюс `UpdateTest.php` и
`RetrackersUpdateSequenceTest.php`. В sequence-test меняется только preamble;
12-name SET и class-through-EOF bytes заморожены отдельными SHA-256.
Контейнерный RAW audit сохранил 112 RAW/BODY pairs для rTorrent 0.9.8/0.16.21
и подтвердил natural missing-ledger B5 boundary. Production-success B5 и
two-family × eight-state × two-read manifest остаются недостижимы до появления
реальных package producers и обязательны после реализации. Evidence:
`VERIFICATION-retrackers-recovery-precode-2026-08-31.md`.

## Цель

Отправить в upstream **все** расхождения форка, корректно оформленными PR. Это решение
владельца, принятое в начале работы; оно не менялось.

---

## Залито и принято (15 PR)

| PR | тема |
|---|---|
| #3165 | Snoopy: protocol-relative URL |
| #3167 | rTorrent compatibility |
| #3168 | stale details pane |
| #3169 | cache: concurrent writers |
| #3170 | RSS encodings |
| #3174 | conf debug flag |
| #3175 | NNMClub own passkey |
| #3176 | Kinozal login wall |
| #3177 | ratio view guard |
| #3178 | history service entries |
| #3179 | partial seed |
| #3199 | `getScheduleCommand` — детерминированный старт |
| #3200 | Up/small UI |
| **#3205** | **loginmgr: выбор аккаунта по хосту URL, и никогда ниже его схемы** — security |
| **#3224** | **PHP 7.4 Torrent metadata compatibility** |

Отдельно: **#3206** (`fde9863b`) — xirvik сам расширил нашу работу из #3205 на NNMClub.
То есть подход принят и мейнтейнер на нём строит.

После #3224 upstream также принял follow-up **#3225** для чтения numeric torrent
key по обеим формам. **#3226** — отдельная upstream FileUtil brace/test правка;
она не закрывает полный семипутевой FileUtil package этого плана.

---

## Открыто в upstream

| PR | ветка | состояние |
|---|---|---|
| **#3198** | `up/kinozal-session` (local `de98a49a`, remote `4cf74c52`) | Открыт. Локальный один commit перебазирован на `755404f3`, 5 файлов, +636/−28; focused 35/35, обе полные PHP-матрицы и PHPStan зелёные. Remote намеренно не менялся и требует owner-only force-with-lease. Ответ xirvik: `../2026-08-28-upstream-rebuild/REPLY-3198.md` |
| **#3227** | `up/setsettings-socket-alloc` (`a8b60bea`) | Открыт владельцем. GitHub выявил один package-owned ESLint `no-redeclare` в `js/rtorrent.js`; one-file follow-up `a8b60bea` исправляет только индекс fault-loop. Владелец опубликовал fix, remote/local совпадают; все восемь GitHub checks и local focused Jest 66/66 зелёные. Тот же patch интегрирован и опубликован в fork `master` как `fe5313fa`. Evidence: `VERIFICATION-setsettings-socket-alloc-2026-08-30.md` |
| **#3228** | `up/httprpc-refusals` (`c7a431aa`) | Открыт владельцем. Exact 5 paths `+437/-14` на `f19c9d86`; candidate и fork integration independently APPROVED. Evidence: `VERIFICATION-httprpc-refusals-2026-08-31.md` |

---

## Готово, но НЕ отправлено

| ветка | коммит | объём | что мешает |
|---|---|---|---|
| `up/fileutil-defects` | `79190927` | +514/−10, 7 файлов | **ГОТОВА.** Один commit прямо на `755404f3`, patch после rebase идентичен; PHP 8.5/8.1 — 48 файлов, 303 теста, 1815 assertions; PHPStan и direct probes зелёные. PHP 7.4 qualification: `../2026-08-28-fileutil-defects/VERIFICATION.md` |
| `up/history-service-labels` | `4cf3bd69` | +37/−5, 3 файла | **НЕ ОТПРАВЛЯТЬ.** Достижимая потеря истории/Pushbullet для пользовательских `.private`-меток; producer отсутствует в upstream; тест не держит production-gate. Разбор: `REVIEW-history-service-labels.md` |
| `up/test-harness` | `8eafb529` | +49/−17, 4 файла | **ГОТОВА.** Один commit прямо на `755404f3`; PHP 8.5/8.1 — 47 файлов, 287 тестов, 1781 `Passed:`; семь полных мутаций красные. Тексты: `REVIEW-test-harness.md`, `PR-test-harness.md` |
| `up/rtorrent-0-16-21` | `48bc6d4b` | +9/−4, 3 test-файла | **ГОТОВА.** Один commit прямо на `755404f3`; обе полные PHP-матрицы 46/285/1790, Jest 20/196, независимые spec/quality reviews зелёные. Тексты: `REVIEW-rtorrent-0-16-21.md`, `PR-rtorrent-0-16-21.md` |
| `up/kinozal-session` | `de98a49a` | +636/−28, 5 файлов | **ГОТОВА ЛОКАЛЬНО.** Один commit прямо на `755404f3`; remote открыт на старом `4cf74c52` и обновляется только владельцем с exact lease. Handoff: `REVIEW-ready-branches-2026-08-29.md` |
| `up/scgi-transport` | `4682a761` | +1569/−51, 7 paths | **ГОТОВА ЛОКАЛЬНО.** Один commit прямо на final httprpc `c7a431aa`; reviews, PHP 7.4/8.1/8.5 и real 0.9.8/0.16.21 UNIX probes APPROVED. Fork integration `19086b5f` + test adaptation `3ff4860c`; push не выполнялся. Evidence: `VERIFICATION-scgi-transport-2026-08-31.md`, PR text: `PR-scgi-transport.md` |
| `up/loginmgr-account-selection` | `1975ecb4` | — | **Уже влита как #3205.** Ветку можно удалять |

### Что исправлено в `up/test-harness`

Половина про `TestCase.php` подтверждена: падающий test печатал `Passed:` при
`zend.assertions=-1`, после правки печатает `Failed:`. Два прежних блокера закрыты:

1. Runner явно передаёт `-d zend.assertions=1 -d error_reporting=-1
   -d display_errors=1`; системный php.ini больше не меняет видимость diagnostics.
2. Ложная команда `php -f php/CacheTest.php` удалена из commit message и заменена
   реальным standalone probe.

Добавлен `TestCaseTest.php`, который держит и поведение при выключенных assertions,
и все три effective runner settings. Полный разбор: `REVIEW-test-harness.md`.

---

## Снято с отправки

| ветка | почему |
|---|---|
| `up/snoopy-gzip-body` | Дефект реален в коде, недостижим в проде: ни один живой сервер не ставит gzip-флаг FNAME, а curl-путь вообще не запрашивает сжатие. Моя правка внесла регрессию **хуже** исходного бага (`gzdecode()` возвращает `false` там, где `gzinflate()` возвращал тело целиком → `STE_DELETED` в `check.php:445`). Ветка удалена. Разбор: `../2026-08-28-upstream-rebuild/REVIEW-snoopy.md`, память `snoopy-gzip-dead-end` |
| `up/log-unwritable` | Обоснование перевёрнуто, тест красный в документированной команде. Ветка снята, вернуть: `git branch up/log-unwritable 5346acbf`. Разбор: `../2026-08-28-upstream-rebuild/VERDICT-log-unwritable.md`. Настоящие дефекты, найденные вместо неё: `../2026-08-28-fileutil-defects/` |

### Никогда не отправлять (решено ранее)

- Гард `unserialize` в `php/cache.php` — адверсально проверен, это no-op
- Хунки `.gitignore` (форковые строки 68-75 + `backup/`; в upstream их нет)
- `AGENTS.md`
- Правки реестра в `tests/plugins/rutracker_check/run.php`

---

## Актуальная очередь

Старые PR 5–11 больше не являются допустимой декомпозицией: whole-file оценки
смешивали независимые fixes и в некоторых случаях удаляли новые upstream tests.
Полный current crosswalk: `CROSSWALK-remaining-divergence-2026-08-29.md`;
исполняемый план: `PLAN-remaining-queue-2026-08-29.md`.

Точный текущий счёт после завершения всех пяти disposition-аудитов и packages
1–4:

- **14 незакрытых реализационных пакетов**;
- **0 неразобранных carve/verdict-аудитов**;
- семь готовых или локально интегрированных owner handoff в это число не входят;
- package 1 уже принят upstream как #3224 и учитывается отдельно как closure.

Арифметика: прежние `13 implementations + 5 audits = 18` преобразованы в
`13 + 6 audited successors = 19`, затем standalone erasedata C сложен внутрь
P0: `19 - 1 = 18`. После реализации packages 1–4: `18 - 4 = 14`.
Successors: rTorrent alias surface, XMLRPC proxy policy,
manual rutracker entrypoints и три foreign-handler packages.
Exact generic `sendTorrent() +17/-0` закрыт как no-send: семантическая
dispatch-vs-load граница реальна, но unconditional log одинаков для будущего
успеха и отказа и потому ничего не диагностирует.

Замороженные contract boundaries: httprpc — exact 5 paths; SCGI — exact 7
paths; retrackers recovery — exact 6 paths; erasedata A — exact 8 production +
2 test paths; XMLRPC proxy policy — exact 7 paths. SCGI historical donor
estimate заменён final exact delta `+1569/-51` от `c7a431aa`.
Design approvals сами по себе не закрыли packages; отдельные реализации 1–4
закрыли четыре строки. Pending carve/verdict audits — 0, семь ready/integrated
owner handoff остаются вне счёта, а PHP74 уже принят upstream.
Erasedata A/B остаются самостоятельными, а C сложен внутрь P0. B donor
`+168/-2` подтверждён только как snapshot и опровергнут как final estimate;
70-путевый rutracker snapshot заменён P0/P1/P2/P3, manual entrypoints и тремя
foreign-handler packages. Полный синтез: `REVIEW-disposition-wave-2026-08-29.md`;
foreign brief: `REVIEW-foreign-tracker-handlers-2026-08-29.md`.

Retrackers recovery: DESIGN APPROVED — implementation pending. Scope — ровно
`init.php`, `done.php`, `run.sh`, `update.php`, `UpdateTest.php` и
`RetrackersUpdateSequenceTest.php`; `guard.php` и P3 service policy исключены.
В шестом path разрешён только import/bootstrap preamble; 12 methods и class
body неизменны. Immediate implementation parent — final
`up/scgi-transport`; P3 строится только после final P1 и package 5. Published
donor tests остаются characterization, не closure. Natural missing-ledger B5 и
wire fixtures уже captured; production-success HistoricalBindingSampleV2/
B5+EPOCH manifest остаётся BLOCKED до появления `rr.receipts.v1`, `pf`/`pv`,
extended owner, paired actions и canonical four-field marker. Это
post-implementation acceptance gate, а не prerequisite design approval;
текущий общий счёт остаётся 14.

Historical five-path authority: approval commit
`14683d93bc54dbab89d6abce636d2e749e8492ba`, contract SHA-256
`922a7bad8caed5c6cdd0ce02112ff4729be9fbb6798ba5ee208440fc1edbfc17`.
Он не является authority исправленного six-path scope; текущий commit/SHA
зафиксирован после двух independent APPROVED reviews. Current six-path
authority: commit `d60f479b746e165c51c75d9c5b763435ca273539`, contract SHA-256
`6232b0cf6e5d9c36eaed49648cdd372e579c305b58d90cbefe631cf5ad59a535`,
pre-code verification SHA-256
`cc7de16b20b1752dd95464a6a98ca034bd22aceaceacf03cdae3bb3d8a21acef`.
Final verification and cleanup authority: commit
`f1e6d4ed7ee5c1095b24dab27adde72493f76cc0`, archive SHA-256
`f2a08d8b1f36b43d2490f87da8d859916c804e8396ac09b7c3600f34d64bee16`,
cleanup report SHA-256
`c416448e396b1a96424aa791a5211dcb3cb78b4ec5ae3cd6cd67c9d1b75f1bea`.
Exact eight-container cleanup is GREEN; this runtime cleanup closes no
implementation package and does not turn B5 or successor behavior GREEN.

Erasedata A: DESIGN APPROVED — implementation pending, exact 8 production + 2
test paths. Frozen design: pre-erase generation + fixed repeating schedule +
real PHP child ack, один NB worker owner, blocking drain scheduler/hash locks,
exact batch set/cardinality, settle-before-remove, prepared/erase-started
journal и A/B restart rearm. Periodic pass остаётся NB,
worker-per-hash/invocation fan-out запрещён. A является sibling SCGI/XMLRPC
после final httprpc и не зависит от SCGI API. Brief:
`REVIEW-erasedata-remove-payload-2026-08-29.md`.

Ratio B сохраняет exact two-path scope: missing-helper fallback достижим, но
donor test его не удерживает и отказ невидим; username filter опровергнут, а
Ratio exact-force direct call недостижим в prod. После corrected A B также
re-arms pending drain на Ratio startup/reload, когда erasedata disabled и
runtime schedule потерян при restart. Corrected B design independently
approved; реализация ещё не начата. Brief:
`REVIEW-ratio-erasedata-contract-2026-08-29.md`.

Standalone erasedata C запрещён и сложен внутрь P0: без P0 все v3 producers
недостижимы в prod, а active inline cleanup остаётся. Fresh base probe удалил
и genuine orphan, и shared path третьего torrent (`requests=0`); combined P0+C
design independently approved на exact 20-path boundary: OLD/NEW-aware
lexical+physical tri-state ownership, no-bridge quarantine с post-capture scan,
token/false/null claim gate, pre-erase A drain acknowledgement и restart rearm.
Shared `getSource`/parsed-Torrent hunks зафиксированы как P1 и не протекают в
P0 только из-за общего filename. Реализация ещё не начата.
Brief:
`REVIEW-erasedata-obsolete-jobs-2026-08-29.md`.

Container checkpoint выполнен на immutable exports с `--network none` и
read-only mounts. A donor: 204/1305, B donor: 10/61, rutracker focused: 312/0
на initial donor и **317/0** на current `24891da9` во всех PHP 7.4/8.1/8.5
compatibility containers; current broader rutracker runner: **700/0**.
Exact-base C probes независимо воспроизвели cross-owner deletion и
silent non-root unlink failure. Это baseline/reachability evidence, не GREEN
ещё не реализованных durable-drain/rearm/no-bridge сценариев; их tests и код
должны появиться RED-first. Exact commands/results/limitations:
`VERIFICATION-erasedata-contracts-2026-08-29.md`.

SCGI transport **implemented and APPROVED** как clean `4682a761`, exact 7 paths
`+1569/-51` from final `up/httprpc-refusals`. Nine-argument API implements complete
segmented writes, exact `Content-Length` framing, raw/body modes, trust bit,
legacy-safe optional globals, PHP 7.4, TCP/UNIX runtime gates, 64 MiB default
client cap and 100 MiB operator/wire ceiling. Fork integration is `19086b5f`
plus isolated test adaptation `3ff4860c`. Evidence:
`VERIFICATION-scgi-transport-2026-08-31.md`; design history:
`REVIEW-scgi-transport-2026-08-29.md`.

Socket package **implemented and APPROVED** как `d548016b` на `f19c9d86`:
patch-identical predecessor `b3e36835` прошёл независимый review. Save-lock держится
через terminal `setuisettings`, scoped 401 диагностирует и unlock-ит без reload,
а ordinary 401 сохраняет global auth navigation. Candidate 59/59 focused и
263/263 full Jest; локальная интеграция `f547b2f3` прошла 310/310 Jest и
baseline-equal PHP comparison. Exact evidence:
`VERIFICATION-setsettings-socket-alloc-2026-08-30.md`.

PHP74 package **implemented и принят upstream как #3224**: historical candidate
`286dd24b`, exact 3 files `+14/-9`, direct parent `eeae9f3a`; full PHP 7.4/8.1
harness и mutations подтверждены. Fork integration commit — `acbf5691`;
upstream follow-up #3225 также включён в локальный `7a78c606`. Design brief:
`REVIEW-php74-torrent-properties.md`.

Httprpc package **implemented and APPROVED**: clean branch `c7a431aa`, exact 5
paths `+437/-14`, direct parent `f19c9d86`; fork integration `48825583`
сохранила richer fork parser/policy/SCGI surface и добавила exact copied-door
tests. Read failure и empty body разделены, terminal 403/500 закреплены,
transport 500 использует neutral text без ложного log promise. Evidence:
`VERIFICATION-httprpc-refusals-2026-08-31.md`; design history:
`REVIEW-httprpc-refusals-2026-08-29.md`.

XMLRPC proxy policy: DESIGN APPROVED — implementation pending, exact 7 paths
from final `up/httprpc-refusals`. Closed policy owns all eight registered
`load.*` methods, exact evaluator/carrier denials, all-or-nothing rebuild of six
direct multicalls, unconditional sanitize-mode `system.multicall` refusal,
common/local/per-user config precedence, root/local switches and bounded
diagnostics while preserving #3209/#3211 parser tests. The later httprpc
erasedata consumer waits both final XMLRPC policy and erasedata A. Brief:
`REVIEW-xmlrpc-proxy-policy-2026-08-29.md`.

Отдельная ветка `php/xmlrpc_path.php` не нужна: endpoint-local resolver остаётся
в proxy doors, а filesystem identity принадлежит erasedata A. После final
httprpc SCGI, XMLRPC policy и A являются sibling packages. Retrackers строится
только после final SCGI. Отдельный two-path
`up/httprpc-erasedata-contract` строится после final XMLRPC policy + final A;
P3 — после final retrackers + final P1. Эти edges не расширяют scopes
предшественников.

## Historical stop checkpoint and subsequent contract closure

На исходном stop checkpoint работа остановилась после independent approval A,
B и combined C+P0 и container baseline:

- A — DESIGN APPROVED — implementation pending, exact 8 production + 2 tests;
- B — corrected design approved, exact 2 paths, строится после final A;
- combined C+P0 — design approved, exact 11 production + 9 tests, строится
  после final A;
- production implementation этих packages не начиналась;
- XMLRPC proxy design на том checkpoint ещё не был закрыт;
- push не выполнялся.

После checkpoint отдельные SCGI и XMLRPC contracts получили DESIGN APPROVED,
а retrackers contract после двух CLEAN reviews получил
тот же статус на первоначальном exact-five scope в approval commit
`14683d93bc54dbab89d6abce636d2e749e8492ba`; этот scope позднее исправлен до
exact six в `VERIFICATION-retrackers-recovery-precode-2026-08-31.md`. Exact
container cleanup
получил GREEN и был зафиксирован commit
`f1e6d4ed7ee5c1095b24dab27adde72493f76cc0`; это runtime cleanup, не
implementation/capture acceptance. С тех пор packages 1–4 закрыты отдельной
реализацией; natural missing-ledger B5 уже captured, а production-success B5
и exact 2×8×2 manifest остаются post-implementation BLOCKED.

Resume point: не считать donor GREEN закрытием packages. A → B и A → combined
C+P0 сохраняются; SCGI, XMLRPC и A начинаются только от final httprpc;
retrackers — только от final SCGI. Общая очередь — 14 implementation packages,
pending carve/verdict audits — 0, ready/integrated handoffs outside count — 7;
ещё один закрытый package уже принят upstream.

---

## Правила, которые нельзя нарушать

- Ветки режутся от финального prerequisite tip и называются `up/<имя>`.
  Fork `master` служит источником intent, но shared files переносятся только
  замороженными hunks поверх текущего upstream; whole-file copy запрещён.
- **Никогда `git add -A` на ветке `up/*`** — в `.gitignore` upstream нет строк про
  `tasks/`, `docs/`, `.claude/`, `.agents/`, `.codex/`, `.superpowers/`, `backup/`,
  и весь этот мусор уедет в PR.
- Никакого PHPUnit и composer — в upstream их нет.
- Обязательные матрицы PHP перед отправкой: локальный 8.5 и root-container 8.1
  **по команде из README, без `--user`**; после PHP74 compatibility PR также
  реальный 7.4 для путей, которые могут затронуть runtime floor.
- Мутационная проверка каждой правки.
- **Мерить посылку до того, как писать аргумент.** Три ветки за один день сгорели
  ровно на этом.

## Что делать дальше

1. ~~Дождаться перепроверки `../2026-08-28-fileutil-defects/`.~~ Завершено и
   зафиксировано в той задаче.
2. ~~Проверить адверсально `up/history-service-labels`.~~ Проверена и отвергнута;
   безопасную marker-based логику сложить с producer-ом в PR 11. Разбор:
   `REVIEW-history-service-labels.md`.
3. ~~Починить `up/test-harness`.~~ Готова как `8eafb529`; осталось push/PR по
   `PR-test-harness.md`.
4. Owner-only handoff семи готовых/integrated packages —
   FileUtil/test-harness/rTorrent/Kinozal/socket/httprpc/SCGI; PHP74 уже принят
   upstream. Первые пять зафиксированы в
   `REVIEW-ready-branches-2026-08-29.md`, httprpc и SCGI — в
   `VERIFICATION-httprpc-refusals-2026-08-31.md` и
   `VERIFICATION-scgi-transport-2026-08-31.md`.
5. Design approvals зафиксированы отдельно от implementation authority. После
   нового явного указания реализовывать packages RED->GREEN только от exact
   final predecessors; approval контракта сам по себе не является командой на
   код, push или upstream sync.
6. После final httprpc SCGI, XMLRPC policy и erasedata A являются sibling
   branches. Retrackers строится только после final SCGI; consumer — после
   XMLRPC policy + A; B и P0+C — после final A; P3 — после final P1 +
   retrackers. Продолжить оставшиеся 14 packages по PLAN; pending audits = 0,
   ready/integrated owner handoffs outside count = 7, accepted closure = 1.
