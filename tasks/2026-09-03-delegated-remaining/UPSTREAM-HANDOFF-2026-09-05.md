# Подготовленные upstream-ветки и точка продолжения — 2026-09-05

## Итог

Подготовлено **5 локальных веток**: пакеты №4, №5, №13, №14 и №15.
Ничего не push, PR не создавались. Основная рабочая ветка остаётся `master`.
Закрыто **8/18** реализационных пакетов, открыто **10**: №6–12, №16–18.
Подготовка PR не является новым закрытием реализации или принятием upstream.

Пользователь разрешил отдельные самостоятельные части `rutracker_check`.
Весь fork-вариант плагина предназначен для отдельной отправки после полной
готовности; сейчас он не переносится. Это уточнение закреплено в AGENTS.md,
который не входит ни в одну upstream-ветку.

## Замороженные refs

Повторный fetch непосредственно перед финализацией подтвердил:

| Ref | SHA |
|---|---|
| Опубликованный `origin/master` | `72d1885ca02358bb1420d1a812450b61e77521f7` |
| `upstream/master` | `b4e84b641ebef7adeb91ec3830d3934bd1885bd1` |
| Принятый master до squash | `92d7111f573fa81e9032e708dcdd3fad9081a7c9` |
| Пользовательский commit, сохраняемый отдельно | `ebb60a7eb272b82d6bbdc7b1cfaa5427be3c6cb0` |
| Резервная ветка | `backup/upstream-handoff-pre-squash-20260905` → `92d7111f` |

Итоговый squash закреплён ref `codex/upstream-handoff-squash-20260905`.
Его первый parent — `ebb60a7e`, второй — `b4e84b64`. В первой линии истории
после пользовательского commit остаётся один aggregate commit, но реальные
upstream commits сохраняются в ancestry. Это намеренно не flatten всей истории.
При неизменном origin публикация master остаётся fast-forward; force не нужен.

Пользователь отдельно спросил, ждать ли заканчивающийся №6. Проверенная
rebuild-ветка №6 имеет merge-base с master `72d1885c`; этот commit сохраняется
и после squash. Поэтому ожидание технически не требуется. Схлопывается только
уже принятая серия; №6 после независимого review войдёт отдельным commit.

## Ветки, scope и порядок

| Пакет | Локальная ветка / собственный commit | База собственного commit | Когда предлагать |
|---|---|---|---|
| 4 | `up/scgi-transport-v2` — `de781784b00528a7a57a483e4c2c4484b6da5b98` | upstream `b4e84b64` | Можно первым |
| 13 | `up/rtorrent-alias-surface` — `a7bf4986d7e06ea90f379a5a9c542f84690603af` | upstream `b4e84b64` | Независимо от №4 |
| 15 | `up/rutracker-manual-entrypoints` — `b64afb3097985c9ccf2e61bc0ddd00c604468875` | upstream `b4e84b64` | Независимо; только ручная часть плагина |
| 5 | `up/retrackers-recovery-v2` — `26991e798b45777c49705aa54f43d97da9429301` | №4 `de781784` | После принятия №4 и refresh ветки |
| 14 | `up/xmlrpc-proxy-policy` — `132efb1f548d063e26e58910b30bfd0e1b09575c` | №4 `de781784` | После принятия №4 и refresh ветки |

У №5/№14 **по одному собственному commit**, но относительно Novik/master
сейчас видны **два commit**, включая №4. Нельзя выдавать их нынешний diff за
независимый PR. №5 использует transport непосредственно; №14 использует его
интеграцию и fixture в проверках реальных HTTP doors. Сам policy-класс не
требует транспортной реализации.

Все пять worktree сохранены под `.worktrees/upstream-<lane>-20260905`, где
lane = `scgi`, `alias`, `manual`, `retrackers`, `proxy`. Для работы используйте
`git -C <worktree> ...`: ветки заняты этими worktree, поэтому обычный switch
на них из основного checkout будет отклонён Git. Основной checkout — master.
Старые `up/scgi-transport` и `up/retrackers-recovery` сохранены как исторические
donor-ветки; **команды публикации ниже относятся к новым v2, не к ним**.

### Точный состав собственных commits

- №4 — 7 файлов: `README.md`, `conf/config.php`, `php/scgitransport.php`,
  `php/xmlrpc.php`, `rpc2.php`, `tests/php/SCGITransportFixture.php`,
  `tests/php/SCGITransportTest.php`. Только транспорт и его настройки/проверки.
- №13 — 4 файла: `php/settings.php`, `tests/php/RtorrentCompatibilityTest.php`,
  `tests/php/SocketAllocLimitsTest.php`, `tests/js/rtorrent.spec.js`.
  Production alias map не меняется; production-правка — уточнение комментариев.
- №15 — 6 файлов: `plugins/rutracker_check/{action.php,batch_check.php,init.js,
  launcher.php}`, `tests/plugins/rutracker_check/{ManualEntrypointsTest.php,
  init.spec.js}`. Это перенос уже принятого upstream-base candidate `5a1a0d97`,
  а не копирование текущего fork-плагина. Fork-only forum crawl hook и
  расширения scheduler/UI в этот PR не входят; в master они сохранены.
- №5 — 6 файлов: `plugins/retrackers/{init.php,done.php,run.sh,update.php}`,
  `tests/plugins/retrackers/{UpdateTest.php,RetrackersUpdateSequenceTest.php}`.
  Все шесть побайтно совпадают с принятым master. `retrackers.php` не меняется.
- №14 — 10 файлов: `conf/xmlrpc_proxy.php`, `env_check.php`,
  `php/xmlrpc_proxy.php`, `plugins/httprpc/{action.php,conf.php}` и пять файлов
  `tests/php/XMLRPCProxy{Test,ContractFixture,ContractTest,EntrypointTest,
  RejectionTest}.php`. Не переносились `xmlrpc_path.php`, `rawFaultString`,
  `removewithdata` и другие части №6/№7.

### Почему upstream-выделение №14 шире локальных семи файлов

Локальный семифайловый implementation опирался на уже существовавшие в форке
prerequisites. На чистой upstream-базе потребовались собственные изменения в
`plugins/httprpc/conf.php` (shared policy / unset-only fallback),
`plugins/httprpc/action.php` (явный root opt-in) и `env_check.php` (недоступная
SimpleXML-функция при загруженном extension должна давать WARN).

Проверки пустого/нечитаемого тела **уже есть в upstream** и не дублировались.
Сохранены обе upstream inline path-resolver функции. Contract-тест исполняет
их точные извлечённые тела на реальных symlink, вместо требования fork-only
shared helper. Все прежние public test names и 70 fixture keys сохранены.
Добавлен один HTTP-тест root opt-in с корректным config bootstrap:
до guard две запрещённые комбинации отправляли setter, после — 403 / zero
sends; две явно разрешённые комбинации продолжают отправляться.
Это adaptation доставки утверждённого контракта, не новый пакет/новый дизайн.

## Соответствие всем 18 пакетам

| Пакеты | Что меняет текущая подготовка | Что НЕ закрывает |
|---|---|---|
| 1–3 | Ничего нового; ранее приняты upstream | Статусы PR не переобъявлялись по новой API-проверке |
| 4 | Самостоятельный транспортный PR; база №5 и текущего handoff №14 | Не реализует durable erasedata |
| 5 | Полный recovery retrackers | Не реализует P3 integration / marker №12 |
| 6 | Не тронут; внешние candidate-ветки сохранены | Требуется независимый review, затем интеграция |
| 7 | Policy prerequisite №14 готов в master | Ждёт №6 и собственной consumer-интеграции |
| 8–9 | Без изменений | Ждут №6 |
| 10 | Без изменений | Ждёт №9; manual №15 не заменяет P1 |
| 11 | Без изменений | Ждёт №10 и event-order evidence |
| 12 | Recovery prerequisite №5 готов | Ждёт №10 и собственной P3 реализации |
| 13 | Независимые compatibility guards | Не меняет daemon API и не закрывает другие реализации |
| 14 | Полный policy PR с baseline adaptations | Не включает removewithdata и не закрывает №7 целиком |
| 15 | Самостоятельная ручная часть rutracker_check | Не включает scheduler или все tracker handlers |
| 16–18 | Без изменений | Ждут №10; полный plugin handoff пока рано |

Совместимость подготовленных исправлений с действующим fork проверена также
полным master harness: в нём уже сосуществуют №4/№5/№13/№14/№15. Отличие №15
по forum crawl намеренно: этот hook нужен fork-интеграции, отсутствует upstream
и не должен попадать в изолированный manual PR. Будущий полный plugin PR нужно
строить поверх к тому времени принятых отдельных исправлений, без их отката.

## Свежая верификация

Артефакты: `.superpowers/upstream-handoff-20260905/` (локальные, не в PR).

| Проверка | Результат |
|---|---|
| Чистый upstream baseline | PHP: 62 test files, exit 0; Jest: 22 suites / 279 tests |
| №4 PHP 7.4/8.1 + host 8.5 | 36 methods / 134 assertions |
| №13 PHP 7.4/8.1 + host 8.5 | Compatibility 11/1122; SocketAllocLimits 6/17 |
| №13 Jest | 22 suites / 283 tests |
| №15 PHP 7.4/8.1 | 13 real-entrypoint scenarios, 0 failures |
| №15 Jest | 23 suites / 284 tests |
| №5 PHP 7.4/8.1 | Worker 221/1097; frozen sequence 12/40 |
| №14 PHP 7.4/8.1 | 193 methods / 2564 assertions, 0 failures |
| Все пять локальных commits | Обычный полный host PHP 8.5 pre-commit hook; без bypass |
| Все пять веток | Полный production PHPStan 2.2.9: 0 errors |
| Принятый master перед squash | PHP: 78 test files, exit 0; Jest: 23 suites / 336 tests |

Для №5 повторно запускался **именно extracted tree**, не fork source tree:
одноразовые rTorrent 0.9.8 и 0.16.21 без сети. На каждом воспроизведены partial
creation fault, rollback, 6 потерянных mutation replies, 12 delayed reads,
11 безопасных late callbacks и завершение lifecycle. Оба процесса дали
`WORKER_END_TO_END_GREEN`, exit 0. Лабораторные контейнеры остановлены/удалены;
реальная служба не менялась. Raw traces сохранены в `final-live-098/1621`.

Независимые проверки extraction: число имён сравнивается с ожидаемой
регистрацией, duplicates/missing = 0; 70 fixture keys одинаковы; все 6 файлов
№5 идентичны master. Frozen sequence class SHA-256:
`f0dac045fa3b9e98172132977e05fa14b7f091d1b9779a989d8b1d047fecc8f3`.

Первичные неудачи тоже сохранены: `proxy-php74/81.log` показывают две
upstream-vs-fork зависимости (env_check и shared resolver), затем
`proxy-final-php74/81.log` подтверждают исправленное выделение. Первоначальная
попытка root-теста через multicall проверяла безусловно запрещённый direct
setter; она заменена реально разрешаемым load.normal command parameter.
Нормативный RED — `proxy-root-red.log`, GREEN — final suites.

Это доказательства перечисленных сценариев, а не обещание отсутствия всех
возможных дефектов или гарантия maintainer acceptance. Новые агенты,
image build/pull и production mutation не запускались.

## Готовые тексты и команды

Заголовок — первая строка соответствующего файла, остальное — цельный English
PR body. Перед созданием PR удалите заголовок из body, если вставляете его
отдельно в поле title.

- [№4: транспорт](PR-4-SCGI-TRANSPORT.md)
- [№13: alias guards](PR-13-RTORRENT-ALIAS-SURFACE.md)
- [№15: ручная проверка](PR-15-MANUAL-UPDATE-CHECKS.md)
- [№5: восстановление retrackers](PR-5-RETRACKERS-RECOVERY.md)
- [№14: XMLRPC policy](PR-14-XMLRPC-PROXY-POLICY.md)

Из основного checkout, сейчас можно публиковать эти независимые ветки:

```sh
git push -u origin up/scgi-transport-v2
git push -u origin up/rtorrent-alias-surface
git push -u origin up/rutracker-manual-entrypoints
```

Затем PR: base `Novik/ruTorrent:master`, head — соответствующая ветка
`IvanShift/ruTorrent`. Команды здесь **не выполнены**.

После принятия №4: fetch upstream, проверить ancestry/patch identity,
перенести только собственные commits №5/№14 на свежую базу в их worktree,
повторить gates; только после этого использовать:

```sh
git push -u origin up/retrackers-recovery-v2
git push -u origin up/xmlrpc-proxy-policy
```

Не выполнять слепой rebase поверх squash-merge №4: сначала установить точную
новую базу. Сейчас эти две ветки ещё stacked и не представлены как готовые
независимые PR против Novik/master.

## Точка продолжения

1. Сначала `git status`, refs, worktree inventory и fresh fetch; проверить,
   не опубликовал ли пользователь подготовленные ветки/master.
2. Основной workflow этого поручения закончен на локальной подготовке и squash.
   Полный `rutracker_check` не предлагать до отдельной полной приёмки.
3. По следующему поручению — review внешнего №6 на актуальном master,
   либо upstream feedback/refresh подготовленных веток. Не считать внешний
   candidate автоматически approved. Открытые №6–12/№16–18 не начинались.
4. Не трогать старые worktree и четыре root log-файла. SHA-256 всех четырёх
   до/после сохранён в `user-logs.sha256`; backup восстанавливает прежнюю историю.

План выполнения: [UPSTREAM-HANDOFF-AND-SQUASH-2026-09-05.md](UPSTREAM-HANDOFF-AND-SQUASH-2026-09-05.md).
Общий реестр: [STATUS-18-PACKAGES-2026-09-03.md](../2026-08-28-upstream-delivery/STATUS-18-PACKAGES-2026-09-03.md).
