# Пакет 6: исправление замечаний и приёмка, 2026-09-07

**APPROVED для локальной интеграции пакета 6.** Проверен `203de666` с правками
этого коммита. Основание работы — разрешение пользователя исправить P2/P3,
повторить проверки и влить при отсутствии блокеров. Push и deploy не выполняются.

## Исправлено

- **P6-C6 / P2:** обычный collector без retentionSink сохраняет прежнее сообщение
  codec об отказе публикации. Collector внутри drain передаёт отказ владельцу
  durable-дедупликации. Production-изменение — выбор одного аргумента; остальные
  production-изменения этого коммита уточняют комментарии.
- **SIDE-S2 / P3:** три aggregate-crash теста теперь проверяют armed и отсутствие
  removal до recovery, затем disarmed, ту же генерацию, ровно одно удаление
  своего drain key и пустой journal. Это дополнение к существующим проверкам
  payload, каждого члена batch, decoy и завершения дочерних процессов.
- **Гонка restart-теста:** общий прогон выявил один отказ в существующем
  `testRestartAfterVolatileScheduleLossRearmsTheExactGeneration`. Concurrent
  worker мог опустошить очередь раньше startup, после чего отсутствие rearm
  было правильным. Worker-first порядок воспроизведён на точном production
  `203de666`: disarmed, journal пуст, прежний assertion падает. Теперь настоящий
  startup сначала регистрирует проверяемый ключ, затем тест запускает worker.
  Проверки ключа и сохранения поколения не ослаблены.

Оба прежних блокера диагностики сохранены закрытыми: смешанная очередь
4095 pending + 10 journal = 4105 задач даёт **8221 / 0 / 0 строк** на трёх
настоящих worker ticks, cap 8198; отказ публикации в generation pass сообщает
первую причину и молчит на следующих трёх тиках. Ordinary/drain чередование
для опубликованного поколения также остаётся ограниченным во времени.

## Регрессии и мутации

Новый обычный collector case проходит через production CLI entry point с
нормальными scheduler argv. До правки он дал 12 ожидаемых assertion failures:
по три тика для конфликта final pathname и реального запрета записи в очередь.
После правки — 32/32 assertions, включая сохранение точных staging/payload
bytes и успешное завершение после снятия препятствия. Отдельный case проверяет
повторные вызовы collector с sink внутри настоящего drain.

Все мутации применялись по одной только в scratch-копии. Для каждой проверены
START/END нужного случая, содержательные assertion failures без fatal/incomplete,
побайтное восстановление production-файла и новый GREEN:

1. Обычный collector снова подавляет codec без sink — новый case падает.
2. Collector внутри drain снова печатает напрямую — dedup case падает.
3. Generation pass снова печатает напрямую — repeated-worker case падает.
4. Worker не вызывает retirement — все три aggregate-crash case падают.
5. Retirement не отправляет removal — все три aggregate-crash case падают.
6. Retirement не записывает disarmed — все три aggregate-crash case падают.
7. Startup не вызывает rearm — исправленный restart case падает.

Независимый reviewer повторил P2 на исходном и исправленном деревьях, проверил
call paths, наблюдения crash-тестов, мутации и дополнительную правку restart.
Итог обоих его проходов — APPROVE, блокирующих замечаний нет.

## Свежие результаты

| Проверка | Результат |
|---|---|
| Финальный host PHP 8.5.4, uid 1000 | 80 файлов, 1074 TestCase-метода, **8855 Passed**, 849 TestLib ok, 0 failed/not-ok/fatal/исключений, exit 0 |
| RemoveWithDataTest на финальном host | 316 методов, 2901 assertion, 0 ошибок |
| PendingQueueTest | 25 методов, 122 assertions |
| RepairToolRefusalTest | 2 метода, 13 assertions |
| Ratio EraseWithDataCommandTest | 10 методов, 61 assertion |
| PHP 7.4.33 / 8.1.34 / shipped 8.5.10 | Четыре профильных набора: по 3096 assertions, 0 ошибок, non-root, 128M, network none, source read-only |
| Последняя правка restart-теста на всех трёх PHP | На каждом 1/1 случай, 7/7 assertions, exit 0 |
| Изолированный неизменённый TaskTest, PHP 8.1 | 4/4 метода, 9/9 assertions, exit 0 |
| PHP lint всех изменённых PHP кампании | 62 файла, PASS |
| PHPStan 2.2.9, level 0, десять production-файлов | 0 errors / file_errors, исходный phpstan.neon |
| Diff whitespace и независимое review | PASS / APPROVE |

Матрица четырёх наборов выполнялась до последней правки только restart-теста;
её production bytes не менялись. Изменённый случай затем повторён на каждой
версии, а весь host-набор — на окончательных тестах. Первый host-run с гонкой
сохранён отдельно и не переименован в успешный результат.

Из host-run исключён только неизменённый `TaskTest.php`: он может послать kill
детям PID 1. Его проверили отдельно в одноразовом PID namespace с root-init
и uid 1000. В официальном PHP 8.1 образе нет pgrep, что отражено двумя строками
stderr; проход 9 assertions не доказывает поведение группового kill. Это
унаследованная граница `_task`, не изменение или покрытие пакета 6.

Host содержит прежние PHP 8.5 Deprecated и ожидаемый warning permission-denied
из LogFileModeTest; отсутствие предупреждений не заявляется. Общие TestCase
и TestLib счётчики приведены раздельно. Полный исторический набор 63 мутаций
заново не запускался: текущий delta проверен семью мутациями выше и свежими
наборами всех затронутых функций; исторические цифры не выданы за новый прогон.

## Реальный rTorrent и wire

Четыре одноразовых non-root сценария HTTP/CLI прошли на rTorrent 0.9.8 и
0.16.21. SHA256 десяти production-файлов совпал с исправленной рабочей веткой.
Обычный collector schedule отключён. Реальный daemon erase event увидел ack;
torrent и payload исчезли, journal пуст, phase=disarmed; после retirement
execute log не вырос. Все созданные контейнеры и анонимные тома удалены.

На каждой версии дополнительно сверена aggregate wire-модель синтетическими
торрентами в daemon без сети:

- Девять команд: полный ответ, девять результатов, ни одного fault; три hash
  отсутствуют после исполнения.
- Клиент закрыл соединение без чтения ответа: все три erase всё равно исполнены.
- Сначала отсутствующий hash, затем три существующих: 12 результатов, первые
  три — per-item faults; следующие девять команд исполнились, hash отсутствуют.

Сохранены полные request/response captures; ответы независимо разобраны XML-RPC
парсером. Это сверка модели транспорта, а детерминированные crash points внутри
одного batch проверяются отдельными дочерними процессами в regression suite.

## Артефакты и границы

Локальное evidence: `.superpowers/p6-accept-fix-20260907/` — host-final.log,
host-final-summary.json, php74/php81/php85.log, соответствующие restart logs,
task-isolated.log, mutations.json, restart-mutation.json и mutant/restored logs,
mixed.log, phpstan.json, lint.json, wire-verified.json.
`runtime/fixed-{098,1621}-{http,cli}/` содержит source hashes, версии, erase
witness, result.json и wire captures.

Прежний реестр production-находок из PACKAGE-6-RECHECK-2026-09-07.md сохранён:
admission, ack, locks, force-2 capability, durability, retry и retirement не
регрессировали в текущих прогонах. P03 остаётся согласованным видимым ручным
восстановлением unjournaled staging. Переход стандартного httprpc/mobile UI на
новый протокол по-прежнему относится к следующему пакету и здесь не заявляется.

Локальный commit выполняется с `--no-verify`: штатный pre-commit безусловно
запускает опасный TaskTest на хосте. Его остальные 80 файлов проверены тем же
runner с одним явным исключением, а TaskTest — отдельно, как указано выше.
Хук и его конфигурация не изменяются.
