# Пакет №5 — финальная реализация и проверка

Этот ранний checkpoint `153f8e45` заменён последующим независимым review,
исправлениями `fde65fe5`/`1c810568` и интеграцией `66571370` в master.
Актуально: **CLOSED LOCALLY / INTEGRATED**, не FINAL REVIEW PENDING.
Все результаты ниже сохранены как исторические; дальнейшие fixes и свежие
проверки: [PACKAGE-5-14-INTEGRATION-2026-09-05.md](PACKAGE-5-14-INTEGRATION-2026-09-05.md).

Дата: 2026-09-05. Область: только `retrackers-recovery`.

**Код: IMPLEMENTED / VERIFIED LOCALLY. Формальная приёмка: FINAL REVIEW PENDING.**
Commit выполнен со штатным pre-commit. Работа остановлена; новый независимый
review не запускался по действующему запрету на новых агентов. Это оставшийся
review-gate, а не незавершённая реализация и не разрешение считать его пройденным.

Это актуальный результат Codex, заменяющий первоначальное
`READY_FOR_CODEX_REVIEW` в [старом отчёте](PACKAGE-5-IMPLEMENTATION-REPORT.md).
Исходные заявления внешнего исполнителя не использовались как доказательство.

## Git и границы

- Worktree: `/home/dev/Documents/my_projects/ruTorrent/.worktrees/up-retrackers-recovery`.
- Ветка: `up/retrackers-recovery`.
- База этой завершающей правки: `8fa05ad2423a7da2b75d8b445ba474a5c7bfe388`.
- Финальный commit: `153f8e459e834925a6824acff346439e96c93ef9`
  (`fix(retrackers): finish verified recovery worker`).
- Штатный pre-commit повторно выполнил полный PHP harness и завершился успешно;
  hook не отключался. Рабочее дерево после commit чистое.
- Изменены только `plugins/retrackers/update.php` и
  `tests/plugins/retrackers/UpdateTest.php`.
- Ранее принятые исправления lifecycle, staging, atomic erase и fenced
  candidate/cleanup/rollback сохранены в ancestry. Remediation-range от
  `4795cdd1` затрагивает четыре разрешённых файла; за границу шести путей
  пакет не выходит.
- Основной checkout остаётся `master=72d1885ca02358bb1420d1a812450b61e77521f7`.
  Его чужие изменения, архивы логов и fixtures не тронуты.
- Merge, rebase, fetch, push, PR, deploy и изменения production не выполнялись.
- Новые агенты не запускались; пакеты №6/№14 и прочие не проверялись и не менялись.

## Что действительно исправлено

1. Worker принимает shipped binary flags строго как `0/1`, `"0"/"1"` или
   boolean; не полагается на PHP truthiness. Неверные значения закрываются
   классифицированным отказом с безопасным release/cleanup.
2. `dontAddPrivate` и точное `<UPPERCASE_HASH>.meta` проверяются по
   аутентифицированному stable snapshot до чтения metainfo/staging/erase.
3. Полная orchestration-цепочка проверена через настоящий `run()`:
   adopt → snapshot → prepare → stage/preflight → arm/erase/reconcile →
   candidate → при необходимости owned cleanup/rollback → release → retirement.
   Callable legacy worker и отдельный `d.start` удалены.
4. Scheduler выбирается согласованной парой: `schedule`/`schedule_remove`
   на старой family, `schedule`/`schedule.remove` на новой. Deprecated `*2`
   canonicalize; неизвестные и смешанные пары не допускаются.
5. RR4 comparator сравнивает одинаковые смещения обоих записей. Ошибка
   `substr_compare()` воспроизведена на 3 и 17 строках, а не только на двух.
6. В lifecycle/adoption/deferred replay исправлено реальное значение `not`:
   quoted command string не исполняется. Проверки отсутствия используют
   command object, например `not=(method.has_key,rr.receipts.v1,...)`.
7. Обе daemon-side `execute.capture` preflight-команды передают пустой
   XMLRPC target перед executable/argv. Реальная procfd-проверка проходит
   в том же UID/PID окружении, где работает тестовый rTorrent.
8. Native `d.local_id` нового объекта не равен info-hash. Готовность
   подтверждается fresh snapshot, marker/ack и hashing verdict; release
   использует захваченный native ID. После захвата смена ID — foreign generation.
9. Cleanup по-прежнему заранее измерен и подготовлен до erase. Два 40-байтовых
   CAS-слота компилируются дифференциальным render до mutation и затем связываются
   с захваченным ID без повторной сериализации. Ни XML target, ни совпавший
   hash-shaped текст пользовательских metadata не подменяются. Размер wire
   неизменен; reserve учитывает дополнительный pre-erase render.
10. Creation fault нового объекта оставляет `is_active=0,is_open=0`, даже
    если старый объект был активен и открыт. Intended `state` сохраняется.
    Копирование old open/active flags в допустимый prefix мешало rollback —
    это воспроизведено и исправлено на обеих версиях.
11. Пустой tracker-list не передаётся в `math.cnt`/`math.add`: нулевое число
    аргументов вызывает daemon fault. Для точной пустой topology достаточно
    `not=(t.multicall,,cat=1)`; исправлены load assertion и cleanup CAS.
12. Убрано повышение memory limit. CAP и retained-state проверяются отдельными
    короткими дочерними процессами под буквальным `memory_limit=128M`.

Пункты 6–11 найдены именно при исполнении production callbacks на настоящих
тестовых демонах. Старые mock-ожидания, которые противоречили измерениям,
исправлены с сохранением имён тестов; guards не заменены безусловным разрешением.

## Проверки

| Проверка | Результат |
|---|---|
| PHP 7.4.33, literal 128M | 217 методов / 1044 assertions; 0 failed methods |
| PHP 8.1.34, literal 128M | 217 / 1044; 0 failed methods |
| PHP 8.5.10, shipped image, literal 128M | 217 / 1044; 0 failed methods |
| Frozen sequence на всех трёх PHP | 12 методов / 40 assertions; GREEN |
| PHP lint пяти PHP-файлов + `sh -n run.sh` | GREEN на всех трёх runtime |
| Полный PHP harness на host PHP 8.5.4 | 51 файл, 3036 `Passed:` и 127 `ok` строк; exit 0 |
| PHPStan 2.2.9, точная CI-команда | 1 baseline `new.static`; 0 новых diagnostics |
| Фиксация исходных 165 методов | Fingerprint и one-pass регистрация сохранены |
| Mutation gates этой правки | 15 отдельных дефектов пойманы named assertions; восстановление байт проверено |
| `git diff --check`, включая remediation-range | GREEN |

Счётчики `Passed:`/`ok` полного harness — разные форматы разных suites,
не число уникальных тестовых методов. Они не складываются с focused-count.

Первый полный host-прогон внутри PID sandbox завершился 137 при
`plugins/_task/TaskTest.php`; это не выдаётся за GREEN. Повтор в обычном
PID-окружении прошёл. Pre-commit не отключался. В полном логе есть warning
намеренно отрицательного read-only log-file fixture; failing assertions нет.

PHPStan на точной базе `8fa05ad2` и на финале возвращает одинаковый
`Unsafe usage of new static()` (`new.static`) в неизменённом helper-коде
`update.php` (строки 4426 / 4508). Не заявляется абсолютный PHPStan GREEN.

### Память: что проверено, а что не обещается

| Нагрузка | Outcome | Peak PHP 7.4 / 8.1 / 8.5, bytes |
|---|---|---|
| CAP−1 = 67,108,863 | dry preflight accepted | 6,291,456 / 6,291,456 / 10,485,760 |
| CAP = 67,108,864 | dry preflight accepted | 6,291,456 / 6,291,456 / 10,485,760 |
| CAP+1 = 67,108,865 | `candidate-too-large`, no output | 73,400,320 на всех |
| 32 MiB candidate | реально materialized | 102,703,104 на всех |
| 8 MiB максимальной accepted projection | 2 lazy reads, accepted consumer | 39,849,984 / 39,849,984 / 41,947,136 |

Тест не утверждает, что одновременные 64 MiB original + 64 MiB candidate
помещаются в 128M. Это прямо исключено binding-контрактом. CAP acceptance
и стоимость реальной материализации — разные проверяемые свойства.

## Контейнерная матрица rTorrent

Только disposable labs через `tasks/rt-lab.sh`; старый/новый рабочие daemon
процессы не содержали production torrents. Handoff, ledger, callbacks, SCGI,
source preflight и coordinator — production-код. Никакой live endpoint не мутировал.

- rTorrent 0.9.8: Debian package `0.9.8-1.1`, PHP 8.1.34,
  `rutorrent-pkg5-rt098:local`.
- rTorrent 0.16.21: shipped `ivanshift/rutorrent:latest`, PHP 8.5.10.
- Legacy serializer family относится к реально использованной Debian/XMLRPC-C
  сборке; это не заявление о byte identity с историческим образом XMLRPC-C 1.33.

На каждой family исполнены все восемь lifecycle-состояний:
BOOTSTRAP, FIRST_INIT_OWNER, IDLE_CURRENT, DONE_OWNER,
IDLE_EMPTY_CURRENT, SECOND_INIT_OWNER, CONTAIN_OWNER, CONTAINED.
Для каждого сохранены два byte-identical RAW ответа — итого **16 пар / 32 RAW**.
Финальный codec/classifier повторно прочитал и принял все сохранённые пары.
Request SHA-256: `ae96a2e5264798d84e4a35e981bbe99d8337820a93a07ff989e480b329b44210`.
BODY/RAW hashes и размеры находятся в `lifecycle-manifest.tsv`.

На обеих family проверены:

- ordinary replacement с новым native local-id и очищенными marker/ack;
- настоящий fault в creation-команде после claim → owned partial cleanup →
  rollback из original procfd, с восстановленным old tracker и без нового;
- та же цепочка при потере шести уже принятых ответов: old erase, candidate
  dispatch, cleanup, rollback dispatch, release, terminal cleanup;
- повтор 11 фактически отправленных callbacks после retirement: никаких
  изменений нового объекта или ledger;
- active-source вариант с `stop/close`, rollback и восстановлением
  `(state,is_active,is_open)=(1,1,1)` при тех же loss/late условиях.

На 0.16.21 отдельно прошёл empty-source tracker-list → partial → rollback,
включая loss/late. На 0.9.8 при отключённом DHT исходный trackerless torrent
не был загружен самим демоном; worker для этого объекта не возникает. Это
ограничение проверенной конфигурации, а не универсальная невозможность для 0.9.8.

Потеря ответа моделировалась в lab-адаптере **после** реального завершения send:
результат скрывается от coordinator, но daemon effects и последующие receipts
настоящие. Это не тест физического packet loss до принятия запроса. Неизвестная
доставка, incomplete fence и late-before-retirement races покрыты focused suite
и ранее сохранённой Task 4 матрицей; они не переименованы в новый live capture.

## Доказательства и воспроизведение

Корень артефактов (локальный, намеренно вне upstream diff):

`/home/dev/Documents/my_projects/ruTorrent/.worktrees/up-retrackers-recovery/.superpowers/sdd/PACKAGE-5-CODEX-REMEDIATION-PLAN/`

- `final-php74.log`, `final-php81.log`, `final-php85.log`;
- `full-php-host-normal-pids.log`, отдельно неуспешный `full-php-host.log`;
- `phpstan-base.json`, `phpstan-final.json`;
- `task7-mutations.md`, `task7-final-mutations.md`,
  `task7-lifecycle-mutation-fixed.md`;
- `lifecycle-manifest.tsv`, `lifecycle-098/`, `lifecycle-1621/`;
- `worker-success-*`, `worker-partial-*`, `worker-loss-late-*`,
  `worker-start-red-*`, `worker-start-green-*`, `worker-empty-green-1621/`;
- `start-green-*.log`, `loss-late-*.log`, `empty-green-1621.log`;
- `lab-lifecycle.php`, `lab-worker.php`, `lab-empty.php`, `lab098/Dockerfile`,
  `verify.php`, `verify-evidence.php`.

В раннем lab-driver `json_encode` всего результата не мог записать некоторые
packed binary projections. RAW не утрачен. Финальный `verify-evidence.php`
повторно классифицировал RAW и записал корректные `*.verified.json` только
для JSON-представимой публичной части verdict. Пустые ранние `*.json` не
используются как доказательство.

Один exploratory lifecycle-mutant достигал ошибки тестового индекса после
assertion failures: он не засчитан. Небезопасное индексирование заменено
`array_map`; повтор того же mutant дал обычные named RED без warning/fatal.
Каждый рабочий mutation возвращён обратным patch; затем повторён весь GREEN.

```sh
cd /home/dev/Documents/my_projects/ruTorrent/.worktrees/up-retrackers-recovery
docker run --rm --user 1000:1000 --network none --entrypoint php \
  -v "$PWD":/w -w /w php:7.4-cli \
  -c tests/php-test.ini -d memory_limit=128M \
  .superpowers/sdd/PACKAGE-5-CODEX-REMEDIATION-PLAN/verify.php
php .superpowers/sdd/PACKAGE-5-CODEX-REMEDIATION-PLAN/verify-evidence.php
```

Для 8.1 заменить image; для shipped 8.5 — image и entrypoint `php85`.
Для frozen sequence задать container env `PKG5_SUITE=sequence`.
`lab-worker.php` и `lab-lifecycle.php` намеренно требуют `/.dockerenv` и
содержат mutations: запускать только в новых disposable daemon namespaces.

Проверенные image IDs:

```text
rt098  sha256:a21c602a318026c78dc5537eaeee48ecbd178807f557472e36749c14587376bc
latest sha256:9e024dbd49ec9d2d3b5a1164ff6f57afc80207221b6a959b67b115f732c6ddb4
php74  sha256:7bbbb12d14986e855e5213c6b349e97e0f2e3da82536ec87da11a6c66fe2fcb2
php81  sha256:7699e39d88f66297bc94a8e3ab1ba60cfa68440a7c511599594475133eb863c7
```

Frozen sequence hashes сохранены:

```text
12 sorted names  0ee7b35f9cda898d00e963b7e23aff02351e3653db21bbf2e99e31a34d5c7044
class..EOF       f0dac045fa3b9e98172132977e05fa14b7f091d1b9779a989d8b1d047fecc8f3
165 base names   c98eea96cfddf100e2dc2ee726750efe678100db6d02e70f8d44334b83052278
update.php       427be8cff8f164037e9f8073f1025bcff42e8784422db780beb3c1dc1fa8ffcd
UpdateTest.php   f3a2916bf6603e620a24dc705dca8b9d566d6040441e4076bae61c554e9373d6
```

## Review и точка остановки

Сохранены предыдущие независимые reviews Tasks 1–5 и lifecycle на `8fa05ad2`.
Финальные изменения, их callers и invariant boundaries проверены Codex;
контейнерные факты сверены с binding-контрактом. **Новый независимый reviewer
для этой финальной правки не запускался** по запрету пользователя на новых
агентов. Не выдаём self-review за новое независимое `APPROVED`.

Все известные реализационные blockers пакета №5 устранены; runtime и focused
acceptance выполнены. Это не обещание отсутствия любых будущих дефектов и не
результат production-deploy проверки.

Оба созданных контейнера `pkg5-finish-098` и `pkg5-finish-1621` удалены после
сохранения evidence. Остальные контейнеры не тронуты. Локальный rt098 image
сохранён для воспроизводимости.

**После фиксации работа остановлена.** Следующая сессия начинает с этого
документа и проверки refs/status. Доставка №5 в master/upstream — отдельное
действие, не выполненное здесь. Не начинать следующий пакет автоматически.

Task-документы и `.superpowers` игнорируются репозиторием и сохранены локально;
они не подмешиваются в upstreamable code commit. Для переноса на другой
компьютер нужно отдельно сохранить указанный каталог доказательств и этот task.
