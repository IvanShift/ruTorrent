# `up/xmlrpc-proxy-policy` — independent design review

Дата: 2026-08-29. Current upstream: `755404f3`. Предполагаемый parent — final
`up/httprpc-refusals`. Fork и прежние findings использовались как гипотезы;
review/probes были read-only.

## Verdict: CHANGES REQUIRED on the same seven-path boundary

`branch` действительно опасен, но branch-only policy неполон. На supported
rTorrent 0.9.8 ещё семь direct evaluators интерпретируют XMLRPC strings как
команды. Кроме того, mixed d/t/f/p multicall может спрятать executable grammar
в `$command=...` или `(command,...)`; проверка outer `commandName()` видит лишь
безобидный `cat`. `system.multicall` оборачивает тот же bypass. Ещё четыре real
dot-load methods (`load.verbose`, `load.start_verbose`, `load.raw_verbose`,
`load.raw_start_verbose`) отсутствуют в current sanitizer и уходят raw/untrusted;
на 0.9.8 их trailing command исполняется.

Minimal correction не пишет второй parser rTorrent grammar. В `sanitize` весь
direct command-carrying multicall отклоняется, если хотя бы один member нельзя
пересобрать из configured safe names. `system.multicall` отклоняет nested
command-carrying/wrapper members. Не rebuilt command-carrying/mixed raw payload
никогда не пересылается; unrelated unknown-method fallback остаётся явно
version-sensitive policy и не выдаётся за legacy-safe.

## Frozen paths and dependencies

Relative to final package 3:

1. `conf/xmlrpc_proxy.php`;
2. `php/xmlrpc_proxy.php`;
3. `plugins/httprpc/action.php`;
4. `plugins/httprpc/conf.php`;
5. `tests/php/XMLRPCProxyTest.php`;
6. `tests/php/XMLRPCProxyContractFixture.php`;
7. `tests/php/XMLRPCProxyEntrypointTest.php`.

Path 7 создаётся prerequisite `up/httprpc-refusals`, поэтому raw-upstream
numstat не является package delta. Новые resolver, source fixture,
`rpc2.php`, `php/xmlrpc_path.php`, erasedata или SCGI paths не нужны.

Ordering подтверждён:

- immediate parent — final `up/httprpc-refusals`;
- later `up/httprpc-erasedata-contract` ждёт proxy-policy и A;
- SCGI, erasedata A и alias-surface не prerequisites этого package;
- PHP74 — full-harness qualification caveat, не production dependency.

## Reachability and version boundary

Оба intended proxy doors достижимы для authenticated automation:

- raw/default branch `plugins/httprpc/action.php`;
- operator-published `rpc2.php` exact FastCGI endpoint.

Оба вызывают `XMLRPCProxy::decide()` и передают resulting trust bit в SCGI.

На rTorrent 0.9.8 `UNTRUSTED_CONNECTION` не проверяется command map. Direct
evaluators и nested command grammar реально исполняются. На verified 0.16.14 и
0.16.21 `branch`/`and`/`or`/`catch` проходят outer safe gate, но unsafe nested
dispatch отвергается inner trust check; `try`, comparators и `match`
отвергаются outer dispatch. Proxy-side denial всё равно нужен для supported
legacy behavior и детерминированного результата двух doors. `passthrough_unsafe` остаётся
осознанным trusted operator mode; `off` отвергает всё.

## Literal verdicts

| Claim | Вердикт | Основание |
|---|---|---|
| exact seven paths relative final package 3 | **ПОДТВЕРЖДЁН** | Все corrections помещаются в существующие policy/config/tests. |
| immediate httprpc dependency | **ПОДТВЕРЖДЁН** | Общие action/rejection/test hunks. |
| common proxy config импортируется httprpc | **ОПРОВЕРГНУТ** сейчас; defect **ПОДТВЕРЖДЁН** | Plugin conf redeclares defaults и игнорирует common. |
| common → plugin fallback → local → per-user precedence достижима | **ПОДТВЕРЖДЁН** | `FileUtil::getPluginConf()` ordering и unset-only defaults. |
| stock root boundary meaningful при `$topDirectory='/'` | **ОПРОВЕРГНУТ** | `/` допускает любой absolute directory setter. |
| independent root opt-in нужен | **ПОДТВЕРЖДЁН** | Root=false должен strip unbounded setter, сохраняя load. |
| root и local-path permission — один switch | **ОПРОВЕРГНУТ** | Они защищают разные URI/data-directory values. |
| allowed local path warning отсутствует | **ПОДТВЕРЖДЁН** | 755 forwards без предупреждения. |
| donor raw-path warning приемлем | **ОПРОВЕРГНУТ** | Утекает caller path/control input. |
| `branch` direct strings исполняются | **ПОДТВЕРЖДЁН** | 0.9.8 `apply_if(flags=1)` парсит strings без trust gate. |
| direct XMLRPC `if` исполняет supplied string | **ОПРОВЕРГНУТ**; executable subpath **НЕДОСТИЖИМ В ПРОДЕ** | Call reachable, но `flags=0`; XMLRPC не создаёт required internal dict-key type. |
| direct XMLRPC `not` исполняет supplied string | **ОПРОВЕРГНУТ**; executable subpath **НЕДОСТИЖИМ В ПРОДЕ** | Call reachable, но исполняется только internal dict-key. |
| direct `compare` исполняет caller fields | **ОПРОВЕРГНУТ**; executable subpath **НЕДОСТИЖИМ В ПРОДЕ** | Call reachable, но execution требует отсутствующий internal target pair. |
| `try` evaluator | **ПОДТВЕРЖДЁН** | 0.9.8 `call_object()` получает XMLRPC string/list. |
| `and`/`or` evaluators | **ПОДТВЕРЖДЁН** | List members проходят `parse_command_single()`. |
| `less`/`greater`/`equal`/`match` evaluators | **ПОДТВЕРЖДЁН** | Operands парсятся на 0.9.8. |
| existing `catch` refusal оправдан | **ПОДТВЕРЖДЁН** | `call_object()` evaluator. |
| branch-only закрывает surface | **ОПРОВЕРГНУТ** | Остаются семь reachable evaluators. |
| current sanitizer покрывает все supported `load.*` command carriers | **ОПРОВЕРГНУТ** | Четыре registered verbose variants дают `send/untrusted/raw` на 755; 0.9.8 исполняет trailing command. |
| missing `load.verbose`/`load.start_verbose` всё равно проходят local-path gate | **ОПРОВЕРГНУТ** | Они попадают в unknown fallback до URI classification и могут назвать daemon-local tied path. |
| raw verbose variants не нуждаются в command-param rebuild | **ОПРОВЕРГНУТ** | Torrent bytes не являются URI, но trailing arguments остаются executable command grammar. |
| evaluator deny должен быть prefix-family | **ОПРОВЕРГНУТ** | Нужен distinct exact-name set. |
| count-only mixed log достаточен | **ОПРОВЕРГНУТ** | Не называет boundary и похож на partial stripping. |
| donor mixed log version-neutral/safe | **ОПРОВЕРГНУТ** | Raw name newline injection, no total bound, ложный daemon prediction. |
| raw mixed forwarding безопасен как untrusted | **ОПРОВЕРГНУТ** | 0.9.8 не имеет trust gate; nested grammar исполняется. |
| outer scan `system.multicall` закрывает bypass | **ОПРОВЕРГНУТ** | Inner member/command strings не rebuilt recursively. |
| shared resolver нужен | **ОПРОВЕРГНУТ** | Door-local `httprpcResolvePath()` остаётся owner. |
| donor `d.custom.set` parser rewrite нужен | **ОПРОВЕРГНУТ** | Ломает #3209/#3211 quoting/escaped-comma contract. |
| donor broad fixture replacement годится | **ОПРОВЕРГНУТ** | Удаляет сотни current rows и закрепляет старое поведение. |
| 0.16.10 trust-boundary warning надо сохранить | **ПОДТВЕРЖДЁН** | Legacy unknown fallback не эквивалентен modern sanitize. |

## Exact evaluator policy

В `sanitize` exact deny set:

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

`if`, `not`, `compare` остаются characterization tests, которые запрещают
ложный overblock. Exact matching отделено от existing versioned family prefixes
вроде `execute*`/`schedule*`.

Exact denial имеет приоритет над operator safe-param configuration: добавление
`branch`, `and` или другого evaluator в safe names не разрешает его ни в load
trailing commands, ни в d/t/f/p multicall. Safe rebuild применяется только
после exact/family refusal.

Каждый reachable evaluator тестируется direct, внутри legal d/t/f/p command
position и `system.multicall`. `passthrough_unsafe` продолжает trusted forward;
`off` terminal rejects до classification.

## Fail-closed mixed grammar

Measured base behavior:

```text
member:   cat=$execute=echo,pwned
decision: send / untrusted / original bytes unchanged
```

На 0.9.8 outer `cat` разбирается, затем `$...` recursively parses/calls inner
command; parenthesized function object тоже вызывается. Outer-name check не
является boundary.

Required structural policy:

1. exact matrix всех восьми registered dot-load methods проходит один rebuild
   owner: URI-backed `load.normal`, `load.start`, `load.verbose`,
   `load.start_verbose` дополнительно проходят local-path policy; raw variants
   `load.raw`, `load.raw_start`, `load.raw_verbose`,
   `load.raw_start_verbose` сохраняют torrent bytes, но rebuild/strip trailing
   command params. Ни один registered load не попадает в unknown raw fallback;
2. direct d/t/f/p multicall в `sanitize`: если rebuild сообщает любой
   unrebuilt/stripped member, reject whole call, zero send;
3. fully rebuilt configured-safe members сохраняют существующий rebuilt path
   только если member не входит в exact evaluator или denied family set;
4. `system.multicall`: reject member `load.*`, d/t/f/p multicall или nested
   `system.multicall`, потому что inner command grammar этим layer не rebuilt;
5. diagnostic: normalized outer names + `members not rebuilt; nothing sent`;
   никаких arguments или daemon outcome claims;
6. arbitrary mixed compatibility доступна только через explicitly configured
   safe rebuild names или `passthrough_unsafe`.

Это закрывает `$...`, `(command,...)`, malformed/escaping variants и wrapper
nesting без parser rewrite. Более сильный claim «все unknown methods безопасны
на 0.9.8» не делается; common config предупреждает legacy operators выбирать
`off`, если остаточный unknown surface неприемлем.

## Config/root/log contract

Load order:

```text
conf/xmlrpc_proxy.php
  -> unset-only httprpc defaults
  -> plugins/httprpc/conf.local.php
  -> conf/users/<user>/plugins/httprpc/conf.php
```

Root matrix:

| top | root opt-in | policy root | setter |
|---|---:|---|---|
| `/srv/downloads` | false/true | `/srv/downloads` | keep only resolved inside |
| `/` | false | `''` | strip, load continues |
| `/` | true | `/` | keep |
| empty/whitespace | false | `''` | strip, load continues |
| empty/whitespace | true | `/` | keep |

Local-path opt-in проверяется независимо во всех четырёх boolean quadrants.

Routine diagnostics:

- one classified line, known method, no caller local path;
- CR/LF/tab/control flattened;
- total byte bound, not only per-name bound;
- omitted-count marker for long lists;
- mixed refusal says proxy rejected and nothing sent;
- no promise whether daemon would accept/refuse;
- raw request остаётся доступен только через on-demand core transcript.

## Upstream behavior that must survive

Не удалять/переписывать tests и parser behavior #3209/#3211:

- pre-quoted value remains one argument;
- cross-seed quoted load params;
- quoted dollar-prefixed argument is dropped;
- quoted directory inside boundary;
- escaped comma does not split, including trailing escaped comma;
- unescaped comma splits;
- backslash not before comma remains.

До/после сравнивается полный SET test names; non-empty check недостаточен.

## Natural RED

На exact final package-3 tip:

1. common `off` и changed safe-param set reach copied real action;
2. common/local/per-user precedence;
3. root `/` + root=false strips arbitrary directory setter, load survives;
4. root/local four quadrants, lexical/resolver escapes;
5. local warning exists, one-line/bounded и не содержит path;
6. exact current-base natural RED для восьми sends — `branch`, `try`, `and`,
   `or`, `less`, `greater`, `equal`, `match`: terminal reject/zero-send после
   fix. `catch` уже rejected на 755 и остаётся preservation gate;
7. all eight registered dot-load methods sanitize trailing `execute=...`;
   URI-backed verbose variants reject local path unless opt-in, raw verbose
   variants preserve exact torrent bytes but never raw-forward command params;
8. direct d/t/f/p `$execute` и `(execute,...)` reject whole call;
9. same nested through `system.multicall`, включая каждый load family,
   rejects;
10. exact-name matcher не превращается в prefix;
11. mixed log names normalized methods и says nothing sent under total cap;
12. copied real httprpc action и rpc2 door refusals terminal
    403/-501/named/zero-send;
13. complete #3209/#3211 parser set remains GREEN.

## Required mutations

- remove/misorder common import or overwrite later config;
- hard-code root `/`, couple root/local switches or replace door resolver;
- omit any of four verbose dot-load methods, send one through unknown fallback,
  skip local-path check for URI verbose load, ошибочно применить URI gate к raw
  variants или изменить raw torrent bytes;
- remove each evaluator family; add unreachable `if`/`not`/`compare`;
- разрешить exact-denied evaluator через configured safe-param set;
- implement exact evaluator as prefix;
- inspect only outer `cat`, miss parenthesized grammar/family/wrapper;
- пропустить executable filter command slot `d.multicall.filtered`;
- forward raw payload after classifying rejection;
- reject evaluator in `passthrough_unsafe`;
- log caller path/raw arg/control newline or omit total cap;
- revert mixed reason to count-only/daemon prediction;
- restore donor parser/fixture and lose quote/escaped-comma cases;
- remove package-3 terminal exits or re-conflate false/empty.

Каждая mutation обязана показать named test execution, intended RED, no fatal и
fresh GREEN after restore.

## Verification and numstat

- PHP 7.4/8.5 lint all seven paths;
- focused proxy/contract/copied-entrypoint tests on 7.4/8.1/8.5;
- full harness 8.1/8.5, PHPStan;
- controlled non-live 0.9.8 characterization, если lab runtime доступен;
- full 7.4 только после `up/php74-torrent-properties`, иначе exact base fatal
  раскрывается как prerequisite limitation;
- no live mutating probe.

Donor aggregate `+368/-950` запрещён как target: почти весь минус — stale
fixture deletion/parser regression. После final httprpc измерять exact range
`<final-httprpc-tip>..HEAD` по seven paths, сравнить full test-name set,
`--name-status`, `--numstat`, `--stat`, `--check`, cached scope и independent
whole-file review.

До фиксации этого corrected brief и повторного independent review package
остаётся **CHANGES REQUIRED**.
