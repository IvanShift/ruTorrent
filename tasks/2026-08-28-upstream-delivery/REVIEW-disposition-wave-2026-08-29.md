# Итог пяти disposition-аудитов — 2026-08-29

База перепроверки: `upstream/master=755404f3`. Historical fork behavior
snapshot: `511ed13f`. Current published fork — `24891da9`; его последующие
claim/sweep production hunks уже отнесены к existing P0, а
magnet-source/parsed-Torrent hunks — к P1. Они не создают новый package и не
меняют итоговый счёт 18.

Аудиты выполнялись независимо от исторических выводов: каждый donor hunk
сопоставлялся с current upstream, реальным production entrypoint и собственным
тестовым/исходным доказательством. Репозиторий, remotes и live service в ходе
этих пяти аудитов не мутировались.

## Счёт disposition wave и последующий C fold

Старый ledger был:

```text
13 обязательных implementation packages + 5 disposition audits = 18 open
```

Все пять аудитов завершены. Четыре дали шесть successor-пакетов, один закрыт
как no-send:

```text
18 - 5 завершённых аудитов + 6 successor packages = 19 after disposition
19 - 1 standalone erasedata C folded into P0 = 18 current open

18 обязательных implementation packages
 0 неразобранных disposition audits
```

Четыре уже готовых owner handoff — FileUtil, test harness, rTorrent 0.16.21 и
Kinozal session — в текущие 18 не входят, как не входили в прежний ledger.

Disposition-аудиты сами создали 19-package result. Последующий независимый
review C доказал, что C-only API недостижим до P0 и не отключает active inline
cleanup, поэтому C и P0 являются одним delivery package. Это не новая находка
disposition wave, а dependency correction, подтверждённая в
`REVIEW-erasedata-obsolete-jobs-2026-08-29.md`.

| Исходный аудит | Финальный вердикт | Successor |
|---|---|---|
| residual rTorrent command surface | upstreamable characterization; production incompatibility не найдена | `up/rtorrent-alias-surface` |
| XMLRPC proxy policy | подтверждён в суженном 7-путевом scope | `up/xmlrpc-proxy-policy` |
| generic `sendTorrent()` diagnostic | exact `php/rtorrent.php +17/-0` опровергнут как fix, no-send | нет |
| manual `rutracker_check` entrypoints | подтверждены семь reachable boundary defects; старый 4-path carve отвергнут | `up/rutracker-manual-entrypoints` |
| foreign tracker handlers | 9-path bucket разделён на три sibling-пакета | Kinozal, NNMClub, siblings |

## 1. `up/rtorrent-alias-surface`

После вычитания готового `up/rtorrent-0-16-21` residual равен четырём путям,
`+1355/-4`, но `js/content.js +4/-0` — comment-only no-send. Финальный exact
scope successor-а:

1. `php/settings.php` — подтверждённое comment correction `+9/-4`;
2. `tests/js/rtorrent.spec.js` — residual alias-map characterization;
3. `tests/php/RtorrentCompatibilityTest.php` — seed/full-map/dormancy tests.

Existing-hunk snapshot — `+1351/-4`; это не финальный numstat: текст fixture
должен честно называть 982 имени полным capture 0.16.20 и проверенным subset
свежего 1027-name 0.16.21 answer. В production несовместимость не найдена.

Три отсутствующих на stock 0.16.21 target-а — `dht.throttle.name`,
`dht.throttle.name.set`, `throttle.ip` — регистрируются только с `-D`, не имеют
production sender и получают verdict **недостижимы в штатном production /
no-send**. Они сохраняются как допустимая 0.9.8 mapping baseline.

Пакет строится после готового `up/rtorrent-0-16-21` из-за пересечения тестов,
но не зависит от socket branch. Natural RED на current base отсутствует;
честный gate — named mutation каждого load-bearing теста.

## 2. `up/xmlrpc-proxy-policy`

Подтверждённый exact scope — семь путей:

- `conf/xmlrpc_proxy.php`;
- `php/xmlrpc_proxy.php`;
- `plugins/httprpc/action.php`;
- `plugins/httprpc/conf.php`;
- `tests/php/XMLRPCProxyTest.php`;
- `tests/php/XMLRPCProxyContractFixture.php`;
- `tests/php/XMLRPCProxyEntrypointTest.php`.

Пакет следует непосредственно после `up/httprpc-refusals`, сохраняет exact
seven-path boundary и остаётся `CHANGES REQUIRED`, пока не построены RED для
полного corrected contract:

- common-config precedence, независимые root/local-path opt-ins и bounded
  classified diagnostics без caller path/raw arguments;
- единый rebuild owner для восьми registered dot-load methods, включая четыре
  verbose variants; ни один load carrier не уходит в unknown raw fallback;
- exact deny set `catch`, `branch`, `try`, `and`, `or`, `less`, `greater`,
  `equal`, `match`, независимо от operator safe-name configuration;
- whole-call fail-closed для direct d/t/f/p mixed command grammar, если хотя бы
  один member не rebuilt; raw payload не пересылается;
- отказ nested load/mixed/wrapper members через `system.multicall`, zero-send;
- сохранение полного #3209/#3211 quote/escaped-comma parser SET.

На поддерживаемом rTorrent 0.9.8 все перечисленные evaluators исполняют XMLRPC
strings/list operands без trust gate. `if`, `not`, `compare` calls достижимы,
но их executable subpaths требуют internal types, недостижимых через XMLRPC, и
остаются characterization/no-overblock gates. Modern 0.16 trust checks не
заменяют proxy denial для legacy и deterministic behavior двух proxy doors.

No-send: shared resolver, `d.custom.set` arity/parser rewrite, broad fixture
replacement, `if`/`not`/`compare` denial и fork-wide copy. Они либо не меняют
policy, либо регрессируют уже принятые #3209/#3211 contracts.

`up/httprpc-erasedata-contract` тоже меняет
`plugins/httprpc/action.php`, поэтому он строится позже и ждёт одновременно
этот proxy-policy tip и erasedata A. Это ordering edge, а не перенос erasedata
behavior в proxy package.

## 3. Generic `sendTorrent()` — no-send

Семантический разрыв подтверждён: `sendTorrent()` возвращает локально
вычисленный hash после успешной доставки XMLRPC, а rTorrent завершает load
асинхронно и ещё может отклонить torrent.

Однако exact donor hunk `php/rtorrent.php +17/-0` пишет одинаковое
`load dispatched, not confirmed` и для будущего успеха, и для будущего отказа.
Он не отличает потерю, шумит при каждом нормальном add и ссылается на fork-only
plugin. Поэтому предложенный fix **опровергнут / no-send**, отдельного пакета
нет.

Confirmation принадлежит caller-у, который знает транзакцию и последствия;
для replacement/retrackers она уже входит в их владельцев. Новый проект по
универсальному UI/RSS/watch confirmation из этого аудита не выводится.

## 4. `up/rutracker-manual-entrypoints`

Старый snapshot `4 paths, +1270/-23` отвергнут как пакет: aggregate test
смешивал восемь чужих owners, `batch_check.php` заранее тянул P1 crawler, HTTP
503 обходил action callback и ложно помечал rTorrent offline, а JS использовал
несуществующий `cantFetchInfo`.

Подтверждены production-reachable границы:

1. same-second handover collision;
2. ignored false/short write;
3. unquoted/unobserved detached launch;
4. unbounded и невалидированный raw body;
5. один `Throwable` обрывает остаток batch и cleanup;
6. invalid handover/cleanup refusal не имеют classified lifecycle;
7. `{}` не различает queued и handled dispatch refusal.

PHP object injection через `unserialize(..., allowed_classes => false)`
опровергнута. Cross-user permission protocol недостижим в штатном manual route,
поскольку producer/worker запускаются под одной OS identity.

Successor имеет ровно шесть focused paths:

- `plugins/rutracker_check/action.php`;
- `plugins/rutracker_check/batch_check.php`;
- новый `plugins/rutracker_check/launcher.php`;
- manual-response hunk в `plugins/rutracker_check/init.js`;
- новый `tests/plugins/rutracker_check/ManualEntrypointsTest.php`;
- новый focused `tests/plugins/rutracker_check/init.spec.js`.

Пакет функционально независим от P0/P1. P1 может позже reuse launcher и добавить
собственный crawl hunk. Exclude: `MAX_HASHES=4096`, raw exception text,
`spawnCrawl()`, HTTP 503, aggregate test, run registry и upstream #3218
`init.php`.

## 5. Foreign tracker handlers

Итоговый independently approved split:

```text
Kinozal   2 paths   +260/-146 current snapshot
NNMClub   2 paths  +1142/-231 current snapshot
siblings  5 paths   +606/-32  current snapshot
aggregate 9 paths  +2008/-409 current snapshot
```

Это три sibling-пакета от final P1 tip, а не serial chain. P0/P1 владеют
`STE_DECLINED`, bounded bencode, одной metainfo parse и replacement
transaction. Handler packages не копируют эти owners.

Independent review нашёл пропущенную production boundary: AniDUB/Tfile
handlers используют HTTP, тогда как loginmgr accounts намеренно HTTPS-only.
Из-за этого session не прикладывается, а topic/download/metainfo допускают
подмену на пути. Поправка принята в том же 5-путевом sibling-пакете:
canonical outbound HTTPS для обоих hosts, canonical HTTPS AniDUB input и RED
для любого возврата к HTTP. Четвёртого пакета и loginmgr path не добавляется;
финальный sibling numstat поэтому ещё не известен.

Полный handler brief и independent evidence вынесены в
`REVIEW-foreign-tracker-handlers-2026-08-29.md`.

## Итоговый вердикт

Все пять audit slots имеют полноценную disposition. Очередь больше не содержит
`pending carve/verdict audit`: остаётся 18 конкретных implementation packages.
Любой новый scope появляется только из отдельного current-base finding и не
может быть добавлен пересчётом исторического fork diff.
