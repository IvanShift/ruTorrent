# Код-ревью неотправленных коммитов `origin/master..25a0e2cb`, 2026-09-25

**Итог: 130 пунктов — 0 P1, 5 P2, 48 P3 и 77 nit.** 125 из них дало исходное ревью, ещё 5 добавила независимая проверка 2026-09-25 (N-S1, N-S2, VC-NEW-1, VC-NEW-2, VO-NEW-1); уровни и числа после неё пересчитаны, см. [Поправки после VERIFY-SUMMARY-2026-09-25](#поправки-после-verify-summary-2026-09-25). 24 пункта — старый долг: они уже были в `origin/master` (C20 — старый дефект, чью досягаемость для сессии loginmgr расширил `af56990f`). Есть ли каждый такой сценарий на проде, не измерялось. Дефектов поведения (баг, безопасность, регресс, ломающее изменение) 21, из них внесены этим диапазоном 11. Остальное — слабые тесты (31), комментарии (19), дублирование (15), мёртвый код (10) и прочие мелочи.

**Блокирует выкладку по правилу «только воспроизведённые P1 и P2 в дельте»:**

- [S1](#s1) · P2 · коммит `156c64af` — Правило «свежий Set-Cookie запрещает межхостовый редирект» ломает анонимные загрузки и ничего не защищает
- [X3](#x3) · P2 · коммит `74aea456` — Узкие формы новых записей $elevate отвергают одиночный `d.views.push_back_unique`, который Sonarr шлёт после импорта (вид `<app>_imported`; у Radarr тот же код). На origin/master вызов уходил недоверенным, на HEAD `decide()` отвечает reject, и обе двери отдают 403 до демона. Лабораторный rtorrent 0.16.22 исполняет такой вызов без доверия.
- Кроме того, [O9](#o9) (P3) делает красным Jest в CI, а зелёный Jest — условие выкладки по AGENTS.md. На HEAD падают 2 теста из 338. На `74aea456` падает только `tests/js/rtorrent.spec.js`: он требует 982 имени (:262 и :266), а в фикстуре 946. Ветке шага 0 достаточно исправить его. С `156c64af` добавляется `js/lang.spec.js`: ключ `accOriginRequired` есть в `en.js` и `ru.js` loginmgr, но его нет в остальных 24 языках. Любая выкладка, которая несёт этот ключ, пройдёт ворота CI, только если ключ есть во всех языках.

**P2, которые не блокируют: старый долг вне дельты, идёт в бэклог.**

- [X1](#x1) · коммит `74aea456` — Старые псевдонимы load_* и set_xmlrpc_size_limit уходят сырыми и на rtorrent 0.9.0–0.9.6 обходят санитайзер. С v0.9.7 этих имён нет. Эти версии вне заявленной в README поддержки (0.9.8 и 0.16.x), но `$legacyDenies` из того же коммита защищает имена этой эпохи, так что защита непоследовательна. На origin/master `decide()` ведёт себя так же (`send trusted=false`). Кейсы фикстуры 184/482, переименованные в дельте, закрепляют обход; это слабый тест внутри X1, отдельным пунктом не считается и выкладку не блокирует. Исправить отдельной малой выкладкой (ARCHITECTURE, шаг 1, пункт 6).
- [VC-NEW-2](#vc-new-2) · коммит `af56990f` (внесено в `29be3350`) — Tapochek считает удалённую тему недоступной: regex таблиц останавливается на первом `</table>`. Воспроизведено на снятом реальном ответе, на origin/master та же ошибка. По снимку парка 2026-08-19 торрентов Tapochek на нашем проде не было; позже не перепроверялось. Исправление — отдельная ранняя выкладка (ARCHITECTURE, шаг 1, пункт 5).
- [N-S1](#n-s1) · старое, код вне диапазона — Cookie плагина cookies уходят открытым текстом по http-ссылке на тот же хост. Выведено из кода и пробы на транспорте, живьём не воспроизведено.

## Поправки после VERIFY-SUMMARY-2026-09-25

2026-09-25 независимый проверяющий перепроверил все 125 пунктов этого ревью на тех же экспортах HEAD и `origin/master`, с пробами `decide()`, лабораторным rtorrent 0.16.22, прогонами Jest и снятым ответом Tapochek. Итог — [VERIFY-SUMMARY-2026-09-25.md](VERIFY-SUMMARY-2026-09-25.md), подробности — [VERIFY-X-25a0e2cb.md](VERIFY-X-25a0e2cb.md), [VERIFY-S-25a0e2cb.md](VERIFY-S-25a0e2cb.md), [VERIFY-C-25a0e2cb.md](VERIFY-C-25a0e2cb.md), [VERIFY-O-25a0e2cb.md](VERIFY-O-25a0e2cb.md), [VERIFY-ARCH-2026-09-25.md](VERIFY-ARCH-2026-09-25.md) и [VERIFY-CHECK-56-2026-09-25.md](VERIFY-CHECK-56-2026-09-25.md). Каждое замечание разобрано отдельно, часть — с повторными пробами. Ниже все решения по этому файлу, включая отклонённые. Числа пересчитаны: было 125 пунктов (0/2/47/76, старый долг 20), стало 130 (0/5/48/77, старый долг 24). Новые пункты старого долга учтены в строке коммита своей области, как X1 и C20.

| id | решение | что изменено или почему отклонено |
|---|---|---|
| X3 (и строка proxy1 CHECK, A8) | принято, P3→P2 | Поднят до P2 и внесён в блокирующий список. Добавлены клиент (Sonarr, у Radarr тот же код), пробы `decide()` на обоих деревьях, ответ демона, последствие, достижимость дверей. Уточнены версии для `d.throttle_name.set` и старых псевдонимов. «Как исправить» переписан: откат для 17 новых имён, RED-тесты, мутации. |
| X1 | частично | Уровень P2 остаётся, но это старый долг вне дельты: из блокирующего списка перенесён в «P2, которые не блокируют». «0.9.x» уточнено до 0.9.0–0.9.6; добавлено, почему пропуск — непоследовательность `$legacyDenies`. Исправление — отдельная выкладка, ARCHITECTURE, шаг 1, пункт 6. |
| X2, prod-facts | принято | Отсутствие conf/xmlrpc_proxy.php на томе прода подано как вывод из записей форка, а не как замер. |
| X5 | частично | Фраза «отказ ничего не защищает» ограничена пакетом, где каждый член поодиночке ушёл бы недоверенным. Смешанный пакет — отвергать; добавлен замер частичного применения на 0.16.22. Условие про демоны до 0.16.8 не принято: там и одиночные вызовы исполняются без проверки, пакет не шире. |
| X7 | частично | Добавлено условие достижимости rpc2.php (`$topDirectory` или флаг корня, иначе 503). Повышение до P2 не принято: старый долг, HEAD улучшает базу. |
| X12 | частично | Метка «мёртвый код» заменена на «лишнее повышение»: записи достижимы прямому клиенту. Возражение против удаления отклонено: базовый прокси эти сеттеры не повышал, и 0.16.22 отвечает им −507. |
| X19 | принято | Метка «мёртвый код» заменена на «страховка без теста». |
| X21 | отклонено | Повышение до P3 не принято: правило старше диапазона, отказ видим (403), код и шапка называют правило setter-only, панель такого вызова не шлёт. Остаётся nit. |
| X23 | принято | Добавлено окно в обратную сторону в rpc2.php; загрузчик политики вынести так, чтобы `decide()` остался без файловых зависимостей. |
| S2 | принято | «Обойти это пользователь не может» заменено описанием неудобного обхода через любую `%XX` в URL. |
| S3 | частично | Уточнено, что при перебитом сбросе банки cookie источника уходит со следующим запросом на любой хост; P3 и недостижимость в дереве остаются. |
| S7 | принято | Утверждение о поведении nginx/Apache/IIS/Cloudflare помечено как не сверенное с захватами. |
| S8 | принято | Правило переформулировано: подключать файлы чужого плагина только после `isPluginRegistered()`. |
| S9 | принято | Второй вариант исправления (снять условие) убран: условие закреплено тестом как контракт LG17. |
| S10 | принято | «Анонимный fetch» заменено: fetch идёт без сессии loginmgr, но с cookies плагина cookies и `:COOKIE:`; тест должен различать три источника cookie. |
| S16 | частично, P3→nit | Ошибка про matrix.sh исправлена (экспорт берёт рабочее дерево). Дефекта теста нет: фикстура намеренно читает отслеживаемый conf/config.php. Исправление через `unset` отозвано; остаётся неточное слово «shipped». Тема «слабый тест» заменена на «соглашения». |
| S28 | принято | Строка 146 названа избыточной, а не «не может упасть». |
| N-S1 | принято, новый P2, старый долг | Добавлен пункт после S32. Номера строк исправлены, описание формата записи плагина cookies без схемы, `:COOKIE:` отделён от главного источника, два варианта исправления с их ценой. Учтён в итогах, как остальные новые пункты. |
| N-S2 | частично, новый P3, старый долг | Добавлен пункт после N-S1. По умолчанию рекомендовано записать повтор условных заголовков как допустимый, а не срезать их. Тема «соглашения», а не «безопасность»: слабость не показана, не записан контракт Snoopy; в «Дефекты поведения» не входит. |
| VC-NEW-2 (решения по C и по CHECK) | принято, новый P2, старый долг | Добавлен пункт после C41 со снятым ответом, таблицей результатов и планом исправления. В итогах и в строке `af56990f` учтён по правилу старого долга. Наличие на проде дано по снимку парка 2026-08-19. |
| VC-NEW-1 | принято, новый nit, старый долг | Добавлен пункт после VC-NEW-2: четыре обработчика читают status/results страницы темы после необработанного отказа fetch. |
| C12, tests24 | принято | «HEAD строго лучше» ограничено синтетикой; прод-факт дан по снимку 2026-08-19; ответ удалённой темы уже снят; внешний контрпример — VC-NEW-2. NBSP-сужение на реальной странице не проявляется. |
| C20 | частично, nit→P3 | Уровень P3 и пометка «старое, расширено диапазоном». Добавлены сверенные предусловия: сессию loginmgr в эту банку впервые кладёт af56990f. P2 не принят: нет воспроизведения, контроль над href не показан. |
| C2 | принято | В «Как исправить» добавлено, что общий предикат обязан различать чужую строку и RuTracker. |
| C7 | принято | Тест должен проверять все три поля и обе формы записи вердикта. |
| O1 | частично | Безвредность шага назад помечена как оценка из исходников, не замер. |
| O5 | отклонено (оговорка проверяющего) | Оговорка «для Ctrl-C не показано» снята замером на уменьшенной модели; замер добавлен в пункт. |
| O9 | принято | Добавлены полные прогоны Jest на HEAD, `74aea456` и `origin/master`; строка шапки переписана: ветке шага 0 хватает одной правки. |
| O12 | частично | Утверждение о `php -n` в CI помечено как вывод, а не замер. |
| O22 | частично | Переименование помечено как необязательное. |
| VO-NEW-1 (решения по O и по CHECK) | принято, новый P3 в дельте | Добавлен пункт после O22: уборка удаляет каталог идущего прогона. |
| VO-CROSS-prod20 | принято | «Старый долг, который есть и на проде сейчас» заменено: пункты были в `origin/master`, наличие каждого сценария на проде не измерялось. |

## По коммитам

| Коммит | P1 | P2 | P3 | nit |
|---|---:|---:|---:|---:|
| `74aea456` прокси | 0 | 2 | 10 | 19 |
| `156c64af` Snoopy и loginmgr | 0 | 2 | 15 | 17 |
| `af56990f` чекер и трекеры | 0 | 1 | 13 | 29 |
| `fb0d8711` тесты, ожидания, инструменты | 0 | 0 | 10 | 12 |

Старый долг учтён в строке коммита своей области: X1 в `74aea456`, N-S1 и N-S2 в `156c64af`, C20, VC-NEW-1 и VC-NEW-2 в `af56990f`. C20 — старый дефект, чью досягаемость для сессии loginmgr расширил `af56990f`; в списке ниже он не стоит, потому что сама склейка URL была и на origin/master. Сумма таблицы равна итогу, 130.

Дефекты поведения, внесённые диапазоном (не старый долг):

- [X3](#x3) · P2 · `74aea456` — Узкие формы новых записей $elevate отвергают прямые одиночные вызовы, которые раньше уходили недоверенными (Sonarr `*_imported`, воспроизведено)
- [S1](#s1) · P2 · `156c64af` — Правило «свежий Set-Cookie запрещает межхостовый редирект» ломает анонимные загрузки и ничего не защищает
- [S12](#s12) · P3 · `156c64af` — Копия шаблона conf.php с вписанным origin в conf.local.php или per-user conf.php молча не применяется
- [C1](#c1) · P3 · `af56990f` — Удержание DELETED/ABSORBED решается по коду ответа, хотя обработчик уже успел узнать и записать новое
- [C4](#c4) · P3 · `af56990f` — После простого 403 от details проба download выбрасывает NOT_NEED/ERROR из createTorrent(); имя и комментарий фильтра остались от удалённой защёлки
- [O2](#o2) · P3 · `fb0d8711` — digest() не покрывает env_check.php, js/, lang/ и tasks/*.php, и хук пропускает набор при красном дереве
- [O3](#o3) · P3 · `fb0d8711` — Неиндексированное удаление или переименование файла роняет digest(), и matrix.sh не запускает ни одной ноги
- [O5](#o5) · P3 · `fb0d8711` — Нет trap на INT/TERM: после Ctrl-C ноги и контейнеры продолжают работать сиротами
- [O8](#o8) · P3 · `fb0d8711` — Красный прогон на том же дереве не отзывает записанный last-green
- [O9](#o9) · P3 · `74aea456` — Фикстуру LISTMETHODS пересняли на 946 имён, а JS-спека по-прежнему требует 982: job jest покраснеет
- [VO-NEW-1](#vo-new-1) · P3 · `fb0d8711` — Уборка удаляет каталог идущего прогона: ложный code=99

## Сводка

| Уровень | Всего | Внесено диапазоном | Старый долг |
|---|---:|---:|---:|
| P1 | 0 | 0 | 0 |
| P2 | 5 | 2 | 3 |
| P3 | 48 | 33 | 15 |
| nit | 77 | 71 | 6 |

| Тема | Пунктов |
|---|---:|
| слабый тест | 31 |
| комментарий | 19 |
| дублирование | 15 |
| дефект | 15 |
| мёртвый код | 10 |
| диагностика | 10 |
| заявление расходится с кодом | 8 |
| странная логика | 8 |
| безопасность | 3 |
| регресс | 3 |
| переносимость | 2 |
| соглашения | 3 |
| упрощение | 1 |
| лишнее повышение | 1 |
| страховка без теста | 1 |

## Указатель

| Id | Уровень | Тема | | Коммит | Место | Суть |
|---|---|---|---|---|---|---|
| [N-S1](#n-s1) | P2 | безопасность | старое | `156c64af` | [php/Snoopy.class.inc:240](../../php/Snoopy.class.inc#L240) | Cookie плагина cookies уходят открытым текстом по http-ссылке на тот же хост |
| [S1](#s1) | P2 | регресс |  | `156c64af` | [php/Snoopy.class.inc:443](../../php/Snoopy.class.inc#L443) | Правило «свежий Set-Cookie запрещает межхостовый редирект» ломает анонимные загрузки и ничего не защищает |
| [VC-NEW-2](#vc-new-2) | P2 | дефект | старое | `af56990f` | [plugins/rutracker_check/trackers/tapocheknet.php:35](../../plugins/rutracker_check/trackers/tapocheknet.php#L35) | Tapochek считает удалённую тему недоступной: regex таблиц останавливается на первом `</table>` и проглатывает вложенную системную таблицу |
| [X1](#x1) | P2 | безопасность | старое | `74aea456` | [php/xmlrpc_proxy.php:165](../../php/xmlrpc_proxy.php#L165) | Старые псевдонимы load_* и set_xmlrpc_size_limit уходят сырыми и на rtorrent 0.9.0–0.9.6 обходят санитайзер |
| [X3](#x3) | P2 | регресс |  | `74aea456` | [php/xmlrpc_proxy.php:123](../../php/xmlrpc_proxy.php#L123) | Узкие формы новых записей $elevate отвергают прямые одиночные вызовы, которые раньше уходили недоверенными |
| [C1](#c1) | P3 | дефект |  | `af56990f` | [plugins/rutracker_check/check.php:2163](../../plugins/rutracker_check/check.php#L2163) | Удержание DELETED/ABSORBED решается по коду ответа, хотя обработчик уже успел узнать и записать новое |
| [C10](#c10) | P3 | слабый тест |  | `af56990f` | [tests/plugins/rutracker_check/KinozalHandlerTest.php:631](../../tests/plugins/rutracker_check/KinozalHandlerTest.php#L631) | Кейс «челлендж распознан по заголовку без тела» не может упасть из-за новой пробы 403 |
| [C11](#c11) | P3 | слабый тест |  | `af56990f` | [tests/plugins/rutracker_check/KinozalHandlerTest.php:652](../../tests/plugins/rutracker_check/KinozalHandlerTest.php#L652) | Проба download после простого 403 закреплена только для UPTODATE; ветки null и DELETED не покрыты |
| [C12](#c12) | P3 | слабый тест |  | `af56990f` | [tests/plugins/rutracker_check/SiblingTrackersTest.php:334](../../tests/plugins/rutracker_check/SiblingTrackersTest.php#L334) | Новая CP1251-ветка удаления Tapochek закреплена синтетикой, повторяющей константы обработчика |
| [C2](#c2) | P3 | дефект | старое | `af56990f` | [plugins/rutracker_check/check.php:2130](../../plugins/rutracker_check/check.php#L2130) | Правило удержания не охватывает осевший NOT_NEED (topic-status/superseded), который isSettled() считает таким же окончательным |
| [C20](#c20) | P3 | безопасность | старое | `af56990f` | [plugins/rutracker_check/trackers/anidub.php:66](../../plugins/rutracker_check/trackers/anidub.php#L66) | Ссылка на .torrent AniDUB проверяется незаякоренным regex и склеивается строкой с хостом |
| [C3](#c3) | P3 | дефект | старое | `af56990f` | [plugins/rutracker_check/check.php:2157](../../plugins/rutracker_check/check.php#L2157) | STE_UNCHANGED для нетерминального состояния штампует свежие chk-time/chk-stime без нового ответа |
| [C4](#c4) | P3 | дефект |  | `af56990f` | [plugins/rutracker_check/trackers/kinozal.php:299](../../plugins/rutracker_check/trackers/kinozal.php#L299) | После простого 403 от details проба download выбрасывает NOT_NEED/ERROR из createTorrent(); имя и комментарий фильтра остались от удалённой защёлки |
| [C5](#c5) | P3 | диагностика |  | `af56990f` | [plugins/rutracker_check/trackers/kinozal.php:399](../../plugins/rutracker_check/trackers/kinozal.php#L399) | Обычный путь Kinozal не проверяет результат fetchComplex(download) и читает устаревший status/results от details |
| [C6](#c6) | P3 | дефект | старое | `af56990f` | [plugins/rutracker_check/updatepass.php:42](../../plugins/rutracker_check/updatepass.php#L42) | Без читаемого комментария проверка владельца срабатывает в пользу RuTracker: смешанная строка получает бесплатный UPTODATE |
| [C7](#c7) | P3 | странная логика | старое | `af56990f` | [plugins/rutracker_check/updatepass.php:498](../../plugins/rutracker_check/updatepass.php#L498) | Быстрая ветка 'transport' затирает осевший DELETED/ABSORBED на CANT_REACH, вопреки новому правилу удержания в run() |
| [C8](#c8) | P3 | заявление расходится с кодом | старое | `af56990f` | [tests/plugins/rutracker_check/FetchErrorTest.php:28](../../tests/plugins/rutracker_check/FetchErrorTest.php#L28) | Тест обещает поймать переформулировку сообщения Snoopy, но сверяет только число присваиваний |
| [C9](#c9) | P3 | слабый тест |  | `af56990f` | [tests/plugins/rutracker_check/KinozalHandlerTest.php:293](../../tests/plugins/rutracker_check/KinozalHandlerTest.php#L293) | Кейс про защёлку download больше её не видит: тема E отвечает UPTODATE до проверки downloadAbandoned |
| [N-S2](#n-s2) | P3 | соглашения | старое | `156c64af` | [plugins/rss/rss.php:141](../../plugins/rss/rss.php#L141) | Условные заголовки RSS повторяются на чужом хосте анонимного редиректа |
| [O1](#o1) | P3 | странная логика | старое | `fb0d8711` | [plugins/erasedata/removewithdata.php:2244](../../plugins/erasedata/removewithdata.php#L2244) | Дедлайн ожидания подтверждения в erasedataWaitForDrainAcknowledgement() считается по стенным часам |
| [O10](#o10) | P3 | слабый тест | старое | `fb0d8711` | [tests/plugins/erasedata/RemoveWithDataTest.php:11073](../../tests/plugins/erasedata/RemoveWithDataTest.php#L11073) | testInverseHashBatchOrderNeverDeadlocksAndKeepsExactOutcomes не проверяет ни двойное стирание, ни порядок блокировок |
| [O2](#o2) | P3 | дефект |  | `fb0d8711` | [tasks/matrix.sh:51](../../tasks/matrix.sh#L51) | digest() не покрывает env_check.php, js/, lang/ и tasks/*.php, и хук пропускает набор при красном дереве |
| [O3](#o3) | P3 | дефект |  | `fb0d8711` | [tasks/matrix.sh:57](../../tasks/matrix.sh#L57) | Неиндексированное удаление или переименование файла роняет digest(), и matrix.sh не запускает ни одной ноги |
| [O4](#o4) | P3 | переносимость |  | `fb0d8711` | [tasks/matrix.sh:79](../../tasks/matrix.sh#L79) | Бюджет TMPDIR проверяется для всех ног по хостовому пути, и полный запуск отказывает при HOME длиннее 11 байт |
| [O5](#o5) | P3 | дефект |  | `fb0d8711` | [tasks/matrix.sh:103](../../tasks/matrix.sh#L103) | Нет trap на INT/TERM: после Ctrl-C ноги и контейнеры продолжают работать сиротами |
| [O6](#o6) | P3 | слабый тест |  | `fb0d8711` | [tasks/matrix.sh:111](../../tasks/matrix.sh#L111) | Нога prod-kinozal проверяет отсутствие iconv только до require, дальше обработчик работает с заглушкой TestLib |
| [O7](#o7) | P3 | слабый тест |  | `fb0d8711` | [tasks/matrix.sh:131](../../tasks/matrix.sh#L131) | Полноту экспорта и число прогнанных файлов никто не проверяет: частичный экспорт даёт зелёные ноги и last-green |
| [O8](#o8) | P3 | дефект |  | `fb0d8711` | [tasks/matrix.sh:145](../../tasks/matrix.sh#L145) | Красный прогон на том же дереве не отзывает записанный last-green |
| [O9](#o9) | P3 | регресс |  | `74aea456` | [tests/js/rtorrent.spec.js:262](../../tests/js/rtorrent.spec.js#L262) | Фикстуру LISTMETHODS пересняли на 946 имён, а JS-спека по-прежнему требует 982: job jest покраснеет |
| [S10](#s10) | P3 | диагностика |  | `156c64af` | [plugins/loginmgr/accounts.php:379](../../plugins/loginmgr/accounts.php#L379) | http-ссылки на шесть трекеров теперь молча уходят без сессии |
| [S11](#s11) | P3 | слабый тест |  | `156c64af` | [plugins/loginmgr/accounts/NNMClub.php:47](../../plugins/loginmgr/accounts/NNMClub.php#L47) | Ветка '..' в нормализации пути NNMClub не закреплена, а префикс /forum/ проверяется по другому пути |
| [S12](#s12) | P3 | дефект |  | `156c64af` | [plugins/loginmgr/conf.php:8](../../plugins/loginmgr/conf.php#L8) | Копия шаблона conf.php с вписанным origin в conf.local.php или per-user conf.php молча не применяется |
| [S13](#s13) | P3 | диагностика |  | `156c64af` | [plugins/rss/rss.php:86](../../plugins/rss/rss.php#L86) | При загрузке торрента из элемента ленты причина credential-redirect-refused теряется |
| [S14](#s14) | P3 | слабый тест |  | `156c64af` | [tests/php/SnoopyTest.php:214](../../tests/php/SnoopyTest.php#L214) | Тест про Basic из userinfo не проверяет, что первый запрос вообще нёс заголовок |
| [S15](#s15) | P3 | слабый тест |  | `156c64af` | [tests/plugins/loginmgr/CredentialBoundaryTest.php:33](../../tests/plugins/loginmgr/CredentialBoundaryTest.php#L33) | Два почти одинаковых двойника транспорта несут свою копию условия импорта cookie, и оно не закреплено |
| [S2](#s2) | P3 | дефект | старое | `156c64af` | [php/Snoopy.class.inc:268](../../php/Snoopy.class.inc#L268) | fetch() не декодирует userinfo из URL, Basic-авторизация уходит с %-кодированными байтами |
| [S3](#s3) | P3 | дефект | старое | `156c64af` | [php/Snoopy.class.inc:308](../../php/Snoopy.class.inc#L308) | Отказ checkTarget() на втором хопе доверенного перехода оставляет взведённым отложенный импорт cookie |
| [S4](#s4) | P3 | слабый тест |  | `156c64af` | [php/Snoopy.class.inc:427](../../php/Snoopy.class.inc#L427) | Список учётных заголовков продублирован в двух методах, а Proxy-Authorization и сырой Authorization под redirectTrust тестами не закреплены |
| [S5](#s5) | P3 | слабый тест |  | `156c64af` | [php/Snoopy.class.inc:431](../../php/Snoopy.class.inc#L431) | Требование https и проверка источника в ветке доверия аккаунту не закреплены тестами |
| [S6](#s6) | P3 | странная логика |  | `156c64af` | [php/Snoopy.class.inc:440](../../php/Snoopy.class.inc#L440) | Непустое тело запроса считается учётными данными, хотя через редирект тело не уходит никогда |
| [S7](#s7) | P3 | дефект | старое | `156c64af` | [php/Snoopy.class.inc:781](../../php/Snoopy.class.inc#L781) | Location без пробела после двоеточия превращается в редирект на корень исходного хоста |
| [S8](#s8) | P3 | переносимость |  | `156c64af` | [plugins/extsearch/engines/YggTorrent.php:81](../../plugins/extsearch/engines/YggTorrent.php#L81) | Движок extsearch YggTorrent без проверки подключает файлы плагина loginmgr |
| [S9](#s9) | P3 | диагностика |  | `156c64af` | [plugins/loginmgr/accounts.php:247](../../plugins/loginmgr/accounts.php#L247) | commonAccount::check() молча пропускает обновление сессии у аккаунта без $url |
| [VO-NEW-1](#vo-new-1) | P3 | дефект |  | `fb0d8711` | [tasks/matrix.sh:158](../../tasks/matrix.sh#L158) | Уборка удаляет каталог ещё идущего прогона, и тот кончается ложным code=99 |
| [X10](#x10) | P3 | слабый тест |  | `74aea456` | [tests/php/XMLRPCProxyTest.php:106](../../tests/php/XMLRPCProxyTest.php#L106) | Пакет «снять игнор» планировщика не проверяется; ветка '' формы scheduler_throttle не закреплена |
| [X11](#x11) | P3 | слабый тест |  | `74aea456` | [tests/php/XMLRPCProxyTest.php:123](../../tests/php/XMLRPCProxyTest.php#L123) | Потолок 1048576 формы socket_alloc не закреплён: единственный отрицательный случай отсекает регулярка |
| [X2](#x2) | P3 | заявление расходится с кодом |  | `74aea456` | [conf/xmlrpc_proxy.php:45](../../conf/xmlrpc_proxy.php#L45) | Upgrade-заметка и подсказка в логе называют только d.directory.base.set, а не f.set_create_queued/f.set_resize_queued |
| [X4](#x4) | P3 | диагностика |  | `74aea456` | [php/xmlrpc_proxy.php:1159](../../php/xmlrpc_proxy.php#L1159) | system.multicall отбрасывает журнал решений членов: теряются WARNING о локальном пути и причины отказа |
| [X5](#x5) | P3 | странная логика | старое | `74aea456` | [php/xmlrpc_proxy.php:1160](../../php/xmlrpc_proxy.php#L1160) | system.multicall с читающим членом по-прежнему получает 403, хотя по одному эти вызовы уходят недоверенными |
| [X6](#x6) | P3 | диагностика | старое | `74aea456` | [php/xmlrpc_proxy.php:1254](../../php/xmlrpc_proxy.php#L1254) | Отказ по границе каталога пишется в лог как «команды нет в политике» |
| [X7](#x7) | P3 | заявление расходится с кодом | старое | `74aea456` | [php/xmlrpc_proxy.php:1655](../../php/xmlrpc_proxy.php#L1655) | Исключение cat пропускает только строку ratio; включённый по умолчанию show_peers_like_wtorrent по-прежнему роняет сырой список |
| [X8](#x8) | P3 | слабый тест |  | `74aea456` | [tests/php/XMLRPCProxyEntrypointTest.php:216](../../tests/php/XMLRPCProxyEntrypointTest.php#L216) | Пометка «[built-in policy]» закреплена только в одну сторону |
| [X9](#x9) | P3 | слабый тест |  | `74aea456` | [tests/php/XMLRPCProxyTest.php:99](../../tests/php/XMLRPCProxyTest.php#L99) | Канонический payload доверенного system.multicall не закреплён ни одним тестом |
| [C13](#c13) | nit | мёртвый код |  | `af56990f` | [plugins/rutracker_check/announce.php:113](../../plugins/rutracker_check/announce.php#L113) | RuTrackerAnnounce::hostKey() осталась публичной обёрткой над UrlHost::normalize() без внешних вызовов |
| [C14](#c14) | nit | упрощение |  | `af56990f` | [plugins/rutracker_check/check.php:219](../../plugins/rutracker_check/check.php#L219) | Цикл по строкам в announceAuthorityFor() не влияет на исход и задаёт второе правило юрисдикции |
| [C15](#c15) | nit | заявление расходится с кодом |  | `af56990f` | [plugins/rutracker_check/check.php:2133](../../plugins/rutracker_check/check.php#L2133) | Предполётная запись того же значения chk-state не доказывает, что демон принимает запись, хотя комментарий это обещает |
| [C16](#c16) | nit | диагностика |  | `af56990f` | [plugins/rutracker_check/check.php:2154](../../plugins/rutracker_check/check.php#L2154) | Строка лога «nothing can be checked and the torrent is flagged» ложна для DELETED/ABSORBED |
| [C17](#c17) | nit | мёртвый код |  | `af56990f` | [plugins/rutracker_check/check.php:2177](../../plugins/rutracker_check/check.php#L2177) | Конъюнкт `&& !$retainedTerminal` в $performed никогда не решает исход |
| [C18](#c18) | nit | мёртвый код |  | `af56990f` | [plugins/rutracker_check/check.php:2181](../../plugins/rutracker_check/check.php#L2181) | Завершающий return($state != STE_CANT_REACH_TRACKER) всегда истинен |
| [C19](#c19) | nit | диагностика |  | `af56990f` | [plugins/rutracker_check/fetcherror.php:82](../../plugins/rutracker_check/fetcherror.php#L82) | Токен 'redirect-refused' в логе плагина недостижим; комментарий о порядке строк Snoopy неверен |
| [C21](#c21) | nit | комментарий |  | `af56990f` | [plugins/rutracker_check/trackers/kinozal.php:184](../../plugins/rutracker_check/trackers/kinozal.php#L184) | Для details лог и комментарии говорят «пропускается этот endpoint», а останавливается весь обработчик Kinozal |
| [C22](#c22) | nit | дублирование |  | `af56990f` | [plugins/rutracker_check/trackers/kinozal.php:182](../../plugins/rutracker_check/trackers/kinozal.php#L182) | guestAnswer() и unreachable() заканчиваются одинаковым хвостом защёлки |
| [C23](#c23) | nit | дублирование |  | `af56990f` | [plugins/rutracker_check/trackers/kinozal.php:272](../../plugins/rutracker_check/trackers/kinozal.php#L272) | Ярлык «$detailsSilent и хеш совпал → UPTODATE» повторяет первую проверку createTorrent() |
| [C24](#c24) | nit | комментарий |  | `af56990f` | [plugins/rutracker_check/trackers/nnmclub.php:195](../../plugins/rutracker_check/trackers/nnmclub.php#L195) | Комментарии обещают CANT_REACH для страниц входа NNMClub, а предикат признаков входа не содержит |
| [C25](#c25) | nit | дублирование |  | `af56990f` | [plugins/rutracker_check/trackers/nnmclub.php:285](../../plugins/rutracker_check/trackers/nnmclub.php#L285) | Вызывающие isAllowedTrackerHost() по-прежнему передают strtolower(host), хотя хелпер нормализует сам |
| [C26](#c26) | nit | комментарий | старое | `af56990f` | [plugins/rutracker_check/trackers/rutracker.php:339](../../plugins/rutracker_check/trackers/rutracker.php#L339) | Блок о подтверждении удаления стоит над deletionConfirmedOnce(), а описывает confirmDeletion() |
| [C27](#c27) | nit | комментарий |  | `af56990f` | [plugins/rutracker_check/updatepass.php:42](../../plugins/rutracker_check/updatepass.php#L42) | isForeignAuthoritative() — однострочная обёртка с лишним $row, а комментарии называют её «ownership test» |
| [C28](#c28) | nit | странная логика |  | `af56990f` | [plugins/rutracker_check/updatepass.php:445](../../plugins/rutracker_check/updatepass.php#L445) | `$settled \|\|` в условии бесплатного прохода поглощён $unresolvedSuccessor |
| [C29](#c29) | nit | слабый тест | условно | `af56990f` | [tests/plugins/rutracker_check/CheckerTest.php:1923](../../tests/plugins/rutracker_check/CheckerTest.php#L1923) | Поставляемые LOAD_WAIT_ATTEMPTS и TOLOKA_CLOUDFLARE_PAUSE не закреплены литералами |
| [C30](#c30) | nit | мёртвый код |  | `af56990f` | [tests/plugins/rutracker_check/CheckerTest.php:4004](../../tests/plugins/rutracker_check/CheckerTest.php#L4004) | Поле 'expect' в testTransientFailurePreservesTerminalVerdict вычисляется для всех строк, а читается только для UPTODATE |
| [C31](#c31) | nit | дублирование |  | `af56990f` | [tests/plugins/rutracker_check/CheckerTest.php:4009](../../tests/plugins/rutracker_check/CheckerTest.php#L4009) | Блок подготовки сессии скопирован в три новых теста; копия унаследовала «cold»-имена и удаление без finally |
| [C32](#c32) | nit | комментарий |  | `af56990f` | [tests/plugins/rutracker_check/FetchErrorTest.php:112](../../tests/plugins/rutracker_check/FetchErrorTest.php#L112) | Комментарий об якорях шаблонов потерял исключение curl-transfer |
| [C33](#c33) | nit | слабый тест |  | `af56990f` | [tests/plugins/rutracker_check/KinozalHandlerTest.php:118](../../tests/plugins/rutracker_check/KinozalHandlerTest.php#L118) | kinozalMissingBlock(): мёртвый параметр $marker и скрытое утверждение в конструкторе фикстуры |
| [C34](#c34) | nit | слабый тест |  | `af56990f` | [tests/plugins/rutracker_check/KinozalHandlerTest.php:1051](../../tests/plugins/rutracker_check/KinozalHandlerTest.php#L1051) | UTF-8 маркер удаления Kinozal не наблюдался, но тест и комментарий выдают его за подтверждённый |
| [C35](#c35) | nit | комментарий |  | `af56990f` | [tests/plugins/rutracker_check/SiblingTrackersTest.php:129](../../tests/plugins/rutracker_check/SiblingTrackersTest.php#L129) | «so the other three have same-hash coverage» стоит над блоком с двумя проверками |
| [C36](#c36) | nit | мёртвый код |  | `af56990f` | [tests/plugins/rutracker_check/TestLib.php:870](../../tests/plugins/rutracker_check/TestLib.php#L870) | strictCp1251() и заглушка iconv() больше никем не используются, но диапазон добавил им охрану и два самопроверочных кейса |
| [C37](#c37) | nit | слабый тест |  | `af56990f` | [tests/plugins/rutracker_check/TestLib.php:943](../../tests/plugins/rutracker_check/TestLib.php#L943) | Двойник Snoopy держит lastredirectaddr между запросами, а настоящий Snoopy теперь его сбрасывает; строка kinozal.php:398 мертва |
| [C38](#c38) | nit | комментарий |  | `af56990f` | [tests/plugins/rutracker_check/UpdatePassTest.php:1379](../../tests/plugins/rutracker_check/UpdatePassTest.php#L1379) | Комментарий описывает владение как «первое совпадение фильтра», которое этот же диапазон отменил |
| [C39](#c39) | nit | дублирование |  | `af56990f` | [tests/plugins/rutracker_check/UpdatePassTest.php:1572](../../tests/plugins/rutracker_check/UpdatePassTest.php#L1572) | Шесть новых тестов вручную повторяют тело upRunPass(); проверка «ничего не записано» написана пятью способами |
| [C40](#c40) | nit | мёртвый код |  | `af56990f` | [tests/plugins/rutracker_check/UpdatePassTest.php:3876](../../tests/plugins/rutracker_check/UpdatePassTest.php#L3876) | Циклы по массиву из одного элемента array('checker') после удаления шва |
| [C41](#c41) | nit | слабый тест |  | `af56990f` | [tests/plugins/rutracker_check/UpdatePassTest.php:4569](../../tests/plugins/rutracker_check/UpdatePassTest.php#L4569) | Имя кейса обещает проверку всех authority-хостов Kinozal, а перебирается только SITE_HOSTS |
| [O11](#o11) | nit | заявление расходится с кодом |  | `fb0d8711` | [AGENTS.md:287](../../AGENTS.md#L287) | Раздел о скорости набора: приписка «rest of the table still holds» ложна, вывод про параллелизм устарел |
| [O12](#o12) | nit | заявление расходится с кодом |  | `fb0d8711` | [AGENTS.md:349](../../AGENTS.md#L349) | php:7.4-cli названа «the CI floor», но совпадает с CI только по версии PHP |
| [O13](#o13) | nit | заявление расходится с кодом |  | `fb0d8711` | [AGENTS.md:465](../../AGENTS.md#L465) | Пункт про заглушку iconv() в TestLib описывает состояние до af56990f |
| [O14](#o14) | nit | комментарий |  | `fb0d8711` | [tasks/matrix.sh:43](../../tasks/matrix.sh#L43) | Сокет фикстуры лежит на 49 байт ниже TMPDIR, а не на 48 |
| [O15](#o15) | nit | слабый тест |  | `fb0d8711` | [tasks/matrix.sh:107](../../tasks/matrix.sh#L107) | prod-kinozal запускает php85 без php-test.ini, и Deprecated проходит молча; шаблон отказов скопирован из php-test.sh |
| [O16](#o16) | nit | странная логика |  | `fb0d8711` | [tasks/matrix.sh:158](../../tasks/matrix.sh#L158) | Уборка по маске `2*` перестанет работать в 2030 году, ранние выходы оставляют пустые каталоги, `mkdir -p "$run"` мёртв |
| [O17](#o17) | nit | комментарий |  | `fb0d8711` | [tests/plugins/erasedata/CollectorFixture.php:1245](../../tests/plugins/erasedata/CollectorFixture.php#L1245) | Докблок shortenAcknowledgementWait(): «An acknowledgement case does not call this helper» противоречит именам вызывающих кейсов |
| [O18](#o18) | nit | дублирование |  | `fb0d8711` | [tests/plugins/erasedata/RemoveWithDataTest.php:10371](../../tests/plugins/erasedata/RemoveWithDataTest.php#L10371) | Предикат «acknowledged === generation, generation не нулевой» переписан вручную пять раз |
| [O19](#o19) | nit | комментарий |  | `fb0d8711` | [tests/plugins/erasedata/RemoveWithDataTest.php:10797](../../tests/plugins/erasedata/RemoveWithDataTest.php#L10797) | Комментарий обещает, что stderr входит в вердикт, а проверяется только код выхода |
| [O20](#o20) | nit | слабый тест | старое | `fb0d8711` | [tests/plugins/erasedata/RemoveWithDataTest.php:11081](../../tests/plugins/erasedata/RemoveWithDataTest.php#L11081) | testProducerAndWorkerRaceKeepOneScheduleAndOneWorker обещает «one active worker», но допуск worker не проверяет |
| [O21](#o21) | nit | комментарий |  | `fb0d8711` | [tests/plugins/erasedata/RemoveWithDataTest.php:12390](../../tests/plugins/erasedata/RemoveWithDataTest.php#L12390) | Сообщение «a real guarded child acknowledged, so the shared recovery really ran» делает неверный вывод |
| [O22](#o22) | nit | соглашения |  | `fb0d8711` | [tests/plugins/retrackers/UpdateTest.php:6188](../../tests/plugins/retrackers/UpdateTest.php#L6188) | Имя testTheShippedWaitsAreFiveSecondsAndAQuarter читается как одно ожидание в 5¼ с |
| [S16](#s16) | nit | соглашения |  | `156c64af` | [tests/plugins/loginmgr/YggConfigurationTest.php:59](../../tests/plugins/loginmgr/YggConfigurationTest.php#L59) | Тест «shipped default» Ygg читает отслеживаемый conf/config.php, и имя это не называет |
| [S17](#s17) | nit | комментарий |  | `156c64af` | [php/urlhost.php:111](../../php/urlhost.php#L111) | Докблок urlIsOneOf() скрывает, что функция решает и доверие Snoopy к редиректам |
| [S18](#s18) | nit | заявление расходится с кодом |  | `156c64af` | [plugins/loginmgr/README.md:34](../../plugins/loginmgr/README.md#L34) | README описывает правило редиректов в состоянии до NEW-E |
| [S19](#s19) | nit | дублирование |  | `156c64af` | [plugins/loginmgr/accounts.php:194](../../plugins/loginmgr/accounts.php#L194) | Обёртка redirectTrust дословно скопирована в fetch() и check() |
| [S20](#s20) | nit | дублирование |  | `156c64af` | [plugins/loginmgr/accounts.php:334](../../plugins/loginmgr/accounts.php#L334) | configurationRequired считается двумя копиями проверки по имени 'YggTorrent' |
| [S21](#s21) | nit | дублирование |  | `156c64af` | [plugins/loginmgr/accounts/LostFilm.php:45](../../plugins/loginmgr/accounts/LostFilm.php#L45) | LostFilm::getDownloadId() повторяет разбор RUTracker::getDownloadId() без его обоснования |
| [S22](#s22) | nit | комментарий |  | `156c64af` | [plugins/loginmgr/accounts/YggTorrent.php:13](../../plugins/loginmgr/accounts/YggTorrent.php#L13) | Комментарий про разделители оказался над проверкой пустого origin |
| [S23](#s23) | nit | мёртвый код |  | `156c64af` | [plugins/loginmgr/accounts/YggTorrent.php:39](../../plugins/loginmgr/accounts/YggTorrent.php#L39) | Условие порт > 65535 недостижимо, такой origin получает причину invalid-url |
| [S24](#s24) | nit | странная логика |  | `156c64af` | [plugins/loginmgr/init.js:99](../../plugins/loginmgr/init.js#L99) | Предупреждение про $yggTorrentOrigin показывается и для выключенного аккаунта |
| [S25](#s25) | nit | слабый тест |  | `156c64af` | [tests/php/SnoopyTest.php:119](../../tests/php/SnoopyTest.php#L119) | Часть веток ядра Snoopy закреплена только тестом плагина loginmgr |
| [S26](#s26) | nit | слабый тест |  | `156c64af` | [tests/php/UrlHostTest.php:47](../../tests/php/UrlHostTest.php#L47) | Проверка 'a non-string folds to nothing' нестрогая, а защита пустого кандидата ни на что не влияет |
| [S27](#s27) | nit | мёртвый код |  | `156c64af` | [tests/plugins/loginmgr/AccountSelectionTest.php:243](../../tests/plugins/loginmgr/AccountSelectionTest.php#L243) | Мёртвый ключ 'ruTrackerAccount' и шесть строк, дублирующих новый перебор аккаунтов |
| [S28](#s28) | nit | слабый тест |  | `156c64af` | [tests/plugins/loginmgr/CredentialBoundaryTest.php:146](../../tests/plugins/loginmgr/CredentialBoundaryTest.php#L146) | Одна проверка в тесте анонимной цепочки не может упасть, вторая избыточна |
| [S29](#s29) | nit | слабый тест |  | `156c64af` | [tests/plugins/loginmgr/CredentialBoundaryTest.php:162](../../tests/plugins/loginmgr/CredentialBoundaryTest.php#L162) | Проверки журнала зависят от процессного static $logged и уникального имени хоста без комментария |
| [S30](#s30) | nit | дублирование |  | `156c64af` | [tests/plugins/loginmgr/YggConfigurationTest.php:95](../../tests/plugins/loginmgr/YggConfigurationTest.php#L95) | Правило origin для Ygg закреплено в двух наборах разными двойниками |
| [S31](#s31) | nit | слабый тест |  | `156c64af` | [tests/plugins/loginmgr/YggConfigurationTest.php:206](../../tests/plugins/loginmgr/YggConfigurationTest.php#L206) | Тест однократной диагностики Ygg зависит от порядка и пишет в общий журнал |
| [S32](#s32) | nit | слабый тест |  | `156c64af` | [tests/plugins/rss/RSSTest.php:33](../../tests/plugins/rss/RSSTest.php#L33) | Токен credential-redirect-refused вписан литералом в мок, и RSS-звено контракта не проверяется |
| [VC-NEW-1](#vc-new-1) | nit | диагностика | старое | `af56990f` | [plugins/rutracker_check/trackers/tfile.php:20](../../plugins/rutracker_check/trackers/tfile.php#L20) | AniDUB, tfile, Toloka и Tapochek не проверяют результат fetchComplex(download) и читают status/results страницы темы |
| [X12](#x12) | nit | лишнее повышение |  | `74aea456` | [php/xmlrpc_proxy.php:125](../../php/xmlrpc_proxy.php#L125) | Записи system.sockets.* в $elevate и форма socket_alloc недостижимы из WebUI |
| [X13](#x13) | nit | комментарий |  | `74aea456` | [php/xmlrpc_proxy.php:137](../../php/xmlrpc_proxy.php#L137) | Комментарий к $directoryCommands оторван от своего массива и читается как описание $filesystemDenies |
| [X14](#x14) | nit | комментарий | старое условно | `74aea456` | [php/xmlrpc_proxy.php:206](../../php/xmlrpc_proxy.php#L206) | d.delete_tied в слоте результата мультиколла применяется ко всей вьюхе; это стоит назвать в комментарии |
| [X15](#x15) | nit | комментарий |  | `74aea456` | [php/xmlrpc_proxy.php:274](../../php/xmlrpc_proxy.php#L274) | Комментарии о границе безопасности не упоминают исключение 'cat=$d.views=' |
| [X16](#x16) | nit | дублирование |  | `74aea456` | [php/xmlrpc_proxy.php:906](../../php/xmlrpc_proxy.php#L906) | Пять копий заголовка methodCall и две копии разбора имени команды |
| [X17](#x17) | nit | дублирование |  | `74aea456` | [php/xmlrpc_proxy.php:938](../../php/xmlrpc_proxy.php#L938) | rejectSystemMember() повторяет формат строки отказа rejectCommandSlot() |
| [X18](#x18) | nit | мёртвый код |  | `74aea456` | [php/xmlrpc_proxy.php:1114](../../php/xmlrpc_proxy.php#L1114) | Остатки удалённых переопределений: локальные $elevate/$sizeLimitMax и лишний параметр |
| [X19](#x19) | nit | страховка без теста |  | `74aea456` | [php/xmlrpc_proxy.php:1154](../../php/xmlrpc_proxy.php#L1154) | Проверка вложенного system.multicall не меняет исход, и тест не может её закрепить |
| [X20](#x20) | nit | диагностика |  | `74aea456` | [php/xmlrpc_proxy.php:1317](../../php/xmlrpc_proxy.php#L1317) | Отказ первой команды view-first d.multicall пишется как «ambiguous multicall view», и cat-исключение там не действует |
| [X21](#x21) | nit | странная логика | старое | `74aea456` | [php/xmlrpc_proxy.php:1327](../../php/xmlrpc_proxy.php#L1327) | Слот фильтра d.multicall.filtered принимает только сеттеры, а тесты и conf-комментарий подают это как «разрешённый фильтр» |
| [X22](#x22) | nit | мёртвый код |  | `74aea456` | [php/xmlrpc_proxy.php:1650](../../php/xmlrpc_proxy.php#L1650) | Параметр $directory у rebuildMulticallParam() мёртв |
| [X23](#x23) | nit | дублирование |  | `74aea456` | [plugins/httprpc/action.php:661](../../plugins/httprpc/action.php#L661) | Загрузка политики скопирована в обе двери, а проверка читаемости в каждой сделана дважды |
| [X24](#x24) | nit | комментарий |  | `74aea456` | [plugins/httprpc/conf.php:9](../../plugins/httprpc/conf.php#L9) | Комментарий httprpc/conf.php обещает, что $XMLRPCProxySafeParams задаёт все команды мультиколла, а чтения идут мимо него |
| [X25](#x25) | nit | комментарий |  | `74aea456` | [tests/php/XMLRPCProxyContractFixture.php:47](../../tests/php/XMLRPCProxyContractFixture.php#L47) | Фикстура контракта описывает себя двумя противоречащими утверждениями |
| [X26](#x26) | nit | слабый тест |  | `74aea456` | [tests/php/XMLRPCProxyContractFixture.php:244](../../tests/php/XMLRPCProxyContractFixture.php#L244) | Нормализация имени слота в логе отказа не закреплена |
| [X27](#x27) | nit | комментарий |  | `74aea456` | [tests/php/XMLRPCProxyEntrypointTest.php:686](../../tests/php/XMLRPCProxyEntrypointTest.php#L686) | Комментарий «the shipped policy files» остался от прежнего условия и не упоминает то, что фикстура дописывает |
| [X28](#x28) | nit | слабый тест | старое | `74aea456` | [tests/php/XMLRPCProxyPolicyParityTest.php:106](../../tests/php/XMLRPCProxyPolicyParityTest.php#L106) | testEveryOtherDefinitionOfThePolicyAgreesWithIt не делает ни одной проверки и не проверяет, что сканер что-то посмотрел |
| [X29](#x29) | nit | дублирование |  | `74aea456` | [tests/php/XMLRPCProxyTest.php:50](../../tests/php/XMLRPCProxyTest.php#L50) | Четыре построителя methodCall в одном тесте и хелперы с именем «review» |
| [X30](#x30) | nit | дублирование |  | `74aea456` | [tests/php/XMLRPCProxyTest.php:309](../../tests/php/XMLRPCProxyTest.php#L309) | testLegacyMulticallCommandCannotHideInTheModernViewSlot повторяет два соседних теста |

## XMLRPC-прокси и его двери

Раздел «XMLRPC-прокси и его двери» (php/xmlrpc_proxy.php, rpc2.php, plugins/httprpc/action.php и conf.php, conf/xmlrpc_proxy.php, тесты XMLRPCProxy*). Номера строк сверены с HEAD 25a0e2cb. Итог: 30 пунктов, из них P2 — 2, P3 — 9, nit — 19. Слиты дубликаты: dead-strange#1+proxy#2, tests-proxy#1+proxy#3, tests-proxy#2+portability-claims#6, dup-prod#1+dead-strange#7. Одна находка условная (X14, согласился один проверяющий из двух). Что это значит для FIX-CHECK-RECHECK2. Пункт P1 про system.multicall закрыт только для пакетов WebUI из повышенных команд. Проба из самого P1 (одна читающая команда внутри system.multicall) на HEAD по-прежнему даёт 403 (X5). Пункт proxy17 (сырой список через rpc2.php без httprpc) закрыт только при выключенном show_peers_like_wtorrent (X7), а в той же конфигурации gettotal по-прежнему отвергается. Прод, где httprpc включён, ни тем, ни другим не задет. P2 два. X1 — старые псевдонимы load_* и set_xmlrpc_size_limit, старый долг, выкладку не блокирует. Для прода на 0.16.22 он неактуален, но на rtorrent 0.9.0–0.9.6 даёт полный обход санитайзера, и тесты диапазона закрепляют этот обход как норму. X3 — регресс `74aea456`, поднят до P2 сверкой 2026-09-25 и блокирует выкладку: узкие формы в $elevate отвергают прямые одиночные d.views.push_back_unique/remove и view.set_visible/set_not_visible с любым видом, кроме rat_N, хотя раньше эти вызовы уходили недоверенными, а 0.16.22 их исполняет. Sonarr шлёт ровно такой вызов с видом «sonarr_imported». Исправление малое и входит в шаг 0. Почти все остальные пункты касаются диагностики и тестов: на путях, которые выдают доверие, тесты не закрепляют канонические байты и границы форм (X9–X11).

### X1

**Старые псевдонимы load_* и set_xmlrpc_size_limit уходят сырыми и на rtorrent 0.9.0–0.9.6 обходят санитайзер** · P2 · безопасность · старый долг · коммит 74aea456 · голоса 2/2 · [php/xmlrpc_proxy.php:165](../../php/xmlrpc_proxy.php#L165)

В режиме sanitize decide() не узнаёт имена load, load_verbose, load_start, load_start_verbose, load_raw, load_raw_start и load_raw_verbose. Такой вызов уходит сырыми байтами с trusted=false (строка 1403), вместе с хвостом команд после загрузки и с локальным путём. В rtorrent 0.9.0–0.9.6 это псевдонимы load.*: CMD2_REDIRECT_GENERIC в блоке method.use_deprecated, флаг по умолчанию true (v0.9.6 src/main.cc:471-478). UNTRUSTED_CONNECTION там ещё нет, он появился только в v0.16.9. Поэтому `load_start("", "http://x/y.torrent", "execute=sh,-c,id")` или `load_raw_start("", <base64>, "execute=...")` исполняет хвост, хотя тот же вызов через load.start прокси отвергает. Пустая цель первым аргументом обязательна, иначе 0.9.6 отвечает "Unsupported target type found.". Так же сырыми уходят set_xmlrpc_size_limit и xmlrpc_size_limit (v0.9.6 main.cc:578/580), и на старом демоне это обходит потолок 16 МиБ, который для network.xmlrpc.size_limit.set держит форма 'size'.

Коммит 74aea456 добавил $legacyDenies (строки 162-169) с именами, которые существуют только до 0.9.6 (set_directory, set_session, *_scgi_dont_route, view_filter, view_sort_*), но эти псевдонимы пропустил. Сама панель в php/methods-0.9.4.php:180-185 называет load_start написанием load.start. Тот же коммит переименовал кейсы фикстуры 184 и 482 в «unsupported underscore ... forwarded», и вместе с testUnsupportedUnderscoreLoadsAreUnownedOrdinaryUnknown (1373) и testLoadRawStartIsOrdinaryUnknown (2345) они закрепляют обход как норму. Новый testLegacyAliasesOfDeniedCommandsNeverReachTransport (212) эти имена тоже не перечисляет. Прод на 0.16.22 не затронут, поведение было и в базе. Поэтому по правилу «Ship In Small Steps» это старый долг вне дельты: выкладку он не блокирует, идёт в бэклог и закрывается отдельной малой выкладкой (ARCHITECTURE, шаг 1, пункт 6). Версии 0.9.0–0.9.6 вне заявленной в README поддержки (README.md:102: `0.9.8` и серия `0.16.x`). Но `$legacyDenies` защищает имена этой эпохи: `set_directory`, `view_filter` и `view_sort_*` есть в v0.9.6 и отсутствуют в v0.9.8. Поэтому пропуск load_* — непоследовательность этой защиты, а не выход за её рамки.

**Как исправить.** Внести в $legacyDenies load, load_verbose, load_start, load_start_verbose, load_raw, load_raw_start, load_raw_verbose, set_xmlrpc_size_limit и xmlrpc_size_limit. Перевести кейсы фикстуры 184/482 и тесты 1373/2345 на отказ с 0 отправок, добавить имена в список теста 212. В тесте 1373 заменить выдуманный load_normal на настоящий псевдоним `load` (methods-0.9.4.php:293).

Ещё места: `tests/php/XMLRPCProxyContractFixture.php:184`, `tests/php/XMLRPCProxyContractFixture.php:482`, `tests/php/XMLRPCProxyTest.php:212`, `tests/php/XMLRPCProxyTest.php:1373`, `tests/php/XMLRPCProxyTest.php:2345`.

Источники: `tests-proxy#0`.

### X2

**Upgrade-заметка и подсказка в логе называют только d.directory.base.set, а не f.set_create_queued/f.set_resize_queued** · P3 · заявление расходится с кодом · коммит 74aea456 · голоса 2/2 · [conf/xmlrpc_proxy.php:45](../../conf/xmlrpc_proxy.php#L45)

Заметка в conf/xmlrpc_proxy.php:45-48 велит дописать в сохранённый явный список только d.directory.base.set. Два других элемента, которые диапазон добавил в список (строка 66: f.set_create_queued, f.set_resize_queued), в ней не названы. Подсказка в rejectCommandSlot() (php/xmlrpc_proxy.php:963-969) тоже есть только для base-directory.

Проверено через decide(): список из `git show origin/master:conf/xmlrpc_proxy.php` даёт reject «f.multicall [slot 3: f.set_create_queued]»; тот же список, дополненный строго по заметке, даёт тот же reject; с defaultSafeParams() получается send trusted. Значит, на установке с сохранённым базовым файлом «Recreate files» (js/rtorrent.js:826, сырой f.multicall) и после обновления получает 403, хотя 74aea456 обещает разрешить эти сеттеры. Для нескольких торрентов в логе видно только «system.multicall [slot 1: f.multicall]»: имя сеттера теряется в rejectSystemMember().

Досягаемость узкая. По записям форка (PROGRESS.md:72-74: том засеян до появления файла 2026-08-19, а startup новые conf-файлы не досевает) этого файла в томе прода нет. Сам том не читался: это вывод, а не замер (проверка — ARCHITECTURE, шаг 0, пункт 1). Сценарий возникает, если docker-rutorrent 668846e (засевает отсутствующие conf-файлы один раз) собрать с RUTORRENT_REF=1fa8defc до поднятия ref, или на любой установке, где уже лежит правленая копия базового файла. Это не регрессия: на origin/master «Recreate files» отвергалось всегда. Отказ видим, неполны только заметка и подсказка.

**Как исправить.** Перечислить в заметке все три новых элемента. Добавить в rejectCommandSlot() такую же подсказку для f.set_create_queued/f.set_resize_queued, в том числе при отказе члена system.multicall. Явный список автоматически не расширять.

Ещё места: `conf/xmlrpc_proxy.php:66`, `php/xmlrpc_proxy.php:963`, `php/xmlrpc_proxy.php:938`.

Источники: `portability-claims#1`.

### X3

**Узкие формы новых записей $elevate отвергают прямые одиночные вызовы, которые раньше уходили недоверенными** · P2 · регресс · коммит 74aea456 · голоса 2/2 · воспроизведено 2026-09-25 · [php/xmlrpc_proxy.php:123](../../php/xmlrpc_proxy.php#L123)

Для d.views.push_back_unique, d.views.remove, view.set_visible, view.set_not_visible, d.throttle_name.set, d.set_throttle_name и d.set_custom в $elevate (строки 118-124) добавлены узкие формы. Они срабатывают и на прямом одиночном вызове: при несовпадении формы decide() отвечает 403 «arguments did not match allowed shape» (1375-1390), а не пересылает вызов недоверенным, как было на origin/master (сейчас это строка 1403). Комментарий 111-112 объясняет формы только нуждами system.multicall.

На текущем 0.16.22 это ломает вызовы, которые rtorrent сам помечает mark_safe: d.views.push_back_unique/d.views.remove (src/command_download.cc:1014-1015) и view.set_visible/view.set_not_visible (src/command_ui.cc:924-925) с любым view не вида rat_N. Пример: вид «<app>_imported», который Sonarr/Radarr после импорта ставят прямым d.views.push_back_unique через /RPC2 или httprpc; теперь он не ставится. d.throttle_name.set с настоящим именем throttle сломался только на демонах без проверки недоверенных соединений, до 0.16.8 включительно: 0.16.9 и новее отвечали ему −507 и на базе, потому что в mark_safe v0.16.22 помечен только геттер d.throttle_name (src/command_download.cc:999). Псевдонимы d.set_custom и d.set_throttle_name зарегистрированы только до v0.9.6 включительно (блок method.use_deprecated, v0.9.6 src/main.cc:717 и :732), и проверки доверия там нет. На 0.9.7 и новее, в том числе во всей 0.16, демон их не знает ни на базе, ни на HEAD. На 0.16.9+ и на 0.9.7+ меняется лишь форма ошибки. На демонах до 0.16.8 (`d.throttle_name.set`) и до 0.9.6 (`d.set_custom`, `d.set_throttle_name`) прежде исполнявшийся вызов теперь получает 403. Из поставляемого WebUI задет только setpushbullet плагина history на rtorrent до 0.9.4 (d.set_custom с ключом x-pushbullet). Сужение d.set_custom ничего не защищает: d.custom.set по-прежнему принимает любой ключ. Пакеты WebUI (ratio, scheduler) не задеты: они всегда из двух и более команд и идут system.multicall.

**Воспроизведено 2026-09-25, отсюда P2** ([VERIFY-X-25a0e2cb.md](VERIFY-X-25a0e2cb.md), строка X3 и абзац «Главное»; повтор пробы `decide()` на обоих деревьях).

- Что шлёт клиент. Sonarr (`v5-develop`) в `RTorrent.cs`, метод `MarkItemAsImported()`, после каждого импорта без условия по настройкам вызывает `PushTorrentUniqueView(DownloadId.ToLower(), _imported_view)`, где `_imported_view = AppName.ToLower() + "_imported"`. `RTorrentProxy.cs` шлёт это одиночным `d.views.push_back_unique(<хеш>, "sonarr_imported")`, а не внутри `system.multicall`. У Radarr `develop` оба файла устроены так же, вид `radarr_imported` (сверено по исходнику при разборе).
- Что отвечает прокси. `decide()` на origin/master пересылал вызов с trusted=false и исходными байтами («untrusted: d.views.push_back_unique»). HEAD отвечает reject «arguments did not match allowed shape» для обоих видов, при хеше в любом регистре, со встроенной политикой и с conf-файлом. Так же ведут себя `d.views.remove` и `view.set_visible` с тем же видом, а вид `rat_N` на HEAD уходит доверенным. Обе двери вызывают `decide()` (rpc2.php:162, plugins/httprpc/action.php:721) и при отказе отвечают до демона HTTP 403 с fault −501.
- Что делает демон. Лабораторный rtorrent 0.16.22 принял такой одиночный вызов от недоверенного соединения, вернул 0 и добавил вид (VERIFY-X, вид `myview`). Имя вида демон не проверяет: команда лишь дописывает строку в список (src/command_download.cc:804) и помечена mark_safe (:1015). С `sonarr_imported` это повторено при разборе 2026-09-25 ([замеры M1 и M4](vsum-lab-evidence/LAB-0.16.22-2026-09-25.md)): демон применил вызов без доверия, а обе двери HEAD в контейнере, httprpc и `/RPC2` (включённый только в лаборатории), ответили 403 с fault −501 и строкой `rejected (arguments did not match allowed shape)` в журнале (механизм: `rpc2.php:162`, `plugins/httprpc/action.php:721`). Прогон всех вызовов Sonarr/Radarr — ARCHITECTURE, шаг 0, п. 4.
- Последствие. Sonarr ловит исключение и пишет Warn «Failed to set torrent post-import view». Импорт проходит, но вида `<app>_imported` нет, и скрипты rtorrent, построенные на нём, не срабатывают. Пользуется ли наш прод Sonarr или Radarr, не установлено; уровень от этого не зависит.
- Почему для самого Sonarr это не откат относительно прода. Это первый неверный ответ на пути, который открыл NEW-1. На origin/master его `GetTorrents` (`d.multicall2("", "", d.name=, …)`) отвергается при любой политике, а без conf/xmlrpc_proxy.php отвергаются и `load.start`/`load.normal`/`load.raw_start` с хвостами, так что до `MarkItemAsImported` дело не доходит. Регресс — по отношению к тому, как origin/master обходился с самим этим вызовом.
- Остальные вызовы `RTorrentProxy.cs` HEAD пропускает: `system.client_version`, список, `load.*` с хвостами `d.custom1.set`/`d.priority.set` (с `d.directory.set` — только когда каталог внутри `$topDirectory`, иначе хвост отвергается, X6), `d.custom1.set` доверенным, `d.erase` и `d.name` недоверенными; 0.16.22 принимает оба (mark_safe, src/command_download.cc:966 и :934).
- Как клиент попадает в дверь. По умолчанию Sonarr ходит на путь `RPC2` (`UrlBase = "RPC2"`). В docker-rutorrent `ENABLE_RPC2=false` (Dockerfile:337, nginx `deny all`), поэтому на образе по умолчанию регресс достижим через `plugins/httprpc/action.php`, если указать клиенту этот путь, или после `ENABLE_RPC2=true`.

По AGENTS.md это P2: неверное поведение на узком реалистичном входе, внесено `74aea456`, воспроизведено. Блокирует выкладку `74aea456` (шаг 0).

**Как исправить (входит в шаг 0).**

Откат. Для 17 имён, добавленных в `74aea456`, при несовпадении числа аргументов или формы в одиночном вызове вернуть поведение origin/master: `forward($rawData, false, 'untrusted: <имя> (shape not elevated)')`. Эти имена стоят в блоке под комментарием 111-112: четыре `f.prioritize_*`, `d.update_priorities`, `d.set_throttle_name`, `d.throttle_name.set`, `d.set_custom`, `view.set_visible`, `view.set_not_visible`, `d.views.push_back_unique`, `d.views.remove` и пять `system.sockets.*`. Для 12 имён, которые были в `$elevate` на базе, терминальный отказ оставить: так было и на origin/master.

Модель пакетов не меняется. Носитель `system.multicall` (строка 1160) требует trusted=true от каждого члена, так что член с откатом по-прежнему роняет пакет в 403 и доверия не одалживает.

Защиты откат не снимает. На 0.16.9 и новее недоверенный вызов судит демон: `view.*`, `d.views.*`, `f.prioritize_*` и `d.update_priorities` он принимает (mark_safe), а `d.throttle_name.set` и сеттеры `system.sockets.*` (появились только в 0.16.15) отвергает с −507. На более старых демонах это ровно поведение базы.

Сначала RED-тесты:

1. Одиночный `d.views.push_back_unique(<хеш в нижнем регистре>, 'sonarr_imported')` даёт send с trusted=false, и payload равен исходным байтам.
2. Тот же член в `system.multicall` рядом с доверенным даёт reject.
3. `view.set_visible(<хеш>, 'main')` и `d.throttle_name.set(<хеш>, 'thr_1')` дают send с trusted=false.

Мутация «убрать откат» должна ронять тесты 1 и 3, мутация «пускать недоверенного члена в пакет» — тест 2.

Проверка в rt-lab. На образе с 0.16.22 отправить тот же одиночный вызов через обе двери на хеш реально загруженного торрента: на несуществующем демон ответит fault независимо от прокси. Для `/RPC2` нужны `ENABLE_RPC2=true` и настроенный `$topDirectory` или `$XMLRPCProxyAllowRootDirectory = true`, иначе rpc2.php отвечает 503 (rpc2.php:139-148). Ожидается ответ 200, и `d.views` этой раздачи содержит `sonarr_imported`.

Комментарий над `$elevate` (88-91). После правки его вторая фраза («anything else is left untrusted») станет верной для 17 имён; для 12 базовых её нужно переписать («иначе — отказ»). Первую фразу («Methods rtorrent refuses to an untrusted caller») поправить для mark_safe-имён (`view.*`, `d.views.*`, `f.prioritize_*`, `d.update_priorities`): демон 0.16.9+ недоверенным их не отказывает.

Ещё места: `php/xmlrpc_proxy.php:111`, `php/xmlrpc_proxy.php:118`, `php/xmlrpc_proxy.php:1375`.

Источники: `proxy#0`.

### X4

**system.multicall отбрасывает журнал решений членов: теряются WARNING о локальном пути и причины отказа** · P3 · диагностика · коммит 74aea456 · голоса 2/2 · [php/xmlrpc_proxy.php:1159](../../php/xmlrpc_proxy.php#L1159)

Носитель system.multicall (строки 1159-1161, 1181-1182; появился в 74aea456) вызывает self::decide() для каждого члена, но берёт из решения только action и trusted, а массив 'log' внутреннего решения отбрасывает.

При пропуске в журнал уходит одна строка «trusted: system.multicall (N members)». Поэтому при $XMLRPCProxyAllowLocalPaths = true load.start с локальным путём, завёрнутый в system.multicall, пересылается без строки «WARNING: operator-enabled local path forwarded». Это нарушает обещание conf/xmlrpc_proxy.php:82-83 «Each forwarded local path is logged».

При отказе rejectSystemMember() (938-945) пишет «rejected (not allowed on this connection): system.multicall [slot N: method]». Настоящая классифицированная причина подменяется: вместо «load from a local path» или «malformed load call» стоит «not allowed on this connection». Пропадают внутренний номер слота и имя команды (load.start с execute=rm напрямую даёт «[slot 3: execute]», в носителе «[slot 1: load.start]») и подсказка rejectCommandSlot() про d.directory.base.set. Само решение политики верное: внутреннему decide() передаются те же флаги. Собственные пакеты WebUI load.* не содержат, так что это касается внешних клиентов, а локальный путь — только при нестандартном флаге.

**Как исправить.** Переносить строки $innerDecision['log'] с префиксом «[slot N]» во внешнее решение и при пропуске, и при отказе (rejectSystemMember() может принимать причину). Добавить тесты на локальный путь и на причину отказа члена внутри system.multicall.

Ещё места: `php/xmlrpc_proxy.php:1181`, `php/xmlrpc_proxy.php:938`, `conf/xmlrpc_proxy.php:82`.

Источники: `dead-strange#1`, `proxy#2`.

### X5

**system.multicall с читающим членом по-прежнему получает 403, хотя по одному эти вызовы уходят недоверенными** · P3 · странная логика · старый долг · коммит 74aea456 · голоса 2/2 · [php/xmlrpc_proxy.php:1160](../../php/xmlrpc_proxy.php#L1160)

Строка 1160 пересылает system.multicall, только если каждый член получил send с trusted=true. Пакет, где хотя бы одна команда поодиночке ушла бы недоверенной (system.client_version, throttle.global_*, d.name/d.complete по хешам), целиком получает 403. Живая проба из самого P1 в CHECK-RECHECK2 (system.multicall из одной читающей команды) на HEAD даёт тот же 403. Шапка файла (8-10: «pass unknown methods as untrusted (rtorrent whitelist decides)») для system.multicall неточна.

Для пакета, где каждый член поодиночке ушёл бы недоверенным, отказ ничего не защищает. rtorrent 0.16.9+ проверяет доверие у каждого члена отдельно (execute_command → call_command; на 0.16.22 это измерено 2026-09-25, VERIFY-X, «Поправки к ARCHITECTURE», п. 5). Каждый член уже прошёл decide() с deny-списками, а HEAD пересобирает членов канонически. На демоне до 0.16.8 включительно заголовок недоверия не читается, и такой пакет исполняется без проверки. Но так же исполняются и те же одиночные вызовы, которые прокси и сейчас пересылает недоверенными, поэтому пакет не шире.

Это не новый дефект диапазона. На origin/master отвергался любой system.multicall (с 29be3350; в #3188 безвредные пакеты шли недоверенными). 74aea456 только сузил отказ и закрепил его тестами (testSystemMulticallTrustRequiresEveryMemberToBeIndividuallyTrusted, 1423, контрактный «still rejected»). В штатной конфигурации с httprpc (прод) WebUI таких пакетов не шлёт: gettotal/getsettings/getprops идут серверными режимами, а сырые setprioritize, restoresocketalloc, schignore, setratio, createqueued состоят из повышаемых или пересобранных членов. Поэтому P1 в заявленных рамках закрыт, но FIX-CHECK не говорит, что проба из P1 всё ещё даёт 403. Страдают сторонние клиенты пакетного чтения через обе двери и панель в конфигурации «/RPC2 → rpc2.php, httprpc выключен»: gettotal на каждом опросе (js/rtorrent.js:698), getopen, getprops. На базе эта конфигурация была сломана сильнее. Отказ видим: 403 и строка лога с номером слота.

**Как исправить.** Пакет, в котором все члены получили send с trusted=false, собирать канонически и пересылать недоверенным. Это не шире одиночных вызовов. Смешанный пакет (есть и trusted, и untrusted члены) отвергать, как сейчас. Отправленный без доверия, он на 0.16.22 применяет недоверенные члены и отвечает −507 на доверенные, без отката и с HTTP 200: лабораторный демон применил и член до −507, и член после него (VERIFY-X, п. 5 поправок; [замеры M2 и M3](vsum-lab-evidence/LAB-0.16.22-2026-09-25.md)): `process_document()` в `src/rpc/xmlrpc_tinyxml2.cc` v0.16.22 ловит fault члена внутри цикла. Член с reject валит весь пакет в любом случае. Обновить тесты 77/1423 и контрактный кейс. Учесть, что getsettings без httprpc не оживёт и так: член network.scgi.dont_route попадает под префикс отказа 'network.scgi'. В FIX-CHECK дописать, что закрыты только пакеты WebUI.

Ещё места: `php/xmlrpc_proxy.php:8`, `tests/php/XMLRPCProxyTest.php:77`, `tests/php/XMLRPCProxyTest.php:1423`.

Источники: `proxy#1`.

### X6

**Отказ по границе каталога пишется в лог как «команды нет в политике»** · P3 · диагностика · старый долг · коммит 74aea456 · голоса 2/2 · [php/xmlrpc_proxy.php:1254](../../php/xmlrpc_proxy.php#L1254)

rebuildSafeLoadParam() возвращает false только из ветки границы каталога (1702-1711: краевые пробелы, сегменты '.'/'..', !directoryIsAllowed()), а null — при отказе политики, неразборчивом аргументе или '$' в аргументе. Строка 1254 сводит оба результата к rejectCommandSlot('not allowed on this connection', ...). В итоге d.directory.set=/etc, =/downloads/./tv и ="/downloads/tv " дают ту же строку «rejected (not allowed on this connection): load.start [slot 3: d.directory.set]», что и команда вне $XMLRPCProxySafeParams.

Самый частый путь — стоковая настройка: при $topDirectory = '/' и $XMLRPCProxyAllowRootDirectory = false httprpc/action.php:708-715 передаёт пустой корень, и каждая load.* с d.directory.set (Sonarr, Radarr, cross-seed) отклоняется этой строкой, хотя d.directory.set стоит в политике по умолчанию. Клиент видит только 403, так что журнал — единственная диагностика, и он посылает оператора править политику, а причина в $topDirectory, флаге корня или пути клиента. Слияние было и в базе (там строка не называла даже слот); диапазон добавил имя команды и два новых отказа (точечные сегменты, краевые пробелы) в ту же формулировку. Поведение отказа верное, неверна классификация.

**Как исправить.** При false вызывать rejectCommandSlot() со своей причиной, например 'directory outside boundary', а при пустом корне добавлять подсказку про $topDirectory / $XMLRPCProxyAllowRootDirectory. При null оставить нынешнюю.

Ещё места: `php/xmlrpc_proxy.php:1709`, `plugins/httprpc/action.php:708`.

Источники: `proxy#4`.

### X7

**Исключение cat пропускает только строку ratio; включённый по умолчанию show_peers_like_wtorrent по-прежнему роняет сырой список** · P3 · заявление расходится с кодом · старый долг · коммит 74aea456 · голоса 2/2 · [php/xmlrpc_proxy.php:1655](../../php/xmlrpc_proxy.php#L1655)

Строка 1655 пропускает только точную строку плагина ratio 'cat=$d.views='. Плагин show_peers_like_wtorrent поставляется с ruTorrent и включён по умолчанию (conf/plugins.ini: enabled=user-defined без enabledByDefault). Он добавляет в список два слота: 'cat="$t.multicall=d.hash=,t.scrape_complete=,cat={#}"' и такой же с t.scrape_incomplete (init.js:5-10 и 20-25). Проба decide() на HEAD: d.multicall2 списка с таким слотом → reject '[slot 4: cat]'; тот же список без него → send.

Проблема только на сыром пути: httprpc выключен, /RPC2 ведёт на rpc2.php. Кроме того, нужен настроенный `$topDirectory` (не пустой и не '/') или `$XMLRPCProxyAllowRootDirectory = true`: иначе rpc2.php отвечает 503 ещё до решения (rpc2.php:139-148). Образ docker-rutorrent `$topDirectory` задаёт, так что при `ENABLE_RPC2=true` условие выполнено. С httprpc список строится в mode=list (plugins/httprpc/action.php:180-197) и проходит, поэтому прод не затронут. Но именно в конфигурации, о которой говорит proxy17, при стандартном наборе плагинов исправление список не возвращает; в той же конфигурации отвергается и gettotal (X5). Статус «Исправлено» в FIX-CHECK-RECHECK2-5eba5135.md:19 завышен. Подмена d.is_partially_done на 'cat=' (js/content.js:578) срабатывает только на rtorrent до 0.9.0 и на практике недостижима. Дефект был и на origin/master, где отвергался даже голый список.

**Как исправить.** Либо в FIX-CHECK и в шапке rpc2.php прямо написать, что панель через rpc2.php без httprpc не поддерживается (вместе с gettotal из X5). Либо так же точечно разрешить точные выражения show_peers_like_wtorrent после проверки на lab-демоне 0.16.22. Общий cat не открывать.

Ещё места: `plugins/show_peers_like_wtorrent/init.js:5`, `plugins/show_peers_like_wtorrent/init.js:20`, `js/content.js:578`, `tasks/2026-09-12-app-log-findings/FIX-CHECK-RECHECK2-5eba5135.md:19`.

Источники: `proxy#5`.

### X8

**Пометка «[built-in policy]» закреплена только в одну сторону** · P3 · слабый тест · коммит 74aea456 · голоса 2/2 · [tests/php/XMLRPCProxyEntrypointTest.php:216](../../tests/php/XMLRPCProxyEntrypointTest.php#L216)

Пометку ' [built-in policy]' добавили в этом диапазоне. Что она есть, когда файла политики нет, проверяет строка 216. Что её нет при явной политике, для rpc2 не проверяет никто: testBothDoorsDecideTheSameTrustForTheSameRequest (147, assertRpc2Log → strpos) и testBothDoorsLoggingDoesNotChangeTransport (582) ищут подстроку, тогда как для httprpc та же строка сверяется точно через in_array (142-144). testExplicitRestrictedAndEmptyPoliciesOverrideDefaultsAtBothDoors (225-236) идёт с включённым логом, но строк лога не смотрит ни на одной двери. Поэтому замена `!isset(...)` на `empty(...)` в rpc2.php:170 и plugins/httprpc/action.php:724 не ловится: намеренно пустой список логировался бы как встроенная политика. Обе мутации на копии HEAD дают 686 passed / 0 failed. Код сейчас верен; пропущенная регрессия дала бы ложную диагностику на установках с явным conf/xmlrpc_proxy.php (новая Docker-установка, upstream), маршрутизацию она не меняет.

**Как исправить.** Для rpc2 сверять последнюю строку лога целиком (отрезав дату) или добавить strpos(..., '[built-in policy]') === false. В тесте 225-236 проверить строки лога обеих дверей для restricted и empty: пометки быть не должно.

Ещё места: `tests/php/XMLRPCProxyEntrypointTest.php:147`, `tests/php/XMLRPCProxyEntrypointTest.php:582`, `tests/php/XMLRPCProxyEntrypointTest.php:225`, `rpc2.php:170`, `plugins/httprpc/action.php:724`.

Источники: `tests-proxy#4`.

### X9

**Канонический payload доверенного system.multicall не закреплён ни одним тестом** · P3 · слабый тест · коммит 74aea456 · голоса 2/2 · [tests/php/XMLRPCProxyTest.php:99](../../tests/php/XMLRPCProxyTest.php#L99)

Новые тесты доверенного system.multicall (XMLRPCProxyTest.php:77, 99, 131 и XMLRPCProxyEntrypointTest.php:153) проверяют только action/trusted и число отправок, но не байты, которые уходят в rtorrent. В XMLRPCProxyContractFixture.php все три кейса system.multicall — отказы, а CANONICAL_MULTICALL_SHA256 закрепляет только d.multicall2. Мутация `forward($canonicalXml, ...)` → `forward($rawData, ...)` в строке 1181 оставляет зелёными XMLRPCProxyTest (1171/0), EntrypointTest (686/0), ContractTest (885/0), RejectionTest (20/0) и PolicyParityTest (7/0). При этом network.xmlrpc.size_limit.set со строкой '999999999' уходит доверенным без потолка 16777216, хеш остаётся в нижнем регистре, строка '2' не становится <i8>2</i8>, а любое расхождение разбора между DOM и xmlrpc-c попадает в демон под trusted. На HEAD код корректен; это дыра в покрытии пути, который выдаёт доверие. До диапазона её не было: system.multicall отвергался целиком.

**Как исправить.** Хотя бы в одном тесте сравнить $decision['payload'] с литералом канонического system.multicall: смешанный пакет с хешем в нижнем регистре, network.xmlrpc.size_limit.set('', '999999999') → <i8>16777216</i8> и d.priority.set со строкой '2' → <i8>2</i8>. В XMLRPCProxyEntrypointTest проверять payload_sha256 успешного пакета, как для CANONICAL_MULTICALL.

Ещё места: `tests/php/XMLRPCProxyTest.php:77`, `tests/php/XMLRPCProxyTest.php:131`, `tests/php/XMLRPCProxyEntrypointTest.php:153`, `php/xmlrpc_proxy.php:1181`.

Источники: `tests-proxy#1`, `proxy#3`.

### X10

**Пакет «снять игнор» планировщика не проверяется; ветка '' формы scheduler_throttle не закреплена** · P3 · слабый тест · коммит 74aea456 · голоса 2/2 · [tests/php/XMLRPCProxyTest.php:106](../../tests/php/XMLRPCProxyTest.php#L106)

Фикстура testWebUiFileSchedulerRatioAndSocketActionsHaveCheckedBatchShapes (100-118) собрана вручную, а её сообщение обещает «real argument shapes». От планировщика в ней только направление «игнорировать»: d.throttle_name.set(h, 'NULL') и d.custom.set(h, 'sch_ignore', '1'). Снятие игнора (plugins/scheduler/init.js:22, 69, 80) шлёт system.multicall из d.throttle_name.set(h, '') и d.custom.set(h, 'sch_ignore', ''), у запущенного торрента ещё d.stop и d.start; httprpc/init.js schignore не перехватывает. Такого пакета нет ни в одном тесте. Мутация `$val !== 'NULL'` в строке 1000 превращает все три варианта пакета снятия игнора в reject «system.multicall [slot N: d.throttle_name.set]», а XMLRPCProxyTest проходит (1171/0). Мутация `$val !== '1'` в строке 1010 (устаревшая форма scheduler_state, d.set_custom) тоже проходит. d.custom.set с '' проверяется общей формой 'text', там дыры нет. На origin/master этих форм не было.

**Как исправить.** Добавить второй пакет, дословно повторяющий schignore для снятия игнора: d.stop, d.throttle_name.set(h, ''), d.start, d.custom.set(h, 'sch_ignore', ''), и вариант с устаревшими именами; ожидать trusted. Лучше строить оба направления по plugins/scheduler/init.js. Слова «real argument shapes» оставить только для форм, которые WebUI действительно шлёт.

Ещё места: `php/xmlrpc_proxy.php:1000`, `php/xmlrpc_proxy.php:1010`, `plugins/scheduler/init.js:22`.

Источники: `sig-test-oracle-cannot-fail#0`.

### X11

**Потолок 1048576 формы socket_alloc не закреплён: единственный отрицательный случай отсекает регулярка** · P3 · слабый тест · коммит 74aea456 · голоса 2/2 · [tests/php/XMLRPCProxyTest.php:123](../../tests/php/XMLRPCProxyTest.php#L123)

Проверка `(int)$val > 1048576` (php/xmlrpc_proxy.php:1017, добавлена в 74aea456) не закреплена. Единственный отрицательный случай 999999999 (XMLRPCProxyTest.php:123) имеет девять цифр, и его отвергает ещё регулярное выражение `^(?:0|[1-9][0-9]{0,6})$` на строке 1016. Положительные случаи — 8 и 128. Если потолок убрать, значения 1048577–9999999 для system.sockets.{files,http}.{min,max}_alloc.set уходят доверенным вызовом <i8>…</i8> (проверено через decide()), а XMLRPCProxyTest (1171/0), EntrypointTest и PolicyParityTest остаются зелёными. Практических последствий нет: libtorrent в adjust_alloc отказывает во всём выше бюджета категории, а 0.16.21+ WebUI отсекает такие значения ещё до отправки. Остаётся граница, которую прокси объявляет, а тест не проверяет. См. также X13: сами эти записи из WebUI недостижимы.

**Как исправить.** Если записи socket_alloc остаются (см. X13), добавить отрицательные случаи 1048577 и 9999999 для min_alloc.set и max_alloc.set и положительный 1048576 с проверкой, что в payload уходит <i8>1048576</i8>.

Ещё места: `php/xmlrpc_proxy.php:1017`.

Источники: `sig-test-oracle-cannot-fail#1`.

### X12

**Записи system.sockets.* в $elevate и форма socket_alloc недостижимы из WebUI** · nit · лишнее повышение · коммит 74aea456 · голоса 1/1 · [php/xmlrpc_proxy.php:125](../../php/xmlrpc_proxy.php#L125)

Пять записей system.sockets.* (125-129) и форма socket_alloc (1014-1019) добавлены под комментарием 111-112 «The WebUI sends these inside system.multicall». Их единственный источник — restoresocketalloc (js/rtorrent.js:501), который запускает setsettingsParseXML после ответа на сырой setsettings. С httprpc setsettings идёт как mode=setsettings в JSON, и ParseXML не вызывается. Без httprpc сырой setsettings начинается с чтений system.sockets.files.min_alloc/max_alloc, которых нет в $elevate, поэтому весь пакет отвергается на первом слоте, и восстанавливать нечего. Код расширяет доверенную поверхность (любой сырой клиент доверенно задаёт границы сокетов) ради пути, который не выполняется, а testWebUiFileSchedulerRatioAndSocketActionsHaveCheckedBatchShapes проверяет пакет, которого WebUI не отправит.

**Как исправить.** Убрать эти пять записей и форму socket_alloc, а из теста — соответствующих членов. Если восстановление нужно в сыром режиме, сначала сделать проходимым сам setsettings и подтвердить это на lab-демоне. Прямых клиентов это удаление не ломает. На origin/master эти пять сеттеров уходили недоверенными. В rtorrent они появились в 0.16.15 или позже, уже после проверки недоверенных соединений (0.16.9), и не входят в `mark_safe` v0.16.22, поэтому демон через базовый прокси отвечал им −507. Удаление возвращает поведение прода.

Ещё места: `php/xmlrpc_proxy.php:111`, `php/xmlrpc_proxy.php:1014`, `js/rtorrent.js:501`, `tests/php/XMLRPCProxyTest.php:99`.

Источники: `proxy#7`.

### X13

**Комментарий к $directoryCommands оторван от своего массива и читается как описание $filesystemDenies** · nit · комментарий · коммит 74aea456 · голоса 1/1 · [php/xmlrpc_proxy.php:137](../../php/xmlrpc_proxy.php#L137)

74aea456 вставил $filesystemDenies (комментарий 154-155, массив 156-160) и $legacyDenies (162-169) между комментарием к каталожным сеттерам (137-153: «Commands whose argument is a path rtorrent will write a download into ...» и абзац «Both spellings of the base-directory setter ...») и массивом $directoryCommands (171-173), который этот комментарий описывает. Сам $directoryCommands остался без комментария. Между 153 и 154 нет пустой строки, поэтому оба текста читаются как один блок над массивом, где стоят 'd.set_directory' и 'd.set_directory_base'. Читатель решит, что эти команды ограничиваются $topDirectory и что «оба написания» относятся к ним; на деле isDeniedCommand() отвергает их безусловно, а граница каталога (1702) касается только трёх команд из $directoryCommands.

**Как исправить.** Перенести блок 137-153 вплотную к строке 171, после $legacyDenies. Поведение не меняется.

Ещё места: `php/xmlrpc_proxy.php:156`, `php/xmlrpc_proxy.php:171`.

Источники: `sig-stale-replacement-comment#5`.

### X14

**d.delete_tied в слоте результата мультиколла применяется ко всей вьюхе; это стоит назвать в комментарии** · nit · комментарий · старый долг · условно: подтвердил один скептик из двух · коммит 74aea456 · голоса 1/2 · [php/xmlrpc_proxy.php:206](../../php/xmlrpc_proxy.php#L206)

d.delete_tied есть в $defaultSafeParams (206). Поэтому d.multicall/d.multicall2 со слотом результата "d.delete_tied=" проходит decide() как send, trusted=true (1307 → rebuildMulticallParam() → rebuildSafeLoadParam()), и rtorrent отвязывает и удаляет привязанный .torrent у каждой загрузки вьюхи. При $saveUploadedTorrents=true это сохранённые копии в profile/torrents; сессия rtorrent и загрузки не страдают. Для установок с поставляемым conf/xmlrpc_proxy.php так было и на origin/master. На проде, где файла в томе нет, путь стал достижим в 74aea456 через откат к defaultSafeParams() (action.php:692-693, rpc2.php:71-72).

Новой возможности это не даёт: тот же клиент уже вызывает d.delete_tied по хешу через $elevate (109) и system.multicall из N таких вызовов, а d.erase уходит недоверенным и в 0.16.x помечен mark_safe. Применение сеттеров ко всей вьюхе — задокументированная модель доверия (conf/xmlrpc_proxy.php:21-24, 64-65). Утверждение, что аутентификация RPC2 «обычно слабее панельной», не проверено. Один из двух проверяющих счёл это не дефектом вовсе.

**Как исправить.** Сужать список не нужно. Добавить у $defaultSafeParams комментарий: слот результата применяет каждый сеттер списка, включая деструктивный d.delete_tied, ко всей вьюхе, и это осознанная модель доверия обеих дверей.

Ещё места: `conf/xmlrpc_proxy.php:21`.

Источники: `proxy-sec#2`.

### X15

**Комментарии о границе безопасности не упоминают исключение 'cat=$d.views='** · nit · комментарий · коммит 74aea456 · голоса 1/1 · [php/xmlrpc_proxy.php:274](../../php/xmlrpc_proxy.php#L274)

Два комментария из 74aea456 утверждают инвариант без исключения: 58-61 (слот результата сверяется с $safeParams и $safeGetters и пересобирается) и 274-277 (читающие команды пересобираются через rebuildSafeLoadParam(), аргумент с '$' отвергается). Код того же коммита, rebuildMulticallParam() на 1655-1656, для слота результата пропускает строку `cat=$d.views=` дословно: без сверки со списками, без пересборки и без отказа по '$'. Запрос уходит trusted, и rtorrent вычисляет $d.views=. Тест XMLRPCProxyTest.php:147-165 закрепляет это как намеренное. Само исключение безопасно (побайтовое сравнение, d.views только читает) и объяснено локально на 1652-1654, но в описании границы на уровне класса его нет, и сопровождающий, проверяющий «ни один $-аргумент не уходит trusted», его не найдёт.

**Как исправить.** В оба комментария (58-61 и 274-277) дописать одно предложение: единственная точная строка ratio `cat=$d.views=` в слоте результата принимается дословно, без списков и пересборки; это безопасно, потому что d.views только читает, а сравнение побайтовое; см. rebuildMulticallParam().

Ещё места: `php/xmlrpc_proxy.php:58`, `php/xmlrpc_proxy.php:1655`, `tests/php/XMLRPCProxyTest.php:147`.

Источники: `sig-stale-replacement-comment#1`.

### X16

**Пять копий заголовка methodCall и две копии разбора имени команды** · nit · дублирование · коммит 74aea456 · голоса 1/1 · [php/xmlrpc_proxy.php:906](../../php/xmlrpc_proxy.php#L906)

Заголовок methodCall собирается пятью одинаковыми копиями: 906-908, 1177-1180, 1263-1266, 1357-1360, 1394-1397. Две из них (906 и 1177) добавил диапазон, копию из rebuildLoadParams при этом удалил. Разбор имени до '=' с trim буквально повторён в двух новых функциях: commandName() (949-952) и rebuildMulticallParam() (1657-1658). Имя для журнала и имя для решения получаются разными копиями; поправив одну (например, набор символов в trim), получим для одного слота разные имена. rebuildSafeLoadParam() (1683-1687) разбирает иначе (без '=' возвращает null), но общий rawCommandName() подходит и ему. Цикл выдачи параметров в system.multicall (1168-1174) похож на цикл emitCallFromScalars() (909-915), но пишет значения в <data> без <param>, так что общий помощник должен возвращать список значений. Двойная запретительная проверка в rebuildMulticallParam() (isDirectDenied, затем isDeniedCommand внутри rebuildSafeLoadParam) намеренная (комментарий 1648-1649) и ничего не стоит, трогать её не нужно.

**Как исправить.** Сделать один private emitCall($method, $paramsXml) для заголовка и rawCommandName($value) для разбора; commandName() и rebuildMulticallParam() построить поверх rawCommandName(). Для system.multicall вынести выдачу скалярных значений в помощник, общий с emitCallFromScalars().

Ещё места: `php/xmlrpc_proxy.php:1177`, `php/xmlrpc_proxy.php:1263`, `php/xmlrpc_proxy.php:1357`, `php/xmlrpc_proxy.php:1394`, `php/xmlrpc_proxy.php:949`, `php/xmlrpc_proxy.php:1657`, `php/xmlrpc_proxy.php:1168`.

Источники: `dup-prod#7`.

### X17

**rejectSystemMember() повторяет формат строки отказа rejectCommandSlot()** · nit · дублирование · коммит 74aea456 · голоса 1/1 · [php/xmlrpc_proxy.php:938](../../php/xmlrpc_proxy.php#L938)

Новые rejectSystemMember() (938-945) и rejectCommandSlot() (956-972) обе собирают хвост ' [slot N: имя]', внешнюю строку 'rejected (<причина>): <метод>' и вызывают reject($outer.$detail, $method, $outer). Формат, закреплённый в XMLRPCProxyContractFixture.php:399/650/660, задан в двух местах.

Просто заменить одну на другую нельзя: commandName() режет по '=' и убирает пробелы, потому что слот мультиколла — строка «имя=аргументы», а у члена system.multicall methodName — буквальное имя. Проверено на копии HEAD: для 'd.stop=x' сейчас пишется 'slot 1: d.stop?x', после замены было бы 'd.stop', то есть отвергнутый член записался бы под именем разрешённого метода. Попутный мелкий дефект: normalizeMethodName('') возвращает null, но проверка $method !== null стоит до нормализации, поэтому член с пустым methodName пишется как '[slot 1: ]'.

**Как исправить.** Вынести формат ' [slot N: имя]' и вызов reject() в общий хелпер, принимающий уже извлечённое имя. rejectCommandSlot() передаёт в него commandName($value) и свою подсказку, ветка system.multicall — normalizeMethodName($innerMethod), с проверкой на null после нормализации. Если хелпер не объединять, хотя бы пояснить комментарием, почему system.multicall не использует commandName().

Ещё места: `php/xmlrpc_proxy.php:956`.

Источники: `sig-seam-removal-leftovers#4`.

### X18

**Остатки удалённых переопределений: локальные $elevate/$sizeLimitMax и лишний параметр** · nit · мёртвый код · коммит 74aea456 · голоса 1/1 · [php/xmlrpc_proxy.php:1114](../../php/xmlrpc_proxy.php#L1114)

Переопределения из $options удалены, но строки 1114-1115 по-прежнему копируют статики $elevate и $sizeLimitMax в локальные переменные. Параметр $sizeLimitMax у emitArgumentFromDecoded() (974) передаётся из единственного вызова (1387) и всегда равен self::$sizeLimitMax; читатель ищет вызов с другим значением. Проверки $checked['method'] !== $innerMethod (1163) и emitScalarValue() === null (1171) на деле не срабатывают: в канонический payload попадают только точные известные имена и значения string/base64/i8. Это страховка «закрыто при сбое», удалять её не нужно, но она не подписана как инвариант.

**Как исправить.** Читать self::$elevate и self::$sizeLimitMax напрямую, убрать параметр $sizeLimitMax. Проверки 1163 и 1171 оставить с комментарием, что это инвариант «закрыто при сбое» и сработать он сейчас не может.

Ещё места: `php/xmlrpc_proxy.php:1115`, `php/xmlrpc_proxy.php:974`, `php/xmlrpc_proxy.php:1387`, `php/xmlrpc_proxy.php:1163`, `php/xmlrpc_proxy.php:1171`.

Источники: `proxy#8`.

### X19

**Проверка вложенного system.multicall не меняет исход, и тест не может её закрепить** · nit · страховка без теста · коммит 74aea456 · голоса 1/1 · [php/xmlrpc_proxy.php:1154](../../php/xmlrpc_proxy.php#L1154)

Строки 1153-1155 (`if($innerMethod === 'system.multicall') return self::rejectSystemMember(...)`) выполняются, но всё, что они отвергают, отвергли бы и следующие шаги. Член со вложенным массивом отвергает emitCallFromScalars() (1156-1158: emitScalarValue() возвращает null на массив). Любую другую форму (без параметров, со скаляром, с двумя параметрами) отвергает рекурсивный decide() на 1143-1145, который требует ровно один непустой массив. Текст отказа тот же: 'system.multicall [slot N: system.multicall]'. Проба на копии HEAD и на мутанте без 1153-1155 дала одинаковый reject на четырёх входах. Поэтому элемент 'system.multicall' в testSystemMulticallTrustRequiresEveryMemberToBeIndividuallyTrusted (86) закрепляет поведение (вложенный пакет не наследует доверие, и это верно), но не эту строку: на входе теста у мутанта отказ даёт count($params) !== 1 в рекурсивном decide().

**Как исправить.** Проверку не удалять, а дописать в комментарий, что это страховка на будущее: сейчас члены только скалярные, и рекурсивный decide() отвергает вложенный system.multicall сам, поэтому тест эту строку не закрепляет.

Ещё места: `tests/php/XMLRPCProxyTest.php:86`.

Источники: `tests-proxy#6`.

### X20

**Отказ первой команды view-first d.multicall пишется как «ambiguous multicall view», и cat-исключение там не действует** · nit · диагностика · коммит 74aea456 · голоса 1/1 · [php/xmlrpc_proxy.php:1317](../../php/xmlrpc_proxy.php#L1317)

Для d.multicall строка с '=' в параметре 1 однозначно считается командой результата. Но при отказе строка 1317 пишет «ambiguous multicall view»: d.multicall('main','d.wibble=') даёт «rejected (ambiguous multicall view): d.multicall [slot 2: d.wibble]», а тот же d.wibble на третьей позиции даёт «not allowed on this connection [slot 3]». Строка 1315 вызывает rebuildMulticallParam() без $resultSlot=true, поэтому исключение ratio 'cat=$d.views=' (1655) не действует в этом слоте, хотя в любом следующем слоте результата срабатывает; это расходится с комментарием 1308-1310. Признак view-first вычисляется дважды: $isViewFirst на 1292 и заново условием strncmp/strpos на 1311 с уточнением на 1313.

**Как исправить.** В ветке d.multicall опираться на $isViewFirst, передать $resultSlot=true и писать причину 'not allowed on this connection'. «ambiguous multicall view» оставить только для d.multicall2 и d.multicall.filtered.

Ещё места: `php/xmlrpc_proxy.php:1292`, `php/xmlrpc_proxy.php:1311`, `php/xmlrpc_proxy.php:1315`.

Источники: `proxy#6`.

### X21

**Слот фильтра d.multicall.filtered принимает только сеттеры, а тесты и conf-комментарий подают это как «разрешённый фильтр»** · nit · странная логика · старый долг · коммит 74aea456 · голоса 2/2 + 1/1 · [php/xmlrpc_proxy.php:1327](../../php/xmlrpc_proxy.php#L1327)

Строка 1327 сверяет фильтр только с $safeParams, то есть со списком сеттеров. Поэтому настоящий предикат (d.is_active=, d.complete=, d.state=) отвергается с 403, а сеттер (d.custom1.set=x, d.stop=, d.delete_tied=) принимается как «фильтр» и уходит trusted. rtorrent вычисляет фильтр для каждой закачки, так что «фильтр» d.stop= останавливает весь вид. Новой возможности записи нет: тот же d.stop= уже разрешён в слоте результата d.multicall2 и на базе.

Правило старше диапазона (на origin/master фильтр тоже сверялся только с $safeParams), и код его не скрывает: шапка (55-61) и комментарий 1336-1341 прямо называют его setter-only. Диапазон добавил чтение в слоты результата при прежнем фильтре и переименовал тест 1695 в testFilteredMulticallRejectsGetterOutsideExplicitFilterPolicy, а кейс фикстуры 412 — в «a filtered multicall with an allowed filter and result is rebuilt and trusted»; эти имена называют сеттер «allowed filter». conf/xmlrpc_proxy.php:40-41 («An empty list therefore refuses filtered multicalls») подсказывает, будто с непустым поставляемым списком фильтрованные мультиколлы проходят; на деле с поставляемым списком проходит только фильтр-сеттер. Измерено: добавление геттера в список помогает (defaultSafeParams() + 'd.is_active' → фильтр 'd.is_active=' уходит trusted). Мутация строки 1327 на self::$safeGetters валит ровно тесты 1695 и 1809. Панель d.multicall.filtered не шлёт, затронуты внешние клиенты; отказ видим.

**Как исправить.** Решить правило. Либо сверять фильтр с $safeGetters и отвергать в нём сеттеры (отдельное решение по политике) и перевернуть тесты 1695, 1809 и кейс 412. Либо оставить правило и переименовать тесты и кейс так, чтобы они говорили «слот фильтра принимает только команды записи из $safeParams». В conf/xmlrpc_proxy.php:40-41 заменить на: «The filter slot of d.multicall.filtered is matched against this list alone, not against the built-in readers. With the shipped list only a setter is accepted there, so a read filter such as d.is_active= is refused unless that reader is added to this list. An empty list refuses every filtered multicall.» Docblock'и php/xmlrpc_proxy.php:1063 и 1096 верны.

Ещё места: `conf/xmlrpc_proxy.php:40`, `tests/php/XMLRPCProxyTest.php:1695`, `tests/php/XMLRPCProxyTest.php:1809`, `tests/php/XMLRPCProxyContractFixture.php:412`.

Источники: `tests-proxy#2`, `portability-claims#6`.

### X22

**Параметр $directory у rebuildMulticallParam() мёртв** · nit · мёртвый код · коммит 74aea456 · голоса 1/1 · [php/xmlrpc_proxy.php:1650](../../php/xmlrpc_proxy.php#L1650)

rebuildMulticallParam() берёт имя так же, как rebuildSafeLoadParam(), и любое имя из $directoryCommands отвергает через isDirectDenied() (1659) до вызова rebuildSafeLoadParam() (1661). Поэтому проверка границы каталога в rebuildSafeLoadParam() (1702-1712) достижима только из хвостов load.* (вызов на 1253), а три вызова 1315, 1327 и 1349 передают $directory зря. По параметру кажется, что граница $topDirectory участвует в проверке слотов мультиколла; на деле сеттеры каталога там запрещены безусловно, что уже описано в комментарии 1648-1649 и закреплено testMulticallMayNotCarryADirectorySetter. Кто решит разрешить d.directory.set в мультиколле, будет считать, что граница уже защищает, хотя этот путь не выполнялся ни разу.

**Как исправить.** Убрать $directory из сигнатуры rebuildMulticallParam() и трёх вызовов, в rebuildSafeLoadParam() передавать значение по умолчанию null. В комментарии указать, что граница каталога действует только для хвостов load.*.

Ещё места: `php/xmlrpc_proxy.php:1315`, `php/xmlrpc_proxy.php:1327`, `php/xmlrpc_proxy.php:1349`, `php/xmlrpc_proxy.php:1702`.

Источники: `sig-seam-removal-leftovers#1`.

### X23

**Загрузка политики скопирована в обе двери, а проверка читаемости в каждой сделана дважды** · nit · дублирование · коммит 74aea456 · голоса 2/2 + 1/1 · [plugins/httprpc/action.php:661](../../plugins/httprpc/action.php#L661)

Диапазон добавил в обе двери три одинаковых шага копией, а не вынес их в ядро: отказ 503 при нечитаемом conf/xmlrpc_proxy.php (rpc2.php:54-60, action.php:661-667), откат на XMLRPCProxy::defaultSafeParams() через isset() (rpc2.php:71-72, action.php:692-693) и побайтово одинаковый суффикс ' [built-in policy]' (rpc2.php:170, action.php:724). Следующая правка в одну дверь даст две реакции.

Внутри каждой двери читаемость проверяется дважды. В action.php ветка 661-667 уже завершила запрос для нечитаемого файла, поэтому `is_readable($policyFile)` на 668 всегда истинно; к тому же между 661 и 668 открыто окно, где пропавшее право на чтение тихо даст встроенный список. В rpc2.php пара стоит в обратном порядке (49 — require только для читаемого, 54 — отказ), одно решение разрезано на два условия, и окно здесь в обратную сторону: файл, нечитаемый на 49 и ставший читаемым к 54, не подключается и не даёт 503, то есть тихо даёт встроенный список.

Форма тела ответа на нечитаемую политику различается (rpc2 — XML-fault -501, httprpc — text/html), но это продолжение старого различия дверей: httprpc и в базе отвечал на 400/500 text/html; одинаковым было только 403 фильтра. Выражения границы каталога (rpc2.php:140-148/164, action.php:708-711) были до диапазона и различаются намеренно. Попутно, до диапазона: rpc2_fault() повторяет конверт XMLRPCProxy::rejectionFault().

**Как исправить.** В обеих дверях: `if(is_file($f)) { if(!is_readable($f)) { отказ } require_once($f); }` (в rpc2.php `$logging = true` ставить до rpc2_log()). Вынести загрузку и нормализацию политики в один малый загрузчик, общий для обеих дверей (различие границы каталога — параметром); `decide()` оставить без файловых зависимостей. Добавить XMLRPCProxy::faultXml($message) вместо rpc2_fault(). Плагин при этом не подключает другой плагин.

Ещё места: `plugins/httprpc/action.php:668`, `plugins/httprpc/action.php:692`, `plugins/httprpc/action.php:724`, `rpc2.php:49`, `rpc2.php:54`, `rpc2.php:71`, `rpc2.php:170`.

Источники: `dup-prod#1`, `dead-strange#7`.

### X24

**Комментарий httprpc/conf.php обещает, что $XMLRPCProxySafeParams задаёт все команды мультиколла, а чтения идут мимо него** · nit · комментарий · коммит 74aea456 · голоса 1/1 · [plugins/httprpc/conf.php:9](../../plugins/httprpc/conf.php#L9)

Абзац 9-12 («The command names a caller may attach to a load.* or to a multicall are $XMLRPCProxySafeParams») был верен на origin/master. В диапазоне появился XMLRPCProxy::$safeGetters (316 читающих команд), и слоты результата мультиколла и слот вида старого d.multicall сверяются с array_merge($safeParams, self::$safeGetters) (1307, 1315, 1349); только фильтр d.multicall.filtered — с одним $safeParams. Проба: decide() для d.multicall2 '' main d.base_path= d.loaded_file= с $safeParams = array() на HEAD даёт send trusted, на base — reject. Оператор, который пишет здесь `$XMLRPCProxySafeParams = array();`, ждёт запрета всего, а читающие мультиколлы проходят trusted; закрыть их можно только $XMLRPCProxy = "off". Файл в том же диапазоне правили (удалено 14 строк), а абзац остался; conf/xmlrpc_proxy.php:27-42 это уже объясняет правильно.

**Как исправить.** Написать, что список касается записывающих команд в хвостах load.*, в слотах мультиколла и в фильтре d.multicall.filtered; читающие команды слотов результата задаёт $safeGetters в php/xmlrpc_proxy.php, отсюда их не сузить, отключается всё только через $XMLRPCProxy = "off". Дать ссылку на conf/xmlrpc_proxy.php.

Ещё места: `php/xmlrpc_proxy.php:1307`, `conf/xmlrpc_proxy.php:27`.

Источники: `sig-stale-replacement-comment#7`.

### X25

**Фикстура контракта описывает себя двумя противоречащими утверждениями** · nit · комментарий · коммит 74aea456 · голоса 1/1 · [tests/php/XMLRPCProxyContractFixture.php:47](../../tests/php/XMLRPCProxyContractFixture.php#L47)

Новый комментарий 47-49 («Case names describe the expected behavior ... the tuple is the assertion oracle ... update both the name and the tuple») появился вместо прежнего «не переименовывать ключи». Он противоречит неизменённой шапке того же файла (6-9: «Generated from the implementation, then frozen. It is not a description of what the proxy should do») и докблоку XMLRPCProxyContractTest.php:36-40 («the fixture records what process() did before decide() was split out»). Последнее уже ложно: в этом диапазоне около 20 кортежей сменили исход. Новому правилу не соответствуют ключи 422, 432 и 442 («t./f./p.multicall is command-carrying too»): исход у них сменился с отказа на trusted-отправку, а имя этого не говорит. Сопровождающий не поймёт, заморозка это или спецификация. Вне диапазона: testMulticallWithAnUnknownCommandIsForwardedUntouched (XMLRPCProxyTest.php:745, тело правлено в диапазоне) и testSystemMulticallIsStillForwardedUntouched (1218) проверяют отказ, но называются «forwarded»; их докблоки «Legacy method identifier preserved» были ещё в базе, потребителя этих имён grep не находит.

**Как исправить.** Оставить одно утверждение: переписать 6-9 и 36-40 в духе «кортеж — оракул, имя описывает исход, правятся вместе». Переименовать три ключа, например «t.multicall of a read command is rebuilt and trusted». Заодно переименовать 745 и 1218 в ...IsRejected.

Ещё места: `tests/php/XMLRPCProxyContractFixture.php:6`, `tests/php/XMLRPCProxyContractTest.php:36`, `tests/php/XMLRPCProxyContractFixture.php:422`, `tests/php/XMLRPCProxyContractFixture.php:432`, `tests/php/XMLRPCProxyContractFixture.php:442`, `tests/php/XMLRPCProxyTest.php:745`.

Источники: `tests-proxy#7`.

### X26

**Нормализация имени слота в логе отказа не закреплена** · nit · слабый тест · коммит 74aea456 · голоса 2/2 · [tests/php/XMLRPCProxyContractFixture.php:244](../../tests/php/XMLRPCProxyContractFixture.php#L244)

Диапазон добавил в строку отказа имя команды слота от клиента, `[slot N: name]`, через commandName() → normalizeMethodName() (947-954: символы вне [A-Za-z0-9_.:-] заменяются на '?', длина режется до 96 байт). Ни один кейс не кладёт в ИМЯ слота перевод строки, кавычки, пробелы, не-ASCII или больше 96 байт. Мутация «commandName() возвращает сырое имя» оставляет XMLRPCProxyTest (1171/0), ContractTest (885/0) и RejectionTest (20/0) зелёными. Подделать строку лога и без неё нельзя: formatLogMessage() (вызов в reject() на 1504) заменяет управляющие символы пробелом и режет строку до 512 байт, это закреплено в XMLRPCProxyTest.php:1547-1551. Последствие косметическое: в лог попадут кавычки, пробелы, не-ASCII и имя примерно до 430 байт. Кейсы 234/244 не пустые, они закрепляют, что значение после '=' в лог не попадает (без разреза по '=' оба падают); только слово «forge» в имени 244 обещает больше, чем кейс проверяет. XMLRPCProxyTest 581/589 не менялись с origin/master.

**Как исправить.** Добавить кейс с '\n', кавычкой и спецсимволами в ИМЕНИ слота, например 'd.x\nxmlrpc-proxy: trusted: forged=1', ожидая '[slot 3: d.x?xmlrpc-proxy:?trusted:?forged]', и кейс с именем длиннее 96 байт с проверкой обрезки. Кейсы 234/244 оставить.

Ещё места: `tests/php/XMLRPCProxyContractFixture.php:234`, `php/xmlrpc_proxy.php:947`, `tests/php/XMLRPCProxyTest.php:581`, `tests/php/XMLRPCProxyTest.php:589`.

Источники: `tests-proxy#3`.

### X27

**Комментарий «the shipped policy files» остался от прежнего условия и не упоминает то, что фикстура дописывает** · nit · комментарий · коммит 74aea456 · голоса 1/1 · [tests/php/XMLRPCProxyEntrypointTest.php:686](../../tests/php/XMLRPCProxyEntrypointTest.php#L686)

Комментарий 686-688 («so that what a door decides here is what it decides on an install rather than what this fixture invented») написан для условия `$policy === 'shipped'`. Диапазон заменил условие на `!== 'none'` (689), а строки 703-712 после побайтовой проверки дописывают в conf/xmlrpc_proxy.php `$XMLRPCProxyLog` из переменной окружения для всех политик и собственный $XMLRPCProxySafeParams для restricted/empty. Для restricted/empty решение двери определяет именно список, придуманный фикстурой. Переключатель лога решений не меняет (влияет только на запись), и комментарий на 706 частично это раскрывает, так что вводит в заблуждение слабо.

**Как исправить.** Одна формулировка: поставляемые файлы копируются и сверяются побайтово, поэтому всё, чего фикстура не называет, совпадает с установкой; затем фикстура дописывает переключатель лога (для всех политик), список для restricted/empty и делает chmod 0000 для unreadable; 'none' не копирует ничего.

Ещё места: `tests/php/XMLRPCProxyEntrypointTest.php:689`, `tests/php/XMLRPCProxyEntrypointTest.php:703`.

Источники: `sig-stale-replacement-comment#9`.

### X28

**testEveryOtherDefinitionOfThePolicyAgreesWithIt не делает ни одной проверки и не проверяет, что сканер что-то посмотрел** · nit · слабый тест · старый долг · коммит 74aea456 · голоса 2/2 · [tests/php/XMLRPCProxyPolicyParityTest.php:106](../../tests/php/XMLRPCProxyPolicyParityTest.php#L106)

otherDefiners() (44-70) и на базе, и на HEAD возвращает пустой список: $XMLRPCProxySafeParams определён только в conf/xmlrpc_proxy.php, который пропускается намеренно (59); вторую копию убрал 62083b85 (#3251). Сам тест работает: если дописать расходящийся список в plugins/httprpc/conf.php, он падает. Но если вдобавок испортить регулярку имени файла на 56 (conf → conff) или список каталогов на 47, получается 7 Passed / 0 Failed, и тест больше ничего не ловит. Диапазон правил файл и оставил цикл как есть.

**Как исправить.** Возвращать из обхода и список просмотренных путей и утверждать, что среди них есть plugins/httprpc/conf.php (файл, который action.php выполняет после общей политики), или что просмотрено не меньше N файлов. Вариант assertEquals(array(), otherDefiners()) не брать: докблок 14-15 и plugins/httprpc/conf.php:14-16 прямо разрешают повторить политику без расхождения.

Ещё места: `tests/php/XMLRPCProxyPolicyParityTest.php:44`, `tests/php/XMLRPCProxyPolicyParityTest.php:56`.

Источники: `tests-proxy#5`.

### X29

**Четыре построителя methodCall в одном тесте и хелперы с именем «review»** · nit · дублирование · коммит 74aea456 · голоса 1/1 · [tests/php/XMLRPCProxyTest.php:50](../../tests/php/XMLRPCProxyTest.php#L50)

reviewCall (50-56), multicall() (718-728), load() (844-853) и callMethod() (935-945) повторяют один цикл по params и различаются мелочами: пролог `<?xml version="1.0"?>` есть только у трёх последних, внешний methodName экранирует только callMethod, кодировку 'UTF-8' передают только review*. Диапазон добавил reviewCall (17 вызовов) и reviewSystemMulticall (58-75), при этом testSystemMulticallWithAnUntrustedReadMemberIsRejected (1423), переименованный в том же коммите, по-прежнему собирает system.multicall вручную. Префикс «review» называет раунд ревью, а не то, что строится, и автор следующего теста выберет хелпер наугад. Проверено на копии HEAD: сведение к двум хелперам дало −44/+28 строк при прежнем 1171 Passed / 0 Failed. Около 200 ручных methodCall с намеренно испорченной структурой трогать не нужно; копию в XMLRPCProxyEntrypointTest.php:156-166 можно оставить.

**Как исправить.** Сделать methodCallXml($method, $params) (с прологом и экранированием имени) и systemMulticallXml($calls), перевести на них callMethod, multicall, load и тест 1423.

Ещё места: `tests/php/XMLRPCProxyTest.php:58`, `tests/php/XMLRPCProxyTest.php:718`, `tests/php/XMLRPCProxyTest.php:844`, `tests/php/XMLRPCProxyTest.php:935`, `tests/php/XMLRPCProxyTest.php:1423`.

Источники: `dup-tests#8`.

### X30

**testLegacyMulticallCommandCannotHideInTheModernViewSlot повторяет два соседних теста** · nit · дублирование · коммит 74aea456 · голоса 1/1 · [tests/php/XMLRPCProxyTest.php:309](../../tests/php/XMLRPCProxyTest.php#L309)

У d.multicall2 и d.multicall.filtered вызов с '=' в слоте вида отвергается на 1313-1314 раньше, чем смотрят на саму команду; эту ветку строже закрывает testOnlyLegacyViewFirstMulticallCanRebuildAnEqualsViewSlot (179-197), который проверяет ещё и причину. Для d.multicall execute.throw в слоте вида уже есть во втором цикле testLegacyViewFirstListingsRebuildTheirFirstResultSlot (242-247). Мутация: если удалить 1313-1314, тест 309 проходит (исполняемую команду отбрасывает пересборка), падает только 179; мутаций, которые ловит только 309, нет. Имя 'ModernViewSlot' вводит в заблуждение: тест покрывает и старый d.multicall. Любая правка правила слота вида требует синхронно менять три места.

**Как исправить.** Удалить тест 309 или добавить его исполняемую команду строкой в тест 179.

Ещё места: `tests/php/XMLRPCProxyTest.php:179`, `tests/php/XMLRPCProxyTest.php:231`.

Источники: `tests-proxy#9`.

## Snoopy, UrlHost, loginmgr и их потребители

Номера строк сверены с экспортом HEAD (/home/dev/.cache/rutorrent-tmp/r3/head). Объединены почти одинаковые находки: S4 (tests-snoopy#2 + snoopy#8, общий список учётных заголовков), S5 (snoopy#1 + tests-snoopy#1), S8, S10, S11, S13 (оба сигнальных прохода про RSS), S15 (tests-snoopy#0 + dup-tests#4, два двойника транспорта), S18, S20, S24, S30. Находка dup-prod#6 разделена на две: обёртка redirectTrust (S19) и проверка по имени 'YggTorrent' (S20). S16 понижен до nit: тест намеренно закрепляет отслеживаемый conf/config.php. После сверки 2026-09-25 в конец раздела добавлены два старых пункта вне дельты: N-S1 (P2) и N-S2 (P3). Три пункта (S2, S3, S7) — старые дефекты: диапазон их не вносил, но переписывал соседний код. S1, S6 и S4 правят одну и ту же функцию hasRedirectCredentials() (Snoopy.class.inc:438-451), поэтому решать их лучше вместе. Если принять S1 и S6, в функции останутся cookies, user/pass и сырые учётные заголовки, и её логично свести с trustsRedirect() в один помощник по S4. Заодно придётся править README:33-39 (S18). Несколько записей FIX-CHECK-RECHECK2 сформулированы шире, чем закреплено тестами: snoopy18 (S3), snoopy20 (S5), tests28 (S25), NEW-C (S4).

### S1

**Правило «свежий Set-Cookie запрещает межхостовый редирект» ломает анонимные загрузки и ничего не защищает** · P2 · регресс · коммит 156c64af · голоса 2/2 · [php/Snoopy.class.inc:443](../../php/Snoopy.class.inc#L443)

В hasRedirectCredentials() проверка Set-Cookie (строки 442-445) срабатывает только у полностью анонимного клиента. Под аккаунтом loginmgr отказ и так безусловный: на строке 372 стоит `redirectTrust !== null ||`. Если у клиента есть cookies, user/pass, тело или сырые учётные заголовки, отказ даёт остальная часть функции. Для анонимного вызова проверка ничего не защищает. Строка 369 уже обнуляет `_redirectaddr`, а setcookies() вызывается только при `passcookies && _redirectaddr` (строки 547 и 693). Значит, кука, которую источник только что выдал, и без этой проверки не импортируется и чужому хосту не уходит. Зонд с цепочкой «302 + Set-Cookie PHPSESSID → cdn.other.example» без условия: запрос к CDN ушёл с cookies=[], банка пуста. Проверка лишь запрещает сам переход, то есть возвращает регресс D3, который исправили раундом раньше.

Пример: публичная ссылка отвечает 302 на CDN или на www.* и попутно ставит PHPSESSID, AWSALB или __cf_bm. На origin/master переход выполнялся. На HEAD fetch() возвращает true со статусом 302, и дальше:
- php/addtorrent.php:85 и plugins/bulk_magnet/action.php:10 дают FailedURL;
- plugins/rss/rss.php:85 даёт rssCantLoadTorrent;
- plugins/rss/rss.php:145 даёт «[RSS-HTTP-Error] Status: 302; credential-redirect-refused».
В журнал уходит «credential-redirect-refused: A -> B; session not sent», хотя у клиента не было никаких учётных данных. Затронуты также extsearch (engines.php:51, в том числе KAT), tracklabels/action.php:126 и провайдеры check_port. Правило описано только в plugins/loginmgr/README.md:36-39, хотя относится к ядру и действует на установках вообще без loginmgr.

Мутация «удалить строки 442-445»: SnoopyTest 24/25 (падает только 'a fresh Set-Cookie refuses an anonymous cross-origin redirect'), CredentialBoundaryTest 15/0.

**Как исправить.** Удалить строки 442-445 и оставить обнуление `_redirectaddr` на строке 369. Тест 'a fresh Set-Cookie refuses an anonymous cross-origin redirect' переписать: переход выполнен, CDN получил запрос без Cookie, банка пуста. Фразы README:38-39 про Set-Cookie убрать. Если условие всё же оставлять, логировать его отдельной причиной (например fresh-set-cookie), а не «session not sent», и описать правило в документации ядра, а не loginmgr.

По плану ARCHITECTURE («Snoopy и loginmgr», п. 1–2) блокировку S1 по умолчанию снимает разделение `156c64af`: часть (б) с этими строками ждёт банку cookie с областью, а на `hasRedirectCredentials` объявлен мораторий. Удаление строк 442-445 — альтернатива на выбор владельца (там же, п. 2): это третий раунд правок охранника, и по умолчанию она не принята (A9).

Ещё места: `php/Snoopy.class.inc:369`, `php/Snoopy.class.inc:372`, `plugins/loginmgr/README.md:38`, `tests/php/SnoopyTest.php:151`.

Источники: `consumers#0`.

### S2

**fetch() не декодирует userinfo из URL, Basic-авторизация уходит с %-кодированными байтами** · P3 · дефект · старый долг · коммит 156c64af · голоса 2/2 · [php/Snoopy.class.inc:268](../../php/Snoopy.class.inc#L268)

fetch() (строки 268-269) берёт user и pass из parse_url() без rawurldecode(). Из этих байтов Snoopy сам собирает `Authorization: Basic`: для HTTP на строках 580-581, для HTTPS на строках 722-723 через curl -H. Увидев чужой заголовок Authorization, curl свои декодированные учётные данные из URL не подставляет. Поэтому `https://alice:p%40ss@host/rss` авторизуется как `alice:p%40ss`.

Для RSS круг поражённых паролей шире. rss.php:56 и :85 пропускают каждый URL через Snoopy::linkencode(), а она (строки 139-163) сама делает rawurlencode для user и pass, если в URL нет ни одной последовательности %XX. В итоге даже пароль, введённый без кодирования, уходит закодированным: `p@ss` превращается в `p%40ss`, `pa$$w0rd!` в `pa%24%24w0rd%21`, логин `bob@mail.test` в `bob%40mail.test`. Сервер отвечает 401 на любой логин или пароль с символом вне `A-Za-z0-9-._~`, и лента не загружается. Обойти это можно только неудобным путём: linkencode() не трогает URL, в котором уже есть любая последовательность %XX (строки 141-142). Поэтому `https://alice:p!ss@tracker.example/rss?x=%20` сохраняет пароль `p!ss`, а тот же URL без `%20` отправляет `p%21ss` (проба на HEAD). Это обход, а не исправление: пароль с `/`, `?` или `#` так не записать, а заранее закодированный пароль по-прежнему уходит закодированным. Добавление торрента по URL (php/addtorrent.php:84) linkencode не вызывает, там ломается только заранее закодированный пароль.

Дефект был и на origin/master (базовые строки 273-276). Диапазон его не вносил: он переписал именно это место, перенеся разбор в новую обёртку fetch() с областью действия цепочки, но декодирование не добавил.

**Как исправить.** На строках 268-269 применить rawurldecode() к user и pass. На копии это проверено: пробы дают открытый пароль, SnoopyTest 25/25. Добавить в SnoopyTest случаи `https://alice:p%40ss@tracker.example/` → pass 'p@ss' и `Snoopy::linkencode('https://alice:p!ss@tracker.example/')` → pass 'p!ss'. Пароль проверять через `$client->requests[0][3]` у SnoopyRedirectProbe, а не через `$client->pass`: после fetch() finally восстанавливает его в ''.

Ещё места: `php/Snoopy.class.inc:269`, `php/Snoopy.class.inc:580`, `php/Snoopy.class.inc:722`, `php/Snoopy.class.inc:157`, `plugins/rss/rss.php:56`.

Источники: `snoopy#2`.

### S3

**Отказ checkTarget() на втором хопе доверенного перехода оставляет взведённым отложенный импорт cookie** · P3 · дефект · старый долг · коммит 156c64af · голоса 2/2 · [php/Snoopy.class.inc:308](../../php/Snoopy.class.inc#L308)

Вложенный fetchRequest() может выйти рано на строках 308-309: при `$httpBlockPrivateNetworks = true` охранник частных адресов отверг цель, либо не ответил DNS. Тогда отложенный импорт `_redirectaddr` не гасится. Внешний кадр в этот момент уже внутри `if($ret)`, поэтому else на строках 396-397 не выполняется ни в одном из двух кадров. Выход на строках 292-295 сюда не относится: цель, которая не разбирается, отвергает trustsRedirect() (строки 418-420), а строка 369 гасит импорт ещё до рекурсии.

Что остаётся: после такого доверенного перехода `_redirectaddr` указывает на цель, в `$this->headers` лежит Set-Cookie первого ответа, и следующий явный fetch() на том же клиенте начинается с setcookies() (строки 547-548 и 693-694). По сравнению с успешным вторым хопом новый канал утечки появляется только в одном случае: отложенный импорт перебивает сброс или замену банки, которую вызывающий сделал между запросами. При успешном хопе та же sid попала бы в плоскую банку в любом случае, это известный пробел области действия cookie. Зато тогда cookie источника уходит со следующим явным запросом на любой хост, в том числе чужой: проба проверяющего после отказа, очистки `$cookies` и `fetch('https://other.test/...')` отправила `sid=source` на other.test. Пример такого кода — privateData::load() в plugins/loginmgr/accounts.php:27. Тогда чужая sid попала бы в сохраняемую сессию аккаунта.

В текущем дереве путь недостижим: `$httpBlockPrivateNetworks` по умолчанию false, и никто не переиспользует клиента после false. На origin/master `_redirectaddr` не гасился ни на одном пути, так что серия дефект сузила, а не внесла. snoopy18 в заявленном объёме (транспорт вернул false после разбора заголовков) действительно закрыт веткой else на строках 396-397. Открытой остаётся более широкая просьба проверяющего D18 (CHECK-RECHECK2:694): гасить импорт на любом выходе без ответа. Неверна и фраза в FIX-CHECK о snoopy18: «импорт делает транспорт вложенного fetch() до любого false». Комментарий на строках 367-368 стоит на недоверенной ветке и там верен.

**Как исправить.** Обнулять `$this->_redirectaddr = false;` в finally после вложенного `fetch($redirect)` (строки 386-390): к этому моменту вложенный транспорт его уже использовал. Проверено на копии: проба закрыта, SnoopyTest 25/25, CredentialBoundaryTest 15/15. Добавить в SnoopyTest случай: отказ checkTarget или DNS на втором хопе, вызывающий очищает банку, следующий запрос уходит без Cookie.

Ещё места: `php/Snoopy.class.inc:309`, `php/Snoopy.class.inc:384`, `php/Snoopy.class.inc:396`.

Источники: `snoopy#0`.

### S4

**Список учётных заголовков продублирован в двух методах, а Proxy-Authorization и сырой Authorization под redirectTrust тестами не закреплены** · P3 · слабый тест · коммит 156c64af · голоса 2/2; 1/1 · [php/Snoopy.class.inc:427](../../php/Snoopy.class.inc#L427)

trustsRedirect() (строки 424-428) и hasRedirectCredentials() (строки 440, 446-448) дважды повторяют одну проверку «у клиента есть свои учётные данные»: user/pass плюс перебор rawheaders по литералу array('cookie','authorization','proxy-authorization').

Тестами закреплён только сырой Cookie, причём в обоих методах. Authorization закреплён только в hasRedirectCredentials(): источник 'raw-auth' в CredentialBoundaryTest.php:102-108 работает без redirectTrust. Proxy-Authorization не закреплён нигде. Мутации на копии:
- убрать 'proxy-authorization' из обоих списков (427 и 447): SnoopyTest 25/0, CredentialBoundaryTest 15/0;
- оставить на строке 427 только array('cookie'): оба набора тоже зелёные.
Поэтому утверждение NEW-C в FIX-CHECK-RECHECK2-5eba5135.md:58, что граница Cookie/Authorization/Proxy-Authorization закреплена в Snoopy и CredentialBoundary, верно только для Cookie.

Утечка латентная. В дереве в rawheaders кладут только Origin, If-None-Match и If-Last-Modified, а сам Snoopy Proxy-Authorization не формирует. Но если сторонний вызывающий код положит такой заголовок, после ослабляющей правки он уйдёт при account trust на соседний HTTPS-хост аккаунта, а при анонимном переходе на любой хост. Две копии списка к тому же могут незаметно разойтись: новый заголовок-носитель секрета (например X-Api-Key), вписанный только в один список, либо будет пересылаться по доверию аккаунта, либо не остановит анонимный переход.

Попутно: `|| isset($parts['pass'])` (строка 263) и `|| isset($targetParts['pass'])` (строка 419) исход не решают, потому что при наличии pass parse_url() всегда ставит и user, пусть пустой. Читатель ищет случай «pass без user», которого не бывает.

**Как исправить.** Вынести список в константу класса (например `const CREDENTIAL_HEADERS`) или в один метод `carriesOwnCredentials()` (user/pass плюс сырые заголовки) и вызывать его из обоих мест. Добавить источник 'raw-proxy-auth' (`$client->rawheaders['PrOxY-AuThOrIzAtIoN']`) в первый тест CredentialBoundaryTest, а в SnoopyTest — случаи с redirectTrust для сырых Authorization и Proxy-Authorization. Условия userinfo сократить до `isset(...['user'])` или пометить комментарием как защитный дубль. Поправить формулировку NEW-C в FIX-CHECK.

Ещё места: `php/Snoopy.class.inc:447`, `php/Snoopy.class.inc:424`, `php/Snoopy.class.inc:263`, `php/Snoopy.class.inc:419`, `tests/plugins/loginmgr/CredentialBoundaryTest.php:102`.

Источники: `tests-snoopy#2`, `snoopy#8`, `dup-prod#4`.

### S5

**Требование https и проверка источника в ветке доверия аккаунту не закреплены тестами** · P3 · слабый тест · коммит 156c64af · голоса 2/2 · [php/Snoopy.class.inc:431](../../php/Snoopy.class.inc#L431)

В ветке доверия аккаунту в trustsRedirect() есть два сужающих условия, и ни одно не закреплено тестом:
- https и у источника, и у цели (строки 431-432);
- проверка источника через redirectTrust (строка 434).
Мутации, удаляющие любое из них, оставляют зелёными SnoopyTest 25/25, CredentialBoundaryTest 15/15, AccountSelection, CommonAccount, KinozalAccount и UrlHostTest. Сама разрешающая ветка закреплена: если заменить её на false, падают 2 случая CredentialBoundaryTest.

Практический охват узкий. У всех поставляемых аккаунтов test() и так требует https, поэтому для них проверка схемы избыточна: KinozalTV без неё всё равно отказывает на 302 https → http. Понижение достижимо только для стороннего аккаунта с `$url = http://...`. Его commonAccount::test() через urlIsOneOf() со схемой 'http' принимает и https, и без проверки на строках 431-432 редирект https://tracker.example → http://dl.tracker.example отправил бы банку сессии открытым текстом (пробой подтверждено: второй запрос уходит с sid). Комментарий на строках 429-430 обещает «never authorizes a downgrade», а тест этого не держит.

Проверка источника охраняет переход, у которого источник лежит вне test() аккаунта. Это может быть стартовый URL операции (например login() LostFilm на /useri.php при префиксе /download.php) или промежуточный URL, куда цепочка попала same-origin переходом за пределы префикса. Поставляемые аккаунты явно запрашивают только собственные хосты, так что вреда для них нет, но и теста нет.

Исходная цепочка snoopy20 (трекер → evil → трекер) теперь закрыта другим механизмом, безусловным отказом при redirectTrust !== null (строка 372). Он закреплён тестом 'account scope refuses an anonymous foreign hop...'. Поэтому строку FIX-CHECK о snoopy20 («закреплена в Snoopy 25/25») нужно дополнить: условия 431-434 тестами не закреплены. На origin/master этого механизма не было, так что это не регрессия.

**Как исправить.** Добавить в SnoopyTest два случая с замыканием redirectTrust, как в существующих тестах, с непустой банкой. Первый: https-источник, http-цель на хосте аккаунта, замыкание принимает http. Второй: источник вне префикса замыкания (например старт на /login при префиксе /forum/), цель https://dl.tracker.example/forum/x. В обоих ожидать один запрос и credential-redirect-refused. Дополнить запись snoopy20 в FIX-CHECK.

Ещё места: `php/Snoopy.class.inc:434`, `php/Snoopy.class.inc:372`.

Источники: `snoopy#1`, `tests-snoopy#1`.

### S6

**Непустое тело запроса считается учётными данными, хотя через редирект тело не уходит никогда** · P3 · странная логика · коммит 156c64af · голоса 2/2 · [php/Snoopy.class.inc:440](../../php/Snoopy.class.inc#L440)

hasRedirectCredentials() (строка 440) считает учётными данными любое непустое тело (`$body !== ''`). Но переход выполняется вызовом `$this->fetch($redirect)` (строка 384) методом GET без тела, так что тело на чужой хост не попадает ни при каком исходе. Для операций аккаунта условие избыточно: loginmgr (plugins/loginmgr/accounts.php:194-197, 240-243) выставляет redirectTrust, и тогда отказ на строке 372 безусловный. На глубине ≥1 тело всегда пустое. Значит, условие решает только в одном случае: анонимный POST на нулевом хопе (пустая банка, нет user/pass, Set-Cookie и сырых Cookie/Authorization) получил редирект на чужой origin. На origin/master такие переходы выполнялись, поведение новое.

Проба на копии HEAD: POST с телом на https://api.example/v1/measure, ответ 303 на https://www.api.example/result/7. Итог: 1 запрос, статус 303, error='credential-redirect-refused', в журнале «api.example -> www.api.example; session not sent, original response retained», хотя никакой сессии не было. Тот же обмен методом GET даёт 2 запроса и статус 200.

В дереве путь почти недостижим. Единственный анонимный POST — check_port/globalping.php; там межхостовый редирект нереалистичен, а переход GET всё равно не создал бы измерение, так что итог (unknown) тот же. Вводят в заблуждение метка ошибки и строка журнала. Для стороннего кода поведение меняется: анонимная форма POST → 303 на другой хост больше не доводится до страницы результата.

Условие закрепляет только вариант 'post' (тело 'password=fake') в тесте «authenticated redirects never send cookies or credentials across origins» (CredentialBoundaryTest.php:99-116). Без `$body !== ''` падает только он («no second request leaves the client: expected 1, got 2»), SnoopyTest 25/25. Тест называется «never send cookies or credentials», а в варианте 'post' никакие учётные данные не пересылаются и без условия.

**Как исправить.** Убрать `$body !== ''` из hasRedirectCredentials(), а вариант 'post' в CredentialBoundaryTest переписать: переход выполнен, второй запрос — GET без тела. Если цель условия — не пускать чужие cookie в плоскую банку клиента, который переиспользуют после POST, написать это в комментарии и логировать отдельной причиной, а не «session not sent». Сейчас такое обоснование непоследовательно: у анонимного GET банка пополняется так же.

Ещё места: `php/Snoopy.class.inc:384`, `tests/plugins/loginmgr/CredentialBoundaryTest.php:102`.

Источники: `snoopy#3`.

### S7

**Location без пробела после двоеточия превращается в редирект на корень исходного хоста** · P3 · дефект · старый долг · коммит 156c64af · голоса 2/2 · [php/Snoopy.class.inc:781](../../php/Snoopy.class.inc#L781)

Заголовок `Location:` без пробела после двоеточия (по RFC 9110 пробел необязателен) не разбирается ни на HTTPS-пути (`/^(Location: |URI:)(.*)/i`, строка 781), ни на HTTP-пути (`\s+`, строка 611). Запасной preg_match для Refresh (строки 612/782) тоже не совпадает, и `$matches[2]` не определён. Из пустого значения собирается `https://<исходный хост>:443/` (на HTTP-пути `http://...:80/`). Этот адрес попадает в `lastredirectaddr`, и trustsRedirect() судит адрес, которого сервер не присылал.

Утечки нет: цель остаётся исходным хостом, и cookie уходят тому, кто их выставил. Реальные последствия:
- вызывающий получает корень исходного хоста вместо настоящей цели редиректа и принимает его за ответ;
- в журнале PHP шум: Warning «Undefined array key 2» на PHP 8.x или Notice «Undefined offset: 2» на 7.4, а на PHP 8.1+ ещё Deprecated про null в preg_match.

Насколько известно, nginx, Apache, IIS и Cloudflare пишут `Name: value`, но захватами это не проверено, и уровень на этом не снижается. curl по HTTP/2 и HTTP/3 сам формирует строки файла -D как `name: value`, так что заголовок без пробела может прийти только по HTTP/1.x. Выражения пришли на origin/master с импортом upstream d1aa2cd8 (базовые строки 501 и 671). Этот диапазон их не менял.

**Как исправить.** На обоих путях разбирать `/^(Location|URI)\s*:\s*(.*)/i`, а если не совпало ни одно выражение, `_redirectaddr` не выставлять. Это заодно закроет пустой `Location:` и двойной пробел на HTTPS-пути: сейчас там значение сохраняет ведущий пробел. Добавить в SnoopyTest сценарный случай: `Location:https://evil.test/x` при непустой банке даёт credential-redirect-refused и один запрос.

Ещё места: `php/Snoopy.class.inc:611`, `php/Snoopy.class.inc:612`, `php/Snoopy.class.inc:782`.

Источники: `snoopy#4`.

### S8

**Движок extsearch YggTorrent без проверки подключает файлы плагина loginmgr** · P3 · переносимость · коммит 156c64af · голоса 2/2 · [plugins/extsearch/engines/YggTorrent.php:81](../../plugins/extsearch/engines/YggTorrent.php#L81)

YggTorrentEngine::trustedAccount() (строки 78-84) через require_once без проверок подключает ../../loginmgr/accounts.php и accounts/YggTorrent.php. Её вызывают action() (строка 97) и getTorrent() (строка 88). Если каталога plugins/loginmgr нет, это фатальная ошибка «Failed opening required» (зонд: PHP 8.5, exit 255, стек YggTorrent.php:97→81 и :88→81). Нарушено правило подключать файлы чужого плагина только после isPluginRegistered(). Сама интеграция extsearch с loginmgr допустима и уже существует в условной форме. Ядро (php/Snoopy.class.inc:243-246) и сам extsearch (engines.php:317-320) подключают чужие файлы только после isPluginRegistered(). На origin/master движок от файлов loginmgr не зависел.

Из обычного UI до падения не добраться. init.php при каждой загрузке вызывает obtain(), и тот ставит auth-движку enabled=0, если loginmgr не зарегистрирован (engines.php:259-262). init.js выключенный движок не показывает и сбрасывает его из выбора. Падение возможно в трёх случаях:
- вручную собранный запрос action.php?mode=get&eng=YggTorrent (ветка default, engines.php:402-405, enabled не проверяет);
- mode=loadtorrents с eng=YggTorrent (engines.php:441-442);
- устаревшая вкладка: каталог loginmgr удалили, страницу не перезагрузили. В кэше extsearch.dat у Ygg остаётся enabled=1, action.php:7-12 не вызывает obtain(), и падает весь ответ общего поиска all/private вместе с результатами остальных движков. После перезагрузки страницы всё проходит.
Если loginmgr выключен через plugins.ini, а каталог на месте, падения нет. Прод не затронут: в образе loginmgr есть.

**Как исправить.** Перед подключением проверять is_file() и isPluginRegistered('loginmgr'), как в engines.php:317. Если проверка не прошла, action() возвращает ту же заглушку, что при пустом $yggTorrentOrigin, а getTorrent() возвращает false. Другой вариант: вынести проверку origin Ygg в небольшой помощник ядра в php/, общий для loginmgr и extsearch.

Ещё места: `plugins/extsearch/engines/YggTorrent.php:82`, `plugins/extsearch/engines/YggTorrent.php:88`, `plugins/extsearch/engines/YggTorrent.php:97`.

Источники: `consumers#2`, `dup-prod#0`.

### S9

**commonAccount::check() молча пропускает обновление сессии у аккаунта без $url** · P3 · диагностика · коммит 156c64af · голоса 2/2 · [plugins/loginmgr/accounts.php:247](../../plugins/loginmgr/accounts.php#L247)

Умолчание commonAccount::$url сменили с 'http://abstract.com' на '' (строка 65), а commonAccount::check() молча выходит, если у $url нет хоста (строки 246-248). В журнал ничего не пишется, а флаг configurationRequired в UI вычисляется только для YggTorrent (строки 333-339 и 403).

Поставляемый код до этого условия не доходит. У всех аккаунтов, кроме Ygg, $url задан, а YggTorrentAccount::check() (YggTorrent.php:71-76) не вызывает parent::check() без origin и пишет свою строку через hasOrigin(). Как защита условие избыточно: Snoopy::fetch('') возвращает false без запроса (Snoopy.class.inc:292-294).

Затронут сторонний аккаунт в plugins/loginmgr/accounts/ без $url, со своим test() и с login() на жёстко заданный хост (так был устроен прежний YggTorrent). obtain() подхватывает такой файл из каталога автоматически. Проба: на origin/master его check() логинился и сохранял сессию (logins=1, stored=1), на HEAD не делает ни одного запроса и ничего не пишет в журнал. UI при этом показывает «auto» включённым. Загрузки продолжают работать: fetch() сам логинится заново по гостевому ответу (проба: ret=true, logins=1, stored=1). Лишних POST с паролем не прибавилось. Пропадает только упреждающее продление сессии, и пропадает незаметно, вопреки правилу AGENTS.md «самовосстановление или видимость». Существенно это лишь для трекера, чей isOK() не распознаёт гостевую страницу.

**Как исправить.** При пустом хосте писать одну классифицированную строку в журнал с именем аккаунта (например `loginmgr: missing-origin: <Account>`, раз на процесс). Само условие не снимать: тест CredentialBoundaryTest 'an account without an origin cannot enter automatic login' закрепляет его как намеренный контракт (без настроенного origin поток учётных данных не начинается, класс LG17). Отмена потребовала бы отдельного решения о доверии.

Ещё места: `plugins/loginmgr/accounts.php:65`, `plugins/loginmgr/accounts.php:248`.

Источники: `sig-silent-tightening-without-upgrade-path#2`.

### S10

**http-ссылки на шесть трекеров теперь молча уходят без сессии** · P3 · диагностика · коммит 156c64af · голоса 2/2 · [plugins/loginmgr/accounts.php:379](../../plugins/loginmgr/accounts.php#L379)

Коммит 156c64af сделал https обязательным в test() для KinozalTV, RUTracker, NNMClub, TapochekNet, Toloka и ZamundaNet. Проба на копиях base и head: на origin/master test() принимал и http://, и https:// ссылки на все шесть трекеров, на HEAD принимает только https. Теперь accountManager::getAccount() (строки 367-380) для http-ссылки молча возвращает false, Snoopy::fetchComplex() (Snoopy.class.inc:253-257) делает fetch без сессии loginmgr, но с cookies плагина cookies и `:COOKIE:`, если они уже загружены в банку на строках 235-242 (см. [N-S1](#n-s1)), и в журнал ничего не пишется: в accounts.php нет ни одного FileUtil::toLog. Саму границу менять не нужно, миграция HTTP→HTTPS признана допустимой прошлым раундом. Вопрос в том, что отказ не виден.

Собственный код форка не страдает: extsearch и rutracker_check строят https-ссылки. Затронуты ссылки пользователя: ручное добавление по URL, элементы RSS, старые правила rssurlrewrite с http://. У Kinozal, RuTracker, Tapochek, Toloka и Zamunda гость получает HTML, и пользователь видит общее addTorrentFailedURL или rssCantLoadTorrent без причины. У NNMClub хуже: гостевая загрузка там работает, поэтому http-ссылка может без ошибки добавить гостевой .torrent без пасскея пользователя (выведено из кода, не измерено). Схема как причина названа только в README.md:42-46.

Молчаливый отказ по схеме был и на base у AniDUB, LostFilm, NovaFilm, Tfile и у унаследованного commonAccount::test() для аккаунтов с https-адресом. Диапазон расширил его ещё на шесть аккаунтов. Это повтор необязательной части D8 (CHECK-FIXES-5eba5135.md): D8 закрыт одним абзацем README, однократный лог не сделан. AGENTS.md требует, чтобы отказ был «either self-healing or visible», а этот чинится только ручной правкой ленты или правила.

**Как исправить.** Сделать диагностику один раз и обобщённо, в getAccount(): если http-URL отвергнут, а его https-вариант принял бы включённый аккаунт, писать раз на процесс классифицированную строку вида `loginmgr: http-url-not-authenticated: <Account> <host>`, без пути и query (там может быть passkey). Закрепить тестом, что строка появляется ровно один раз и сессия loginmgr при этом не уходит. Тест должен различать три источника cookie: сессию loginmgr, плагин cookies и `:COOKIE:` (см. N-S1).

Ещё места: `plugins/loginmgr/accounts/KinozalTV.php:50`, `plugins/loginmgr/accounts/RUTracker.php:119`, `plugins/loginmgr/accounts/NNMClub.php:38`, `plugins/loginmgr/accounts/TapochekNet.php:33`, `plugins/loginmgr/accounts/Toloka.php:34`, `plugins/loginmgr/accounts/ZamundaNet.php:32`, `plugins/loginmgr/README.md:42`.

Источники: `loginmgr#1`, `consumers#1`, `portability-claims#2`.

### S11

**Ветка '..' в нормализации пути NNMClub не закреплена, а префикс /forum/ проверяется по другому пути** · P3 · слабый тест · коммит 156c64af · голоса 2/2 · [plugins/loginmgr/accounts/NNMClub.php:47](../../plugins/loginmgr/accounts/NNMClub.php#L47)

Ветку '..' в новой нормализации пути NNMClub (строки 47-48, исправление loginmgr15) не закрепляет ни один тест. Мутант, у которого '..' становится обычным сегментом, проходит AccountSelectionTest с результатом «11 tests, 0 failures». Пробы /forum/x/../login.php, /forum/%2e%2e/forum/login.php и /forum/../forum/login.php исходный код отклоняет, мутант принимает; на origin/master все три принимались. Список в AccountSelectionTest.php:141-163 содержит только //, /./ и %2E, хотя название теста обещает, что login.php не доходит до аккаунта никогда. Смысл правки — не дать эквивалентной записи /forum/login.php запустить цикл «POST пароля на каждый вызов». Ссылок вида /forum/x/../login.php не порождает ни один известный поток (RSS, rssurlrewrite, extsearch), поэтому это P3 на границе с nit.

Второе, уровня nit: префикс /forum/ проверяется по сырому пути (строки 36-38 → UrlHost::urlIsOneOf, php/urlhost.php:131-136), а исключение login.php — по декодированному и нормализованному (строки 40-53). Поэтому https://nnmclub.to/forum/../dl.php?id=1 аккаунт принимает, хотя нормализованный путь /dl.php лежит вне /forum/. Так было и на origin/master, новое только расхождение двух проверок. Хост тот же, утечки нет.

**Как исправить.** Добавить в список AccountSelectionTest.php:141 случаи 'https://nnmclub.to/forum/x/../login.php' => false и 'https://nnmclub.to/forum/%2e%2e/forum/login.php' => false. Желательно нормализовать путь один раз и сравнивать с ним и префикс /forum/, и исключение login.php.

Ещё места: `plugins/loginmgr/accounts/NNMClub.php:36`, `tests/plugins/loginmgr/AccountSelectionTest.php:141`.

Источники: `loginmgr#2`, `tests-snoopy#3`.

### S12

**Копия шаблона conf.php с вписанным origin в conf.local.php или per-user conf.php молча не применяется** · P3 · дефект · коммит 156c64af · голоса 2/2 · [plugins/loginmgr/conf.php:8](../../plugins/loginmgr/conf.php#L8)

Шаблон plugins/loginmgr/conf.php (строки 8-10, коммит 156c64af) присваивает `$yggTorrentOrigin = ''` под `if (!isset($yggTorrentOrigin))`. FileUtil::getPluginConf() (php/utility/fileutil.php:124-141) сначала подключает этот поставляемый файл, затем conf.local.php, затем conf/users/<user>/plugins/loginmgr/conf.php. К моменту загрузки поздних файлов переменная уже равна '', а isset('') даёт true. Поэтому копия шаблона с вписанным origin в conf.local.php или в per-user conf.php молча не применяется, и Ygg остаётся выключенным с причиной missing-origin. Проба на копии HEAD: в обоих случаях получено ["","","missing-origin",false,configurationRequired=true]. Простое присваивание в тех же файлах работает, и именно его пишут тесты (YggConfigurationTest.php:68 и :73), поэтому ловушку они не видят.

Сценарий реалистичен. Комментарий conf.php:3 сам отправляет оператора в эти файлы, per-user настройку плагина в ruTorrent обычно делают копией его conf.php, в поставляемом config.php $forbidUserSettings=false. При этом журнал (YggTorrent.php:66) называет тот самый файл, который оператор только что отредактировал, и утверждает «later files override earlier». Отказ закрытый и видимый (UI и журнал), утечки нет, но подсказка ложная. Путь из README (одна строка в conf/config.php) работает.

Сам guard не лишний по сравнению с голым `= ''`: без него значение по умолчанию затёрло бы conf/config.php. Лишним является весь исполняемый блок: неустановленную переменную конструктор и так превращает в '' (YggTorrent.php:12, accounts.php:8). Попутно: README:24 («all configuration override locations») и список в YggTorrent.php:66 не упоминают conf/users/<user>/config.php, который util.php:55-57 загружает после conf/config.php.

**Как исправить.** Убрать из conf.php исполняемый код, оставив комментарий и закомментированный пример (`// $yggTorrentOrigin = 'https://tracker.example';`). Проверено на копии: копия шаблона в conf.local.php и в per-user conf.php применяется, без настройки notice нет, результат [null, false, '', 'missing-origin']. При этой правке падает только тест 'the shipped Ygg default is unconfigured' (YggConfigurationTest.php:87-88 ожидает array('', false)); его перевести на проверку url или выбора аккаунта. Добавить в YggConfigurationTest случай «копия шаблона в conf.local.php / per-user conf.php применяется». Дополнить README:24 и строку журнала файлом conf/users/<user>/config.php.

Ещё места: `plugins/loginmgr/accounts/YggTorrent.php:12`, `plugins/loginmgr/accounts/YggTorrent.php:66`, `plugins/loginmgr/README.md:24`, `tests/plugins/loginmgr/YggConfigurationTest.php:87`.

Источники: `loginmgr#0`.

### S13

**При загрузке торрента из элемента ленты причина credential-redirect-refused теряется** · P3 · диагностика · коммит 156c64af · голоса 2/2 · [plugins/rss/rss.php:86](../../plugins/rss/rss.php#L86)

Причину credential-redirect-refused дописывают только к ошибке загрузки самой ленты (rRSS::fetch, строки 154-155; в UI через строку 964). Торрент из элемента ленты скачивает rRSS::getTorrent() (строки 80-106) через тот же rssFetchURL, и этот путь обслуживает и автозагрузку по фильтрам (строка 894), и ручную загрузку из UI (action.php:354). getTorrent() проверяет только 2xx и $cli->error не читает. rRSSManager::getTorrents() (строки 1221-1222) пишет лишь theUILang.rssCantLoadTorrent («Error loading torrent.») и URL, а в history — 'Failed'.

Отказ здесь достижим. У клиента бывают cookies из `:COOKIE:` ленты и cookies плагина cookies для хоста (Snoopy.class.inc:235-242). Для URL аккаунта loginmgr действует redirectTrust, и тогда отказ безусловный (Snoopy.class.inc:372). Отказ даёт и свежий Set-Cookie в ответе 302 (см. S1). Проба на копии HEAD: лента `https://tracker.example/rss.php:COOKIE:uid=1;pass=x`, элемент `https://tracker.example/dl.php?id=5` отвечает 302 на `https://cdn.tracker.example/...`. Итог: 1 запрос, error=credential-redirect-refused, getTorrent()=false, lastErrorMsgs пуст. Та же лента без cookies проходит переход, и .torrent сохраняется. На origin/master переход выполнялся, так что расхождение появилось в 156c64af.

Причина не пропадает бесследно: Snoopy::logRedirectRefusal() пишет её в журнал приложения, одна строка на пару хостов за процесс, без имени ленты и торрента. Но в интерфейсе RSS такая ошибка неотличима от 404 или недоступного трекера, а plugins/loginmgr/README.md:37 без оговорок обещает «RSS also shows that reason». Тест закрепляет только путь ленты (RSSTest.php:28-39). Если у элемента есть guid, wasLoaded() (строки 362-373) повторять загрузку не будет; так было и раньше при любом сбое. Тот же пробел есть в php/addtorrent.php:85 и extsearch, но README их не упоминает.

**Как исправить.** getTorrent() запоминает классифицированную причину (только фиксированный токен, потому что desc в addError — JS-выражение), а getTorrents() дописывает к rssCantLoadTorrent суффикс '; credential-redirect-refused', когда status ≥ 100 и error равен этому значению. Добавить тест на getTorrent/getTorrents с таким моком. Другой вариант: сузить фразу README до «RSS feed errors show that reason».

Ещё места: `plugins/rss/rss.php:85`, `plugins/rss/rss.php:1222`, `plugins/rss/rss.php:154`, `plugins/loginmgr/README.md:37`.

Источники: `sig-sibling-path-asymmetry#0`, `sig-silent-tightening-without-upgrade-path#1`.

### S14

**Тест про Basic из userinfo не проверяет, что первый запрос вообще нёс заголовок** · P3 · слабый тест · коммит 156c64af · голоса 2/2 · [tests/php/SnoopyTest.php:214](../../tests/php/SnoopyTest.php#L214)

Тест 'a later real HTTPS request does not carry Basic from earlier URL userinfo' (строки 214-222) проверяет только отсутствие 'Authorization: Basic' во втором вызове curl. Фейковый curl при каждом запуске затирает SNOOPY_TEST_ARGS (строка 43), поэтому первый запрос тест не видит вообще, и контрольной проверки, что заголовок там был, нет.

Сама утечка закреплена: если снять восстановление user/pass в finally у fetch() (Snoopy.class.inc:278-282), падают этот тест и ещё два. Не закреплён последний шаг — превращение user/pass в байты Authorization: Basic на обоих транспортах. Если поставить if(false) на Snoopy.class.inc:722 (а заодно на 580), SnoopyTest 25/0, CredentialBoundaryTest 15/0 и CommonAccountTest 18/0 остаются зелёными. Случаи SnoopyTest.php:130-137 и 164-172 идут через SnoopyRedirectProbe и в _httpsrequest не попадают. Пробел старый, на origin/master такого утверждения тоже не было; новый тест его не закрыл.

Для userinfo по HTTPS пропажа заголовка почти незаметна: curl получает полный URL (строка 760) и сам шлёт Basic. Сломались бы сокетный транспорт и вызовы с явным $client->user, например уведомления Pushbullet (plugins/history/history.php:324): они получали бы 401, а тесты бы молчали. Предложенная пара с 'alice:secret' не поймает дефект S2 (нет rawurldecode): для этого нужен отдельный случай с %40.

**Как исправить.** После первого fetch проверить `in_array('Authorization: Basic ' . base64_encode('alice:secret'), snoopyCurlArgs(), true)`, после второго — отсутствие заголовка. На чистом коде такая проверка проходит, при отключённой строке 722 падает (проверено). Вместе с S2 добавить случай с `p%40ss`.

Ещё места: `php/Snoopy.class.inc:722`, `php/Snoopy.class.inc:580`.

Источники: `sig-test-oracle-cannot-fail#2`.

### S15

**Два почти одинаковых двойника транспорта несут свою копию условия импорта cookie, и оно не закреплено** · P3 · слабый тест · коммит 156c64af · голоса 2/2; 1/1 · [tests/plugins/loginmgr/CredentialBoundaryTest.php:33](../../tests/plugins/loginmgr/CredentialBoundaryTest.php#L33)

В 156c64af добавлены два двойника поверх настоящего Snoopy: BoundaryTransport (CredentialBoundaryTest.php:19-51) и SnoopyRedirectProbe (SnoopyTest.php:95-117). Оба целиком заменяют _httprequest/_httpsrequest и несут свою копию продового условия импорта `if(passcookies && _redirectaddr) setcookies()` (CredentialBoundaryTest.php:33, SnoopyTest.php:106; в проде Snoopy.class.inc:547-548 и :693-694). Поэтому тесты «cookie прошлого ответа не импортируются в следующий запрос» закрепляют только половину инварианта, которая живёт в fetchRequest(): сброс `_redirectaddr` на строках 367-369, 392-393, 396-397. Мутация этой половины ловится (SnoopyTest 2 падения, CredentialBoundaryTest 1). Мутация условия импорта на `if($this->passcookies)` — то есть Set-Cookie любого предыдущего ответа уходит в следующий запрос — оставляет SnoopyTest 25/25, CredentialBoundaryTest 15/15, CommonAccountTest 18/18. Сами строки 547/693 диапазон не менял, но он чинил утечку с тем же симптомом.

Комментарий CredentialBoundaryTest.php:18 («Only the wire is replaced: fetch(), redirect recursion and cookie parsing are production code») неточен: двойник подменяет ещё решение об импорте cookie, сборку заголовков и разбор Location.

Дублирование: первые десять строк двойников совпадают, а кортежи устроены по-разному. Журнал запросов: [url, method, body, cookies] против [url, cookies, user, pass], поэтому requests[i][3] в одном файле — cookie, в другом — пароль. Ответ: [status, redirect, headers, results] против [ok, redirect, headers, status]. У BoundaryTransport есть ещё режим без очереди (строки 45-48), всегда возвращающий true. Любое изменение контракта _httpsrequest придётся повторять дважды, а решение открытого consumers9 (scoped cookie jar) будет переписывать именно это место.

**Как исправить.** Как минимум добавить в SnoopyTest один сквозной случай через настоящий _httpsrequest и фейковый curl: snoopyRespondWith отдаёт 302 + Location на чужой хост + Set-Cookie, ожидается credential-redirect-refused, затем fetch() на другой хост без аргумента curl, начинающегося с «Cookie:». Проверено: на HEAD проходит (26/26), под мутацией падает с «Cookie: secret=one». Режим SNOOPY_TEST_REDIRECT Set-Cookie отдавать не умеет, поэтому нужен snoopyRespondWith. Поправить комментарий на строке 18. Желательно вынести один двойник в tests/php/SnoopyWireFixture.php с именованными ключами журнала (прецеденты подключения фикстур из tests/php есть: NNMClubHandlerTest.php:6, EditActionSequenceTest.php:4).

Ещё места: `tests/plugins/loginmgr/CredentialBoundaryTest.php:18`, `tests/plugins/loginmgr/CredentialBoundaryTest.php:19`, `tests/php/SnoopyTest.php:95`, `tests/php/SnoopyTest.php:106`, `php/Snoopy.class.inc:547`, `php/Snoopy.class.inc:693`.

Источники: `tests-snoopy#0`, `dup-tests#4`.

### S16

**Тест «shipped default» Ygg читает отслеживаемый conf/config.php, и имя это не называет** · nit · соглашения · понижен после сверки 2026-09-25 (было P3, условно) · коммит 156c64af · голоса 1/2 · [tests/plugins/loginmgr/YggConfigurationTest.php:59](../../tests/plugins/loginmgr/YggConfigurationTest.php#L59)

yggConfigProbe() (строка 59) копирует в фикстуру conf/config.php из рабочего дерева. Для конфигурации 'default' (строка 62) $yggTorrentOrigin ничем не перекрывается. Если в conf/config.php задать $yggTorrentOrigin, как советует сам плагин (conf.php:3, журнал YggTorrent.php:66), тест 'the shipped Ygg default is unconfigured' (строка 130) даёт «14 tests, 1 failures»: получено 'https://mine.example' вместо ''. Pre-commit хук гоняет набор по рабочему дереву и заблокирует коммит; пропуск по дайджесту не спасает, потому что дайджест включает conf/. Чистый только checkout CI. tasks/matrix.sh экспортирует рабочее дерево (`git ls-files | tar`), так что локальная правка conf/config.php попадает и в матрицу.

Решение после сверки 2026-09-25: дефекта теста нет. Фикстура намеренно различает локальный override (conf.local.php удаляется, строки 56-57) и отслеживаемый поставляемый conf/config.php. Так же устроены ConfigTest.php:41,134 и EnvCheckShippedConfigTest.php:38,98. Правка conf/config.php в рабочем дереве — правка поставляемого default, и тест обязан на ней упасть. Настоящие локальные override (plugins/*/conf.local.php, conf/config.local.php, conf/users/*) в фикстуру не попадают, это проверено.

**Как исправить.** Не менять поведение. В комментарии у строки 130 или в имени теста уточнить, что «shipped» означает отслеживаемый conf/config.php, а свою настройку разработчик держит в `conf.local.php` или `conf/users/<user>/`.

Ещё места: `tests/plugins/loginmgr/YggConfigurationTest.php:62`, `tests/plugins/loginmgr/YggConfigurationTest.php:130`.

Источники: `tests-snoopy#5`.

### S17

**Докблок urlIsOneOf() скрывает, что функция решает и доверие Snoopy к редиректам** · nit · комментарий · коммит 156c64af · голоса 1/1 · [php/urlhost.php:111](../../php/urlhost.php#L111)

Докблок UrlHost::urlIsOneOf() (строки 111-112) утверждает: «This selects loginmgr accounts; Snoopy separately guards authenticated redirects». Это вводит в заблуждение. Когда редирект уходит на другой origin, Snoopy::trustsRedirect() (Snoopy.class.inc:431-435) спрашивает redirectTrust. На время fetch()/check() аккаунта это array($this,'test') (accounts.php:197, :243), а test() через urlAddresses() (accounts.php:100-103) приходит именно в urlIsOneOf(). Значит, эта функция решает, на какой чужой хост Snoopy перешлёт cookie аккаунта. Сам Snoopy добавляет только свои условия: https на обоих концах, нет userinfo, нет user/pass и сырых учётных заголовков. Второй вызов вне loginmgr — plugins/rutracker_check/trackers/kinozal.php:246: по этой функции определяется вердикт «гостевая стена» по редиректу на login.php.

Сопровождающий, который ослабит проверку хоста или pathPrefix «только для выбора аккаунта», по докблоку не узнает, что расширяет круг адресов, куда уходят cookie аккаунта. Ослабление проверки схемы доверие к редиректам не изменит (Snoopy сам требует https), но изменит выбор аккаунта и вердикт Kinozal.

**Как исправить.** Переписать, например: «Selects loginmgr accounts and, through commonAccount::test(), also decides which cross-origin HTTPS redirects Snoopy may carry an account's session to; the Kinozal checker classifies login redirects with it.»

Ещё места: `php/urlhost.php:112`, `php/Snoopy.class.inc:433`, `plugins/rutracker_check/trackers/kinozal.php:246`.

Источники: `snoopy#7`.

### S18

**README описывает правило редиректов в состоянии до NEW-E** · nit · заявление расходится с кодом · коммит 156c64af · голоса 1/1 · [plugins/loginmgr/README.md:34](../../plugins/loginmgr/README.md#L34)

README.md:33-39 говорит, что редирект вне политики аккаунта не выполняется, «когда клиент уже держит cookies, заголовки аутентификации или тело запроса». Сейчас при fetch/refresh loginmgr Snoopy отказывает любому редиректу вне политики аккаунта, если это не same-origin и не апгрейд HTTP(80)→HTTPS(443) на тот же хост (Snoopy.class.inc:372, `redirectTrust !== null ||`). От cookies, заголовков и тела это не зависит: первый анонимный логин с пустой банкой тоже не уходит на чужой хост, и это закрепляют SnoopyTest.php:186 и CredentialBoundaryTest.php:279. Фраза «An anonymous redirect may change origin only when the response did not set cookies» верна только вне account-скоупа (и её суть оспаривается в S1). Фраза «RSS also shows that reason» верна только для загрузки самой ленты, при загрузке элемента причины в UI нет (см. S13).

**Как исправить.** Разделить абзац на два правила: «под аккаунтом loginmgr любой недоверенный редирект не выполняется» и «вне аккаунта не выполняется при cookies, auth-заголовках или свежем Set-Cookie» (с учётом решения по S1 и S6). Про RSS написать, что причину показывает ошибка загрузки ленты, а отказ при загрузке элемента виден только в журнале, либо исправить это по S13.

Ещё места: `plugins/loginmgr/README.md:37`, `php/Snoopy.class.inc:372`.

Источники: `consumers#4`, `loginmgr#3`.

### S19

**Обёртка redirectTrust дословно скопирована в fetch() и check()** · nit · дублирование · коммит 156c64af · голоса 1/1 · [plugins/loginmgr/accounts.php:194](../../plugins/loginmgr/accounts.php#L194)

Последовательность «сохранить redirectTrust, поставить array($this,'test'), в finally вернуть» дословно повторена в commonAccount::fetch() (строки 194-197 и 231-235) и check() (строки 240-243 и 272-276). Новый сетевой метод аккаунта (например отдельный logout) легко забыть обернуть. Утечки это не даст: finally уже вернул политику вызывающего, обычно null, и Snoopy просто откажет в редиректе с учётными данными (Snoopy.class.inc:372). Но такой метод будет вести себя иначе, чем fetch()/check(), и это неочевидно. Сейчас все сетевые пути обёрнуты: переопределения YggTorrent лишь охраняют вызов parent::.

**Как исправить.** Сделать в commonAccount один protected-метод, например withRedirectTrust($client, callable $body), с try/finally, и вызывать его из fetch() и check(). Для PHP 7.4 это безопасно: параметры приходят по значению.

Ещё места: `plugins/loginmgr/accounts.php:231`, `plugins/loginmgr/accounts.php:240`, `plugins/loginmgr/accounts.php:272`.

Источники: `dup-prod#6`.

### S20

**configurationRequired считается двумя копиями проверки по имени 'YggTorrent'** · nit · дублирование · коммит 156c64af · голоса 1/1 · [plugins/loginmgr/accounts.php:334](../../plugins/loginmgr/accounts.php#L334)

Признак configurationRequired вычисляется двумя копиями одного особого случая с жёстко заданным именем `$name === 'YggTorrent'`: в get() (строки 333-339) и в getInfo() (строка 403), обе появились в 156c64af. Метод configurationError() есть только у YggTorrentAccount (accounts/YggTorrent.php:54), поэтому проверка имени фактически заменяет вопрос «есть ли у класса этот метод». UI (init.js:99) берёт признак из theWebUI.theAccounts, которые строит get(). Режим action.php?mode=info, то есть getInfo(), ни один JS в дереве не вызывает, но это существующий HTTP API (он есть и на base), так что поле не мёртвое. Второму аккаунту с origin из конфигурации придётся править обе ветки менеджера, а если поправить одну, UI и API разойдутся. Предупреждение в init.js:99-101 написано только под $yggTorrentOrigin, так что UI тоже придётся менять.

**Как исправить.** Объявить в commonAccount `public function configurationError() { return(''); }` и в обоих местах вызывать его без проверки имени. Проверено на копии: YggConfigurationTest 14/14, get() строит все 25 аккаунтов, признак true только у ненастроенного Ygg. Цена: get() при каждой загрузке интерфейса подключает все классы аккаунтов, как уже делает getInfo(). Если ленивую загрузку нужно сохранить, достаточно одного приватного помощника с этой проверкой, общего для get() и getInfo().

Ещё места: `plugins/loginmgr/accounts.php:403`, `plugins/loginmgr/accounts/YggTorrent.php:54`.

Источники: `loginmgr#5`, `consumers#6`, `dead-strange#6`, `dup-prod#6`.

### S21

**LostFilm::getDownloadId() повторяет разбор RUTracker::getDownloadId() без его обоснования** · nit · дублирование · коммит 156c64af · голоса 1/1 · [plugins/loginmgr/accounts/LostFilm.php:45](../../plugins/loginmgr/accounts/LostFilm.php#L45)

LostFilm::getDownloadId() (строки 45-52) и RUTracker::getDownloadId() (RUTracker.php:60-81) появились в одном коммите 156c64af и делают один и тот же разбор: parse_url, проверка хоста, точный путь через strcasecmp, parse_str и `isset && is_string && preg_match('/^\d+\z/')`. Различаются только имя параметра (id/t), путь (/download.php, /forum/dl.php) и способ проверки хоста: у LostFilm test() со схемой https и префиксом, у RUTracker UrlHost::isOneOf по FORUM_HOSTS без схемы. Восьмистрочное обоснование (почему parse_str, почему берётся последний повтор, почему \z, а не $) есть только в RUTracker.php:69-76, хотя в LostFilm оно применимо целиком: id уходит в URL details.php и в регэксп на строке 26. Каждую копию закрепляет своя таблица тестов (CredentialBoundaryTest.php:319-331 и AccountSelectionTest.php:107-132), поэтому новое правило для id, внесённое в одну копию, до второй не дойдёт. Вызов `$this->test($url)` внутри LostFilm::getDownloadId на продовом пути избыточен, но это страховка: прямой вызов isOKPostFetch в CredentialBoundaryTest.php:328 на неё опирается.

**Как исправить.** Вынести защищённый статический помощник в commonAccount, например `queryDigits($url, $hosts, $path, $name)`, с единственной копией обоснования. Оба getDownloadId станут однострочниками. Проверено на копии HEAD: CredentialBoundaryTest, AccountSelectionTest, RuTrackerDomainListTest и CommonAccountTest проходят без изменений.

Ещё места: `plugins/loginmgr/accounts/RUTracker.php:60`, `plugins/loginmgr/accounts/RUTracker.php:69`.

Источники: `loginmgr#7`.

### S22

**Комментарий про разделители оказался над проверкой пустого origin** · nit · комментарий · коммит 156c64af · голоса 1/1 · [plugins/loginmgr/accounts/YggTorrent.php:13](../../plugins/loginmgr/accounts/YggTorrent.php#L13)

Комментарий «Reject delimiters before parsing: PHP 7.4 discards empty query/fragment components, and parse_url() rewrites control characters in host names» (строки 13-14) после последнего раунда стоит над `if ($origin === '')` (строка 15). Проверка, которую он объясняет, оказалась тремя строками ниже, на строке 18: ветку пустого origin вставили между комментарием и его кодом (видно в delta prev→head). Читатель соотносит объяснение про разделители с проверкой пустой строки. Это тот класс дефекта, который AGENTS.md называет самым частым: комментарий, ставший неправдой о строке под ним.

**Как исправить.** Перенести две строки комментария непосредственно над проверкой на строке 18. Над `if ($origin === '')` при желании написать «Unset or empty: missing-origin».

Ещё места: `plugins/loginmgr/accounts/YggTorrent.php:15`, `plugins/loginmgr/accounts/YggTorrent.php:18`.

Источники: `loginmgr#8`.

### S23

**Условие порт > 65535 недостижимо, такой origin получает причину invalid-url** · nit · мёртвый код · коммит 156c64af · голоса 1/1 · [plugins/loginmgr/accounts/YggTorrent.php:39](../../plugins/loginmgr/accounts/YggTorrent.php#L39)

Половина условия `$parts['port'] > 65535` (строка 39) недостижима: parse_url() возвращает false для порта больше 65535. Поэтому 'https://ygg.example:65536' классифицируется на строке 24 как invalid-url, а не bad-port. Оператор, указавший порт 70000, ищет ошибку в синтаксисе URL, хотя README:24 обещает классифицированную причину отказа. Мёртвое условие создаёт видимость, что диапазон портов проверяется здесь. Тест 'invalid origins stay disabled...' включает ':65536' и ':0', но утверждает только url === '', поэтому неверная причина его не валит.

**Как исправить.** Оставить проверку `< 1`. Выход за 65535 распознавать до parse_url (например регуляркой по authority) или честно отнести к invalid-url и убрать `> 65535`. В тесте для каждого отвергнутого origin утверждать ожидаемый configurationError().

Ещё места: `tests/plugins/loginmgr/YggConfigurationTest.php:256`.

Источники: `loginmgr#4`.

### S24

**Предупреждение про $yggTorrentOrigin показывается и для выключенного аккаунта** · nit · странная логика · коммит 156c64af · голоса 1/1 · [plugins/loginmgr/init.js:99](../../plugins/loginmgr/init.js#L99)

Предупреждение «Set $yggTorrentOrigin ...» (init.js:99-101) выводится под YggTorrent всегда, когда configurationRequired=true, а get() (accounts.php:331-340) и getInfo() (accounts.php:403) выставляют флаг, не глядя на enabled. Поставляемый default origin не задаёт, поэтому жёлтый alert видят все установки с loginmgr, включая те, где Ygg не используют (проба на HEAD с enabled=0: `configurationRequired: true`). Журнальная диагностика (YggTorrent.php:59-68) достижима только для включённого аккаунта: getAccount проверяет enabled (accounts.php:371), checkAuto требует enabled && auto. Отсюда непоследовательность: UI шумит, журнал молчит, и пользователь привыкает игнорировать предупреждение. ru.js:18 «(или настройках loginmgr)» отправляет в тот самый диалог, где поля для origin нет. en.js:18 говорит о конфиг-файле («a loginmgr override»), но путей не называет, хотя журнал (YggTorrent.php:66) их перечисляет.

**Как исправить.** Показывать alert по состоянию чекбокса Enabled, переключая его в существующем onchange, чтобы предупреждение появлялось сразу при включении аккаунта. В ru.js и en.js назвать conf/config.php, plugins/loginmgr/conf.local.php и conf/users/<user>/plugins/loginmgr/conf.php. Вычисление флага свести в один помощник (см. S20).

Ещё места: `plugins/loginmgr/accounts.php:333`, `plugins/loginmgr/lang/ru.js:18`, `plugins/loginmgr/lang/en.js:18`.

Источники: `loginmgr#6`, `portability-claims#7`.

### S25

**Часть веток ядра Snoopy закреплена только тестом плагина loginmgr** · nit · слабый тест · коммит 156c64af · голоса 1/1 · [tests/php/SnoopyTest.php:119](../../tests/php/SnoopyTest.php#L119)

При мутации этих веток падает лишь tests/plugins/loginmgr/CredentialBoundaryTest.php, а tests/php/SnoopyTest.php остаётся 25/25:
- сброс error (Snoopy.class.inc:288);
- гашение `_redirectaddr` при исчерпании maxredirs (392-393);
- латч и запись строки журнала (410-413);
- в hasRedirectCredentials() слагаемые user/pass и тела на строке 440;
- в hasRedirectCredentials() проверка сырых заголовков (446-448).
Для user/pass и сырых заголовков в SnoopyTest есть похожие случаи, но в них задан redirectTrust, и проверка на строке 372 срабатывает раньше, так что hasRedirectCredentials() не вызывается. Слагаемое `!empty($this->cookies)` в SnoopyTest закреплено. Семь из пятнадцати кейсов CredentialBoundaryTest (строки 99, 118, 135, 148, 208, 230, 242) не создают аккаунт и проверяют только политику ядра. Плагины не должны быть зависимостями ядра: если loginmgr отключат или перенесут его тесты, эти ветки php/ останутся без проверки. В FIX-CHECK tests28 помечен «Исправлено»; для UrlHostTest это верно (появились таблицы sameOrigin() и isHttpsUpgrade()), для Snoopy — лишь частично.

**Как исправить.** Перенести в SnoopyTest по одному случаю на каждую перечисленную ветку (SnoopyRedirectProbe или общий двойник из S15 для этого подходит), а семь кейсов без аккаунта переместить из CredentialBoundaryTest в SnoopyTest. В CredentialBoundaryTest оставить политику доверия аккаунтов (Kinozal, Ygg, LostFilm, BoundaryUnconfigured). Уточнить статус tests28 в FIX-CHECK.

Ещё места: `php/Snoopy.class.inc:288`, `php/Snoopy.class.inc:392`, `php/Snoopy.class.inc:410`, `php/Snoopy.class.inc:440`, `php/Snoopy.class.inc:446`.

Источники: `snoopy#6`.

### S26

**Проверка 'a non-string folds to nothing' нестрогая, а защита пустого кандидата ни на что не влияет** · nit · слабый тест · коммит 156c64af · голоса 1/1 · [tests/php/UrlHostTest.php:47](../../tests/php/UrlHostTest.php#L47)

'a non-string folds to nothing' (строка 47) сравнивает через нестрогий TestCase::assertEquals (`==`). Мутация normalize() на `return null` для не-строки (urlhost.php:40) проходит, потому что null == '', хотя докблок обещает ''. Строгая форма эту мутацию ловит (проверено).

Защита `if($candidate === '') continue;` (urlhost.php:100-101) исход не меняет: normalize() срезает все завершающие точки, пустой хост отсекается раньше, и условие `substr($host, -1) === '.'` при пустом кандидате не выполняется никогда. Удаление защиты проходит все 40 проверок. Проверка 'an empty candidate matches nobody' (строка 49) эту защиту не закрепляет, но и не бесполезна: она поймает переписанное сравнение на строке 102, при котором пустая строка начнёт совпадать (например через strpos или str_contains).

**Как исправить.** На строке 47 писать `assertTrue(UrlHost::normalize(array(...)) === '')`. Защиту на строке 100 удалить или оставить с комментарием, что она лишь повторяет инвариант normalize() и границей не является.

Ещё места: `php/urlhost.php:40`, `php/urlhost.php:100`.

Источники: `tests-snoopy#6`.

### S27

**Мёртвый ключ 'ruTrackerAccount' и шесть строк, дублирующих новый перебор аккаунтов** · nit · мёртвый код · коммит 156c64af · голоса 1/1 · [tests/plugins/loginmgr/AccountSelectionTest.php:243](../../tests/plugins/loginmgr/AccountSelectionTest.php#L243)

Ключ 'ruTrackerAccount' в $paths (строка 243) не читается никогда. $productionAccountClasses (строка 15) строится из имён файлов, класс приходит как 'RUTrackerAccount', а поиск $paths[$class] (строка 248) чувствителен к регистру. Проверено: без 'ruTrackerAccount' набор 11/11, без 'RUTrackerAccount' перебор падает на «RUTrackerAccount positive control».

Шесть строк, добавленных в тест 'a url may not weaken the scheme its site is configured with' (строки 223-228: ruTracker, KinozalTV, NNMClub, Toloka, TapochekNet, ZamundaNet), целиком повторяют новый перебор 'every production HTTPS account rejects its HTTP download form' (строки 241-253). У Kinozal и Toloka отличается только путь, который их test() не смотрит, а у перебора есть положительный контроль. Проверено: без этих шести строк перебор сам ловит снятие "https" в test() KinozalTV или Toloka. При добавлении аккаунта сопровождающий правит два места и не понимает, какое из них и есть граница.

**Как исправить.** Удалить ключ 'ruTrackerAccount'. Шесть строк 223-228 удалить или заменить комментарием со ссылкой на перебор.

Ещё места: `tests/plugins/loginmgr/AccountSelectionTest.php:223`.

Источники: `tests-snoopy#8`.

### S28

**Одна проверка в тесте анонимной цепочки не может упасть, вторая избыточна** · nit · слабый тест · коммит 156c64af · голоса 1/1 · [tests/plugins/loginmgr/CredentialBoundaryTest.php:146](../../tests/plugins/loginmgr/CredentialBoundaryTest.php#L146)

В тесте 'anonymous redirects without source cookies keep cookies from a later same origin hop' (строки 135-147) проверка на строке 144 `boundarySame(array(), $client->requests[1][3], 'source cookie never reaches CDN')` упасть не может: банка пуста, а у первого ответа (строка 138) нет Set-Cookie. В последнем раунде из ответа убрали `Set-Cookie: source=secret` и переименовали тест, а сообщение осталось от прежнего сценария. Мутация «снять строку 369 и правило Set-Cookie 443-445» оставляет тест зелёным.

Проверка на строке 146 `boundarySame(true, $client->passcookies, ...)` — остаток спора о D3 (CHECK-FIXES предлагал временно выключать passcookies, реализация так не делает). Ни одна строка php/ и plugins/ passcookies не пишет. Реалистичную регрессию (passcookies=false перед рекурсивным fetch()) раньше неё ловит строка 145. Упасть она может только при будущей записи в публичное поле passcookies, так что удалять её или оставлять как страховку будущего контракта — выбор, а не исправление дефекта.

Утечку свежей cookie источника держат SnoopyTest.php:151 и CredentialBoundaryTest.php:148 (строки 160 и 166), оба через отказ, так что новый случай не нужен, пока действует отказ S1; после исправления S1 нужен свой кейс (см. [S1](#s1), CHECK `tests28`).

**Как исправить.** Проверку на строке 146 удалить или пометить комментарием как страховку контракта. Сообщение на строке 144 переформулировать, например «anonymous hop starts with an empty jar».

Ещё места: `tests/plugins/loginmgr/CredentialBoundaryTest.php:144`.

Источники: `tests-snoopy#7`.

### S29

**Проверки журнала зависят от процессного static $logged и уникального имени хоста без комментария** · nit · слабый тест · коммит 156c64af · голоса 2/2 · [tests/plugins/loginmgr/CredentialBoundaryTest.php:162](../../tests/plugins/loginmgr/CredentialBoundaryTest.php#L162)

Проверки журнала в 'a refused redirect leaves response cookies explicit and never imports them on reuse' (строки 162-163 и 170) зависят от `static $logged` в Snoopy::logRedirectRefusal (Snoopy.class.inc:403, 410-412). Он общий на весь процесс, и сбросить его нельзя. Первый кейс файла (строки 99-116) уже записал пару 'tracker.example -> evil.test', поэтому в этом раунде цель переименовали в evil-refusal.test (строки 154, 163, 168), не оставив комментария о причине.

Сейчас тест детерминирован и дедупликацию закрепляет: если убрать isset/return на строках 410-411, он падает на 'same host pair is logged once'. Перестановка существующих кейсов ничего не ломает (проверено). Но если выше появится кейс с той же парой хостов или вернуть имя evil.test, упадёт 'application log classifies refusal: expected true, got false', и это читается как регрессия Snoopy. Проверка 'application log contains no URL or cookie secrets' (строка 164) при пустом журнале пройдёт впустую.

**Как исправить.** Минимум: однострочный комментарий у строки 154 о том, что пара хостов должна быть уникальной в процессе. Полнее: вынести проверки журнала в дочерний процесс, как yggChild() в YggConfigurationTest.php:36, или сделать дедупликацию сбрасываемой (статическое свойство класса с тестовым сбросом).

Ещё места: `tests/plugins/loginmgr/CredentialBoundaryTest.php:154`, `php/Snoopy.class.inc:403`.

Источники: `sig-test-oracle-cannot-fail#3`.

### S30

**Правило origin для Ygg закреплено в двух наборах разными двойниками** · nit · дублирование · коммит 156c64af · голоса 1/1 · [tests/plugins/loginmgr/YggConfigurationTest.php:95](../../tests/plugins/loginmgr/YggConfigurationTest.php#L95)

Два новых в 156c64af набора частично повторяют друг друга. Правило «доверять только настроенному origin» закреплено в двух файлах одними и теми же тремя проверками (test(), fetch() и directLogin() возвращают false, запросов нет), но разными двойниками и непересекающимися списками URL. CredentialBoundaryTest.php:254-272 проверяет похожие хосты, схему и порт через BoundaryTransport на настоящем Snoopy, YggConfigurationTest.php:281-293 — userinfo через YggConfigTransport. Мутация показала, что userinfo закреплён ТОЛЬКО в YggConfigurationTest:281-293: без проверки user/pass в trusts() (YggTorrent.php:90) CredentialBoundaryTest остаётся зелёным, поэтому просто удалить этот кейс нельзя. Двойники directLogin (YggConfigurationTest:95-99 и CredentialBoundaryTest:69-73) отличаются только литералом пароля. Регистрация accountManager->accounts['YggTorrent'] одинакова в трёх местах (строки 139, 156, 170; на строке 214 она другая). Новый невалидный origin, добавленный в один файл, в другом не появится.

Переносить все Ygg-кейсы CredentialBoundaryTest (254-312) не стоит: им нужны BoundaryTransport и loadData() с кэшем, а кейс 279-296 проверяет границу редиректов Snoopy.

**Как исправить.** Добавить три URL с userinfo в список CredentialBoundaryTest:258-261 и удалить YggConfigurationTest:281-293 (проверено на копии), чтобы у списка отвергнутых URL было одно место. Для трёх одинаковых регистраций сделать помощник yggManager().

Ещё места: `tests/plugins/loginmgr/CredentialBoundaryTest.php:69`, `tests/plugins/loginmgr/CredentialBoundaryTest.php:258`, `tests/plugins/loginmgr/YggConfigurationTest.php:281`, `tests/plugins/loginmgr/YggConfigurationTest.php:139`, `tests/plugins/loginmgr/YggConfigurationTest.php:156`, `tests/plugins/loginmgr/YggConfigurationTest.php:170`.

Источники: `dup-tests#5`, `tests-snoopy#9`.

### S31

**Тест однократной диагностики Ygg зависит от порядка и пишет в общий журнал** · nit · слабый тест · коммит 156c64af · голоса 2/2 · [tests/plugins/loginmgr/YggConfigurationTest.php:206](../../tests/plugins/loginmgr/YggConfigurationTest.php#L206)

Тест 'only Ygg-shaped downloads and explicit refresh diagnose missing configuration once' (строка 206) проходит только потому, что до него в процессе никто не вызвал hasOrigin() с пустым origin: флаг `private static YggTorrentAccount::$configurationWarningShown` (YggTorrent.php:6, 64-65) живёт весь процесс. Проверено на копии: добавленный первым тест, вызывающий YggTorrentAccount::test('https://x.example/engine/download_torrent?id=1') при пустом origin, даёт «15 tests, 1 failure» ('application diagnostic names invalid-or-missing-origin'). Диагностика при этом уходит в журнал по умолчанию, `$_ENV['RU_LOG_FILE'] ?? '/tmp/errors.log'`, то есть в общий журнал вне TMPDIR: в отличие от SnoopyTest и CredentialBoundaryTest, этот файл RU_LOG_FILE не перенаправляет. Тест 'invalid origins stay disabled before any credential request' (строка 254) и сейчас вызывает hasOrigin() с пустым и битыми origin и молчит только потому, что флаг уже поднят тестом 206.

**Как исправить.** Первой строкой файла направить `$_ENV['RU_LOG_FILE']` во временный файл, как у соседей. Проверку «диагностика ровно один раз» вынести в дочерний процесс, как уже сделано через yggChild(), либо сбрасывать флаг через ReflectionProperty перед ней (setAccessible вызывать условно, как в других файлах).

Ещё места: `plugins/loginmgr/accounts/YggTorrent.php:6`, `plugins/loginmgr/accounts/YggTorrent.php:64`.

Источники: `tests-snoopy#4`.

### S32

**Токен credential-redirect-refused вписан литералом в мок, и RSS-звено контракта не проверяется** · nit · слабый тест · коммит 156c64af · голоса 1/1 · [tests/plugins/rss/RSSTest.php:33](../../tests/plugins/rss/RSSTest.php#L33)

В testRefusedCredentialRedirectNamesTheReason SnoopyMock вписывает 'credential-redirect-refused' вручную (строка 33). Тест не связывает значение, которое реально пишет Snoopy (Snoopy.class.inc:374), с литералом, который сравнивает rss.php:154. Мутация: если в Snoopy:374 заменить токен на 'credential-redirect-denied', RSSTest остаётся зелёным, а rss.php перестаёт дописывать причину отказа. Переименование заметят SnoopyTest и CredentialBoundaryTest, но RSS-звено этим не проверяется. Сам rss.php держит токен дважды: сравнивает на строке 154 и заново пишет литералом на строке 155, хотя мог бы дописать $cli->error.

Строгое сравнение результата fetch() (строка 36) не нужно: оба вызывающих места (rss.php:962, :1037) проверяют результат только на истинность.

**Как исправить.** Завести константу класса, например Snoopy::CREDENTIAL_REDIRECT_REFUSED, и использовать её в Snoopy:374/413, rss.php:154-155 и в моке RSSTest (Snoopy уже подключён в rss.php:4). fetcherror.php:82 оставить на регулярке: это лист без зависимости от Snoopy, а FetchErrorTest уже сверяет присваивания $this->error. Несуществующая константа класса роняет код при выполнении, и RSSTest до этой строки доходит, так что переименование перестанет проходить молча.

Ещё места: `php/Snoopy.class.inc:374`, `php/Snoopy.class.inc:413`, `plugins/rss/rss.php:154`, `plugins/rss/rss.php:155`.

Источники: `sig-test-oracle-cannot-fail#6`.

### N-S1

**Cookie плагина cookies уходят открытым текстом по http-ссылке на тот же хост** · P2 · безопасность · старый долг · вне дельты, в бэклог · коммит 156c64af по области (код старше диапазона) · найдено независимой проверкой 2026-09-25 · [php/Snoopy.class.inc:240](../../php/Snoopy.class.inc#L240)

`Snoopy::fetchComplex()` (строки 231-258) ещё до выбора аккаунта loginmgr сливает в `$this->cookies` cookie плагина cookies (строки 235-241, вызов на 240) и `:COOKIE:` из URL (строка 242). `rCookies::getCookiesForHost()` (plugins/cookies/cookies.php:83-89) выбирает их только по имени хоста. Запись плагина имеет формат `host|name=value;...` (разбор в `set()`, строки 38-55; подсказка UI «Format: host|cookie1;cookie2...»), в ней нет ни схемы, ни признака Secure. Для http-URL без https-прокси запрос идёт в `_httprequest()` (строки 317-326), и тот пишет `Cookie:` открытым текстом (строки 562-567); curl (строка 706) берётся для https (строка 336). Поэтому cookie, которую пользователь завёл для хоста трекера, работающего по HTTPS, уйдёт открытым текстом и по http-ссылке на тот же хост: из ленты RSS, правила rssurlrewrite или ручного добавления по URL. Через `fetchComplex()` идут addtorrent, bulk_magnet, rss и tracklabels. Браузер не отправил бы по http cookie, которую трекер выставил с Secure; плагин этот признак теряет, и поведение соответствует cookie без Secure.

Проба при разборе на хостовом PHP 8.5, настоящий `fetchComplex()`, подкласс с `connect()` в файл, запись плагина `tracker.example|sid=plugin-secret`: `http://tracker.example/dl.php?id=1` ушёл как `GET /dl.php?id=1 HTTP/1.0 … Host: tracker.example … Cookie: sid=plugin-secret`. На экспорте origin/master результат тот же: `fetchComplex()` на base и HEAD совпадает построчно, `git diff upstream/master HEAD -- plugins/cookies` пуст. Проверяющий тем же способом довёл до провода путь `:COOKIE:` ([VERIFY-S-25a0e2cb.md](VERIFY-S-25a0e2cb.md), N-S1). Но `:COOKIE:` автор задаёт в самом URL вместе со схемой, и отправка по этой схеме — его намерение; риск там есть, только если правило rssurlrewrite дописывает `:COOKIE:`, а схему оставляет из ленты. Главный источник — плагин cookies.

По меркам самого форка это слабость безопасности: для сессий шести трекеров loginmgr 156c64af отправку по http запретил (S10; у AniDUB, LostFilm, NovaFilm и Tfile запрет был и раньше), а второй носитель сессии эту границу не соблюдает. На HEAD редирект https→http с непустой банкой охранник уже отвергает, так что утечка возникает на первом запросе по http-ссылке. Живьём не воспроизведено. Код в диапазоне не менялся, выкладку пункт не блокирует.

**Как исправить.** Решить строкой таблицы решений (ARCHITECTURE, раздел «Snoopy и loginmgr», п. 4). Есть два варианта.

1. Отдавать cookie плагина только https-URL. Это ломает трекеры, которые работают только по http и держатся на этом плагине, поэтому отказ надо сделать видимым, как предлагается в S10.
2. Ввести в запись признак «только https», например схему в поле хоста (`https://host|...`), и сверять его в `fetchComplex()` со схемой URL. Для этого нужны новый формат записи, правка `set()`/`add()`/init.js и решение о старых записях без признака.

Тест S10 должен различать три источника cookie: плагин cookies, `:COOKIE:` и сессию loginmgr.

Ещё места: `plugins/cookies/cookies.php:83`, `plugins/cookies/cookies.php:38`, `php/Snoopy.class.inc:242`, `php/Snoopy.class.inc:564`.

Источники: `VERIFY-S-25a0e2cb.md` (N-S1).

### N-S2

**Условные заголовки RSS повторяются на чужом хосте анонимного редиректа** · P3 · соглашения · старый долг · вне дельты, в бэклог · коммит 156c64af по области (код старше диапазона) · найдено независимой проверкой 2026-09-25 · [plugins/rss/rss.php:141](../../plugins/rss/rss.php#L141)

rRSS::fetch() кладёт сохранённые If-None-Match и If-Last-Modified в заголовки запроса (rss.php:139-145), а rssFetchURL() — в `rawheaders` клиента (rss.php:9-16). hasRedirectCredentials() их учётными не считает, и транспорт повторяет их на чужом origin: проба проверяющего показала один и тот же If-None-Match у трекера и CDN. Так же поступают браузеры и curl, которые при смене origin снимают только учётные заголовки; секретность ETag не показана.

**Как исправить.** Записать в контракте Snoopy, что условные заголовки при смене origin допустимы. Срезать их, только если найдётся лента с идентифицирующим ETag. Попутно: `If-Last-Modified` — нестандартное имя, серверы понимают `If-Modified-Since`.

Источники: `VERIFY-S-25a0e2cb.md` (N-S2).

## rutracker_check: планировщик, чекер и трекеры

Раздел «rutracker_check: планировщик, чекер и трекеры»: 43 пункта, из них 1 P2, 13 P3 и 29 nit, P1 нет. После сверки 2026-09-25 добавлены два старых пункта: VC-NEW-2 (P2, найден на снятом ответе Tapochek) и VC-NEW-1 (nit), а C20 поднят до P3. Близкие дубли объединены. Одиннадцать находок о двойнике Snoopy и мёртвой строке kinozal.php:398 сведены в C37. Семь находок о недостижимом токене 'redirect-refused' и неверном порядке строк в таблице сведены в C19. Находки о isForeignAuthoritative() и формулировке «ownership test» сведены в C27. Имя и комментарий hasIndependentDownloadVerdict() вошли в C4. В C37 одна находка шла как P3, но оба скептика её поправили: вреда для продакшена нет, покрытие ядра держит SnoopyTest. Поэтому итоговая оценка nit. C29 условный: с ним согласился только один скептик из двух. В C20 (AniDUB) скептики по-разному оценили, сделал ли диапазон утечку достижимой; обе позиции изложены в тексте. Сверка показала, что сессию loginmgr в эту банку впервые кладёт af56990f, поэтому оценка P3. C2, C3, C6 и C7 — давние дефекты, но C2 и C7 связаны с новым правилом удержания в run(). Их стоит чинить вместе с C1, одним общим предикатом «осевший вердикт». Номера строк сверены с HEAD, экспорт /home/dev/.cache/rutorrent-tmp/r3/head.

### C1

**Удержание DELETED/ABSORBED решается по коду ответа, хотя обработчик уже успел узнать и записать новое** · P3 · дефект · коммит af56990f · голоса 2/2 · [plugins/rutracker_check/check.php:2163](../../plugins/rutracker_check/check.php#L2163)

run() оставляет DELETED/ABSORBED на месте при любом CANT_REACH/ERROR ($retainedTerminal, check.php:2163-2165) и смотрит только на код ответа. Обработчик RuTracker к этому моменту уже мог записать побочные эффекты. (а) DELETED: строка темы найдена в дампе, rutracker.php:935/940 обнуляют chk-del и стирают chk-msg ещё до switch. Затем 'unknown' (tor_status 9/11 или битый info_hash, :960-961) или провал RuTrackerMetaFetch::begin() на 'updated' (ERROR при отказе загрузки в каталог сервиса или torrentExists()===null, CANT_REACH при ненайденной заглушке). Итог: chk-state=DELETED при пустых chk-del и chk-msg, и UI показывает «Probably deleted», хотя этот прогон нашёл тему живой. chk-time не обновляется, поэтому после недели отдыха строка перепроверяется каждый цикл и временные причины лечатся сами. Но устойчивый ERROR от begin() на этом торренте не виден нигде, кроме logDebug при $rutrackerCheckDebug, тогда как на нетерминальных торрентах тот же сбой виден как ERROR. (б) ABSORBED: реальный путь — строка пропала из дампа, layer 2 не подтвердил (:916-919). Токен 'absorbed|<id>' стирается, и init.js:98-99 теряет ссылку на собственную (поглощённую) тему торрента; потеря косметическая, тот же URL есть в комментарии. При подтверждении слоем 2 под удержанным ABSORBED появляется чужой токен 'deleting|n/3'. Выход 'transport' (:652-655) под планировщиком достижим лишь в гонке повторного чтения. Комментарий rutracker.php:650 «These two DID change the verdict» для терминальных строк в части 'transport' теперь неверен. Сценарий узкий: вернуться должна тема с уже подтверждённым удалением. Внесено в диапазоне: на origin/master эти выходы писали CANT_REACH.

**Как исправить.** Удерживать терминальный вердикт только когда обработчик ничего не узнал. Либо пусть обработчик явно сообщает «ничего не узнано» (как STE_UNCHANGED), а выходы после «строка найдена» и провалы begin() возвращают собственный вердикт без удержания или не трогают chk-msg/chk-del. На выходах, где ABSORBED остаётся, не стирать его токен. Поправить комментарий rutracker.php:650.

Ещё места: `plugins/rutracker_check/trackers/rutracker.php:935`, `plugins/rutracker_check/trackers/rutracker.php:960`, `plugins/rutracker_check/trackers/rutracker.php:916`, `plugins/rutracker_check/trackers/rutracker.php:650`.

Источники: `checker#0`.

### C2

**Правило удержания не охватывает осевший NOT_NEED (topic-status/superseded), который isSettled() считает таким же окончательным** · P3 · дефект · старый долг · коммит af56990f · голоса 2/2 · [plugins/rutracker_check/check.php:2130](../../plugins/rutracker_check/check.php#L2130)

run() получает только chk-state и не читает chk-msg, поэтому узнаёт как терминальные лишь DELETED/ABSORBED (check.php:2130). Осевший NOT_NEED с токеном 'topic-status|N' (закрытая тема) или 'superseded|…', который isSettled() (updatepass.php:917-926) считает окончательным, после недели отдыха идёт в ветку else: пишется chk-state=1 и свежий chk-time (2142), а затем CANT_REACH/ERROR обработчика снова со свежим chk-time (2168-2170). Проверено на копии: для NOT_NEED записи 'chk-state=1, chk-time, chk-state=5, chk-time', для DELETED только 'chk-state=4'. Токен 'topic-status|N' остаётся под «не удаётся связаться» на выходах нечитаемого chk-forum (rutracker.php:789) и недоступного дампа (:825); другие выходы его стирают. Строка перестаёт быть осевшей, и со следующего цикла RuTracker-строка идёт кандидатом в статистику предохранителя (updatepass.php:360); при сработавшем хосте ветка fused её не диспетчеризует — тот самый риск «кладбища», от которого уже ограждены DELETED/ABSORBED. Обычно лечится само на первом удачном цикле; вечное срабатывание требует многодневного сбоя дампа и доли закрытых тем не меньше max(3, 20%). Чужие NOT_NEED+superseded в предохранитель не попадают (host ''), для них переход в CANT_REACH с сохранённым указателем предусмотрен ($unresolvedSuccessor). Дефект был и на origin/master (там затирался даже DELETED); диапазон закрыл дыру лишь частично.

**Как исправить.** Определять удерживаемый вердикт тем же правилом, что isSettled(): читать под claim ещё и chk-msg или вынести общий предикат «осевший вердикт» в одно место для run() и updatepass. Предикат обязан принимать признак чужой строки, как isSettled($row, $foreign). Для чужих строк осевшим считается только NOT_NEED+superseded, DELETED/ABSORBED там не окончательны. run() сейчас удерживает DELETED/ABSORBED для любого обработчика, и общий предикат должен явно решить, сохраняется ли это различие. Учесть, что быстрая ветка 'transport' в updatepass (см. отдельный пункт) затирает осевшие строки сама, так что одной правки run() мало. Добавить тест: осевший NOT_NEED + retryable-ответ не пишет ни chk-state, ни chk-time.

Ещё места: `plugins/rutracker_check/updatepass.php:917`, `plugins/rutracker_check/check.php:2142`, `plugins/rutracker_check/trackers/rutracker.php:789`, `plugins/rutracker_check/trackers/rutracker.php:825`.

Источники: `sig-no-answer-treated-as-answer#0`.

### C3

**STE_UNCHANGED для нетерминального состояния штампует свежие chk-time/chk-stime без нового ответа** · P3 · дефект · старый долг · коммит af56990f · голоса 2/2 · [plugins/rutracker_check/check.php:2157](../../plugins/rutracker_check/check.php#L2157)

Когда обработчик вернул STE_UNCHANGED (единственный источник — layer 1 'cold', rutracker.php:625), а прежнее состояние нетерминальное, run() восстанавливает его через setState(previous) (check.php:2157, затем 2170). Это заново пишет chk-time, а для UPTODATE и chk-stime. Свежий chk-time ещё раньше пишет блокировка INPROGRESS (2142). Проба: записи 'chk-state=1,chk-time=T,chk-state=3,chk-time=T,chk-stime=T'. Следствия: ручная «проверка» остановленного торрента RuTracker не шлёт ни одного запроса, но показывает «No update required, checked just now»; для осевшей нетерминальной строки NOT_NEED с topic-status свежий chk-time сдвигает недельное окно SETTLED_RECHECK. Перештамповка chk-stime решения об удалении не меняет (deletionRunStatus() даёт тот же ответ). Повтор «каждый цикл» преувеличен: планировщик ходит только по seeding-представлению, cold там длится до первого анонса после старта демона. Комментарий rutracker.php:618-624 («то же, что делает быстрый проход, пропуская cold-строку без записи») этому пути не соответствует. Так было и на origin/master; диапазон исправил только DELETED/ABSORBED.

**Как исправить.** Восстанавливать «только chk-state» недостаточно: chk-time уже переписала блокировка. Нужно записать обратно исходные $time (и chk-stime), прочитанные getState() до блокировки, либо не трогать chk-time на этом пути, как для DELETED/ABSORBED. Поправить комментарий rutracker.php:618-624.

Ещё места: `plugins/rutracker_check/check.php:2142`, `plugins/rutracker_check/check.php:2170`, `plugins/rutracker_check/trackers/rutracker.php:618`.

Источники: `checker#3`.

### C4

**После простого 403 от details проба download выбрасывает NOT_NEED/ERROR из createTorrent(); имя и комментарий фильтра остались от удалённой защёлки** · P3 · дефект · коммит af56990f · голоса 2/2 · [plugins/rutracker_check/trackers/kinozal.php:299](../../plugins/rutracker_check/trackers/kinozal.php#L299)

На пути неклассифицированного 403 от get_srv_details (kinozal.php:349-363) hasIndependentDownloadVerdict() (299-303) пропускает только null/UPTODATE/DELETED. STE_NOT_NEED от createTorrent() (check.php:1406-1407, к этому моменту уже записан chk-msg 'superseded|<hash>') и STE_ERROR отбрасываются, строка 362 возвращает STE_CANT_REACH_TRACKER через unreachable(..., 'details') и засчитывает отказ details. На пути стены-челленджа (322, 341) тот же вердикт возвращается без изменений. Последствия NOT_NEED: в UI «Ошибка доступа к трекеру — текущая версия уже в клиенте: <hash>», isSettled() не срабатывает, $unresolvedSuccessor (updatepass.php:440) снимает free pass, и каждый цикл снова тратит 403, загрузку около 200 КБ и RPC createTorrent на тот же результат. STE_ERROR показывается как ошибка трекера; три таких подряд взводят $cycleAbandoned, и следующая тема Kinozal со здоровым details уже не спрашивается (проба: вердикты [5,5,5,5]). Комментарий 296-298 («not independent evidence that details can be bypassed for other topics») и имя функции остались от удалённой защёлки NEW-G и противоречат строкам 353-355 («settles this topic only»); о NOT_NEED комментарий молчит. Путь достижим только когда 403 перестаёт распознаваться как челлендж (например, блок-страница Cloudflare 1020); сейчас прод получает cf-mitigated: challenge. На origin/master этого кода нет.

**Как исправить.** Решать по тому, пришла ли metainfo, а не по списку значений: decideFromDownload() сообщает, что download дал разобранную metainfo, и тогда любой вердикт createTorrent() (включая NOT_NEED и ERROR) возвращается как вердикт этой темы без unreachable(). Отказ details засчитывать только если download сам не ответил. Переименовать функцию (например, downloadSettlesThisTopic()) и переписать комментарий 296-298 под контракт «только эта тема», убрав упоминание других тем.

Ещё места: `plugins/rutracker_check/trackers/kinozal.php:296`, `plugins/rutracker_check/trackers/kinozal.php:352`, `plugins/rutracker_check/trackers/kinozal.php:362`, `plugins/rutracker_check/check.php:1406`.

Источники: `trackers#0`, `dead-strange#0`, `sig-seam-removal-leftovers#2`.

### C5

**Обычный путь Kinozal не проверяет результат fetchComplex(download) и читает устаревший status/results от details** · P3 · диагностика · коммит af56990f · голоса 2/2 · [plugins/rutracker_check/trackers/kinozal.php:399](../../plugins/rutracker_check/trackers/kinozal.php#L399)

Snoopy в начале явного fetch сбрасывает только error и lastredirectaddr (Snoopy.class.inc:288-290); status, headers и results перезаписывает лишь _httpsrequest() (761-765). Обычный путь Kinozal переиспользует клиент details и не смотрит на возвращаемое значение fetchComplex(DOWNLOAD_URL) (kinozal.php:396-399). Если Snoopy отказал на checkTarget() (308-309; нужен включённый $httpBlockPrivateNetworks, по умолчанию false, и нерезолвящийся или непубличный dl.kinozal.guru при проходящем kinozal.guru) или commonAccount::fetch вернул false (accounts.php:205-206), decideFromDownload() видит 200 и страницу details, обнуляет серию downloadTransportFailures (стр. 260) и пишет «download.php returned no metainfo ... bytes=<размер страницы details>». Вердикт верный и повторяемый (CANT_REACH), но отказ транспорта классифицирован как неразборчивый ответ, текст отказа теряется, а защёлка download на этом пути не взводится. Запросов это не стоит: checkTarget отказывает до отправки. Само чтение устаревшего status было и на origin/master; новое — обнуление счётчика и обход новой защёлки. Путь через свежий клиент (downloadClient() → makeClient()) тот же отказ учитывает как транспортный.

**Как исправить.** Проверять возвращаемое значение fetchComplex() и при false идти в unreachable('download') с классифицированной причиной, либо брать для download отдельный клиент, как downloadClient(), с переносом cookies. Отдельно можно сбрасывать status/results/headers в начале явного fetch глубины 0 рядом со сбросом error и lastredirectaddr.

Ещё места: `php/Snoopy.class.inc:288`, `php/Snoopy.class.inc:308`, `plugins/rutracker_check/trackers/kinozal.php:260`.

Источники: `sig-sibling-path-asymmetry#1`.

### C6

**Без читаемого комментария проверка владельца срабатывает в пользу RuTracker: смешанная строка получает бесплатный UPTODATE** · P3 · дефект · старый долг · коммит af56990f · голоса 2/2 · [plugins/rutracker_check/updatepass.php:42](../../plugins/rutracker_check/updatepass.php#L42)

commentOf() (updatepass.php:33-40) берёт comment из строки или из session-копии через sessionComment() (check.php:266-280), который на любую неудачу возвращает ''. isForeignComment('') даёт false, поэтому строка Kinozal/NNMClub с кросс-сидом на bt.t-ru.org идёт в слой 1 RuTracker. При живом анонсе RuTracker ветка 'alive' (:473) пишет UPTODATE вместе с chk-stime и стирает chk-msg/chk-del, обработчик-владелец не вызывается. При cold-анонсе RuTracker строка не отправляется вовсе. Проба на копии: живой RuTracker даёт checked=0 uptodate=1 без комментария против checked=1 uptodate=0 с комментарием. Для foreign-прохода правило обратное: ownerOf('') даёт null, бесплатного прохода нет. Досягаемость узкая: пример «демон без session.path» невозможен (init.php:12-16 тогда отключает плагин), остаётся потерянная или битая session-копия конкретного кросс-сид торрента. Пока копия не читается, владелец не опрашивается; после восстановления всё лечится само, ручная проверка находит владельца через getSource(). Тест UpdatePassTest.php:1539 смотрит только строку с одним Kinozal-анонсом. Дефект был и на origin/master.

**Как исправить.** Если комментарий неизвестен, а в строке есть анонс под фильтр другого зарегистрированного трекера, не давать бесплатный путь RuTracker и отправлять строку в dispatch; либо получать комментарий тем же путём, что run() (session-копия, затем d.get_tied_to_file). Закрепить тестом на смешанную строку без комментария.

Ещё места: `plugins/rutracker_check/updatepass.php:33`, `plugins/rutracker_check/check.php:266`, `plugins/rutracker_check/updatepass.php:473`.

Источники: `sig-no-answer-treated-as-answer#1`.

### C7

**Быстрая ветка 'transport' затирает осевший DELETED/ABSORBED на CANT_REACH, вопреки новому правилу удержания в run()** · P3 · странная логика · старый долг · коммит af56990f · голоса 2/2 · [plugins/rutracker_check/updatepass.php:498](../../plugins/rutracker_check/updatepass.php#L498)

В ветке 'transport' (updatepass.php:498-505) нет проверки isSettled($row), которая есть в ветке fused (:522). После недели отдыха осевший DELETED/ABSORBED (и NOT_NEED 'topic-status|…') получает chk-state=5, свежий chk-time и пустой chk-msg: исчезают 'deleting|N/M' и 'absorbed|<topic>'. Это расходится с правилом, введённым в run() этим диапазоном (check.php:2159-2167): «A retryable failure cannot refute a terminal topic verdict». Код ветки не менялся с origin/master, расхождение появилось из-за правки run(). Путь узкий: classify() отвечает 'transport' только если у торрента нет включённых чужих строк; одна строка retracker.local или dht:// уже даёт 'candidate', и тогда строку берёт run() с удержанием. Вне срабатывания предохранителя всё чинится за один цикл (chk-del и chk-stime сохранены). Если таких снятых строк на хосте наберётся не меньше max(3, 20%), хост срабатывает и остаётся закрытым до ручной проверки: проба — 3 снятые строки из 12 держат хост в fused два цикла подряд, ran=0. Видно в логе (fused=<host>) и в chk-msg (fuse|host).

**Как исправить.** Добавить в ветку 'transport' `if (self::isSettled($row)) continue;`, как в ветке fused. С этой правкой все 150 существующих тестов проходят. Добавить тест: осевшая строка в ветке 'transport' не получает ни одной `d.set_custom` — ни chk-state, ни chk-time, ни chk-msg. Подкладывать обе формы записи вердикта (три и четыре команды).

Ещё места: `plugins/rutracker_check/updatepass.php:522`, `plugins/rutracker_check/check.php:2159`.

Источники: `checker#1`.

### C8

**Тест обещает поймать переформулировку сообщения Snoopy, но сверяет только число присваиваний** · P3 · заявление расходится с кодом · старый долг · коммит af56990f · голоса 1/1 · [tests/plugins/rutracker_check/FetchErrorTest.php:28](../../tests/plugins/rutracker_check/FetchErrorTest.php#L28)

Комментарий FetchErrorTest.php:28-30 обещает, что переформулированное сообщение Snoopy будет поймано «by the token it stops producing». Таблица :32-43 — рукописные копии строк, classify() проверяет эти копии, а добавленная в диапазоне проверка :44-49 лишь сравнивает число совпадений `$this->error =` с count($expected)+1. Мутация в Snoopy.class.inc:908 ('dns lookup failure (-4)' → 'DNS resolution failed (-4)') оставляет FetchErrorTest зелёным (9/0), а classify() на новом тексте возвращает 'unclassified' — классифицированная причина пропадает из лога молча. Обещание было ложным и на origin/master; диапазон правил этот комментарий (eight → nine) и ложь оставил.

**Как исправить.** Для каждой строки таблицы проверять, что её статический фрагмент (у интерполируемых строк — литеральный префикс) есть в исходнике Snoopy, например strpos($source, 'dns lookup failure (-4)'). Либо сузить комментарий до того, что тест делает: ловит добавление и удаление присваивания.

Ещё места: `tests/plugins/rutracker_check/FetchErrorTest.php:44`.

Источники: `tests-checker#6`.

### C9

**Кейс про защёлку download больше её не видит: тема E отвечает UPTODATE до проверки downloadAbandoned** · P3 · слабый тест · коммит af56990f · голоса 2/2 · [tests/plugins/rutracker_check/KinozalHandlerTest.php:293](../../tests/plugins/rutracker_check/KinozalHandlerTest.php#L293)

В кейсе «the download guest streak resets on metainfo and then latches independently of healthy details» тема E получает из details свой же хеш (:313), поэтому kinozal.php:386-387 возвращает UPTODATE раньше единственной проверки downloadAbandoned на обычном пути (:395). Проверки :327-329 и :333-334 (9 запросов) дают одно и то же, сработала защёлка или нет. На origin/master этот кейс ждал от E CANT_REACH и 8 запросов, то есть защёлку проверял; в диапазоне ожидание переписали, и покрытие потеряно. Мутации: (1) guestAnswer() защёлкивает download только при detailsWalled — ловит лишь соседний «an unclassified 403 with a login page never proves deletion or a wall»; (2) здоровый ответ details обнуляет downloadGuestAnswers (ровно то, что запрещает комментарий kinozal.php:93-95) — на HEAD файл целиком зелёный, на origin/master этот кейс падает. Цена поломки: лишний запрос и строка лога на каждую тему с реально изменившимся хешем; вердикт в любом случае CANT_REACH.

**Как исправить.** Дать E (или шестой теме) в details другой хеш и утверждать CANT_REACH без download-запроса: список запросов должен заканчиваться на details E, как в кейсе :1068. Проверено на копии: такой кейс падает под мутацией и проходит без неё.

Ещё места: `tests/plugins/rutracker_check/KinozalHandlerTest.php:313`, `tests/plugins/rutracker_check/KinozalHandlerTest.php:327`, `tests/plugins/rutracker_check/KinozalHandlerTest.php:333`.

Источники: `tests-checker#2`.

### C10

**Кейс «челлендж распознан по заголовку без тела» не может упасть из-за новой пробы 403** · P3 · слабый тест · коммит af56990f · голоса 2/2 · [tests/plugins/rutracker_check/KinozalHandlerTest.php:631](../../tests/plugins/rutracker_check/KinozalHandlerTest.php#L631)

Добавленная в диапазоне проба неклассифицированного 403 через download.php (kinozal.php:349-361) для одной темы даёт тот же список запросов (details, download) и тот же вердикт UPTODATE, даже если заголовок cf-mitigated полностью игнорируется. Мутация на копии: в kinozal.php:335 `isChallenge($client->headers, …)` заменено на `isChallenge(array(), …)` — KinozalHandlerTest остаётся зелёным (52/52), включая дочерний прогон без iconv. С такой регрессией detailsWalled не выставляется, и каждая тема цикла сначала снова стучится в стену get_srv_details: два запроса на тему вместо одного.

**Как исправить.** После стены, опознанной только по заголовку, добавить вторую тему и проверить, что для неё запрошен только download, без details; либо прямо утверждать strictGetPrivateStatic('KinozalCheckImpl', 'detailsWalled') === true — неклассифицированный 403 этот флаг не ставит.

Ещё места: `plugins/rutracker_check/trackers/kinozal.php:335`, `plugins/rutracker_check/trackers/kinozal.php:349`.

Источники: `tests-checker#0`.

### C11

**Проба download после простого 403 закреплена только для UPTODATE; ветки null и DELETED не покрыты** · P3 · слабый тест · коммит af56990f · голоса 2/2 · [tests/plugins/rutracker_check/KinozalHandlerTest.php:652](../../tests/plugins/rutracker_check/KinozalHandlerTest.php#L652)

Для hasIndependentDownloadVerdict() (kinozal.php:299-303) тест есть только на исход STE_UPTODATE (KinozalHandlerTest.php:652). Ветки null (замена совершена) и STE_DELETED (страница «Нет раздачи с таким ID.») не покрыты: тест на :673 берёт DELETED из здорового ответа details, а не из пробы. Мутации на копии (убрать null, убрать DELETED, оставить только UPTODATE) оставляют набор зелёным (52/0). Без DELETED удалённая тема за простым 403 навсегда остаётся CANT_REACH; без null успешная замена превращается в CANT_REACH для уже несуществующего хеша. В обоих случаях добавляется отказ details, и три такие темы подряд обрывают остаток цикла Kinozal.

**Как исправить.** Добавить два кейса: (1) details 403 без признаков челленджа, download отдаёт kinozalDownloadMissingBody() — ожидается STE_DELETED, два запроса, detailsTransportFailures=0; (2) details 403, download с новой metainfo, createTorrent → null — ожидается null и отсутствие отказа details. Проверено на копии: оба проходят на HEAD (54/0) и каждый падает на своей мутации (54/1).

Ещё места: `plugins/rutracker_check/trackers/kinozal.php:299`.

Источники: `trackers#1`.

### C12

**Новая CP1251-ветка удаления Tapochek закреплена синтетикой, повторяющей константы обработчика** · P3 · слабый тест · коммит af56990f · голоса 2/2 · [tests/plugins/rutracker_check/SiblingTrackersTest.php:334](../../tests/plugins/rutracker_check/SiblingTrackersTest.php#L334)

Распознавание удаления Tapochek в cp1251 (tapocheknet.php:14-15, 46, 54-55) заменило путь через iconv, и проверяет его только рукописная фикстура SiblingTrackersTest.php:340, байты которой совпадают с MISSING_MARKER_CP1251 и INFORMATION_CP1251. Сами константы верны (точная cp1251-кодировка UTF-8 фразы, проверено), но снятой страницы tapochek.net в репозитории нет, а сообщение теста называет маркер «measured». Нарушение правила AGENTS.md о снятых ответах старше диапазона (на origin/master та же синтетика собиралась через strictCp1251()), диапазон перенёс его на новую ветку. Зонд подтвердил одно поведенческое сужение: сырой cp1251-NBSP 0xA0 внутри фразы на HEAD даёт STE_CANT_REACH_TRACKER, а на origin/master при наличии iconv давал STE_DELETED — регресс только там, где iconv есть (CI, upstream, машины разработчиков). Строго лучше HEAD только на однотабличной синтетике: снятая 2026-09-25 настоящая страница удалённой темы и на HEAD, и на origin/master (с iconv и без) даёт STE_CANT_REACH_TRACKER из-за вложенной таблицы, см. [VC-NEW-2](#vc-new-2). Обёртки из тегов strip_tags() снимает; лишний текст в td одинаково ломает оба пути. По снимку парка 2026-08-19 (`tasks/2026-08-19-branch-review/TASK-FOR-NEXT-AGENT.md:235-236`) торрентов Tapochek на проде не было; позже это не перепроверялось. Байта 0xA0 в снятом ответе нет вовсе, поэтому NBSP-сужение на реальной странице не проявляется и остаётся гипотетическим.

**После сверки 2026-09-25.** Опасение подтвердилось на реальном ответе. Снятая страница удалённой темы Tapochek содержит те же cp1251-байты, но обработчик её не распознаёт из-за вложенной разметки. Это отдельный дефект поведения, [VC-NEW-2](#vc-new-2). Сам C12 остаётся P3: фикстура повторяет константы обработчика.

**Как исправить.** Ответ удалённой темы уже снят: [verify-c-evidence/tapochek-p7-2026-09-25.html](verify-c-evidence/tapochek-p7-2026-09-25.html), 11 391 байт, SHA-256 `541b85ac…997ba`. Заменить синтетику его байтами целиком (base64, длина, SHA-256, как kinozalDownloadMissingBody()), назвав тест по происхождению. Это же RED для VC-NEW-2, поэтому делать в том же коммите, что исправление VC-NEW-2, иначе тест на реальной странице будет красным. Кроме того, проверять в тесте, что cp1251-константы — это cp1251-кодировка MISSING_MARKER и «Информация», и убрать слово «measured» из сообщения.

Ещё места: `tests/plugins/rutracker_check/SiblingTrackersTest.php:340`, `plugins/rutracker_check/trackers/tapocheknet.php:14`, `plugins/rutracker_check/trackers/tapocheknet.php:46`, `plugins/rutracker_check/trackers/tapocheknet.php:54`.

Источники: `trackers#2`.

### C13

**RuTrackerAnnounce::hostKey() осталась публичной обёрткой над UrlHost::normalize() без внешних вызовов** · nit · мёртвый код · коммит af56990f · голоса 1/1 · [plugins/rutracker_check/announce.php:113](../../plugins/rutracker_check/announce.php#L113)

Единственный внешний потребитель, RuTrackerUpdatePass::hostOf(), в этом диапазоне переведён на UrlHost::of(). Комментарий, объяснявший, зачем hostKey() public, заменён на «UrlHost owns the normalization rule shared with the scheduler fuse», а public и обёртка остались; её вызывают четыре раза через self:: (242, 262, 329, 354), тесты и другие плагины — нет. Риск в том, что hostKey — отдельная точка, через которую ключ бюджета анонсов может разойтись с группой fuse, если кто-то поправит только её тело. Блок над ней («Why this host may or may not be probed right now: 'allow', 'cooldown' or 'cap'») описывает probeDecision(), а не hostKey(); это было и до диапазона.

**Как исправить.** Сделать hostKey private или заменить четыре вызова на UrlHost::normalize(). Блок про 'allow'/'cooldown'/'cap' удалить (то же сказано у probeDecision() :220 и в @return reserveProbe() :258-259), абзац о регистронезависимости имён хостов оставить.

Ещё места: `plugins/rutracker_check/announce.php:242`, `plugins/rutracker_check/announce.php:262`, `plugins/rutracker_check/announce.php:329`, `plugins/rutracker_check/announce.php:354`.

Источники: `sig-seam-removal-leftovers#3`.

### C14

**Цикл по строкам в announceAuthorityFor() не влияет на исход и задаёт второе правило юрисдикции** · nit · упрощение · коммит af56990f · голоса 1/1 · [plugins/rutracker_check/check.php:219](../../plugins/rutracker_check/check.php#L219)

Единственный вызов (updatepass.php:445-449) даёт бесплатный проход только при announceVerdict(...) === 'alive', а announceSignal() (detector.php:156-174) сам требует включённую строку под тот же announceFilter владельца и хост из authority; без неё ответ 'none', и строка уходит в диспетчер так же, как при null. Цикл check.php:219-227 при этом вводит второе определение «строки в юрисдикции», где enabled не учитывается. Докблок сам называет его префильтром, который «does not establish host trust». Мутация на копии (цикл заменён безусловным возвратом пары): UpdatePassTest 150/0 до и после. Лишний проход по строкам бывает только у неосевших чужих строк владельца с authority (сегодня Kinozal).

**Как исправить.** Убрать цикл вместе с параметром $trackers и поправить докблок, либо убрать announceAuthorityFor() целиком и в updatepass брать ownerOf() и передавать его announceFilter/announceAuthority в announceVerdict().

Ещё места: `plugins/rutracker_check/updatepass.php:446`, `plugins/rutracker_check/detector.php:156`.

Источники: `dead-strange#3`, `dup-prod#2`.

### C15

**Предполётная запись того же значения chk-state не доказывает, что демон принимает запись, хотя комментарий это обещает** · nit · заявление расходится с кодом · коммит af56990f · голоса 2/2 · [plugins/rutracker_check/check.php:2133](../../plugins/rutracker_check/check.php#L2133)

Комментарий check.php:2133-2134 обещает «confirm the daemon can still write this hash». Для DELETED/ABSORBED пишется то же значение chk-state (2138-2140), и RuTrackerCustomProjection::write() (runstate.php:187-230) при fault или отсутствии ответа сверяет чтение с ожидаемым, которое совпадает с хранимым, — и возвращает true. Проба: при отказанной записи и рабочем чтении handlerCalls=1. На origin/master предполётом был setState(INPROGRESS) с другим значением, и сверка ловила незапись (handlerCalls=0), так что ослабление внесено в af56990f. Реального вреда нет: чекер ходит по доверенному SCGI, fault -507 там не бывает, а неизвестный хеш роняет и чтение, после чего срабатывает ранний выход. Неверны первая фраза комментария и пометка «idempotent writeability check» в CheckerTest.php:4027; случай 'write unconfirmed' (4066-4099) честно проверяет «проекция не доказана — отложить», а не «запись отвергнута при рабочем чтении». Вторая фраза комментария (о различении неясной записи и исчезновения) верна.

**Как исправить.** Сузить комментарий до «хеш на месте и демон отвечает; запись того же значения не доказывает, что она легла» и поправить пометку в тесте. Если нужна настоящая проверка записи — писать значение, которое действительно меняется, и добавить кейс «запись отвергнута, чтение проходит».

Ещё места: `tests/plugins/rutracker_check/CheckerTest.php:4027`, `tests/plugins/rutracker_check/CheckerTest.php:4066`, `plugins/rutracker_check/runstate.php:187`.

Источники: `checker#2`.

### C16

**Строка лога «nothing can be checked and the torrent is flagged» ложна для DELETED/ABSORBED** · nit · диагностика · коммит af56990f · голоса 1/1 · [plugins/rutracker_check/check.php:2154](../../plugins/rutracker_check/check.php#L2154)

При $source === false состояние становится STE_ERROR (2158), затем $retainedTerminal возвращает $previous (2163-2165), и финальная запись пропускается; единственная запись — проверочная запись того же значения на 2138-2140. Торрент остаётся DELETED/ABSORBED, run() возвращает true, а лог (2154-2156) говорит «flagged». Проба: для previous=4/10 записи chk-state ['4']/['10'], в логе «flagged»; для previous=3 записи ['1','6'], там фраза верна. Текст остался от 29be3350 и был верен, пока ERROR писался всегда; ложным его сделало удержание из af56990f. Для терминальной строки отсутствующая копия сессии теперь видна только в этой строке, и та вводит в заблуждение.

**Как исправить.** Писать строку после решения об удержании и для терминального состояния формулировать её как «terminal verdict kept».

Ещё места: `plugins/rutracker_check/check.php:2163`.

Источники: `checker#4`.

### C17

**Конъюнкт `&& !$retainedTerminal` в $performed никогда не решает исход** · nit · мёртвый код · коммит af56990f · голоса 1/1 · [plugins/rutracker_check/check.php:2177](../../plugins/rutracker_check/check.php#L2177)

$retainedTerminal истинен только при $terminal, и тогда гейт на 2168-2169 пропускает setState(), $finalWrite остаётся null (2167), а последний конъюнкт `$finalWrite === true` (2178) уже даёт false. Мёртвым конъюнкт стал в последнем раунде фиксов: в prev финальная запись была безусловной. Читатель ищет третий случай, который он якобы отсекает; тест его не закрепляет.

**Как исправить.** Удалить строку 2177, а в комментарии 2171-2173 сказать, что удержанный терминальный вердикт ничего не пишет и потому не консумирует правку.

Ещё места: `plugins/rutracker_check/check.php:2171`.

Источники: `checker#5`.

### C18

**Завершающий return($state != STE_CANT_REACH_TRACKER) всегда истинен** · nit · мёртвый код · коммит af56990f · голоса 1/1 · [plugins/rutracker_check/check.php:2181](../../plugins/rutracker_check/check.php#L2181)

Блок `if($state!==self::STE_INPROGRESS)` (2128) в диапазоне получил собственный return (2179), поэтому строка 2181 достигается только при $state === STE_INPROGRESS: META_PENDING возвращается раньше (2123), протухший замок уже сброшен в 0 (2126). Выражение ложно намекает, что сюда может прийти CANT_REACH. Смысл ветки при этом не «живой замок другого исполнителя»: конкурента отсекает claimCheck() на 2072-2080, а этот процесс сам держит claim. Свежий chk-state=1 здесь — метка воркера, умершего посреди проверки, или старой версии плагина без claim; она стареет до MAX_LOCK_TIME.

**Как исправить.** Заменить на `return(true);` с комментарием о свежей метке INPROGRESS от умершего воркера или старой версии; не писать, что замок держит другой исполнитель.

Ещё места: `plugins/rutracker_check/check.php:2179`, `plugins/rutracker_check/check.php:2072`.

Источники: `dead-strange#5`.

### C19

**Токен 'redirect-refused' в логе плагина недостижим; комментарий о порядке строк Snoopy неверен** · nit · диагностика · коммит af56990f · голоса 2/2 · [plugins/rutracker_check/fetcherror.php:82](../../plugins/rutracker_check/fetcherror.php#L82)

Оба производственных читателя $client->error — makeClient() (check.php:2010) и NNMClubCheckImpl::guestFetch() (nnmclub.php:173) — классифицируют его только при status < 100. Snoopy пишет 'credential-redirect-refused' (Snoopy.class.inc:374) уже после разбора ответа, и status остаётся 3xx (проба с локальным сервером: status '302', error 'credential-redirect-refused'). Поэтому строки «error=redirect-refused» в логе чекера не бывает; в отладочной строке NNMClub/Kinozal видно лишь «status=302» без причины, а у Toloka/tfile/Tapochek/AniDUB загрузка идёт прямым fetchComplex мимо makeClient, и createTorrentFromDownload() (check.php:1250) молчит на любой не-200. Невидимым отказ не остаётся: Snoopy::logRedirectRefusal() (Snoopy.class.inc:413) безусловно пишет строку «Snoopy: credential-redirect-refused: <src> -> <dst>» раз на пару хостов за процесс, то есть каждый цикл. Паритетные тесты (CheckerTest.php:2165-2171, NNMClubHandlerTest.php:1769-1775, корпус TestLib.php:473) подают этот отказ со status 0, которого настоящий Snoopy не создаёт, а реальный путь с 3xx не закреплён. Строку таблицы удалять нельзя: её требует сторож FetchErrorTest.php:44-48. Кроме того, комментарий fetcherror.php:76-77 и TestLib.php:464-465 («in the order that file writes them») неверен: credential-redirect-refused записан в Snoopy (374) раньше обоих 'Refusing to fetch' (461/467), а в таблице стоит после них. В TestLib.php:485 осталось устаревшее «Not one of the eight», хотя строк девять.

**Как исправить.** Либо, как rss.php:154, классифицировать непустой $client->error и при status >= 100 и писать 'http-status=302 error=redirect-refused'; либо как минимум написать в fetcherror.php, что при гейте status < 100 этот токен не встречается и отказ виден по строке самого Snoopy. Паритетные тесты подавать со status 302. Поставить запись в порядке файла или убрать утверждение о порядке (fetcherror.php:76, TestLib.php:464); исправить «eight» на «nine» в TestLib.php:485.

Ещё места: `plugins/rutracker_check/fetcherror.php:76`, `plugins/rutracker_check/check.php:2010`, `plugins/rutracker_check/trackers/nnmclub.php:173`, `tests/plugins/rutracker_check/TestLib.php:464`, `tests/plugins/rutracker_check/TestLib.php:485`, `tests/plugins/rutracker_check/NNMClubHandlerTest.php:1769`, `tests/plugins/rutracker_check/CheckerTest.php:2165`.

Источники: `checker#8`, `proxy-sec#0`, `consumers#5`, `portability-claims#5`, `sig-sibling-path-asymmetry#3`, `sig-no-answer-treated-as-answer#2`, `sig-silent-tightening-without-upgrade-path#0`.

### C20

**Ссылка на .torrent AniDUB проверяется незаякоренным regex и склеивается строкой с хостом** · P3 · безопасность · старое, расширено диапазоном · поднят после сверки 2026-09-25 (было nit) · коммит af56990f · голоса 2/2 · [plugins/rutracker_check/trackers/anidub.php:66](../../plugins/rutracker_check/trackers/anidub.php#L66)

anidub.php:66 проверяет href regex без якорей, а строка 69 склеивает его с "https://tr.anidub.com". href ".evil.test/engine/download.php?id=1" проходит символьный класс строки 59 и проверку 66, и хост итогового URL — tr.anidub.com.evil.test. AniDUBAccount::test() такой хост не признаёт, fetchComplex уходит в обычный fetch, и Snoopy с плоской банкой отправляет туда cookies: сессию loginmgr для tr.anidub.com (privateData::load, accounts.php:27), cookies плагина cookies и Set-Cookie из ответа (setcookies(), строка 68). Склейка была и на origin/master, но скептики расходятся в оценке: один указывает, что утечку сессии loginmgr сделал достижимой именно этот диапазон (строки 6 и 54 переведены на https, и AniDUBAccount::test теперь выбирает аккаунт), другой — что href берётся только из шаблонной разметки блока качества (div id="tv720" > … > div.torrent_h > a), первое совпадение побеждает, и подложить его может только сам AniDUB или релизёр с контролем над HTML, то есть сторона, которой cookies и так принадлежат; к тому же перевод на https сузил поверхность против посредника. Абсолютный URL отсекается классом символов (нет ':'). Это тот класс ошибок «хост из подстроки URL», против которого AGENTS.md ввёл UrlHost; соседние обработчики собирают URL из id.

Предусловия, сверенные с кодом 2026-09-25:

1. Торрент с комментарием `tr.anidub.com/?newsid=N` и тегом качества в имени.
2. Первое совпадение шаблона строки 59 (блок `div#<тип><качество> > div > div.torrent_h > a`) несёт href, который начинается не с `/`. Класс символов не пропускает `:` и `@`, поэтому возможен только суффикс к хосту (`tr.anidub.com.evil.test`), и атакующему нужен собственный домен.
3. Snoopy отправляет туда всю плоскую банку (Snoopy.class.inc:562-565, 704-707): cookies плагина cookies, Set-Cookie страницы темы и — только на HEAD — сессию loginmgr. `AniDUBAccount::test()` требует https, а на origin/master обработчик ходил по http:// и аккаунт не выбирался, так что сессию в эту банку впервые кладёт af56990f.

Кто может записать такой href в блок качества живой страницы, не установлено. Проверяющий видел на `?newsid=12327` обычный `/engine/download.php?id=44976`. Комментарии DLE экранируют HTML, а тело релиза пишет команда AniDUB. Поэтому уровень P3: латентная слабость без воспроизведения. При доказанном внешнем контроле над href это P2 безопасности. Выкладку не блокирует.

**Как исправить.** Извлекать id якорным '`^/engine/download\.php\?id=(\d{1,10})$`' и запрашивать "https://tr.anidub.com/engine/download.php?id=" . $id, как toloka.php:60, tfile.php:20, tapocheknet.php:77 и kinozal.php:399.

Ещё места: `plugins/rutracker_check/trackers/anidub.php:59`, `plugins/rutracker_check/trackers/anidub.php:69`.

Источники: `trackers#3`.

### C21

**Для details лог и комментарии говорят «пропускается этот endpoint», а останавливается весь обработчик Kinozal** · nit · комментарий · коммит af56990f · голоса 1/1 · [plugins/rutracker_check/trackers/kinozal.php:184](../../plugins/rutracker_check/trackers/kinozal.php#L184)

Для endpoint 'details' guestAnswer()/unreachable() ставят $cycleAbandoned (kinozal.php:183, 210), а download_torrent() проверяет его на 312-313, раньше всех обходов через download.php (319-322, 333-340, 349). Это задумано (комментарий 118-122, state.php:57-60, тест KinozalHandlerTest.php:688-705 «a real two-door outage still stops the rest of this cycle»). Ложен только текст: строка лога «, this endpoint is skipped for the rest of this cycle» (184, 211), «Per-endpoint, per-process latches» (70) и «declared gone for the whole endpoint» (172-174). В origin/master было точное «the rest of this cycle is skipped»; диапазон его испортил. Сопровождающий будет искать, почему не отработал обход через download.

**Как исправить.** Для details писать «Kinozal checks are skipped for the rest of this cycle» (или выбирать суффикс по $endpoint), а в комментариях 70-81 и 172-174 прямо сказать, что защёлка download закрывает только download.php, а защёлка details завершает обработчик на весь цикл. Переименовывать флаг в $detailsAbandoned не нужно: это противоречит тесту 703-704.

Ещё места: `plugins/rutracker_check/trackers/kinozal.php:211`, `plugins/rutracker_check/trackers/kinozal.php:70`, `plugins/rutracker_check/trackers/kinozal.php:172`, `plugins/rutracker_check/trackers/kinozal.php:312`.

Источники: `trackers#6`.

### C22

**guestAnswer() и unreachable() заканчиваются одинаковым хвостом защёлки** · nit · дублирование · коммит af56990f · голоса 1/1 · [plugins/rutracker_check/trackers/kinozal.php:182](../../plugins/rutracker_check/trackers/kinozal.php#L182)

Строки 182-184 и 209-211 совпадают: выбор $downloadAbandoned/$cycleAbandoned и cantReach($log . ', this endpoint is skipped for the rest of this cycle'). Повтор появился в диапазоне (в base была только guestAnswer с $sessionDead); правка текста (см. пункт про лог details) потребует двух мест. Набор хостов Kinozal записан трижды: SITE_HOSTS (15), альтернация TOPIC_PATTERN (16) и KinozalTV.php:50 в loginmgr. Тихого расхождения первых двух быть не может: KinozalHandlerTest.php:547 и UpdatePassTest.php:4569 проходят по SITE_HOSTS и требуют, чтобы TOPIC_PATTERN и ownerOf() узнавали каждый хост (мутация «зеркало только в SITE_HOSTS» падает). Не охвачено лишь обратное направление, с мягким последствием. Копия в KinozalTV.php старше диапазона и живёт в другом плагине.

**Как исправить.** Вынести хвост в private static latch($endpoint, $log). Собирать TOPIC_PATTERN из SITE_HOSTS не нужно (в PHP 7.4 константу пришлось бы сделать методом, согласованность уже закреплена тестами); ссылку на KinozalTV.php:50 в комментарии у строки 14 можно добавить по желанию.

Ещё места: `plugins/rutracker_check/trackers/kinozal.php:209`, `plugins/rutracker_check/trackers/kinozal.php:14`, `loginmgr/accounts/KinozalTV.php:50`.

Источники: `dup-prod#9`.

### C23

**Ярлык «$detailsSilent и хеш совпал → UPTODATE» повторяет первую проверку createTorrent()** · nit · дублирование · коммит af56990f · голоса 1/1 · [plugins/rutracker_check/trackers/kinozal.php:272](../../plugins/rutracker_check/trackers/kinozal.php#L272)

kinozal.php:269-274 сравнивает strtoupper(hash_info) === strtoupper($hash) и возвращает UPTODATE. createTorrent() делает то же первой содержательной проверкой (check.php:1373, strcasecmp → STE_UPTODATE), до неё нет ни RPC, ни записи; докблок 231-234 сам признаёт, что вердикт был бы тот же. Остальной код ярлыку противоречит: обычный путь Kinozal и toloka/anidub/tfile/tapocheknet полагаются на сравнение внутри createTorrent(). Условие «$detailsSilent &&» заставляет искать вред от createTorrent() при равном хеше, а его нет. От ярлыка зависят семь тестов (631, 652, 673, 742, 834, 848, 873): без него каждый падает с «No TestLib result queued for ruTrackerChecker::createTorrent» (мутация: 7 из 52). Тест 834 закрепляет «createTorrent не вызван» — свойство, видимое только со стабом.

**Как исправить.** Удалить 269-274 и поправить докблок 232-234. Во все семь тестов добавить queueResult('createTorrent', STE_UPTODATE); в тесте 834 проверять, что createTorrent вызван с неизменённым хешем и его вердикт проброшен.

Ещё места: `plugins/rutracker_check/check.php:1373`, `plugins/rutracker_check/trackers/kinozal.php:231`, `tests/plugins/rutracker_check/KinozalHandlerTest.php:834`.

Источники: `trackers#5`.

### C24

**Комментарии обещают CANT_REACH для страниц входа NNMClub, а предикат признаков входа не содержит** · nit · комментарий · коммит af56990f · голоса 1/1 · [plugins/rutracker_check/trackers/nnmclub.php:195](../../plugins/rutracker_check/trackers/nnmclub.php#L195)

nnmclub.php:194-197 и докблок RuTrackerFetchError::isChallenge() (fetcherror.php:29-31) говорят, что login/challenge/captcha-страницы повторяемы (CANT_REACH). Regex looksLikeChallengePage() (nnmclub.php:202) — `cf-chl|turnstile|captcha|cloudflare|just a moment|challenge-platform` — признаков страницы входа не содержит. Страница входа NNMClub с кодом 200 без ссылки на download.php и без этих слов уходит в STE_ERROR «unexpected topic page format» (832-833). Разбирающий STE_ERROR будет искать сломанную разметку темы, хотя пришла стена входа.

**Как исправить.** В обоих местах написать «Cloudflare/captcha/turnstile pages». Поведение без захваченного реального ответа NNMClub не менять.

Ещё места: `plugins/rutracker_check/fetcherror.php:29`, `plugins/rutracker_check/trackers/nnmclub.php:202`.

Источники: `sig-stale-replacement-comment#8`.

### C25

**Вызывающие isAllowedTrackerHost() по-прежнему передают strtolower(host), хотя хелпер нормализует сам** · nit · дублирование · коммит af56990f · голоса 1/1 · [plugins/rutracker_check/trackers/nnmclub.php:285](../../plugins/rutracker_check/trackers/nnmclub.php#L285)

isAllowedTrackerHost() (261-265) нормализует хост через UrlHost::normalize() (регистр и корневая точка), и тест NNMClubHandlerTest.php:1790-1797 закрепляет, что сырой 'BT.NNMCLUB.TO.' принимается. Три вызова (parseAuthUrl:285, injectAuthIntoUrl:459, buildScrapeUrl:611) всё равно передают strtolower($parts['host']) — мёртвая полунормализация, внушающая, что хелпер требует заранее приведённого ввода. Избыточность старше диапазона (в base хелпер уже делал strtolower внутри). Двойная нормализация хоста темы (parseTopicRef:221 и isAllowedTopicHost:257) дублированием не является: первая нужна для хоста в исходящей ссылке, вторая — для прямых вызовов с сырым хостом; её не трогать.

**Как исправить.** Убрать strtolower в строках 285, 459 и 611; поведение не меняется.

Ещё места: `plugins/rutracker_check/trackers/nnmclub.php:459`, `plugins/rutracker_check/trackers/nnmclub.php:611`, `plugins/rutracker_check/trackers/nnmclub.php:264`.

Источники: `trackers#8`.

### C26

**Блок о подтверждении удаления стоит над deletionConfirmedOnce(), а описывает confirmDeletion()** · nit · комментарий · старый долг · коммит af56990f · голоса 1/1 · [plugins/rutracker_check/trackers/rutracker.php:339](../../plugins/rutracker_check/trackers/rutracker.php#L339)

Блок 339-343 («chk-del holds count:timestamp … capped at once per $interval … three required cycles») описывает confirmDeletion() (411; проверка на 483, инкремент и запись на 497-498), но без разделителя стоит над докблоком deletionConfirmedOnce() (344-365, функция на 366), которая только читает chk-del. Блок попал сюда ещё в 293f57d0; диапазон правил соседние строки 347-348 того же докблока. Сам текст к тому же неточен: ограничение — раз в deletionGate($interval) (интервал минус десятая часть, 3240 с при часовом интервале, 262-292), а число циклов задаёт $rutrackerDeleteCycles (по умолчанию 3, минимум 1, строка 416).

**Как исправить.** Перенести блок к confirmDeletion() на строку 411 и при переносе исправить: «раз в deletionGate($interval)» и «$rutrackerDeleteCycles циклов (по умолчанию 3)».

Ещё места: `plugins/rutracker_check/trackers/rutracker.php:366`, `plugins/rutracker_check/trackers/rutracker.php:411`.

Источники: `trackers#7`.

### C27

**isForeignAuthoritative() — однострочная обёртка с лишним $row, а комментарии называют её «ownership test»** · nit · комментарий · коммит af56990f · голоса 1/1 · [plugins/rutracker_check/updatepass.php:42](../../plugins/rutracker_check/updatepass.php#L42)

isForeignAuthoritative($row, $comment) (updatepass.php:42-45) не использует $row и после удаления шва $foreignAuthoritativeResolver стала публичной обёрткой над ruTrackerChecker::isForeignComment() — свободным фильтром комментария плюс зашитым regex. Докблок commentOf() (27-31) и докблок sessionComment() (check.php:262-264, оба af56990f) называют её «the ownership test», хотя по AGENTS.md владение объявляется и решается ownerOf(); ownerOf() спрашивает только announce-гейт прохода 2. Зонд: для 'https://rutracker.org/forum/viewtopic.php?t=12345&source=kinozal.tv' loose=true, owner=NULL, строка уходит по чужому пути (verdict 'none', host ''), где isSettled($row, true) не считает DELETED осевшим. Поведение было и в базе, новы только комментарии. Заменить isForeignComment() на ownerOf() в проходе 1 просто так нельзя: для такого комментария ownerOf() даёт null (RuTracker зарегистрирован первым без topicPattern), а null там не означает «RuTracker». Кейс UpdatePassTest «a RuTracker topic URL that mentions Kinozal is still RuTracker's to check» этот путь не закрепляет: с мутацией он тоже проходит. Слово «Authoritative» в имени осталось от удалённого hasForeignAuthoritativeComment() и путается с announceAuthority.

**Как исправить.** Вызывать ruTrackerChecker::isForeignComment($comments[$index]) напрямую (или убрать $row и сделать метод private). В обоих комментариях написать «the loose foreign-comment test in pass 1 (isForeignComment(), not ownerOf())». Отдельно решить, должен ли проход 1 опираться на объявленное владение.

Ещё места: `plugins/rutracker_check/updatepass.php:27`, `plugins/rutracker_check/check.php:262`.

Источники: `checker#7`, `proxy-sec#1`, `dead-strange#4`, `dup-prod#5`, `portability-claims#8`, `sig-stale-replacement-comment#2`.

### C28

**`$settled ||` в условии бесплатного прохода поглощён $unresolvedSuccessor** · nit · странная логика · коммит af56990f · голоса 1/1 · [plugins/rutracker_check/updatepass.php:445](../../plugins/rutracker_check/updatepass.php#L445)

isSettled($row, true) истинно для этой ветки только при NOT_NEED + токене superseded, а это всегда и $unresolvedSuccessor (state !== UPTODATE && superseded, 440-441). Три слагаемых читаются как три независимые причины отказа, хотя их две; следующий правщик будет считать, что одно страхует другое.

**Как исправить.** Убрать $settled из условия или явно отметить в комментарии, что это подмножество $unresolvedSuccessor.

Ещё места: `plugins/rutracker_check/updatepass.php:440`.

Источники: `checker#6`, `dup-prod#3`.

### C29

**Поставляемые LOAD_WAIT_ATTEMPTS и TOLOKA_CLOUDFLARE_PAUSE не закреплены литералами** · nit · слабый тест · условно: подтвердил один скептик из двух · коммит af56990f · голоса 1/2 · [tests/plugins/rutracker_check/CheckerTest.php:1923](../../tests/plugins/rutracker_check/CheckerTest.php#L1923)

Мутации на копии: LOAD_WAIT_ATTEMPTS 40 → 2 (check.php:76) проходит CheckerTest (141/0); TOLOKA_CLOUDFLARE_PAUSE 5 → 0 (toloka.php:7) проходит SiblingTrackersTest (17/0), потому что тест сам объявляет её нулём (SiblingTrackersTest.php:25). Пробел по LOAD_WAIT_ATTEMPTS был и на origin/master (сверка с константой в CheckerTest.php:1909), литерал sleep(5) в toloka тоже не измерялся. Новый testAnExhaustedLoadWaitCostsTheDeclaredDelayNotTheShippedOne (1913) своё обещание выполняет: его предмет — задержка между опросами (порог 0,5 с), а LOAD_WAIT_DELAY_US=50000 закреплена счётчиком str_replace (300-303). Новое в диапазоне одно: fb0d8711 сделал паузу toloka объявляемой, но в отличие от retrackers (UpdateTest.php:6188) не добавил проверку её поставляемого значения. Один из двух скептиков дефекта диапазона не видит.

**Как исправить.** По образцу testTheShippedWaitsAreFiveSecondsAndAQuarter читать в дочернем PHP без объявлений TOLOKA_CLOUDFLARE_PAUSE и LOAD_WAIT_ATTEMPTS и сравнивать с литералами 5 и 40.

Ещё места: `plugins/rutracker_check/check.php:76`, `plugins/rutracker_check/trackers/toloka.php:7`, `tests/plugins/rutracker_check/SiblingTrackersTest.php:25`.

Источники: `sig-test-oracle-cannot-fail#5`.

### C30

**Поле 'expect' в testTransientFailurePreservesTerminalVerdict вычисляется для всех строк, а читается только для UPTODATE** · nit · мёртвый код · коммит af56990f · голоса 1/1 · [tests/plugins/rutracker_check/CheckerTest.php:4004](../../tests/plugins/rutracker_check/CheckerTest.php#L4004)

'expect' задаётся как (string)($failure === STE_UPTODATE ? $failure : $previous) (4004), а читается один раз на 4057 внутри тернарного оператора с тем же условием, так что ветка `: $previous` не используется. Мутация: замена $previous на произвольную строку — CheckerTest 141/0. Ожидания для CANT_REACH/ERROR/UNCHANGED на деле заданы условиями на 4044, 4056 и 4060. Имя поля взято из соседнего теста (3989), где 'expect' читается во всех строках, и вводит в заблуждение.

**Как исправить.** Задать ожидаемые записи chk-state и число записей chk-time прямо в строке таблицы и сравнивать без условия, либо убрать 'expect' и написать на 4057 (string) ruTrackerChecker::STE_UPTODATE.

Ещё места: `tests/plugins/rutracker_check/CheckerTest.php:4057`, `tests/plugins/rutracker_check/CheckerTest.php:4044`.

Источники: `sig-test-oracle-cannot-fail#7`.

### C31

**Блок подготовки сессии скопирован в три новых теста; копия унаследовала «cold»-имена и удаление без finally** · nit · дублирование · коммит af56990f · голоса 1/1 · [tests/plugins/rutracker_check/CheckerTest.php:4009](../../tests/plugins/rutracker_check/CheckerTest.php#L4009)

testTransientFailurePreservesTerminalVerdict (блок 4009-4019), testTerminalPreflightStopsOnMissingHashOrUnprovedWrite (4071-4083) и testRetryableHandlerVerdictNeverConsumesForumCorrection (4157-4166) повторяют одну подготовку: случайный каталог, <OLD_HASH>.torrent с 'x', rTorrentSettings session, Torrent::$fixtures, registerTracker на *.invalid, очередь GETSTATE. Копий теперь семь (ещё 3958, 4115, 4185, 4349 без registerTracker) плюс два старых варианта (3043, 3676). Копия в 3997 унаследовала префикс 'rut-cold-' и хосты cold-test.invalid, хотя тест о терминальных вердиктах, и удаляет каталог на 4062 без finally: при падении в одной из 10 итераций rut-cold-* остаётся в TMPDIR. Цикл сбора записей chk-state (4050-4056) повторяет 3984-3988 с добавленной веткой chk-time.

**Как исправить.** Вынести подготовку в хелпер по образцу strictWithStateDir() с callback и удалением каталога в finally; вынести сбор записей d.set_custom по имени поля в отдельную функцию; префикс и хосты в 4009-4019 назвать по смыслу теста.

Ещё места: `tests/plugins/rutracker_check/CheckerTest.php:4071`, `tests/plugins/rutracker_check/CheckerTest.php:4157`, `tests/plugins/rutracker_check/CheckerTest.php:3958`, `tests/plugins/rutracker_check/CheckerTest.php:4062`, `tests/plugins/rutracker_check/CheckerTest.php:4050`.

Источники: `dup-tests#3`, `tests-checker#8`.

### C32

**Комментарий об якорях шаблонов потерял исключение curl-transfer** · nit · комментарий · коммит af56990f · голоса 1/1 · [tests/plugins/rutracker_check/FetchErrorTest.php:112](../../tests/plugins/rutracker_check/FetchErrorTest.php#L112)

Новый текст «The transport patterns are anchored at the start of the message … and each ends at a word boundary» двусмыслен: термин «transport patterns» не определён, а при естественном прочтении в него попадает curl-transfer (`/cURL could not retrieve/i`, fetcherror.php:83), у которого нет ни `^`, ни `\b`. Прежняя формулировка «Seven of the eight patterns are anchored» называла исключение явно. Проверка: 'the cURL could not retrieve x' и 'Error: cURL could not retrieved' дают curl-transfer, а не unclassified. redirect-refused (`^…$`) к транспортным не относится, но делает устаревшим старый подсчёт.

**Как исправить.** Написать: «Eight of the nine patterns are anchored at the start (curl-transfer is not); seven of those end at a word boundary, and redirect-refused must match the whole message».

Ещё места: `plugins/rutracker_check/fetcherror.php:83`.

Источники: `tests-checker#5`.

### C33

**kinozalMissingBlock(): мёртвый параметр $marker и скрытое утверждение в конструкторе фикстуры** · nit · слабый тест · коммит af56990f · голоса 1/1 · [tests/plugins/rutracker_check/KinozalHandlerTest.php:118](../../tests/plugins/rutracker_check/KinozalHandlerTest.php#L118)

Хелпер kinozalMissingBlock($marker = KinozalCheckImpl::DOWNLOAD_MISSING_CP1251) (118-123) вызывается один раз (931) без аргумента; параметр наводит на мысль о UTF-8 варианте блока, которого нет. Внутри спрятано strictAssertTrue(strpos(kinozalDownloadMissingBody(), DOWNLOAD_MISSING_CP1251) !== false), дублирующее уже закреплённое: при порче одного байта константы падают четыре теста, включая тест захвата (899) и тест гостевой страницы (930), упавший только из-за этого утверждения. Тесту гостевой страницы оно не нужно — он закрепляет порядок проверок «гость раньше пропажи».

**Как исправить.** Убрать параметр и утверждение из хелпера: захват уже закреплён отдельным тестом.

Ещё места: `tests/plugins/rutracker_check/KinozalHandlerTest.php:931`.

Источники: `dead-strange#8`.

### C34

**UTF-8 маркер удаления Kinozal не наблюдался, но тест и комментарий выдают его за подтверждённый** · nit · слабый тест · коммит af56990f · голоса 2/2 · [tests/plugins/rutracker_check/KinozalHandlerTest.php:1051](../../tests/plugins/rutracker_check/KinozalHandlerTest.php#L1051)

DOWNLOAD_MISSING_UTF8 (kinozal.php:52) принимается как доказательство удаления наравне с cp1251-формой (:156), но единственный захват download.php — cp1251, 4010 байт, и kinozal.php:46-49 сам говорит, что страница отдаётся в windows-1251. UTF-8 написание держит только рукописная однострочная фикстура в кейсе «the deletion marker page served as UTF-8 is recognised too» (1051-1055); сам кейс не пустой (мутация убирает сравнение — падает он один). Комментарий 46-50 обосновывает оба написания кодировкой get_srv_details.php, а это другая точка со своей парой маркеров. Риск ложного DELETED почти нулевой: нужен якорь pad5x5 и точное совпадение блока, оба закреплены захватом, а DELETED для Kinozal не окончателен (updatepass.php:917-921). Появилось в af56990f.

**Как исправить.** Оставить сравнение и добавить provenance-комментарий «UTF-8 форма не наблюдалась, принята по симметрии с MISSING_MARKER»; то же отразить в имени или сообщении теста и убрать ссылку на кодировку get_srv_details.php. Удалять UTF-8 ветку хуже: при переходе сайта на UTF-8 удалённые раздачи молча станут CANT_REACH.

Ещё места: `plugins/rutracker_check/trackers/kinozal.php:52`, `plugins/rutracker_check/trackers/kinozal.php:46`, `plugins/rutracker_check/trackers/kinozal.php:156`.

Источники: `tests-checker#3`.

### C35

**«so the other three have same-hash coverage» стоит над блоком с двумя проверками** · nit · комментарий · коммит af56990f · голоса 1/1 · [tests/plugins/rutracker_check/SiblingTrackersTest.php:129](../../tests/plugins/rutracker_check/SiblingTrackersTest.php#L129)

Комментарий 127-129 верен для файла в целом, но открывает блок (130-141), где проверяются только tfile и tapochek. Toloka вошёл в sibHandlers() в этом диапазоне, и его случай — отдельный тест 'toloka same hash needs no download link' (463-470), на который комментарий не ссылается. На origin/master здесь стояло точное «so only two are checked». Читатель ищет третий случай и решит, что toloka не покрыт, или допишет дубль.

**Как исправить.** Написать «…so two are checked here; toloka's same-hash case is the separate test 'toloka same hash needs no download link' below».

Ещё места: `tests/plugins/rutracker_check/SiblingTrackersTest.php:463`.

Источники: `tests-checker#7`, `dup-tests#6`.

### C36

**strictCp1251() и заглушка iconv() больше никем не используются, но диапазон добавил им охрану и два самопроверочных кейса** · nit · мёртвый код · коммит af56990f · голоса 1/1 · [tests/plugins/rutracker_check/TestLib.php:870](../../tests/plugins/rutracker_check/TestLib.php#L870)

Диапазон заменил вызовы @iconv в kinozal.php и tapocheknet.php литеральными cp1251-константами, а strictCp1251 в фикстурах (KinozalHandlerTest:267, SiblingTrackersTest:311) — литеральными байтами. После этого заглушка iconv() с охраной disable_functions (TestLib.php:802-819), strictCp1251() (870-880) и два кейса KinozalHandlerTest (1088, 1103), запускающие дочерний PHP ради проверки этого тестового кода, мертвы. Проверка под `-d disable_functions=iconv`: KinozalHandlerTest 52/0, SiblingTrackersTest 17/0, DetectorTest 16/0; после удаления этих 55 строк KinozalHandlerTest 50/0, SiblingTrackersTest 17/0. Абзац AGENTS.md:465-468 про полифилл iconv «for the one word Поглощено» («the branch saw a fake») тоже добавлен в диапазоне и уже неверен: ни одна продакшен-ветка iconv не вызывает.

**Как исправить.** Удалить strictCp1251(), заглушку iconv() с охраной disable_functions и кейсы KinozalHandlerTest.php:1088 и :1103; поправить абзац AGENTS.md про полифилл.

Ещё места: `tests/plugins/rutracker_check/TestLib.php:802`, `tests/plugins/rutracker_check/KinozalHandlerTest.php:1088`, `tests/plugins/rutracker_check/KinozalHandlerTest.php:1103`, `AGENTS.md:465`.

Источники: `tests-checker#4`, `dup-tests#1`.

### C37

**Двойник Snoopy держит lastredirectaddr между запросами, а настоящий Snoopy теперь его сбрасывает; строка kinozal.php:398 мертва** · nit · слабый тест · коммит af56990f · голоса 2/2 · [tests/plugins/rutracker_check/TestLib.php:943](../../tests/plugins/rutracker_check/TestLib.php#L943)

В 156c64af php/Snoopy.class.inc:289-290 стал обнулять lastredirectaddr в fetchRequest() на глубине 0, то есть на каждом явном fetch/fetchComplex; путь loginmgr (commonAccount::fetch, KinozalTVAccount::login) тоже проходит через $client->fetch. Закреплено SnoopyTest.php:202-212. В af56990f появились двойник TestLib.php:943-945, который поле никогда не сбрасывает, с комментарием «Real Snoopy leaves redirect metadata on a reused client until its caller clears it», строка kinozal.php:398 `$client->lastredirectaddr = '';` с комментарием 397 «Snoopy retains redirect metadata across calls on the same client» и тест KinozalHandlerTest.php:1150 «a redirect on details does not classify the next failed download as a guest». Оба комментария на HEAD ложны, а строка 398 в продакшене ничего не делает (если запрос ушёл, Snoopy обнулил поле сам; если нет — status 200 от details, и ветка редиректа на 245 не читается). Мутации: без 398 падает ровно тест 1150 (52/1, «download has its own transport failure; expected 1, got 0»); если двойник обнуляет поле в начале respond(), набор зелёный и со строкой 398, и без неё (52/0). Покрытие ядра не страдает: сброс в Snoopy ловит SnoopyTest:202. Ложная модель ядра подталкивает копировать ручные сбросы в другие обработчики. Один скептик отмечает, что двойник строже настоящего клиента и тест 1150 полезен при передаче плагина в upstream, где Snoopy поле не обнуляет.

**Как исправить.** Основной вариант: обнулять lastredirectaddr в начале respond() двойника и поправить комментарий 943-944; удалить kinozal.php:397-398; тест 1150 удалить как дубль SnoopyTest:202 или перестроить так, чтобы редирект стоял на самом ответе download. Альтернатива для upstream-передачи: двойник и строку 398 оставить, но в обоих комментариях честно написать, что Snoopy этого дерева сбрасывает поле при _redirectdepth === 0, а двойник намеренно строже, чтобы обработчик не полагался на сброс в ядре.

Ещё места: `plugins/rutracker_check/trackers/kinozal.php:397`, `plugins/rutracker_check/trackers/kinozal.php:398`, `tests/plugins/rutracker_check/KinozalHandlerTest.php:1150`, `php/Snoopy.class.inc:289`.

Источники: `trackers#4`, `snoopy#5`, `consumers#3`, `dead-strange#2`, `portability-claims#4`, `tests-checker#1`, `sig-sibling-path-asymmetry#2`, `sig-test-oracle-cannot-fail#4`, `sig-stale-replacement-comment#0`, `sig-seam-removal-leftovers#0`, `sig-silent-tightening-without-upgrade-path#3`.

### C38

**Комментарий описывает владение как «первое совпадение фильтра», которое этот же диапазон отменил** · nit · комментарий · коммит af56990f · голоса 1/1 · [tests/plugins/rutracker_check/UpdatePassTest.php:1379](../../tests/plugins/rutracker_check/UpdatePassTest.php#L1379)

UpdatePassTest.php:1379-1380: «ruTrackerChecker::run() hands a torrent to the handler whose comment filter matches». run_ex() спрашивает обработчики по порядку регистрации и идёт дальше при отказе. Через 70 строк (1453) тот же файл говорит обратное: «The loose comment filter says who is ASKED first, not who owns the torrent». Докблок registerTracker() (check.php:118-120) и AGENTS.md строят всё на различии «кого спросят» и «кто владеет»; этот комментарий его стирает и ведёт к ошибке, из-за которой бесплатный проход Kinozal однажды отдал вердикт по теме NNMClub.

**Как исправить.** Заменить на: «Ownership is the comment's, as run() decides it: the handlers whose comment filter matches are asked in registration order and the first that does not decline owns the torrent -- see registerTracker()».

Ещё места: `tests/plugins/rutracker_check/UpdatePassTest.php:1453`, `plugins/rutracker_check/check.php:118`.

Источники: `sig-stale-replacement-comment#4`.

### C39

**Шесть новых тестов вручную повторяют тело upRunPass(); проверка «ничего не записано» написана пятью способами** · nit · дублирование · коммит af56990f · голоса 1/1 · [tests/plugins/rutracker_check/UpdatePassTest.php:1572](../../tests/plugins/rutracker_check/UpdatePassTest.php#L1572)

Диапазон добавил upRunPass() (1326-1334), но тесты на 1572, 1610, 4539, 4588, 4607 и 4640 повторяют её тело (регистратор checker, reset, queue, upQueueUnchanged, run), различаясь лишь очередью: обе формы записи вердикта (три теста), ни одной (4588, 4607) или только четырёхкомандная (4640). Проверка «нет d.set_custom» написана пять раз в двух стилях: накопление в $writes и сравнение с array() (1587-1590, 1620-1623) и strictAssertTrue на каждый запрос (4547-4548, 4563-4565, 4594-4595). В отрицательных тестах форма очереди ни на что не влияет: двойник пишет запрос в $requests до поиска ответа (TestLib.php:659), а бесплатный вердикт не вызывает checker. Разница между копиями — шум, заставляющий гадать, есть ли в ней смысл.

**Как исправить.** Добавить в upRunPass параметр со списком форм очереди (по умолчанию обе) и один хелпер проверки «нет запросов d.set_custom»; перевести на них шесть тестов и пять циклов.

Ещё места: `tests/plugins/rutracker_check/UpdatePassTest.php:1326`, `tests/plugins/rutracker_check/UpdatePassTest.php:1610`, `tests/plugins/rutracker_check/UpdatePassTest.php:4539`, `tests/plugins/rutracker_check/UpdatePassTest.php:4588`, `tests/plugins/rutracker_check/UpdatePassTest.php:4607`, `tests/plugins/rutracker_check/UpdatePassTest.php:4640`.

Источники: `dup-tests#2`.

### C40

**Циклы по массиву из одного элемента array('checker') после удаления шва** · nit · мёртвый код · коммит af56990f · голоса 1/1 · [tests/plugins/rutracker_check/UpdatePassTest.php:3876](../../tests/plugins/rutracker_check/UpdatePassTest.php#L3876)

В RuTrackerUpdatePass теперь одна статика, private static $checker (updatepass.php:26), но TestLib.php:315 (с property_exists) и UpdatePassTest.php:3876 перебирают array('checker'), будто список положено пополнять. Цикл в UpdatePassTest.php:3877-3879 вдобавок вручную повторяет ReflectionProperty + setAccessible + getValue, то есть тело strictGetPrivateStatic() (TestLib.php:410-415), добавленного тем же диапазоном и уже вызываемого в этом файле (1340, 1462, 4619, 4629).

**Как исправить.** В TestLib заменить цикл на `if (property_exists('RuTrackerUpdatePass', 'checker')) strictSetPrivateStatic('RuTrackerUpdatePass', 'checker', null);`. В UpdatePassTest заменить блок на `strictAssertSame(null, strictGetPrivateStatic('RuTrackerUpdatePass', 'checker'), …)`.

Ещё места: `tests/plugins/rutracker_check/TestLib.php:315`, `tests/plugins/rutracker_check/TestLib.php:410`.

Источники: `dup-tests#9`, `tests-checker#9`.

### C41

**Имя кейса обещает проверку всех authority-хостов Kinozal, а перебирается только SITE_HOSTS** · nit · слабый тест · коммит af56990f · голоса 1/1 · [tests/plugins/rutracker_check/UpdatePassTest.php:4569](../../tests/plugins/rutracker_check/UpdatePassTest.php#L4569)

Кейс 'every Kinozal authority host has a declared topic owner' перебирает KinozalCheckImpl::SITE_HOSTS (три хоста), а зарегистрированный authority-список — $kinozalAnnounceHosts из шести (torrent4me.com, tor4me.info, tor2me.info плюс SITE_HOSTS; kinozal.php:414-415, 459). Для анонс-хостов утверждение и бессмысленно: тем на них нет. На деле кейс проверяет согласованность SITE_HOSTS ↔ TOPIC_PATTERN ↔ ownerOf; кто добавит новый анонс-хост, решит по имени, что кейс его увидит.

**Как исправить.** Переименовать в духе 'every Kinozal topic host is accepted by TOPIC_PATTERN and owned by the Kinozal handler'. Если нужна проверка именно authority — читать список из реестра через strictGetPrivateStatic('ruTrackerChecker', 'TRACKERS').

Ещё места: `plugins/rutracker_check/trackers/kinozal.php:414`, `plugins/rutracker_check/trackers/kinozal.php:459`.

Источники: `sig-seam-removal-leftovers#6`.

### VC-NEW-2

**Tapochek считает удалённую тему недоступной: regex таблиц останавливается на первом `</table>` и проглатывает вложенную системную таблицу** · P2 · дефект · старый долг · коммит af56990f (внесено в 29be3350, он на origin/master; af56990f менял в этой функции только cp1251-иглы, regex строки 35 не трогал) · найдено сверкой 2026-09-25, воспроизведено · [plugins/rutracker_check/trackers/tapocheknet.php:35](../../plugins/rutracker_check/trackers/tapocheknet.php#L35)

2026-09-25 проверяющий снял анонимным `curl` без cookies ответ `https://tapochek.net/viewtopic.php?p=7`: HTTP 200, `charset=windows-1251`, 11 391 байт, SHA-256 `541b85acf6a7aed472a2a7b070f8806bdd4708a6777fd98c3f46cdba426997ba`, файл [verify-c-evidence/tapochek-p7-2026-09-25.html](verify-c-evidence/tapochek-p7-2026-09-25.html). В ответе есть `<table class="forumline message">` (строки 271-274 файла) с заголовком «Информация» и ячейкой «Темы, которую вы запросили, не существует.». `btih` и ссылки на download.php нет. Системная таблица вложена в `<table … class="table-full-width">` (строки 226-287), общую обёртку страницы.

Нежадный `<table\b…>(.*?)</table>` на строке 35 начинает совпадение на внешней таблице и обрывает его на первом `</table>`, а это закрывающий тег внутренней таблицы (строка 274). Из шести совпадений ни одно не несёт класса `forumline`, поэтому `isMissingAnswer()` отвечает false, и ответ 200 без btih превращается в `STE_CANT_REACH_TRACKER` (строка 92) вместо `STE_DELETED` (строка 84). Тема удалённой раздачи перепроверяется каждый цикл, в `chk-state` пишется 5, а DELETED не наступает никогда.

Проверено вызовом `download_torrent()` со стабом `makeClient` (status 200, тело из файла):

| дерево | iconv | вся страница | только внутренняя таблица |
|---|---|---|---|
| HEAD | есть | 5 | 4 |
| HEAD | нет | 5 | 4 |
| origin/master | есть | 5 | 4 |
| origin/master | нет | 5 | 5 (без iconv origin/master cp1251 не распознаёт вовсе, см. C12) |

Контроль: если на той же странице открывающие `table-full-width` заменить на `div`, HEAD даёт 4, так что дело во вложенности, а не в байтах маркера. В `ivanshift/rutorrent:latest` (php85, без iconv) полная страница тоже даёт 5. При разборе `isMissingAnswer()` повторно проверен на этом файле: false на всей странице и true на строках 271-274, на HEAD и на origin/master.

Разбор сломан на любой среде с тех пор, как в 29be3350 появился regex. Правка tests24 из af56990f (cp1251-иглы) верна, но разбор до игл не доходит. Синтетика SiblingTrackersTest.php:334 состоит из одной таблицы без обёртки и этого не видит (C12). В upstream `isMissingAnswer()` нет: там на этой странице получается `STE_NOT_NEED`, это другой неверный вердикт. Значит, обещанное форком распознавание удаления на реальной странице не работает.

На нашем проде вреда сейчас нет: по снимку парка 2026-08-19 (`tasks/2026-08-19-branch-review/TASK-FOR-NEXT-AGENT.md:235-236`) торрентов Tapochek там не было, позже состав не перепроверялся. Задеты другие установки форка. Уровень P2 держится на реальном входе, а не на наличии раздач.

Что не проверено:

- Страница под сессией аккаунта не снималась. Обёртка `table-full-width` относится к шаблону страницы, а не к гостевому ответу, поэтому вложенность там ожидаема, но это вывод, не замер.
- `p=7` может быть номером поста, которого никогда не было. Трекер отдаёт на него ту же страницу, что и на удалённую тему, а обработчик эти случаи не различает, так что на вывод это не влияет.

Дефект вне дельты: выкладку не блокирует и идёт отдельной ранней выкладкой (ARCHITECTURE, шаг 1, пункт 5).

**Как исправить.** Отдельным коммитом.

1. Сначала RED-тест на снятом ответе байт в байт. Байты перенести в `tests/` (каталог `tasks/` игнорируется Git), закрепить длину и SHA-256, назвать тест по происхождению, например `the live Tapochek removal page is a deletion verbatim`. Тест должен проходить и в ноге без iconv.
2. Затем найти открывающий `<table>` с классами `forumline` и `message` и читать до парного `</table>` с учётом вложенности, например по смещениям `<table\b` и `</table>` из `preg_match_all(…, PREG_OFFSET_CAPTURE)`. Другой путь — найти ячейку с маркером и проверить, что ближайшая охватывающая её таблица системная.
3. DOMDocument тоже годится: ext-dom есть в `ivanshift/rutorrent:latest` (`php85 -m`: dom, libxml, xml; iconv нет), а также в `php:7.4-cli` и `php:8.1-cli` из матрицы. На этом файле `loadHTML()` в прод-образе по meta charset отдаёт «Информация» и фразу в UTF-8. Без meta charset libxml читает страницу как ISO-8859-1 (оба случая замерены при разборе 2026-09-25), поэтому байтовые cp1251-иглы из af56990f оставить. Без них прод-образ без iconv не распознаёт даже страницу без вложенности.
4. Отдельно закрепить, что маркер в посте или сайдбаре вне системной таблицы не даёт DELETED.
5. Мутация: вернуть regex строки 35, и тест обязан упасть.
6. В том же коммите заменить синтетику C12 этим ответом.

Ещё места: `plugins/rutracker_check/trackers/tapocheknet.php:83`, `plugins/rutracker_check/trackers/tapocheknet.php:92`, `tests/plugins/rutracker_check/SiblingTrackersTest.php:334`.

Источники: `VERIFY-C-25a0e2cb.md` (VC-NEW-2), `verify-c-evidence/tapochek-p7-2026-09-25.html`.

### VC-NEW-1

**AniDUB, tfile, Toloka и Tapochek не проверяют результат fetchComplex(download) и читают status/results страницы темы** · nit · диагностика · старый долг · коммит af56990f (код старше диапазона) · найдено сверкой 2026-09-25 · [plugins/rutracker_check/trackers/tfile.php:20](../../plugins/rutracker_check/trackers/tfile.php#L20)

Четыре обработчика переиспользуют клиент страницы темы и вызывают `fetchComplex()` для .torrent без проверки возвращаемого значения: anidub.php:68-71, tfile.php:19-21, toloka.php:57-61, tapocheknet.php:76-78. Snoopy в начале явного fetch сбрасывает только error и lastredirectaddr (Snoopy.class.inc:288-290). Поэтому при раннем false (отказ checkTarget или commonAccount::fetch) `createTorrentFromDownload()` (check.php:1250-1257) видит status 200 и HTML темы. Вердикт верен: HTML не metainfo, итог STE_CANT_REACH_TRACKER. Но причина отказа теряется. Форма та же, что у C5, только без защёлки Kinozal.

**Как исправить.** При следующем проходе по этим обработчикам проверять bool и классифицировать отказ до чтения status/results. Либо сбрасывать status/results/headers в Snoopy при явном fetch глубины 0, как предложено в C5. Вердикт без снятого реального ответа не менять.

Источники: `VERIFY-C-25a0e2cb.md` (VC-NEW-1).

## Ожидания, инструменты, дублирование и сквозные темы

Раздел «Ожидания, инструменты, дублирование и сквозные темы»: 23 пункта, P1 и P2 нет. VO-NEW-1 (P3) добавлен в конец раздела после сверки 2026-09-25. Дубли объединены: waits#4 с dup-tests#7 (O18), tooling#0 с portability-claims#0 (O2), tooling#4 с portability-claims#3 (O14), tooling#8 с dead-strange#9 (O16). Все номера строк сверены с экспортом HEAD. Поправки скептиков учтены. - O1: простой перевод на hrtime ломает случай шага часов назад, потому что планировщик rTorrent тоже идёт по стенным часам. Комментарий на 10787 сейчас верен. - O14: TMPDIR длиной 59 байт на деле не ломает фикстуру. Неверно только число 48 в комментариях. - O10: предложенное исходно исправление ничего бы не закрепило. Самое важное для CI — O9. Job jest покраснеет на первом же push, на rtorrent.spec.js (982 против 946) и отдельно на js/lang.spec.js (нет accOriginRequired в языках loginmgr). Большая часть находок по tasks/matrix.sh (O2–O8, O14–O16) — это локальный инструмент разработчика. Прод он не затрагивает, но сейчас его зелёный маркер проверяет меньше, чем заявлено: O2, O6, O7, O8. Порядок: по серьёзности, внутри — по файлу и строке.

### O1

**Дедлайн ожидания подтверждения в erasedataWaitForDrainAcknowledgement() считается по стенным часам** · P3 · странная логика · старый долг · коммит fb0d8711 · голоса 2/2 · [plugins/erasedata/removewithdata.php:2244](../../plugins/erasedata/removewithdata.php#L2244)

erasedataWaitForDrainAcknowledgement() вычисляет дедлайн через microtime(true) (строки 2244 и 2256). Соседние дедлайны используют монотонный hrtime: teardownDeadline() в plugins/retrackers/update.php и php/scgitransport.php. Пока цикл ждёт, producer держит per-hash блокировки. Шаг системных часов вперёд внутри 11-секундного окна (NTP-step при старте контейнера, синхронизация времени Hyper-V после resume) обрывает ожидание раньше ack. Producer уходит в drain-no-ack, откатывает свой staging и отвечает пользователю отказом, хотя подтверждение пришло бы через доли секунды. Отказ виден в журнале, торренты и обязательство остаются, удаление можно повторить. Шаг назад, по оценке из исходников (не замер), почти безвреден: тик планировщика rTorrent тоже идёт по стенным часам (cached_time() в libtorrent = system_clock / gettimeofday) и сдвигается вместе с дедлайном. Держать блокировки N+11 с producer будет только тогда, когда подтверждения нет вообще, а этот путь и так кончается отказом. Дефект был уже на origin/master, строки 2244/2256 в base и HEAD совпадают. Новый кейс RemoveWithDataTest.php:10780 эту зависимость НЕ закрепляет: с hrtime в продукте он проходит (1.03 с, проверено мутацией). Со стенными часами кейс связывает только верный сейчас комментарий на 10787 «Match the wall-clock deadline…».

**Как исправить.** Простой перевод на hrtime исправляет шаг вперёд, но ломает шаг назад: producer будет сдаваться через 11 с, пока стоит планировщик rTorrent, который идёт по стенным часам. Если чинить, то осознанно. Например, отказывать только когда истекли и монотонное, и стенное окно, либо оставить как есть и записать в комментарии к циклу, почему выбраны стенные часы. При любой правке переписать комментарий на RemoveWithDataTest.php:10787. Сам замер в тесте (10788/10791) можно оставить на microtime.

Ещё места: `plugins/erasedata/removewithdata.php:2256`, `tests/plugins/erasedata/RemoveWithDataTest.php:10787`.

Источники: `waits#2`.

### O2

**digest() не покрывает env_check.php, js/, lang/ и tasks/*.php, и хук пропускает набор при красном дереве** · P3 · дефект · коммит fb0d8711 · голоса 2/2 · [tasks/matrix.sh:51](../../tasks/matrix.sh#L51)

digest() хэширует только `git ls-files -- php plugins tests conf rpc2.php` и сам tasks/matrix.sh. Ноги при этом гоняют набор на полном экспорте (строка 92, ls-files без pathspec). Набор читает и исполняет файлы за пределами digest: env_check.php (его подключают RequirementsTest, EnvCheckShippedConfigTest, PluginExtensionsTest, LogPathAgreementTest, а путь к нему берут EnvCheckTest и XMLRPCProxyTest), js/, lang/ и PHP-файлы в корне (их обходит RtorrentCompatibilityTest.php:1433-1462), tasks/retrackers-clear-markers.php (его запускает RepairToolRefusalTest.php:54-55). Правка любого из этих файлов после зелёного matrix.sh digest не меняет. Тогда .git/hooks/pre-commit печатает «already green for this exact tree» и пропускает прогон. Проверено на копии: после мутации Requirements::isUnixSocket digest по-прежнему 1d926b226a6f…, а RequirementsTest даёт 9 строк Failed:. Правка js/content.js digest тоже не меняет. На прод не влияет, CI (tests.yml, push в master и pull_request) всё поймает, но на шаг позже, уже после push. Комментарий matrix.sh:34-36 («everything php-test.sh can see») и AGENTS.md:353-354 («what the suite sees») завышают покрытие.

**Как исправить.** Строить digest из того же `git ls-files -z --cached --others --exclude-standard`, что и экспорт на строке 92, исключив только ':!*.md'. Тогда «что видят ноги» и «что хэшируется» будут одним определением, а коммиты только с tasks/*.md по-прежнему прогон пропустят. Добавить в pathspec лишь env_check.php, js, lang и 'tasks/*.php' мало: новый PHP-файл в корне опять выпадет. Поправить комментарии matrix.sh:34-36, AGENTS.md:353-354 и комментарий в хуке.

Ещё места: `tasks/matrix.sh:34`, `AGENTS.md:353`, `tasks/matrix.sh:92`.

Источники: `tooling#0`, `portability-claims#0`.

### O3

**Неиндексированное удаление или переименование файла роняет digest(), и matrix.sh не запускает ни одной ноги** · P3 · дефект · коммит fb0d8711 · голоса 2/2 · [tasks/matrix.sh:57](../../tasks/matrix.sh#L57)

`git ls-files --cached` (строка 51) перечисляет и отслеживаемые файлы, удалённые в рабочем дереве, пока удаление не проиндексировано. На таком пути `xargs -0r sha256sum` (строка 57) падает, и digest() возвращает 1. Воспроизведено на копии: после `rm tests/php/EnvCheckTest.php` или обычного `mv` без `git mv` команда `tasks/matrix.sh digest` печатает «sha256sum: tests/php/EnvCheckTest.php: No such file or directory» и выходит с 1. `tasks/matrix.sh local` и полный запуск выходят с 1 на строке 86, не запустив ни одной ноги, и оставляют пустой каталог запуска, созданный mktemp -d на строке 76. После `git rm` или `git add -A` всё работает. Ложного зелёного нет: хук при пустом digest идёт на полный прогон. Но дерево с удалённым файлом, то есть ровно то, что хочется проверить перед `git rm`, matrix.sh проверить не может. Комментарии в строках 18-19 и 34 («unstaged edits», «working-tree content») обещают обратное.

**Как исправить.** Вычитать `git ls-files -z --deleted` по тем же путям, например `comm -z -23` над двумя списками, отсортированными под LC_ALL=C. Либо писать для таких путей в hashes фиксированную строку «deleted <path>». Ошибку для файла, исчезнувшего между двумя вызовами, сохранить.

Ещё места: `tasks/matrix.sh:76`, `tasks/matrix.sh:86`.

Источники: `tooling#6`.

### O4

**Бюджет TMPDIR проверяется для всех ног по хостовому пути, и полный запуск отказывает при HOME длиннее 11 байт** · P3 · переносимость · коммит fb0d8711 · голоса 2/2 · [tasks/matrix.sh:79](../../tasks/matrix.sh#L79)

Цикл 77-83 применяет tmpdir_budget к `${run}/${leg}/tmp` для каждой ноги. Но у докер-ног TMPDIR внутри контейнера равен /mtmp, а prod-kinozal UNIX-сокетов не открывает вовсе. Самое длинное имя у prod-kinozal: TMPDIR = HOME + 48 байт, поэтому полный запуск отказывает при любом HOME длиннее 11 байт. С HOME=/home/runner (12) или /home/ivanshift (15) `tasks/matrix.sh` без аргументов выходит с exit 2 и сообщением «UNIX socket fixtures need it <= 59», хотя у ноги local, единственной, которой бюджет нужен, 53 и 56 байт. last-green пишет только полный запуск, так что у такого разработчика хук никогда не получит маркер. Кроме того, отказ случается после mktemp -d (строка 76) и оставляет пустой каталог запуска.

**Как исправить.** Проверять бюджет только для ноги local. Для докер-ног не проверять вовсе или считать по /mtmp. Проверку перенести до mktemp -d: длина пути известна заранее.

Ещё места: `tasks/matrix.sh:76`.

Источники: `tooling#5`.

### O5

**Нет trap на INT/TERM: после Ctrl-C ноги и контейнеры продолжают работать сиротами** · P3 · дефект · коммит fb0d8711 · голоса 2/2 · [tasks/matrix.sh:103](../../tasks/matrix.sh#L103)

Ноги запускаются фоновыми подоболочками `( ... ) &` в неинтерактивном bash (строки 103, 106-113, 116-118), а trap на INT/TERM нет. Управление заданиями выключено, поэтому фоновые подоболочки и их потомки игнорируют SIGINT. Ctrl-C убивает только сам matrix.sh, ждущий в `wait` на строке 127. Нога local (php-test.sh с php-потомками) доходит до конца. Контейнеры тоже: docker CLI пересылает SIGINT в PID 1 (bash php-test.sh), и тот продолжает цикл. Безымянные контейнеры переживают даже SIGKILL всего дерева, например таймаут Bash-инструмента агента. Уборка на строке 158 не выполняется. Ложного зелёного нет, коллизии файлов тоже (у нового прогона свой каталог и TMPDIR). Но около 90 с осиротевшие ноги с 64 MiB фикстурами делят CPU со следующим прогоном, а AGENTS.md прямо называет такие наложения источником ложных результатов (окна по 1 с в ManualEntrypointsTest). Перемерено 2026-09-25 на уменьшенной модели (неинтерактивный bash, нога в `( ... ) &`, SIGINT всей группе процессов, как при Ctrl-C): родитель завершился, нога доработала и записала свой `exit`. Для SIGTERM то же независимо показал проверяющий ([VERIFY-O-25a0e2cb.md](VERIFY-O-25a0e2cb.md)).

**Как исправить.** Давать контейнерам имена (`--name rtm-<stamp>-<leg>`). Ногу local запускать через setsid, чтобы у неё была своя группа процессов. Поставить trap на INT TERM, который делает `kill -- -$pgid` для каждой ноги, `docker rm -f` по именам и `exit 130`. Одного `kill $(jobs -p)` мало: он убивает подоболочку, но не php-test.sh.

Ещё места: `tasks/matrix.sh:106`, `tasks/matrix.sh:116`, `tasks/matrix.sh:158`.

Источники: `tooling#7`.

### O6

**Нога prod-kinozal проверяет отсутствие iconv только до require, дальше обработчик работает с заглушкой TestLib** · P3 · слабый тест · коммит fb0d8711 · голоса 2/2 · [tasks/matrix.sh:111](../../tasks/matrix.sh#L111)

Строка 111 проверяет `function_exists("iconv")` до require. Сразу после этого KinozalHandlerTest включает TESTLIB_HANDLER_STUBS, и TestLib.php:805-807 объявляет заглушку iconv(): функции нет, а в disable_functions она не указана. В образе function_exists('iconv') до require даёт 0, после — 1. Поэтому все кейсы внутри процесса гоняют обработчик Kinozal уже с «iconv». Настоящий случай без iconv один: дочерний `php -n -d disable_functions=iconv` в KinozalHandlerTest.php:1005. Он выполняется в каждой ноге и проходит только ветки «деталей нет» и download после challenge. Мутация на копии: в kinozal.php:386 (сравнение хеша на ветке UPTODATE) вызов `@iconv('CP1251','UTF-8',$details)`. Результаты: нога prod-kinozal в нынешнем виде 52/0, local 52/0, ноги 7.4 и 8.1 зелёные, матрица пишет last-green. Та же нога с `-d disable_functions=iconv` даёт «52 tests, 19 failures» с «Call to undefined function iconv()». В продакшн-образе такая регрессия роняла бы проверку каждой актуальной раздачи Kinozal. Сверх ноги local нога добавляет только ворота NOT CHECKED (132-137), а они проверяют сам TestLib, а не обработчик. Строки 7 и 9 («no-iconv Kinozal handler suite») и FIX-CHECK-RECHECK2-5eba5135.md:81 обещают больше, чем проверяется.

**Как исправить.** Запускать ногу с `-c tests/php-test.ini -d disable_functions=iconv`: тогда TestLib заглушку не ставит. На чистом HEAD это 52/0, на мутации 19 падений. Если так не делать, честно переписать строки 7 и 9: нога проверяет набор на PHP 8.5 продакшн-образа, а не «без iconv».

Ещё места: `tasks/matrix.sh:7`, `tasks/matrix.sh:9`, `tests/plugins/rutracker_check/TestLib.php:805`, `tasks/2026-09-12-app-log-findings/FIX-CHECK-RECHECK2-5eba5135.md:81`.

Источники: `tooling#1`.

### O7

**Полноту экспорта и число прогнанных файлов никто не проверяет: частичный экспорт даёт зелёные ноги и last-green** · P3 · слабый тест · коммит fb0d8711 · голоса 2/2 · [tasks/matrix.sh:131](../../tasks/matrix.sh#L131)

В основном скрипте нет pipefail (он есть только в digest(), строка 47). Статус конвейера экспорта (92-93) и `cp -al` (100) не проверяется. Счёт `files` на строке 131 только печатается: на строке 140 зелёным ногу делают `code=0` и `fails=0`. Положительный счёт тестов требуется только от prod-kinozal (132-137). Полная потеря экспорта и так краснеет: у local падает cd, докер-ноги не находят php-test.sh. Зелёной проходит частичная потеря. tests/php-test.sh распаковывается раньше tests/php/ и tests/plugins/. Если часть каталогов с *Test.php не распаковалась, find в php-test.sh пишет ошибку в stderr, её не ловит ни один шаблон строки 130, и скрипт выходит с 0. Файл, созданный tar, но пустой из-за ENOSPC, тоже проходит с exit 0. Проверено на копии: подменённый tar -xf вернул 2 и удалил tests/php и tests/plugins, а `matrix.sh local` показал files=0 и «green», exit 0. digest считается по рабочему дереву, поэтому before == after и пишется last-green, если prod-kinozal уцелел. Достижимость низкая (нужен транзиентный ENOSPC или EIO в ~/.cache), последствие — один пропущенный хуком прогон, CI и прод не затронуты. Это тот же класс «пустой прогон выглядит зелёным», который NEW-B закрыл только для одной ноги.

**Как исправить.** Добавить `set -o pipefail` и проверять статус обоих tar и `cp -al`. Сверять экспорт с рабочим деревом, например тем же digest по export_dir против before. Сверка files с числом *Test.php внутри того же экспорта недостаточна: она считает уже повреждённую копию. Ожидаемое число брать из $root через git ls-files.

Ещё места: `tasks/matrix.sh:92`, `tasks/matrix.sh:100`, `tasks/matrix.sh:140`.

Источники: `sig-no-answer-treated-as-answer#3`.

### O8

**Красный прогон на том же дереве не отзывает записанный last-green** · P3 · дефект · коммит fb0d8711 · голоса 2/2 · [tasks/matrix.sh:145](../../tasks/matrix.sh#L145)

Ветка «NOT green» (строка 155) маркер не трогает. Сценарий: полная матрица была зелёной на дереве D. Повторный запуск на том же D красный (гонка вроде «5 из 8» из AGENTS.md, обновлённый образ, нехватка места). Скрипт печатает «NOT green», а хук при `git commit` сообщает «PHP suite already green for this exact tree» и пропускает прогон вопреки последнему измерению. Так же ведёт себя красный выборочный запуск (`matrix.sh 7.4`) на том же дереве.

**Как исправить.** При status != 0 удалять маркер, если в нём тот же digest: `[ "$(sed -n 1p "$marker" 2>/dev/null)" = "$after" ] && rm -f "$marker"`. Делать это и для выборочных запусков: красный результат на этом дереве должен отменять пропуск.

Ещё места: `tasks/matrix.sh:155`.

Источники: `tooling#2`.

### O9

**Фикстуру LISTMETHODS пересняли на 946 имён, а JS-спека по-прежнему требует 982: job jest покраснеет** · P3 · регресс · коммит 74aea456 · голоса 2/2 · [tests/js/rtorrent.spec.js:262](../../tests/js/rtorrent.spec.js#L262)

74aea456 переснял heredoc LISTMETHODS в tests/php/RtorrentCompatibilityTest.php с голого rtorrent 0.16.22 без ruTorrent. Было 982 имени, стало 946, размер закреплён на :1332-1333. tests/js/rtorrent.spec.js читает тот же heredoc регулярным выражением (:237) и требует ровно 982 (:262 toHaveLength, :266 размер Set). Поэтому тест 'uses a complete unique sorted shared daemon-method fixture' падает: Expected length 982, Received 946. На origin/master jest зелёный (338/338). Job jest в .github/workflows/tests.yml:40-51 покраснеет на первом же push. tasks/matrix.sh JS не гоняет и этого не видит. Комментарий :229-230 ложен дважды: «982-name» и «0.16.20 daemon». Размер одной фикстуры записан в трёх местах на двух языках. Отдельно: тот же job упадёт и на js/lang.spec.js, потому что в plugins/loginmgr/lang/*.js нет ключа accOriginRequired (добавлен в 156c64af). Одной правки 982→946 для зелёного CI мало. Перемерено 2026-09-25. Адресный запуск `npx jest --runInBand js/rtorrent.spec.js js/lang.spec.js` на HEAD дал 2 failed suites и 2 failed tests, 29 из 31 прошли; `lang.spec.js` перечисляет 24 файла без ключа. Полный `npx jest --runInBand` на HEAD: 2 failed suites из 24, 2 failed tests, 336 из 338 прошли, других падений нет. На экспорте `74aea456` полный прогон дал 1 failed (`rtorrent.spec.js`), 337 из 338 прошли: там ключа нет ни в одном языке, и `lang.spec.js` зелёный. На экспорте `origin/master` зелёные все 338 из 338.

**Как исправить.** В JS оставить проверки на непустоту, уникальность и сортировку, а точный размер держать только в RtorrentCompatibilityTest.php:1332. Комментарий :229-230 переписать без числа и версии. Параллельно добавить accOriginRequired во все языковые файлы loginmgr.

Ещё места: `tests/js/rtorrent.spec.js:266`, `tests/js/rtorrent.spec.js:229`, `tests/php/RtorrentCompatibilityTest.php:1332`.

Источники: `dup-tests#0`.

### O10

**testInverseHashBatchOrderNeverDeadlocksAndKeepsExactOutcomes не проверяет ни двойное стирание, ни порядок блокировок** · P3 · слабый тест · старый долг · коммит fb0d8711 · голоса 2/2 · [tests/plugins/erasedata/RemoveWithDataTest.php:11073](../../tests/plugins/erasedata/RemoveWithDataTest.php#L11073)

Кейс запускает детей через runChildren() и не играет планировщик, поэтому подтверждения нет. Оба producer уходят в no-ack rollback (removewithdata.php:3025-3035), d.erase не отправляется, и утверждение 11073-11074 «no hash is erased twice» сравнивает 0 с 0. Упасть оно может только тогда, когда продукт стирает без ack, а это уже напрямую проверяют 11357/11369/11423/11609. Инвариант в `$invariant` (11043-11045, «the exact canonical set is admitted once») не проверяется: на деле набор допущен дважды (journal=2, pending=6). Под runChildrenScheduled() фикстура стирает каждый хэш дважды ([A,B,C,A,B,C]) даже при корректном продукте: drainScript всегда отвечает d.get_base_path, фейковый демон стёртое не забывает. Имя обещает и канонический порядок блокировок, но и его кейс не закрепляет. Если снять сортировки в pending.php:353 и removewithdata.php:1982, по отдельности или вместе, кейс остаётся зелёным (3 из 3): producer'ы не пересекаются по времени, а несортированную запись отбрасывает валидатор ('journal-write ... admission-refused'). Кейс существовал на origin/master. Диапазон добавил shortenAcknowledgementWait() (11048) и преамбулу 10850-10855, которая относит кейс к setup для batch race.

**Как исправить.** Вариант «оба батча укладываются в бюджет, записи журнала отсортированы» ничего не закрепит. Для настоящей проверки deadlock нужно принудительно чередовать захват блокировок: держать блокировку одного хэша, пока оба producer не начнут ждать. Двойное стирание проверять с живым планировщиком и демоном, у которого стёртый торрент исчезает (AggregateEraseFixture). Если переделывать не будут, переименовать кейс и переписать `$invariant` под то, что закреплено на деле, а утверждение про erase заменить явным `count($erased) === 0` с пометкой «no-ack путь».

Ещё места: `tests/plugins/erasedata/RemoveWithDataTest.php:11043`, `tests/plugins/erasedata/RemoveWithDataTest.php:10850`.

Источники: `waits#1`.

### O11

**Раздел о скорости набора: приписка «rest of the table still holds» ложна, вывод про параллелизм устарел** · nit · заявление расходится с кодом · коммит fb0d8711 · голоса 1/1 · [AGENTS.md:287](../../AGENTS.md#L287)

Цифры на 287 верны (29 с, 36 с, прогон около 90 с; последний matrix.sh дал local около 88 с на 84 файлах). Ложна приписка «The rest of the table still holds»: пункт «Two more courtesies, 9 s» (324), добавленный тем же диапазоном, снимает около 9 с как раз со строки «the other 79 files ~35», теперь там около 26 с. Устарели и остальные числа таблицы: 81 файл (сейчас 84), доли 59/24/17 %, итог 201 с, заголовок на 276 («200 Seconds, And Two Files Are 83%»). «Two things follow» (291) вводит пять пунктов. Пункт 297-301 («about 118 seconds instead of 201», «Splitting them is what would let parallelism bite») опирается на самый медленный файл в 118 с, а теперь это около 36 с, и рычагом оказались ожидания, а не разбиение файлов. matrix.sh этому пункту не противоречит: он распараллеливает версии PHP, файлы внутри ноги идут последовательно.

**Как исправить.** Обновить таблицу и заголовок: около 29, 36 и 26 с, итого около 90 с на 84 файлах. Убрать приписку. «Two things follow» заменить на фактическое число пунктов. Пункт про параллелизм пометить как исторический и дать новую границу, самый медленный файл около 36 с. На строке 351 «bounded by the slowest file» заменить на «bounded by the slowest leg».

Ещё места: `AGENTS.md:276`, `AGENTS.md:291`, `AGENTS.md:299`, `AGENTS.md:324`, `AGENTS.md:351`.

Источники: `sig-stale-replacement-comment#6`.

### O12

**php:7.4-cli названа «the CI floor», но совпадает с CI только по версии PHP** · nit · заявление расходится с кодом · коммит fb0d8711 · голоса 1/1 · [AGENTS.md:349](../../AGENTS.md#L349)

В CI (shivammathur/setup-php, tests.yml:19-24) iconv, json и mbstring ставятся как shared-модули, и `php -n` их, по устройству этой сборки, выгружает. Это вывод, раннер CI с `php -n` не запускался. В php:7.4-cli и php:8.1-cli они вкомпилированы статически: там `php -n` сохраняет iconv=json=mbstring=true (для 7.4 перемерено 2026-09-25). Поэтому кейс 'CP1251 fixture conversion rejects the no-extension stand-in unchanged result' (KinozalHandlerTest.php:1089-1101) на ногах 7.4 и 8.1 печатает «NOT CHECKED: iconv is built in» и засчитывается как ok, а в CI реально проверяет guard. Зелёная нога 7.4 для этого случая CI floor не повторяет. На пропуск хука это не влияет: случай выполняют локальная нога и prod-kinozal (tasks/matrix.sh:132-137).

**Как исправить.** В AGENTS.md:349-350 написать, что php:7.4-cli совпадает с CI floor только по версии: iconv, json и mbstring там встроены, поэтому случай с `php -n` без iconv на этой ноге не проверяется, и его закрывают локальная нога и prod-kinozal. Засчитывать NOT CHECKED как провал на 7.4 и 8.1 нельзя. Можно выводить его как предупреждение.

Ещё места: `AGENTS.md:350`, `tests/plugins/rutracker_check/KinozalHandlerTest.php:1089`.

Источники: `portability-claims#9`.

### O13

**Пункт про заглушку iconv() в TestLib описывает состояние до af56990f** · nit · заявление расходится с кодом · коммит fb0d8711 · голоса 1/1 · [AGENTS.md:465](../../AGENTS.md#L465)

Пункт появился в fb0d8711, то есть позже af56990f. Первая фраза верна: TestLib.php:805-819 объявляет заглушку iconv(). Вывод устарел: «зелёный прогон обработчиков в образе не доказывает CP1251-ветку, ветка видела подделку». В HEAD ни kinozal.php, ни tapocheknet.php iconv не вызывают, маркеры записаны литеральными байтами (`*_CP1251`), слова «Поглощено» в обработчиках нет. strictCp1251() (TestLib.php:870-880) фикстуры больше не используют, его вызывает только самопроверка KinozalHandlerTest.php:1087-1101. Выходит, заглушка и strictCp1251 нужны лишь тестам о самих себе. Работу без iconv доказывает кейс KinozalHandlerTest.php:1005 в дочернем PHP. Пункт противоречит статусу tasks/2026-09-12-iconv-missing/README.md:8. Тот же устаревший тезис повторяет комментарий KinozalHandlerTest.php:999-1001.

**Как исправить.** Переписать пункт: CP1251-ветки обработчиков от iconv не зависят, заглушка осталась только для strictCp1251 и его самопроверок, отсутствие iconv проверяет кейс KinozalHandlerTest в дочернем PHP. Другой вариант — удалить заглушку, strictCp1251 и две самопроверки вместе с пунктом. Заодно поправить комментарий KinozalHandlerTest.php:999-1001.

Ещё места: `tests/plugins/rutracker_check/TestLib.php:805`, `tests/plugins/rutracker_check/TestLib.php:870`, `tests/plugins/rutracker_check/KinozalHandlerTest.php:999`.

Источники: `sig-stale-replacement-comment#3`.

### O14

**Сокет фикстуры лежит на 49 байт ниже TMPDIR, а не на 48** · nit · комментарий · коммит fb0d8711 · голоса 2/2 · [tasks/matrix.sh:43](../../tasks/matrix.sh#L43)

В комментариях matrix.sh:26 и :43 («107 - 48») и в AGENTS.md:355-356 сказано «48 bytes below». На деле '/rutorrent-scgi-' 16 + uniqid('', true) 23 + '/scgi.sock' 10 = 49 (SCGITransportFixture.php:134,143). Отказа из-за этого нет: TMPDIR длиной 59 байт ничего не ломает. PHP одинаково усекает путь в bind() и в connect(), и SCGITransportTest при TMPDIR 58, 59, 60, 66 и 69 даёт 0 провалов. Четыре провала «SCGI fixture peer exited: server:» появляются только на 67-68 байтах, когда после усечения остаётся ровно путь каталога, а этот диапазон бюджет 59 и так отсекает. Защита консервативна. Неверны только число и формулировка, будто отказ начинается сразу за бюджетом.

**Как исправить.** Заменить 48 на 49 в комментариях и в AGENTS.md. Можно поставить tmpdir_budget=58 с пояснением «107 - 49» и уточнить, что усечение ломает фикстуру, только когда от пути остаётся сам каталог.

Ещё места: `tasks/matrix.sh:26`, `AGENTS.md:356`.

Источники: `tooling#4`, `portability-claims#3`.

### O15

**prod-kinozal запускает php85 без php-test.ini, и Deprecated проходит молча; шаблон отказов скопирован из php-test.sh** · nit · слабый тест · коммит fb0d8711 · голоса 2/2 · [tasks/matrix.sh:107](../../tasks/matrix.sh#L107)

Нога вызывает php85 образа с его /etc/php85/php.ini: error_reporting=22527 (E_ALL без E_DEPRECATED), display_errors выключен, zend.assertions=-1. Обработчик TestLib (TestLib.php:273-274) превращает в провал только биты из error_reporting(). Мутация `strlen(null)` в download_torrent даёт в этой ноге exit=0 и «52 tests, 0 failures», а с `-c tests/php-test.ini` — exit=1 и 48 провалов. Это расходится с php-test.ini («these settings and no others»). Матрицу в целом мутация не зеленит: её ловит нога local. Незамеченным остался бы только Deprecated на пути, который существует лишь без iconv, а такого пути в kinozal.php на HEAD нет. Поэтому дефект латентный, и ослаблен только фокусный запуск `matrix.sh prod-kinozal`. Регулярное выражение отказов в строке 130 дословно копирует tests/php-test.sh:48: расширение одного не дойдёт до другого.

**Как исправить.** Добавить `-c tests/php-test.ini` перед `-r` (вместе с `-d disable_functions=iconv` из O6). Признак отказа держать в одном месте, например в переменной или файле, который читают и php-test.sh, и matrix.sh.

Ещё места: `tasks/matrix.sh:110`, `tasks/matrix.sh:130`, `tests/php-test.sh:48`.

Источники: `tooling#3`.

### O16

**Уборка по маске `2*` перестанет работать в 2030 году, ранние выходы оставляют пустые каталоги, `mkdir -p "$run"` мёртв** · nit · странная логика · коммит fb0d8711 · голоса 1/1 · [tasks/matrix.sh:158](../../tasks/matrix.sh#L158)

stamp строится через `%y` (строка 74). С 2030-01-01 имена начнутся с «30…», маска `2*` на строке 158 их не найдёт, и старые запуски (экспорт, четыре дерева, TMPDIR с фикстурами) перестанут удаляться. Ранние выходы `exit 2` (81) и `exit 1` (86) идут после `mktemp -d` на строке 76 и до уборки, поэтому оставляют пустой каталог. Он занимает одно из трёх мест «самых новых» (сортировка по mtime) и вытесняет настоящий прогон вместе с журналами, которые обещает сохранить сообщение «logs under $run». Оба случая воспроизведены на копии. `mkdir -p "$run"` на строке 84 мёртв: каталог уже создал mktemp -d, а при его ошибке скрипт выходит.

**Как исправить.** Заменить маску на `[0-9]*` или на точную из 12 цифр и `.??????`. `%y` оставить: `%Y` удлинит TMPDIR prod-kinozal с 57 до 59 байт, ровно до бюджета. Проверку бюджета перенести до mktemp -d (см. O4). При отказе digest делать `rmdir "$run"`. Строку 84 удалить.

Ещё места: `tasks/matrix.sh:74`, `tasks/matrix.sh:81`, `tasks/matrix.sh:84`, `tasks/matrix.sh:86`.

Источники: `tooling#8`, `dead-strange#9`.

### O17

**Докблок shortenAcknowledgementWait(): «An acknowledgement case does not call this helper» противоречит именам вызывающих кейсов** · nit · комментарий · коммит fb0d8711 · голоса 1/1 · [tests/plugins/erasedata/CollectorFixture.php:1245](../../tests/plugins/erasedata/CollectorFixture.php#L1245)

Helper вызывают testHeldSchedulerLockStillLetsTheChildAcknowledgeFirst (RemoveWithDataTest.php:11196) и testTheDrainWorkerNeverHoldsTheStateLockWhileWaitingForAHashLock (10332, утверждает acknowledged === generation). Оба кейса про подтверждение и по имени, и по инварианту. Код следует другому правилу, и оно уже точно записано в преамбуле RemoveWithDataTest.php:10850-10855. Кейсы, где producer'у отвечают внутри его окна через runChildrenScheduled() или acknowledgeOnce(), helper не вызывают и сохраняют 11 с. В девяти кейсах-подготовках producer остаётся без ответа, а подтверждение потом пишет worker, которого кейс запускает сам. Фраза 1241-1242 про команду для планировщика rTorrent буквально верна и безвредна: эту команду не исполняет ни один тест, а константу читает только путь producer'а (removewithdata.php:2673-2674).

**Как исправить.** Заменить фразу на 1245-1246 правилом из преамбулы: «не вызывать в кейсах, где producer'у отвечают внутри его окна (runChildrenScheduled()/acknowledgeOnce())». Фразу 1241-1242 можно уточнить или оставить.

Ещё места: `tests/plugins/erasedata/CollectorFixture.php:1241`, `tests/plugins/erasedata/RemoveWithDataTest.php:10850`.

Источники: `waits#3`.

### O18

**Предикат «acknowledged === generation, generation не нулевой» переписан вручную пять раз** · nit · дублирование · коммит fb0d8711 · голоса 1/1 · [tests/plugins/erasedata/RemoveWithDataTest.php:10371](../../tests/plugins/erasedata/RemoveWithDataTest.php#L10371)

Диапазон заменил прежнюю слабую проверку `isset($state['acknowledged'])` одним и тем же четырёхчастным условием (state — массив, generation и acknowledged заданы, generation не нулевой, acknowledged === generation) в четырёх местах: 10371-10373, 11107-11109, 11278-11280, 12387-12389. Пятая копия была в acknowledgeOnce() (10969-10971) ещё до диапазона. Все пять стоят рядом с хелпером mirrorState(). Если формат drain-state изменится, править придётся пять копий. testHeldSchedulerLockStillLetsTheChildAcknowledgeFirst (11214-11215) проверяет старую форму `acknowledged !== '0000000000000000'`. Для одного поколения она корректна, это прямо сказано в комментарии acknowledgeOnce() (10944-10949), так что здесь несогласованность, а не ошибка.

**Как исправить.** Вынести приватный хелпер, например `stateAcknowledgesItsGeneration($state)`, рядом с mirrorState() и вызывать его во всех пяти местах. Кейс held-scheduler при желании перевести на него же.

Ещё места: `tests/plugins/erasedata/RemoveWithDataTest.php:11107`, `tests/plugins/erasedata/RemoveWithDataTest.php:11278`, `tests/plugins/erasedata/RemoveWithDataTest.php:12387`, `tests/plugins/erasedata/RemoveWithDataTest.php:10969`, `tests/plugins/erasedata/RemoveWithDataTest.php:11214`.

Источники: `waits#4`, `dup-tests#7`.

### O19

**Комментарий обещает, что stderr входит в вердикт, а проверяется только код выхода** · nit · комментарий · коммит fb0d8711 · голоса 2/2 · [tests/plugins/erasedata/RemoveWithDataTest.php:10797](../../tests/plugins/erasedata/RemoveWithDataTest.php#L10797)

В testAShortenedWaitReachesAChildUnderARootWithIniMetacharacters (добавлен в fb0d8711) комментарий 10794-10796 заканчивается словами «so the exit code and stderr are part of it». Утверждение 10797-10799 проверяет только `$code === 0`, а `$producer->err` попадает лишь в текст сообщения. Мутация на копии: удалён guard `if(!defined('ERASEDATA_DRAIN_ACK_TIMEOUT'))` (removewithdata.php:1447). Все утверждения кейса и весь файл остаются зелёными (2910 Passed, EXIT=0), ожидание 1.03 с, а в строке «Passed» напечатано «PHP Warning: Constant ERASEDATA_DRAIN_ACK_TIMEOUT already defined». Последствий почти нет: на PHP 7.4–8.5 побеждает define из prepend, продакшн константу заранее не определяет, сценарий «подождал, поставил в очередь и умер» ловится кодом выхода (фатальная ошибка даёт 255). На PHP 9 поломка будет громкой.

**Как исправить.** Убрать из комментария обещание про stderr. Либо добавить точечную проверку, что в `$producer->err` нет «ERASEDATA_DRAIN_ACK_TIMEOUT» вместе с «already defined», и передать child `-d log_errors=1`. Строгое `=== ''` не подходит: php() запускает child без -c php-test.ini, и шум conf.d хоста его уронит.

Ещё места: `tests/plugins/erasedata/RemoveWithDataTest.php:10794`, `plugins/erasedata/removewithdata.php:1447`.

Источники: `waits#0`.

### O20

**testProducerAndWorkerRaceKeepOneScheduleAndOneWorker обещает «one active worker», но допуск worker не проверяет** · nit · слабый тест · старый долг · коммит fb0d8711 · голоса 1/1 · [tests/plugins/erasedata/RemoveWithDataTest.php:11081](../../tests/plugins/erasedata/RemoveWithDataTest.php#L11081)

Имя и `$invariant` (11080-11082) обещают один активный worker. Тело (11083-11111) проверяет только три вещи: ключ drain в scheduleLog один и не больше двух регистраций, ack совпадает с поколением, бюджет соблюдён. После перехода на runChildrenScheduled() (11090, fb0d8711) вместе с явным worker работают до двух тиков планировщика. Мутация на копии: в erasedataAcquireDrainPassLock для ERASEDATA_DRAIN_WORKER_LOCK_NAME пропущен flock. Кейс остаётся зелёным 3 из 3 (0.082 с). Мутацию ловят testAStaleWorkerOwnerIsClassifiedWithoutBreakingItsLock (10080), testDrainCollectsPayloadAndRetiresBeforeReleasingPassLocks (214) и testGlobalLockBypassWouldShowOverlapInSharedRecovery (12280). Имя и инвариант были такими ещё до диапазона. Детерминированно проверить допуск здесь нельзя: кейс длится около 0.08 с, и перекрытие детей не гарантировано.

**Как исправить.** Убрать «one active worker» из имени и инварианта и сослаться на кейсы 10080 и 12280, которые закрепляют единственность допуска.

Ещё места: `tests/plugins/erasedata/RemoveWithDataTest.php:11077`, `tests/plugins/erasedata/RemoveWithDataTest.php:10080`, `tests/plugins/erasedata/RemoveWithDataTest.php:12280`.

Источники: `waits#6`.

### O21

**Сообщение «a real guarded child acknowledged, so the shared recovery really ran» делает неверный вывод** · nit · комментарий · коммит fb0d8711 · голоса 1/1 · [tests/plugins/erasedata/RemoveWithDataTest.php:12390](../../tests/plugins/erasedata/RemoveWithDataTest.php#L12390)

fb0d8711 ужесточил условие на 12387-12389 в testGlobalLockBypassWouldShowOverlapInSharedRecovery, а сообщение оставил прежним (оно было уже на origin/master, base:12300). Вывод в нём перевёрнут. erasedataDrainWorkerMain() пишет подтверждение на шаге (1), removewithdata.php:4876, до допуска worker на шаге (2), 4885-4887. Проигравший ребёнок с worker-busy тоже подтверждает поколение, ничего не восстановив. Из восстановления следует подтверждение, но не наоборот. Восстановление закрепляют строки 12377-12383 (d.get_base_path по каждому хэшу, отсутствие .pending и .tmp).

**Как исправить.** Заменить сообщение формулировкой соседних кейсов (11868, 12024, 12165): 'a real guarded child acknowledged the exact generation the producer armed'. Удалять строку не нужно: ужесточённое условие проверяет подтверждение именно этого поколения.

Ещё места: `tests/plugins/erasedata/RemoveWithDataTest.php:12387`, `plugins/erasedata/removewithdata.php:4876`.

Источники: `waits#5`.

### O22

**Имя testTheShippedWaitsAreFiveSecondsAndAQuarter читается как одно ожидание в 5¼ с** · nit · соглашения · коммит fb0d8711 · голоса 1/1 · [tests/plugins/retrackers/UpdateTest.php:6188](../../tests/plugins/retrackers/UpdateTest.php#L6188)

«Five seconds and a quarter» по-английски обычно значит одно ожидание длиной 5,25 с, а такого значения в коде нет. Тест закрепляет два значения: RETRACKERS_TEARDOWN_TIMEOUT = 5 с и RETRACKERS_RECEIPT_POLL = 0,25 с (plugins/retrackers/update.php:9-12). Правильные числа стоят в сообщении проверки (6203), так что читателю это стоит секунды, ошибкой не является.

**Как исправить.** Необязательно. Если трогать файл по другой причине, переименовать по образцу соседей, которые называют каждую константу по её роли, например в testTheShippedTeardownIsFiveSecondsAndThePollAQuarterSecond. Поменять имя в двух местах: 6188 и список `$added` на 11433. Guard сравнивает имена, количество не меняется.

Ещё места: `tests/plugins/retrackers/UpdateTest.php:11433`.

Источники: `waits#7`.

### VO-NEW-1

**Уборка `ls -1dt "${base}"/2* | tail -n +4 | xargs -r rm -rf` удаляет каталог ещё идущего прогона, и тот кончается ложным `code=99`** · P3 · дефект · коммит fb0d8711 · найдено сверкой 2026-09-25 (VERIFY-O) · [tasks/matrix.sh:158](../../tasks/matrix.sh#L158)

Уборка оставляет три каталога с самым новым mtime и не отличает законченный прогон от идущего. mtime каталога прогона меняется, только пока в нём создаются записи (экспорт и каталоги ног, строки 90-100). Поэтому долгий прогон, начатый раньше, выглядит старше трёх коротких, начатых позже, и третий из них, завершаясь, удаляет его дерево, TMPDIR и журнал. Старый прогон читает `exit` из удалённого каталога, получает `code=99` (строка 129) и печатает «NOT green».

Воспроизведено дважды. Проверяющий запустил в мини-репозитории четыре параллельных `matrix.sh local` ([VERIFY-O-25a0e2cb.md](VERIFY-O-25a0e2cb.md)). При разборе 2026-09-25 то же повторено на копии `tasks/matrix.sh` с заглушкой `php-test.sh`: первый `matrix.sh local` спал 12 с, три следующих по 1 с. Три коротких дали `green`, первый вывел `line 103: …/local/exit: No such file or directory` и `local 99`, затем `NOT green` и exit 1; его каталога после этого нет.

Ложного зелёного нет, прогон повторяется, прод и CI не затронуты. Но несколько одновременных прогонов при работе агентов — обычное дело (AGENTS.md, «Concurrent agents contaminate each other's runs»), а красный результат без журнала читается как дефект кода. Находка уже упомянута в CHECK-FIX (строка NEW-B), в O16 не попала.

**Как исправить.** Удалять только законченные прогоны. Например, прогон держит `flock` на `$run/.lock` до выхода, а уборка пропускает каталоги, чей lock занят (`flock -n`), или прогон помечает каталог файлом `done` в конце. Либо убрать автоуборку. Дату в имени и порядок по mtime признаком завершения не считать. Маску `2*` поправить вместе с O16.

Ещё места: `tasks/matrix.sh:129`, `tasks/matrix.sh:76`.

Источники: `VERIFY-O-25a0e2cb.md` (VO-NEW-1).

## Отклонённые находки

**XMLRPC-прокси и его двери**

- tests-proxy#8: утверждалось, что строка `$XMLRPCProxyLog = (getenv(...) === '1');` в заглушке conf/config.php (XMLRPCProxyEntrypointTest.php:842) ни на что не влияет. Отклонено: для политики 'unreadable' файл conf/xmlrpc_proxy.php получает chmod 0000, rpc2.php его не читает, и логирование задаёт именно эта строка, так что предложенное удаление тихо убрало бы проверку.
- sig-seam-removal-leftovers#5: утверждалось, что в emitScalarValue() (php/xmlrpc_proxy.php:896-897) условие `!in_array($param['typeTag'], self::$validTypes, true)` никогда не истинно. Факт верен (typeTag пишет только decodeValue() и только из допустимых тегов), но это дешёвая страховка «закрыто при сбое» на пути выдачи доверия, а не дефект, поэтому находка отброшена.
- sig-seam-removal-leftovers#7: утверждалось, что process() с self::$log, log() и зависимостями от FileUtil/rXMLRPCRequest мёртв в продакшене и его надо удалить. Отклонено: у process() действительно нет продакшен-вызывающих, но так было и на origin/master, а диапазон это уже признал в шапке файла (строки 15-19); адаптер держит контрактную фикстуру, так что нового дефекта нет.

**Snoopy, UrlHost, loginmgr и их потребители**

- Утверждалось, что UrlHost::sameOrigin() не учитывает userinfo, поэтому оба вызывающих места (Snoopy::trustsRedirect и YggTorrentAccount::trusts) отдельно дописывают «и без user/pass», а sameOrigin() и isHttpsUpgrade() дублируют разбор URL. Находку отклонили: в Snoopy отказ для цели с userinfo (строки 418-420) — не довесок к sameOrigin(), а предусловие для всех трёх веток доверия, и предложенный перенос проверки в sameOrigin() ослабил бы этот охранник.

## Чего это ревью не установило

1. **XMLRPC-прокси на живом rtorrent 0.16.22 с тем conf/xmlrpc_proxy.php, который реально лежит на томе продакшена.** Главная P1-проблема (d.multicall2 и system.multicall через plugins/httprpc/action.php отвечают 403, измерено на проде 2026-09-24) считается закрытой только по юнит-тестам и фикстурам «формы живого запроса». Никто не прогнал HEAD против настоящего демона и не проверил сочетание нового php/xmlrpc_proxy.php с СТАРЫМ файлом conf/xmlrpc_proxy.php (в нём нет d.directory.base.set и f.set_create_queued/f.set_resize_queued). По записям форка (PROGRESS.md:72-74) файла на томе прода нет, и нынешний startup conf/ не досевает (`rm -rf conf` и симлинк на том). Старая копия появится, если собрать docker-rutorrent `668846e` раньше fast-forward ruTorrent (ARCHITECTURE, поправка 2) или положить файл руками. Кроме того, plugins/httprpc/conf.php больше не подключает conf/xmlrpc_proxy.php и не задаёт умолчания; как это ведёт себя с per-user override conf/users/<user>/plugins/httprpc/conf.php, тоже не проверено. Дверь /RPC2 (rpc2.php, ENABLE_RPC2=true) тоже не проверялась вживую.
   *Как закрыть:* `tasks/rt-lab.sh up` флаг `-e ENABLE_RPC2=true` не передаёт (скрипт делает `docker run -d --name … -p …` без дополнительных аргументов), поэтому контейнер поднять вручную: `docker run -d --name rl-proxy -p 18080:8080 -e ENABLE_RPC2=true ivanshift/rutorrent:latest`, дождаться `/run/rtorrent/rtorrent.sock` и наложить дерево через `tasks/rt-lab.sh sync rl-proxy`. Для /RPC2 нужен заданный `$topDirectory` или `$XMLRPCProxyAllowRootDirectory = true`, иначе rpc2.php отвечает 503 до решения прокси (ARCHITECTURE, шаг 0, п. 4). Затем `git -C /home/dev/Documents/my_projects/ruTorrent show origin/master:conf/xmlrpc_proxy.php > x.php && docker cp x.php rl-proxy:/config/rutorrent/conf/xmlrpc_proxy.php`. Отправить POST сырым XML на /plugins/httprpc/action.php и на /RPC2: `d.multicall2('', 'main', 'd.hash=', 'd.name=', 't.multicall=,t.url=')`, `system.multicall` из d.custom1.set и f.prioritize_first.enable, а также load.raw_start с d.directory.base.set. Ожидается HTTP 200 без fault и ни одной строки `xmlrpc-proxy: rejected` в /tmp/errors.log контейнера, кроме строки про d.directory.base.set, которая должна быть видимой и классифицированной. Повторить без файла conf/xmlrpc_proxy.php и с пустым списком.
2. **Полнота $safeGetters и узкие формы членов system.multicall против реальных клиентов и всех плагинов WebUI.** Списки чтения ($safeGetters) и узкие формы аргументов для членов system.multicall (массив в php/xmlrpc_proxy.php:100-130) составлены по запросам, которые перечислили сами авторы. Их никто не сверил с тем, что на самом деле шлют сторонние клиенты (Sonarr/Radarr, Flood, Transdroid, autodl-irssi) и все плагины WebUI: bundled scheduler, throttle, ratio, seedingtime, trafic, а также geoip2/ratiocolor, которые образ docker-rutorrent тянет при сборке. Здесь действует то же правило AGENTS.md, что и для валидаторов ответов: список, выведенный без реального захвата, отрежет клиента молча, поскольку отказ отдаётся целиком на весь вызов. Частично установлено 2026-09-25 по исходникам Sonarr `v5-develop` и Radarr `develop`: из вызовов их `RTorrentProxy.cs` HEAD отвергает только одиночный `d.views.push_back_unique` с видом `*_imported` ([X3](#x3), P2); остальные `decide()` пропускает, с оговоркой X6 для `d.directory.set` вне `$topDirectory`. Flood, Transdroid, autodl-irssi и плагины WebUI по-прежнему не сверены.
   *Как закрыть:* В rt-lab включить `$rpcLogCalls = true` и `$XMLRPCProxyLog = true`, затем пройти в браузере все действия WebUI: старт/стоп/метки/приоритет файлов/throttle/ratio view/scheduler/настройки сокетов. Подключить Sonarr или Flood к /RPC2, или взять их методы из исходников (например, RTorrentProxy.cs). Выполнить `grep -c 'xmlrpc-proxy: rejected' /tmp/errors.log` и сверить каждое имя метода из журнала запросов с `XMLRPCProxy::safeGetters()` и списком форм. Отдельно: `grep -rhoE '"[dtfp]\.[a-z_.]+=?' plugins/*/init.js js/*.js | sort -u` против того же списка.
3. **Отказ Snoopy следовать межхостовому редиректу на реальных цепочках редиректов.** Snoopy::hasRedirectCredentials() (php/Snoopy.class.inc:438) считает учётными данными любой Set-Cookie в ответе, непустой jar $this->cookies и любое тело запроса. Поэтому межхостовый редирект, будь то apex→www, трекер→CDN, feedburner или downloads→mirror, теперь заканчивается `credential-redirect-refused`, если в ответе был хотя бы Cloudflare `__cf_bm`. Это касается RSS-лент с куками (plugins/rss/rss.php:51), добавления по URL с суффиксом `:COOKIE:`, скачиваний extsearch и запросов rutracker_check. Насколько часто такое случается на настоящих лентах и трекерах, не измерялось: тесты используют синтетические Location и заголовки. Коммит 25a0e2cb сам признаёт, что «conditional cross-host cookie flow» остаётся открытым.
   *Как закрыть:* С разрешения пользователя и без учётных данных собрать для каждого хоста из accounts/*.php (login.php, dl.php), для каждой ленты из share/users/*/settings/rss и для download-URL движков extsearch: `curl -sS -o /dev/null -D - --max-redirs 0 <url>`, сохранив Location и Set-Cookie. Затем в своей копии скормить пары (source, Location, headers) в Snoopy::trustsRedirect() и hasRedirectCredentials() через подкласс с публичными обёртками и перечислить, какие цепочки теперь отказывают, а раньше проходили.
4. **Изменения в обработчиках трекеров без захваченного ответа: AniDUB https, Tapochek cp1251, NNMClub/LostFilm test().** AniDUB теперь принудительно ходит на `https://tr.anidub.com/?newsid=` и строит ссылку скачивания по https (plugins/rutracker_check/trackers/anidub.php:54,69). Что хост отдаёт HTTPS с тем же HTML и ссылкой /engine/download.php, никто не проверял. Для Tapochek cp1251-иглы MISSING_MARKER_CP1251/INFORMATION_CP1251 проверены на фикстуре, собранной перекодированием (SiblingTrackersTest.php:340), а не на захваченных байтах страницы: реальный &nbsp;, байт 0xA0 cp1251 и meta charset не проверены. Дополнение 2026-09-25: сверка сняла анонимную страницу удалённой темы ([verify-c-evidence/tapochek-p7-2026-09-25.html](verify-c-evidence/tapochek-p7-2026-09-25.html)). В ней есть meta charset windows-1251 и нет байта 0xA0, а cp1251-иглы совпадают с текстом ячейки. Но разбор до них не доходит: таблица сообщения вложена во внешнюю, и обработчик отвечает CANT_REACH ([VC-NEW-2](#vc-new-2)). Страница под сессией аккаунта не снималась. NNMClubAccount::test() теперь требует схему https и путь /forum/, а LostFilm::getDownloadId() — точный путь /download.php. Не проверено, в каком виде URL лежат в комментариях и RSS настоящих торрентов, в том числе http:// у старых раздач: такие URL тихо теряют куки аккаунта.
   *Как закрыть:* Только чтение: получить с живого инстанса через безопасный путь чтения `d.multicall2('', 'main', 'd.hash=', 'd.custom2=')` (комментарии) и t.url, выписать различные формы scheme://host/path для nnmclub/anidub/lostfilm/tapochek и прогнать каждую через NNMClubAccount::test(), LostFilmAccount::getDownloadId() и шаблон AniDUB в копии. С разрешения: `curl -sS https://tr.anidub.com/?newsid=<id>` (Tapochek уже снят, см. VC-NEW-2).
5. **Новые тесты и новый код в продакшен-образе (PHP 8.5 без iconv/tokenizer/posix/pcntl).** Матрица tasks/matrix.sh гоняет local, 8.1 и 7.4 плюс одну no-iconv ногу для Kinozal. Новые наборы tests/php/UrlHostTest.php, tests/php/SnoopyTest.php, tests/plugins/loginmgr/CredentialBoundaryTest.php, YggConfigurationTest.php, SiblingTrackersTest.php (Tapochek cp1251) и изменённый XMLRPCProxyTest.php в ivanshift/rutorrent:latest не запускались. Не установлено, что при отсутствии iconv и tokenizer они проходят или падают так же, как на базе. Хендлер-наборы с TestLib полифиллом iconv (TESTLIB_HANDLER_STUBS) доказывают меньше, чем кажется. Также не проверено, работают ли filter_var(FILTER_VALIDATE_DOMAIN) из accounts/YggTorrent.php и proc_open с массивом (новые тесты) на 7.4 и в Alpine.
   *Как закрыть:* Сделать свою копию ~/.cache/rutorrent-tmp/<label>/tree для HEAD и такую же для base. Для каждой: `docker run --rm --user 1000:1000 --network none --entrypoint sh -v <copy>:/w -w /w/tests ivanshift/rutorrent:latest -c 'for f in php/UrlHostTest.php php/SnoopyTest.php php/XMLRPCProxyTest.php plugins/loginmgr/CredentialBoundaryTest.php plugins/loginmgr/YggConfigurationTest.php plugins/rutracker_check/SiblingTrackersTest.php; do echo ">$f"; <как php-test.sh запускает файл, php85>; done'` (TaskTest не трогать). Сравнить множества упавших тестов HEAD и base; любое новое падение, не связанное с tokenizer/posix, считать регрессией.
6. **UI loginmgr и заглушка Ygg в extsearch: не отрисовывались и не проходили node --check.** В plugins/loginmgr/init.js:98-101 появился блок alert для configurationRequired. Ключ accOriginRequired есть только в en.js и ru.js, а английский текст продублирован в init.js как fallback. Ни jest-спека, ни рендера в браузере нет. YggTorrentEngine::action() (plugins/extsearch/engines/YggTorrent.php:97-102) при пустом origin кладёт строку результата с ключом '' и именем «YggTorrent: configure $yggTorrentOrigin». Никто не проверил, как UI extsearch рисует результат с пустой ссылкой и что будет при нажатии «добавить»: пустой URL уйдёт в addtorrent.
   *Как закрыть:* `node --check plugins/loginmgr/init.js plugins/loginmgr/lang/en.js plugins/loginmgr/lang/ru.js`. Затем rt-lab без $yggTorrentOrigin: открыть Settings → Accounts и сделать снимок, убедиться, что предупреждение только у YggTorrent и есть на языке без ключа (fr). Сделать поиск extsearch по Ygg и попробовать добавить строку-заглушку, следя за запросом к php/addtorrent.php в devtools и за /tmp/errors.log.
7. **Межплагинная зависимость и порядок загрузки конфигурации $yggTorrentOrigin.** extsearch/engines/YggTorrent.php:78-84 теперь делает require_once файлов plugins/loginmgr/accounts.php и accounts/YggTorrent.php. Это нарушает правило подключать файлы чужого плагина только после `isPluginRegistered()` (см. [S8](#s8)); сама интеграция допустима. При удалённом каталоге loginmgr это фатальная ошибка; при плагине, отключённом для пользователя, поведение не определено. accounts.php выполняет `eval(getPluginConf('loginmgr'))` в области метода с `global $yggTorrentOrigin`. Не проверено, видит ли процесс extsearch значение, заданное только в conf/users/<user>/plugins/loginmgr/conf.php или plugins/loginmgr/conf.local.php, и не выйдет ли так, что поиск говорит «configure», а скачивание через loginmgr работает, или наоборот.
   *Как закрыть:* В rt-lab положить `$yggTorrentOrigin='https://ygg.invalid';` поочерёдно в conf/config.php, в plugins/loginmgr/conf.local.php и в conf/users/<user>/plugins/loginmgr/conf.php. Для каждого варианта вызвать поиск Ygg через plugins/extsearch/action.php и loginmgr get (settings bootstrap), сравнить результаты: либо origin, либо 'configure'. Затем отключить loginmgr в conf/users/<user>/plugins.ini и в копии удалить plugins/loginmgr, повторить поиск extsearch и проверить /tmp/errors.log на Fatal.
8. **Мутационная проверка того, что новые тесты безопасности действительно держат поведение.** Ревью читало тесты, но нет свидетельства, что для ключевых новых защит сделана мутация своей копии плюс запуск одного файла, как требует AGENTS.md для поведенческих изменений. Речь о защитах от рекурсии и от доверия непроверенному члену в system.multicall (php/xmlrpc_proxy.php:1153-1164), о trustsRedirect/hasRedirectCredentials (php/Snoopy.class.inc:416-451), о восстановлении user/pass в fetch() через finally и о UrlHost::sameOrigin/isHttpsUpgrade (php/urlhost.php:59,73). Утверждения вида «тест закрепляет X» в 140 находках, скорее всего, опираются на чтение, а не на красный прогон.
   *Как закрыть:* В ~/.cache/rutorrent-tmp/<label>/tree с TMPDIR там же по одной мутации, каждый раз с проверкой на `PHP Fatal error` и на то, что названный тест выполнился: (1) удалить проверку `$innerMethod === 'system.multicall'` и прогнать tests/php/XMLRPCProxyTest.php; (2) заменить `!$innerDecision['trusted']` на `false`, тот же файл; (3) в trustsRedirect() вернуть true до проверки rawheaders, прогнать tests/php/SnoopyTest.php и tests/plugins/loginmgr/CredentialBoundaryTest.php; (4) убрать восстановление `$this->user` в finally, прогнать SnoopyTest.php; (5) в UrlHost::isHttpsUpgrade() разрешить смену порта, прогнать tests/php/UrlHostTest.php. Каждая мутация, после которой всё зелёное, — это находка уровня P3 о тесте.

## Как проводилось

- **Охват:** все 72 не-markdown файла пяти коммитов `origin/master..25a0e2cb` (+5804/−1117), полными файлами. Удалённый `master` совпадает с `origin/master`.
- **Первый проход:** 16 ревьюеров по областям (прокси, безопасность прокси, Snoopy, loginmgr, потребители Snoopy, чекер, трекеры, ожидания, инструменты, три группы тестов) и сквозным темам (дублирование в коде и тестах, мёртвый код и странная логика, переносимость и заявления коммитов). 143 находки, после схлопывания дублей 106.
- **Проверка:** по два скептика на находку P1–P3 (опровержение, достижимость) и один на nit; решение большинством, при 1 из 2 пункт помечен «условно».
- **Второй проход:** 6 ревьюеров искали новые экземпляры классов дефектов, выведенных из подтверждённых находок; повторы отсеяны до проверки.
- **Итог:** 144 проверенные находки, 140 подтверждены, после слияния по областям — 125 пунктов. Всего 278 агентов.
- **Сам:** матрица на HEAD (зелёная), подтверждение красного Jest (`1 failed, 23 passed` в `rtorrent.spec.js`).
- **Данные:** [review-25a0e2cb-evidence/](review-25a0e2cb-evidence/).
- **Сверка 2026-09-25:** независимая проверка и разбор её замечаний изменили уровни X3, S16 и C20 и добавили пять пунктов; итог 130. См. [Поправки после VERIFY-SUMMARY-2026-09-25](#поправки-после-verify-summary-2026-09-25).
