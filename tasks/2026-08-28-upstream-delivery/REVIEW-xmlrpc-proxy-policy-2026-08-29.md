# `up/xmlrpc-proxy-policy` — закрытый технический контракт

Дата: 2026-08-29. Current upstream: `755404f3`. Immediate parent будущей
ветки — final `up/httprpc-refusals`. Fork и прежние findings использовались
только как гипотезы; registration и execution paths перепроверены по исходникам
rTorrent 0.9.8 (`6154d169`) и 0.16.21 (`109a20c0`), а current behavior — в
immutable PHP-контейнерах.

## Verdict: DESIGN APPROVED — implementation pending

Seven-path boundary подтверждена. Открытых policy-решений больше нет. В
`sanitize` proxy не пытается написать второй parser rTorrent grammar:

- все восемь зарегистрированных `load.*` проходят одного canonical rebuild
  owner;
- девять direct evaluators, два direct directory setters и source-verified legacy command carriers
  terminally отклоняются;
- шесть direct multicall допускаются только при полном rebuild каждого
  command/data slot;
- `system.multicall` отклоняется целиком;
- arbitrary **trusted** passthrough остаётся только в явно опасном
  `passthrough_unsafe`; `sanitize` сохраняет лишь well-formed ordinary-method
  compatibility как raw/untrusted.

Код этого контракта ещё не реализован. Текущие зелёные donor tests являются
characterization, а не GREEN исправленного policy.

## Frozen paths and dependencies

От exact final tip `up/httprpc-refusals` пакет меняет ровно семь путей:

1. `conf/xmlrpc_proxy.php`;
2. `php/xmlrpc_proxy.php`;
3. `plugins/httprpc/action.php`;
4. `plugins/httprpc/conf.php`;
5. `tests/php/XMLRPCProxyTest.php`;
6. `tests/php/XMLRPCProxyContractFixture.php`;
7. `tests/php/XMLRPCProxyEntrypointTest.php`.

Path 7 создаёт prerequisite. Поэтому raw fork/upstream aggregate и текущий
numstat не являются package delta; final numstat измеряется только после
final httprpc tip.

Не входят: `rpc2.php`, `php/xmlrpc.php`, `php/xmlrpc_path.php`, SCGI transport,
erasedata, новый resolver и `tests/php/XMLRPCProxyContractTest.php` — последний
исполняет изменяемую fixture, но сам правки не требует. Поздний
`up/httprpc-erasedata-contract` ждёт одновременно этот tip и erasedata A из-за
общего `plugins/httprpc/action.php`.

## Production reachability and version boundary

Оба intended authenticated proxy doors вызывают `XMLRPCProxy::decide()`:

- default/raw branch `plugins/httprpc/action.php`;
- operator-published exact FastCGI `rpc2.php` endpoint.

Оба обязаны передать `decision['trusted']` в фактический SCGI send без
recomputation. На rTorrent 0.9.8 `UNTRUSTED_CONNECTION` не защищает command map;
на 0.16.x часть nested calls повторно проверяется daemon-ом. Proxy-side denial
нужен именно для supported legacy behavior и одинакового результата двух
doors. `off` отвергает всё; `passthrough_unsafe` остаётся сознательным trusted
operator escape hatch.

## Перепроверенные verdicts

| Гипотеза | Вердикт | Основание |
|---|---|---|
| exact seven paths relative final httprpc | **ПОДТВЕРЖДЕНО** | Все corrections принадлежат существующим config/policy/door/tests. |
| common config сейчас импортируется httprpc | **ОПРОВЕРГНУТО**; defect подтверждён | Plugin conf redeclares defaults и игнорирует common policy. |
| common → unset-only defaults → local → per-user precedence достижима | **ПОДТВЕРЖДЕНО** | Existing plugin config loader уже задаёт последние два слоя. |
| `$topDirectory='/'` сам по себе confine-ит setters | **ОПРОВЕРГНУТО** | `/` разрешает любой absolute output directory. |
| root и local-torrent-path permission — один switch | **ОПРОВЕРГНУТО** | Они разрешают разные URI/output capabilities. |
| `branch` — единственный direct evaluator | **ОПРОВЕРГНУТО** | На 0.9.8 ещё восемь exact evaluator names принимают caller objects. |
| direct `if`/`not`/`compare` исполняют обычные XMLRPC strings | **ОПРОВЕРГНУТО; executable subpaths НЕДОСТИЖИМЫ В ПРОДЕ** | Требуются flags/internal dict-key/target-pair types, которых direct XMLRPC не создаёт. |
| девять evaluators исчерпывают legacy surface | **ОПРОВЕРГНУТО** | Найдены `p.call_target`, `directory.watch.*` и шесть persistent `view.*` carriers. |
| current sanitizer покрывает все dot-load | **ОПРОВЕРГНУТО** | Четыре verbose variants уходят raw/untrusted с trailing grammar. |
| outer command-name scan делает mixed multicall безопасным | **ОПРОВЕРГНУТО** | `$command=...`, `(command,...)` и filter slot исполняют inner grammar. |
| selective scan делает `system.multicall` boundary | **ОПРОВЕРГНУТО** | Recursive structs сохраняют scanner/parser differential и nested carriers. |
| malformed XML/no method имеет compatibility value | **ОПРОВЕРГНУТО** | Terminal reject: без structural method policy применить невозможно. |
| well-formed ordinary unknown raw/untrusted допустим | **ПОДТВЕРЖДЕНО В SUPPORTED BOUNDARY** | 0.9.8 command map перепроверена по исходникам; modern daemon добавляет trust gate. Будущая неизвестная версия требует новой проверки. |
| broad donor fixture/parser replacement годится | **ОПРОВЕРГНУТО** | Теряет current upstream #3209/#3211 parser contracts. |
| отдельный shared path resolver нужен | **ОПРОВЕРГНУТО** | Door-local resolver остаётся owner; filesystem identity принадлежит A. |

## Exact method/trust matrix

| Method class | `sanitize` result | Payload / trust |
|---|---|---|
| любой method при `off` | terminal reject | empty, `trusted=false`, zero SCGI send |
| любой method при `passthrough_unsafe` | raw forward | original bytes, `trusted=true` |
| 8 dot-load methods | canonical rebuild | retained data re-emitted; unsafe trailing commands removed; trusted iff every retained value rebuilt |
| URI loads: `load.normal`, `load.start`, `load.verbose`, `load.start_verbose` | local URI terminally rejected без opt-in | network/magnet или allowed local URI затем rebuild |
| Raw loads: `load.raw`, `load.raw_start`, `load.raw_verbose`, `load.raw_start_verbose` | no URI gate | decoded torrent bytes preserved exactly after canonical base64 re-emission; trailing grammar removed |
| unsupported underscore `load_start/load_raw_start/load_raw` | ordinary unknown | raw/untrusted; обе target versions их не регистрируют |
| direct evaluator/carrier | terminal reject | empty, zero send |
| `if`, `not`, `compare` | no overblock | ordinary untrusted fallback, если другая structural rule не сработала |
| 6 direct multicalls | all-or-nothing rebuild | любой unrebuilt slot terminally rejects outer call; original bytes never fallback-forward |
| `system.multicall` | unconditional terminal reject | empty, zero send |
| exact elevated method, valid shape | canonical rebuild | `trusted=true` |
| exact elevated method, invalid shape | terminal reject | empty, zero send; owned method никогда не raw-forward-ится |
| direct `d.directory.set` / `d.directory_base.set` | terminal reject | empty, zero send; directory разрешается только как rebuilt load/multicall command slot |
| malformed XML / no method | terminal reject | empty, zero send |
| иной well-formed unknown method | compatibility fallback | original raw/untrusted; только source-verified supported-version boundary |

Deny precedence фиксирован:

1. mode;
2. direct exact/family refusal, включая оба directory setters;
3. structural load/multicall owner;
4. configured safe names;
5. elevated exact-shape owner;
6. unknown fallback.

Configured safe name никогда не разрешает evaluator/carrier. В load denied
trailing parameter удаляется, а сам load продолжается; в direct multicall
невозможность rebuild хотя бы одного slot отклоняет весь outer call.

## Exact evaluator and carrier policy

Exact evaluator deny set:

```text
catch
branch
try
and
or
less
greater
equal
match
```

Matching exact, не prefix. `if`, `not`, `compare` и suffix-like names являются
обязательными no-overblock tests.

Дополнительные source-verified carriers:

```text
exact:  p.call_target
prefix: directory.watch.
exact:  view.filter
        view.filter.temp
        view.sort_new
        view.sort_current
        view.event_added
        view.event_removed
```

`p.call_target` вызывает caller command непосредственно. Directory/view methods
хранят caller command для последующего watch/filter/sort/event execution. Prefix
`directory.watch.` покрывает `added` на 0.9.8 и `added/ready` на modern daemon.
Обычные `view.filter_on`, `view.sort` и `view.set` в этот carrier set не входят.

Отдельный exact direct deny set:

```text
d.directory.set
d.directory_base.set
```

Это не удаляет setters из configured safe parameters. Они остаются допустимы
только как command slots внутри canonical rebuilt load/direct-multicall, где
path проходит directory boundary owner. Прямой XMLRPC setter нельзя безопасно
оставить unknown raw/untrusted: rTorrent 0.9.8 игнорирует trust header и исполнит
его вне `$topDirectory`.

Post-fix prefix deny set фиксирован буквально:

```text
execute
method.
import
try_import
schedule
log.
network.scgi
session.path.set
directory.default.set
system.env
system.shutdown
```

`catch`, `branch` и остальные evaluators сюда не входят: они проверяются exact,
иначе prefix matching ошибочно заблокирует `if` и suffix-like ordinary names.
Exact evaluator set дополняет, но не расширяет эти prefix rules.

Exact elevated structural set также не оставляется implementation decision:

| Methods | Canonical shapes |
|---|---|
| `d.open`, `d.start`, `d.stop`, `d.delete_tied` | `hash` |
| `d.custom1.set` … `d.custom5.set` | `hash, text` |
| `d.custom.set` | `hash, text, text` |
| `d.priority.set` | `hash, int` |
| `network.xmlrpc.size_limit.set` | `empty, size` |

`hash` — canonical 40 hex re-emitted uppercase; `text` — XMLRPC string data,
не command grammar; `int` — signed decimal до 18 digits; `size` — positive
decimal до 18 digits, canonical value clamp-ится к `16,777,216`. Любое другое
число/тип/количество/shape у этих двенадцати exact methods terminally rejected,
а не переходит в raw/untrusted fallback.

## Canonical load and multicall ownership

Все восемь registered dot-load проходят один owner:

- первые два value slots canonical re-emitted;
- URI variants используют independent local-path gate;
- raw variants не интерпретируют metainfo bytes как URI;
- каждый trailing command разбирается существующим accepted parser и либо
  canonical rebuild-ится из configured safe name/arguments, либо удаляется;
- denied evaluator/carrier имеет приоритет над safe-name config;
- raw torrent bytes после base64 decode/re-encode должны совпадать exactly.

Если owned load нельзя полностью разобрать и canonical re-emit-ить, outer call
terminally отклоняется. Его исходные bytes не переходят в unknown fallback.

Direct multicall set:

```text
d.multicall
d.multicall2
d.multicall.filtered
t.multicall
f.multicall
p.multicall
```

Для `d.multicall.filtered` command filter после target/view — такой же executable
slot, как result commands. Fixture обязана содержать одновременно filter и хотя
бы один result member. `$execute=...`, parenthesized functions, malformed
escaping или любой unknown/unrebuildable member дают outer reject, empty payload
и zero send.

`system.multicall` в `sanitize` всегда terminally rejected, включая benign
members. Selective recursion запрещена: честная альтернатива потребовала бы
canonical recursive parser/rebuilder всех struct shapes, что противоречит
выбранному minimal/no-second-parser design. Compatibility доступна через
`passthrough_unsafe`.

## Config, root and door contract

Load order:

```text
conf/xmlrpc_proxy.php
  -> unset-only plugins/httprpc/conf.php defaults
  -> plugins/httprpc/conf.local.php
  -> conf/users/<user>/plugins/httprpc/conf.php
```

Root behavior намеренно асимметрично, потому что `rpc2.php` не входит в scope:

| `$topDirectory` | root opt-in | httprpc | rpc2 |
|---|---:|---|---|
| bounded path | false/true | setters только внутри resolved boundary | то же |
| `/` или empty | false | directory setter stripped, load survives | existing pre-decision HTTP 503 |
| `/` или empty | true | policy root `/`, unbounded output explicitly accepted | endpoint enabled с root `/` |

`$XMLRPCProxyAllowLocalPaths` отдельно разрешает daemon-local torrent URI и не
включает root output permission. Нужны все четыре boolean quadrants. Во всех
строках direct `d.directory.set` и `d.directory_base.set` rejected; таблица
описывает только setters внутри canonical rebuilt load/direct-multicall.
Outside-boundary либо root-disabled setter в load удаляется, а safe base load
продолжается; тот же slot в direct multicall отклоняет весь outer call.

## Reject, fault and log contract

Каждый policy reject возвращает:

```text
action=reject
payload=''
trusted=false
```

Оба doors отвечают HTTP 403, XMLRPC fault `-501`, explicit exit и zero SCGI
send. Client-visible method identity — только normalized outer method: direct
deny называет direct method; nested direct multicall называет outer multicall;
`system.multicall` называет `system.multicall`. Attacker-controlled inner name
не попадает в fault/log.

Routine diagnostic contract:

- ровно одна classified record на decision;
- formatted proxy message максимум 512 bytes до timestamp/logger prefix;
- method component максимум 96 bytes;
- допустимые method bytes `[A-Za-z0-9_.:-]`, остальные заменяются `?`;
- payload, argument, URI/path, raw remote fault и guessed daemon outcome
  запрещены;
- truncation/omitted-count marker входит в 512-byte bound.

Raw transcript остаётся только on-demand core diagnostic. Empty body/read/
transport outcomes принадлежат predecessor/SCGI и здесь не переопределяются.

## Upstream parser behavior that must survive

Полный десятиметодный #3209/#3211 set сохраняется:

```text
testPreQuotedValueIsKeptAsOneArgument
testCrossSeedQuotedLoadParamsAreKept
testQuotedDollarPrefixedArgumentIsDropped
testUnclosedQuoteIsDropped
testQuotedDirectoryInsideTheBoundaryIsKept
testAnEscapedCommaDoesNotSeparateArguments
testAnEscapedCommaAtTheEndOfAValueIsKept
testAnUnescapedCommaStillSeparates
testABackslashThatIsNotBeforeACommaIsKept
testATrailingBackslashIsStillJustAValue
```

До/после сравнивается полный SET test names и fixture keys с exact count;
non-empty guard недостаточен. Measured immutable predecessor baseline:
`XMLRPCProxyTest` — **79 unique methods**, contract fixture — **70 top-level
cases** (`192` и `845` passed assertions соответственно на PHP 7.4). RED branch
до implementation отдельно замораживает полный expected name/key set и его
новый exact count. Current fork aggregate удаляет часть upstream methods и
поэтому не переносится whole-file.

## RED, GREEN and mutations

На exact final predecessor tip natural RED обязаны показать:

1. четыре verbose load raw-forward executable trailing parameter;
2. восемь ещё не denied evaluators; `catch` остаётся preservation GREEN;
3. direct legacy carriers проходят proxy;
4. `$...` и parenthesized grammar raw-forward в каждом direct multicall;
5. unsafe `d.multicall.filtered` filter проходит;
6. benign и malicious `system.multicall` forwarding вместо terminal policy;
7. common config/root matrix не соблюдается;
8. caller path/control bytes или unbounded data попадают в diagnostic.
9. direct `d.directory.set` и `d.directory_base.set` проходят raw/untrusted;
10. invalid-shape exact elevated call проходит raw/untrusted.
11. malformed XML/no method проходит к SCGI.

`if/not/compare`, четыре уже-known load methods и десять parser tests должны
оставаться GREEN на RED base.

После реализации на PHP 7.4/8.1/8.5 обязательны focused
`XMLRPCProxyTest`, fixture contract и copied-real entrypoint suites. PHP 8-only
syntax запрещён. Full harness — 8.1/8.5; full 7.4 после
`up/php74-torrent-properties`, иначе identical prerequisite fatal фиксируется
отдельно. Также PHPStan, exact seven-path lint/diff и whole-file review.

Обязательные mutations, каждая с named executed RED, no preceding fatal и
fresh GREEN after restore:

- удалить каждый load/evaluator/carrier по одному;
- разрешить direct directory setter либо invalid owned/elevated shape raw;
- вернуть malformed/no-method raw fallback;
- убрать common-config import или нарушить unset-only → local → per-user
  precedence;
- выполнить classification до `off` либо применить deny в
  `passthrough_unsafe` вместо short-circuit;
- назвать nested deny inner method в fault/log вместо normalized outer method;
- перепутать URI/raw class или изменить raw metainfo bytes;
- превратить evaluator exact match в prefix и overblock `if/not/compare`;
- разрешить deny через safe config;
- проверять только outer `cat`, пропустить `$...`, parentheses или filter slot;
- разрешить partial direct-multicall raw fallback;
- вернуть selective/raw-forward `system.multicall`;
- потерять/invert trust bit в любом door;
- связать root/local switches или hard-code `/`;
- удалить terminal exit;
- показать inner method/path/argument/control bytes или превысить 512 bytes;
- потерять любой из десяти upstream parser tests.

Disposable runtime gates на supported oldest и 0.16.21: exact trust-header
capture, eight loads, evaluator/carrier zero-send, all-or-nothing direct
multicalls и unconditional `system.multicall` refusal. Mutating probe на live
service запрещён.

## Approval boundary

Design и exact ownership scope утверждены. Implementation branch ещё нет.
Нельзя называть package готовым до witnessed natural RED, corrected GREEN,
mutations, exact range `<final-httprpc-tip>..HEAD`, full test-name/fixture-key
checks и independent whole-file review.
