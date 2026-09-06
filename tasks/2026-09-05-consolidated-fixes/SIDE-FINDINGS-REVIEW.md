# Независимая проверка `SIDE-FINDINGS.md`

Дата проверки: 2026-09-05.

## Граница и состояние

- Проверенный срез: ветка `fix/consolidated-round1`,
  `HEAD=62406d2246be8297d36bb8d376367526ff823ca9`.
- До проверки рабочее дерево уже содержало одно чужое изменение:
  `M tasks/2026-08-28-upstream-delivery/STATUS-18-PACKAGES-2026-09-03.md`.
  Оно не читалось как источник вывода и не изменялось.
- Исправления production/test-кода, commit/push/merge/deploy, live-запросы и
  сетевые запросы не выполнялись. Единственное изменение этой проверки — этот
  отчёт.
- После проверки tracked-состояние осталось тем же: только исходный `M` у
  `STATUS-18-PACKAGES-2026-09-03.md`. Этот отчёт существует на диске, но попадает
  под repository rule `.gitignore:75` (`tasks/`), поэтому обычный `git status`
  его не показывает.
- `SIDE-FINDINGS.md` смешивает текущий срез и отдельную ветку package 6.
  `fix/p6-combined` (`3cf52e4d`) **не является предком** текущего HEAD. Поэтому
  его API, тесты и результаты нельзя выдавать за свойства `62406d22`.
- Вердикт `подтверждено` ниже означает подтверждение на текущем срезе. Старое
  измерение без воспроизводимого артефакта отдельно помечено как
  `недостаточно данных`.

Финальное уточнение основного ревьюера: после завершения параллельной проверки
другой процесс добавил `3009d6c1` (I04, общий parser chk-forum). Полный набор
SIDE-тестов на нём повторно не запускался; приведённые результаты относятся
к `62406d22`. Ветка package 6 осталась на `3cf52e4d`; её S2/S3 и две копии
классификатора дополнительно просмотрены основным ревьюером. Сторонние
изменения и исходный dirty STATUS сохранены.

## Итог

Из десяти строк раздела «Исправлено» текущий результат подтверждён для всех
десяти, но исторические числа в строках про process leak и `/tmp` заново не
доказаны. Две строки — закрытия уже известных M09 и Q08, а не новые независимые
дефекты.

Из открытых S1–S4:

- S1 подтверждает дублирование, но опровергается объяснение, будто дешёвого
  общего владельца быть не может; это low-priority maintainability fix.
- S2 не относится к текущей архитектуре по названному API, но реальный пробел
  crash-покрытия подтверждён на отдельной `fix/p6-combined`; закрыть до её
  интеграции.
- S3 устарел: на tip `fix/p6-combined` тест уже mutation-sensitive благодаря
  `3cf52e4d`; перенести строку из «Открыто» в «Исправлено».
- S4 — точные дубли O02/O05 и верно отнесён к `docker-rutorrent`; здесь ничего
  не менять.

## Десять строк «Исправлено»

| № | Вердикт | Текущие доказательства | Нужен ли ещё fix / связь с реестром |
|---:|---|---|---|
| F1 | **Подтверждено на current.** `Torrent::touch()` теперь пишет stamp только для объекта, построившего info из payload. | `$built` объявлен в `php/Torrent.php:24-28`, устанавливается только в build-ветке `:151-154`, а `touch()` выходит для decoded torrent в `:677-695`. `TorrentMetaTest` (21 test method) и `TorrentCreatePathSequenceTest` (5) прошли. | Дополнительный fix не нужен. Это закрытие уже зарегистрированного **M09**, а не независимая новая находка. Формулировка «~20 ожиданий инвертированы» не является воспроизводимым acceptance-критерием и отдельно не подтверждалась. |
| F2 | **Подтверждено, но это второй caller того же M09.** | `plugins/create/correct.php:53-68` объясняет границу authorship; decoded `temp.torrent` редактируется в `:69-94`. `testCorrectingAnExternallyBuiltTorrentSetsTheFormsValues` прошёл и сохранил исходные author/date. | Fix не нужен. Не считать вторым root cause поверх F1/M09. |
| F3 | **Текущий fix подтверждён; историческая статистика подтверждена не полностью.** | Обе string-команды начинаются с `exec`: `tests/php/SCGITransportFixture.php:263-272` и copied RPC2 server в `tests/php/SCGITransportTest.php:887-896`. Все 36 методов `SCGITransportTest` прошли при разрешённых loopback/Unix sockets. | Fix кода не нужен. Числа «6/6» и «785 orphaned php -S» живут только в комментарии/исходном документе; отдельного process transcript в доступных task-артефактах нет, поэтому эти числа — **недостаточно данных**, не current proof. Полезен будущий узкий тест, явно проверяющий PID/отсутствие живого потомка после `close()`, но это test hardening, не открытый production bug. |
| F4 | **Механизм и current mitigation подтверждены; исторический каскад не перемерен.** | Во время проверки `/tmp` действительно был tmpfs 1.7 GiB и имел лишь ~400 MiB свободно. `tests/php-test.sh:22-36` предупреждает ниже 512 MiB; локальный `.git/hooks/pre-commit:24-30` выставляет disk-backed `TMPDIR`. 64 MiB границы видны в `SCGITransportTest.php:867` и retrackers fixtures. | Production fix не нужен. Tracked-часть — предупреждение; hook локален и не переносится clone-ом. «Дюжина suite» и «дважды приняли за regression» — **недостаточно данных** без сохранённых прогонов. Связано с O04, но не тождественно ему. |
| F5 | **Подтверждено.** | `AGENTS.md:128` ссылается на существующий `RuTrackerAnnounce::hasValidSuccessSchema()`; `RuTrackerAnnounceProbe` в current отсутствует. | Fix не нужен. Это точный дубль **Q08**. |
| F6 | **Подтверждено как намеренная безопасная граница, не как оставшийся дефект.** | `ruTrackerAccount::FORUM_HOSTS` содержит четыре forum mirror в `plugins/loginmgr/accounts/RUTracker.php:7-34`; `.cc` и `t-ru.org` сознательно исключены из cookie/login области (`:14-33`). Обратная зависимость действительно была бы неверной: `plugins/rutracker_check/plugin.info:8` объявляет зависимость от `loginmgr`. `RuTrackerDomainListTest` — 5/5 green. | Ничего не объединять между plugins. Добавлять новый forum mirror нужно парно в source/test вручную; `rutracker.cc` сейчас добавлять нельзя без нового credential-flow решения. |
| F7 | **Подтверждено.** | `RuTrackerAnnounce::buildUrl()` принимает только byte-exact `http`/`https` в `plugins/rutracker_check/announce.php:398-422`. Focused test явно принимает HTTP/HTTPS и отвергает UDP/WSS/uppercase HTTP (`RuTrackerAnnounceTest.php:24-47`); suite 35/35 green. | Fix не нужен. С M06 лишь взаимодействует: M06 был про pathless URL и caller decision, это не дубль. |
| F8 | **Подтверждено.** | `ruTrackerChecker::classifyFetchError()` возвращает безопасный token (`plugins/rutracker_check/check.php:1858-1894`), а `makeClient()` пишет единую форму `error=<token>` (`:1919-1947`). NNM guest path пишет ту же форму (`trackers/nnmclub.php:164-176`). `CheckerTest` 135/135 и `NNMClubHandlerTest` 29/29 green. | Fix не нужен. Это фактическое закрытие диагностического пробела **I05** для обоих путей; не считать новой production аварией. |
| F9 | **Подтверждено.** | `clearStaleDeletion` не встречается ни в production, ни в tests current. Актуальный комментарий над `resetDeletion()` в `plugins/rutracker_check/trackers/rutracker.php:222-228` описывает именно `chk-del`. | Fix не нужен. Production-поведения здесь не было: исправлена вводящая в заблуждение документация. |
| F10 | **Подтверждено.** | Комментарий в `tests/plugins/rutracker_check/UpdatePassTest.php:3183-3188` теперь верно говорит: integer `0` даёт UNKNOWN/null, а не доказанное отсутствие. `UpdatePassTest` 130/130 green. | Fix не нужен. Это test-fixture documentation, не production defect. |

## S1–S4

### S1 — две классификации Snoopy

**Вердикт: частично подтверждено, частично опровергнуто. Исправить, но с низким
приоритетом.**

Подтверждено:

- `plugins/rutracker_check/check.php:1877-1893` и
  `plugins/rutracker_check/trackers/nnmclub.php:211-229` содержат одинаковые
  восемь token/regexp пар.
- Первая версия сначала `trim(preg_replace('/\s+/', ' ', ...))`, вторая только
  `trim()`. Следовательно, одинаковый Snoopy message с repeated whitespace уже
  может иметь разные tokens.
- Комментарий NNMClub честно фиксирует эту обязанность в `nnmclub.php:197-204`.

Опровергнута абсолютная часть «свести в одну нельзя дёшево». Нельзя дешёво
вызывать метод `ruTrackerChecker`: `check.php:7-13` загружает tracker-файлы до
объявления класса в `:20`, а standalone NNM suite подставляет checker stub. Но
из этого не следует необходимость третьей копии в stub. Чистый classifier может
жить в отдельном leaf-файле/классе без зависимости от `ruTrackerChecker`, и его
могут `require_once` оба владельца и standalone test.

Рекомендация: вынести pure classifier вместе с whitespace normalization и одной
таблицей patterns; добавить parity cases для tab/newline/repeated spaces. Это
закроет также зарегистрированный I06. Severity — minor/maintainability: текущие
известные восемь сообщений классифицируются, утечка raw text отсутствует.

### S2 — crash внутри aggregate erase

**Вердикт на current: названная реализация недостижима/отсутствует. На отдельной
ветке package 6 пробел подтверждён. Исправить тест до интеграции package 6.**

На `62406d22` функции `erasedataEraseRequest()` нет. Current legacy producer
сам строит один aggregate `rXMLRPCRequest` в
`plugins/erasedata/removewithdata.php:1369-1376`, удерживая hash locks до
разбора результата (`:1377-1405`). Поэтому утверждение о «границе, которую
добавили оба исправления package 6», не является current-tree claim.

На `fix/p6-combined:3cf52e4d` API существует, а пробел зафиксирован самим тестом:

- `RemoveWithDataTest.php:10841-10859` объясняет, что `cut=2` не срабатывает:
  erase — один aggregate request, а cut считает requests, не commands;
- текущий mirror умеет выбрать scripted entry по команде внутри batch, но
  `CollectorFixture.php:1152-1158` всё ещё убивает только на N-м **request**;
  command-granularity partial reply/process death отсутствует;
- имеющийся `testCrashAfterPartialEraseKeepsEveryRemainingObligation` фактически
  проверяет no-ack rollback и последующее recovery, не producer death во время
  частично исполненного aggregate erase.

Рекомендация для package 6: расширить mirror command-level cut/partial reply,
сделать непустую трёхэлементную erase-группу, доказать номер исполненной команды,
удержание descriptor/locks в точке смерти и точное recovery оставшихся
obligations. До этого crash-claim package 6 не закрыт. Это не разрешение менять
current legacy producer в рамках данной проверки.

Разделить два сценария: смерть PHP producer/потеря ответа не гарантирует остановку
уже принятого daemon batch; частичное исполнение внутри daemon — отдельная точка
отказа. Fixture должна явно показывать, какой процесс остановлен и какие команды
реально исполнены, а не считать client disconnect доказательством остановки erase.

### S3 — `testGlobalLockBypassWouldShowOverlapInSharedRecovery`

**Вердикт: как открытая находка опровергнуто/устарело. Код current её не содержит;
на tip package 6 она уже исправлена commit `3cf52e4d`. Новый fix не нужен.**

- В `62406d22` нет ни этого метода, ни package-6 drain worker, поэтому current
  production/test verdict для него невозможен.
- В неизменённой scratch-копии `fix/p6-combined` один только названный метод
  прошёл: 9 наблюдаемых assertions green.
- В отдельной scratch-копии были обойдены acquisitions state/pass locks (current
  package-6 lines `1826-1830` и `3121-3125`; checkout не менялся). Тот же метод
  дал явный `Failed` на новом assertion о ровно одном `worker-busy`, тогда как
  остальные end-state assertions остались green. Это именно нагрузка, которой
  не было до `3cf52e4d`.
- Новый test принудительно расширяет overlap window на 0.6 s per request и
  считает classified loser (`fix/p6-combined:RemoveWithDataTest.php:11023-11067`).

Следствие: историческое утверждение могло описывать предка `3cf52e4d`, но оно не
переживает актуальный tip ветки. Перенести S3 в «Исправлено», указав commit;
никакой production fix на `62406d22` отсюда не следует.

### S4 — Docker 0.9.8 и GeoLite

**Вердикт: подтверждено как дубли и repository routing; здесь не исправлять.**

Это буквально O02/O05 из consolidated register. `AGENTS.md:17-29` относит
Dockerfile dependency pins, image stages и image smoke к
`/home/dev/Documents/my_projects/docker-rutorrent`. Внешний GeoLite URL/build
в этой локальной проверке не перепроверялся, поэтому current availability не
утверждается. Если владелец решит продолжить, открыть отдельную задачу в
`docker-rutorrent` и заново проверить URL/checksum и полный image boot; в этот
fork изменения не переносить.

## Три мелких пункта

| Пункт | Вердикт | Рекомендация |
|---|---|---|
| Lookalike одновременно в `$foreign` и `$enabled` | **Подтверждено и достижимо**, но не дефект текущего caller. `detector.php:156-185` задаёт два разных вопроса: attribution и jurisdiction; locals нигде не суммируются и наружу не возвращаются. | Не исправлять и не держать как open bug. Уже достаточный warning в коде. Это продолжение M03/его fix, не новая проблема. |
| Defaults `stillPending()` | **Подтверждено.** Семь реальных call sites (`metafetch.php:466,481,496,500,611,643,676`) всегда задают `$owned` и `$reason`; четыре вызова без `$isMeta` неизбежно идут в `!$owned || $stubTuple === null`, где `$isMeta` не читается (`:562-573`). | Не удалять. Defensive API defaults без текущего потребителя не создают ошибку. Это точный дубль **U08**. |
| Docker PHP 8.1 от root | **Частично подтверждено / часть статистики недоказана на current.** Root действительно не является валидным наблюдателем EACCES для directory mode 0400; package-6 P04 test в current отсутствует. Утверждение о наборе «несвязанных permission failures» этим прогоном не проверялось. | Для permission-sensitive package-6 matrix обязательно `--user <non-root uid>:<gid>`; root-run не использовать как доказательство ни GREEN, ни RED. Это test-runtime правило, не app-code fix. |

## Утверждения о методе и числах

| Утверждение | Вердикт |
|---|---|
| «5 прогонов, 91 запись, 13 large/blocking/important» | **Недостаточно данных.** В current task-directory нет пяти исходных структурированных отчётов и машинно проверяемого crosswalk 91→13. `SIDE-FINDINGS.md` — единственный доступный источник этих чисел. |
| «Раунды нашли 8, затем 1, затем 4 important» | **Недостаточно данных.** Git history показывает три серии branch merges, но severity и дедупликация не закодированы в commits; переносить эти числа как измеренный current result нельзя. |
| «Почти все important — комментарий, заменивший одну ложь другой» | **Не доказано и сформулировано слишком широко.** Current history включает не только comments, но и исполняемые fixes: `$built` gate, scheme gate, classifier, host predicate, fixture process launch. Без исходного per-finding crosswalk долю `почти все` получить нельзя. |
| «Комментарий проверять как поведение для следующего читателя» | **Подтверждено как полезное правило review, не как статистический вывод.** F5, F9, F10 и несколько текущих load-bearing comments действительно исправляют неверную модель, а S2 показывает, как комментарий может честно раскрыть непокрытую границу. |

## Выполненная проверка

- PHP 8.5.4 syntax: 6/6 файлов без ошибок — `Torrent.php`, `correct.php`,
  `SCGITransportFixture.php`, `announce.php`, `check.php`, `nnmclub.php`.
- Current focused self-running suites: `RuTrackerDomainListTest` 5/5,
  `RuTrackerAnnounceTest` 35/35, `NNMClubHandlerTest` 29/29,
  `CheckerTest` 135/135, `UpdatePassTest` 130/130; всего **334/334**.
- Current TestCase methods: `TorrentMetaTest` 21, `TorrentCreatePathSequenceTest`
  5, `SCGITransportTest` 36; всего **62/62**. Первый sandbox run SCGI не мог
  bind local sockets (`Operation not permitted`); тот же focused suite был
  повторён с loopback/Unix sockets и disk-backed `TMPDIR`, после чего прошёл.
- `fix/p6-combined` S3 targeted baseline: 9/9 observable assertions green.
  Review-only lock-bypass mutant: новый `worker-busy` assertion RED; mutation
  не касалась checkout и scratch-копия удалена.
- `git diff --check` до и после проверки — clean для tracked diff; отдельная
  whitespace-проверка ignored отчёта также clean.

## Финальная рекомендация

Не открывать production-fix пакет по десяти закрытым строкам, S3 или трём
защитным minor observations. Оставить два follow-up решения:

1. minor refactor S1 в самостоятельный pure Snoopy-error classifier;
2. blocking test-hardening S2 на **ветке package 6** до её интеграции.

S4 выполнять только в `docker-rutorrent`. Статистику 91/13 и 8→1→4 убрать из
acceptance evidence либо приложить исходные отчёты и детерминированный crosswalk.
