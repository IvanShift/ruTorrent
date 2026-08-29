# Foreign tracker handlers — independently reviewed brief

Дата: 2026-08-29. База: `upstream/master=755404f3`; donor behavior snapshot:
`511ed13f`. Девять путей independently перемерены, focused suites и три
production mutations повторены в disposable export. Live tracker, daemon,
repository, branches и remotes не мутировались.

## Verdict

Девятипутевый bucket production-reachable и upstreamable, но не является одним
PR. Финальный split после независимой перепроверки — **APPROVED**:

| Пакет | Production | Test | Current snapshot |
|---|---|---|---:|
| `up/kinozal-checker-resilience` | `trackers/kinozal.php` | `KinozalHandlerTest.php` | `+260/-146` |
| `up/nnmclub-checker-live-contract` | `trackers/nnmclub.php` | `NNMClubHandlerTest.php` | `+1142/-231` |
| `up/sibling-tracker-verdicts` | `trackers/{anidub,tapocheknet,tfile,toloka}.php` | `SiblingTrackersTest.php` | `+606/-32` |

Все три строятся как siblings от final P1 tip, который следует после P0:

```text
P0 replacement transaction -> P1 checker orchestration/bencode
                             |-> Kinozal checker resilience
                             |-> NNMClub live contract
                             `-> sibling tracker verdicts
```

Current snapshot `9 paths, +2008/-409` измерен точно, но не является final
branch target. Sibling numstat изменится после обязательной HTTPS-поправки.

## Ownership boundary

Handler packages потребляют, но не реализуют заново:

- P0: одна metainfo parse, parsed-object seam, destructive replacement и
  confirmation/recovery;
- P1: `STE_DECLINED`, dispatcher, classified fetch status и один bounded
  bencode grammar.

На чистом current base copied handlers PHP-lint, но не runnable/standalone:
нужные P0/P1 constants/files/methods отсутствуют. Поэтому whole-file copy до
prerequisites запрещён.

Не входят: loginmgr storage/account implementation, scheduler/entrypoints,
retrackers, erasedata, history, Docker, task docs, `run.php`, второй bencode
decoder или второй metainfo parser. P1 либо existing sibling test обязан иметь
non-vacuous witness, что real `check.php` включает и регистрирует все семь
handlers.

## Kinozal residual

Upstream #3176 уже содержит базовый login-wall/latch behavior; ready Kinozal
session branch отдельно владеет loginmgr route. Residual handler package
добавляет:

- exact missing-topic deletion signal;
- retryable redirect/login/empty/unparseable/5xx/transport outcomes;
- независимые consecutive-guest counters для details и download endpoints;
- recovery после одиночного guest blink без преждевременного конца цикла;
- reset только соответствующего streak и передача уже parsed metainfo в P0.

Repository fixtures сохраняют live captures от 2026-08-07 и fleet observation
с recovery через три секунды. Это provenance сохранённого capture, а не новая
live-проверка в этом аудите.

## NNMClub residual

Upstream #3175 уже содержит own-passkey foundation. Residual package требует:

- credential только из typed announce текущего torrent и только на official
  host;
- сохранение path/query credential формы;
- bounded structural scrape decode с canonical non-negative validation каждого
  присутствующего counter, но без требования любого optional counter;
- принятие exact captured 67-byte ответа с `complete=159`, `incomplete=1` и без
  `downloaded`;
- unreadable HTTP-200 не авторизует verdict, но передаёт authority guest
  download; transport failure остаётся retryable;
- одна metainfo parse и тот же parsed object на replacement boundary.

Прежнее обязательное поле `downloaded` реально остановило все NNMClub checks;
spec-derived fixture не может заменить сохранённый live answer.

## Sibling verdicts и HTTPS/session

AniDUB, Tapochek, Tfile и Toloka не могут превращать curl/socket error,
HTTP 403/429/5xx или malformed HTTP-200 download в `STE_DELETED`. Удаление
разрешает только tracker-specific structural signal; остальные owned-but-
unreadable outcomes retryable.

Пакет также владеет strict infohash comparison, escaped host boundaries,
AniDUB quality parsing/returns и Toloka hash-vs-download-link separation.

Независимая перепроверка добавила обязательный reachable fix в те же пять
путей:

- принимать canonical HTTPS AniDUB topic; legacy HTTP input допустим только с
  немедленным upgrade всех outbound requests;
- отправлять AniDUB topic/metainfo только на `https://tr.anidub.com`;
- отправлять Tfile topic/metainfo только на `https://megatfile.cc`;
- доказывать, что HTTPS URL выбирает уже-upstream loginmgr account и ни один
  topic/metainfo request не уходит по HTTP.

Иначе HTTPS-only loginmgr account не прикладывает session к HTTP handler, а
сетевой посредник может изменить topic hash, download link или metainfo. P0
проверяет структуру/ownership, но не аутентифицирует plaintext response.

## Fresh evidence

Host PHP 8.5 focused baselines:

- Kinozal: 21 tests, 0 failures;
- NNMClub: 25 tests, 0 failures;
- siblings: 14 tests, 0 failures.

Независимо повторены три one-at-a-time mutation RED без fatal/parse/uncaught:

- NNMClub требует все три counters: 25 tests, 2 failures, включая exact live
  answer;
- Kinozal объединяет endpoint streaks: 21 tests, 1 named failure;
- Tfile снова считает positive status deletion: 14 tests, 2 failures.

После каждого restore suites возвращались к `21/0`, `25/0`, `14/0`, а
production files были byte-identical donor snapshot.

Обязательные дополнительные HTTPS mutations: вернуть HTTP отдельно в AniDUB и
Tfile либо отклонить canonical HTTPS AniDUB comment; named focused test должен
стать RED после своего start marker.

## Closure gates

Каждая из трёх веток требует RED-first carve на final P1, mutation каждой
load-bearing ветви, PHP 7.4/8.1/8.5 checks, PHPStan 2.2.9, complete non-empty
test-name count, exact diff/scope, whole-file independent review и отдельные
task/PR texts. Агент не выполняет push.
