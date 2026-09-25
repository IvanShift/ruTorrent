# Исправление CHECK-RECHECK2-5eba5135

Дата: 2026-09-25. Итог: 44 пункта закрыты локально, `consumers9` оставлен открытым условно. Основа проверки: прежний локальный `master` HEAD `7c4d1804` с существовавшими незакоммиченными правками. Содержание пересобрано в `master` по темам: XMLRPC `74aea456`, Snoopy/loginmgr `156c64af`, tracker checker `af56990f`, матрица и тесты `fb0d8711`. Этот реестр ведёт исправления 45 пунктов версии 2; исходный отчёт остаётся исторической записью проверки.

| Пункт | Исполнитель | Состояние и проверка |
| --- | --- | --- |
| [P1 proxy1](CHECK-RECHECK2-5eba5135.md#p1-proxy1) | proxy | Исправлено: system.multicall допускается только если каждый член trusted; WebUI batch проверен в rTorrent 0.16.22 lab. |
| [P2 proxy2](CHECK-RECHECK2-5eba5135.md#p2-proxy2) | proxy | Исправлено: разрешены только реальные f.set_create_queued/f.set_resize_queued; recreate проверен в lab. |
| [NEW-1](CHECK-RECHECK2-5eba5135.md#new-1) | proxy | Подтверждено исправление safeGetters: raw d.multicall2 отвечает 200 в lab; на проде по прежнему 403. |
| [P3 checker3](CHECK-RECHECK2-5eba5135.md#p3-checker3) | checker | Исправлено: terminal verdict не обновляет chk-time при retry/UNCHANGED; CheckerTest 141/0. |
| [P3 checker4](CHECK-RECHECK2-5eba5135.md#p3-checker4) | checker | Исправлено: idempotent preflight подтверждает запись до handler; исчезнувший hash — no-op; CheckerTest 141/0. |
| [P3 checker7](CHECK-RECHECK2-5eba5135.md#p3-checker7) | checker | Исправлено локально: каждый unclassified 403 получает один download probe для своей темы, следующий topic снова спрашивает details; 4th-topic DELETED/stale download RED 53/1 → GREEN 52/0 на PHP 7.4/8.1/8.5/no-iconv. Прод после выкладки не проверялся. |
| [P3 consumers10](CHECK-RECHECK2-5eba5135.md#p3-consumers10) | Snoopy/loginmgr | Исправлено: credential redirects и ответ origin проверены core Snoopy тестами 25/25. |
| [P3 consumers11](CHECK-RECHECK2-5eba5135.md#p3-consumers11) | Snoopy/loginmgr | Исправлено: граница redirectTrust закреплена CredentialBoundary 14/14. |
| [P3 consumers12](CHECK-RECHECK2-5eba5135.md#p3-consumers12) | Snoopy/loginmgr | Исправлено: учётные данные на редиректах ограничены account trust; CredentialBoundary 14/14. |
| [P3 consumers9](CHECK-RECHECK2-5eba5135.md#p3-consumers9) | Snoopy/loginmgr | Открыто условно: межхостовый 30x с cookies безопасно отклоняется; поддержка требует scoped cookie jar и живого подтверждения маршрута. |
| [P3 loginmgr14](CHECK-RECHECK2-5eba5135.md#p3-loginmgr14) | Snoopy/loginmgr | Исправлено: диагностика Ygg называет все источники конфигурации и причину; UI показывает требование настройки. |
| [P3 loginmgr15](CHECK-RECHECK2-5eba5135.md#p3-loginmgr15) | Snoopy/loginmgr | Исправлено: путь входа NNMClub нормализуется перед исключением; AccountSelection 11/11. |
| [P3 proxy17](CHECK-RECHECK2-5eba5135.md#p3-proxy17) | proxy | Исправлено: cat=$d.views= возвращает строку для ratio; lab сравнил со списком d.views. |
| [P3 snoopy18](CHECK-RECHECK2-5eba5135.md#p3-snoopy18) | Snoopy/loginmgr | Исправлено: поведение redirect refusal и восстановление клиента закреплены в Snoopy 25/25. |
| [P3 snoopy19](CHECK-RECHECK2-5eba5135.md#p3-snoopy19) | Snoopy/loginmgr | Исправлено: credential boundary на редиректах закреплена в Snoopy 25/25. |
| [P3 snoopy20](CHECK-RECHECK2-5eba5135.md#p3-snoopy20) | Snoopy/loginmgr | Исправлено: credential boundary на редиректах закреплена в Snoopy 25/25. |
| [P3 snoopy21](CHECK-RECHECK2-5eba5135.md#p3-snoopy21) | Snoopy/loginmgr | Исправлено: URL userinfo ограничено одним fetch chain; Snoopy 25/25. |
| [P3 snoopy23](CHECK-RECHECK2-5eba5135.md#p3-snoopy23) | Snoopy/loginmgr | Исправлено: lastredirectaddr сбрасывается на новом явном fetch; Snoopy 25/25. |
| [P3 tests24](CHECK-RECHECK2-5eba5135.md#p3-tests24) | checker | Исправлено: Tapochek распознаёт CP1251 без iconv; Sibling 17/0 в no-iconv образе. |
| [P3 tests26](CHECK-RECHECK2-5eba5135.md#p3-tests26) | checker | Исправлено: fresh/expired INPROGRESS действительно проходят lock recovery; UpdatePass 150/0. |
| [NEW-2](CHECK-RECHECK2-5eba5135.md#new-2) | Snoopy/loginmgr | Исправлено: буквальный README snippet проходит php -l; YggConfiguration 13/13. |
| [P3 checker6](CHECK-RECHECK2-5eba5135.md#p3-checker6) | checker | Исправлено: CANT_REACH/ERROR сохраняют forum correction; CheckerTest 141/0. |
| [nit checker29](CHECK-RECHECK2-5eba5135.md#nit-checker29) | checker | Исправлено: комментарий сверён с текущим dispatch. |
| [nit checker30](CHECK-RECHECK2-5eba5135.md#nit-checker30) | checker | Исправлено: переменная и комментарий больше не называют INPROGRESS терминальным. |
| [nit consumers31](CHECK-RECHECK2-5eba5135.md#nit-consumers31) | checker | Исправлено вместе с checker4: исчезнувший hash и неподтверждённая запись дают ранний выход. |
| [P3 loginmgr13](CHECK-RECHECK2-5eba5135.md#p3-loginmgr13) | Snoopy/loginmgr | Исправлено: отсутствие origin видно в UI и серверной диагностике; YggConfiguration 13/13. |
| [nit loginmgr32](CHECK-RECHECK2-5eba5135.md#nit-loginmgr32) | Snoopy/loginmgr | Исправлено: README/диагностика требуют ASCII/punycode host. |
| [nit loginmgr33](CHECK-RECHECK2-5eba5135.md#nit-loginmgr33) | Snoopy/loginmgr | Исправлено: документация указывает once per request. |
| [nit loginmgr34](CHECK-RECHECK2-5eba5135.md#nit-loginmgr34) | Snoopy/loginmgr | Исправлено: контракт источников конфигурации и состояний Ygg уточнён. |
| [P3 portability16](CHECK-RECHECK2-5eba5135.md#p3-portability16) | checker | Исправлено: UrlHost::normalize() для исходящего tracker URL; NNMClub 34/0. |
| [nit portability35](CHECK-RECHECK2-5eba5135.md#nit-portability35) | root | Исправлено: digest стабилен при C/en_US.utf8; пустой/исчезнувший список даёт ошибку. |
| [nit portability36](CHECK-RECHECK2-5eba5135.md#nit-portability36) | root | Исправлено: четыре вызова setAccessible условны; оба файла проходят на PHP 8.5 без Deprecated. |
| [nit portability37](CHECK-RECHECK2-5eba5135.md#nit-portability37) | root | Исправлено: AGENTS.md перечисляет все три дополнительных tokenizer-зависимых файла. |
| [nit proxy38](CHECK-RECHECK2-5eba5135.md#nit-proxy38) | proxy | Исправлено: view-first ограничен d.multicall; профильные proxy тесты прошли. |
| [nit proxy39](CHECK-RECHECK2-5eba5135.md#nit-proxy39) | proxy | Исправлено: лог отказа называет 1-based slot и нормализованный метод; контракт fault сохранён. |
| [nit proxy40](CHECK-RECHECK2-5eba5135.md#nit-proxy40) | proxy | Исправлено: отказ при старом явном allowlist называет canonical spelling и conf/XMLRPCProxySafeParams. |
| [P3 snoopy22](CHECK-RECHECK2-5eba5135.md#p3-snoopy22) | Snoopy/loginmgr | Исправлено: pending Set-Cookie на недоверенном 30x вызывает видимый отказ без передачи cookie; Snoopy 25/25. |
| [P3 tests28](CHECK-RECHECK2-5eba5135.md#p3-tests28) | Snoopy/loginmgr | Исправлено: прямые UrlHost и Snoopy тесты на границы редиректа. |
| [nit tests43](CHECK-RECHECK2-5eba5135.md#nit-tests43) | checker | Исправлено: девятый Snoopy token и тест словаря; FetchError 9/0. |
| [nit tests44](CHECK-RECHECK2-5eba5135.md#nit-tests44) | checker | Исправлено: удалена мёртвая CP1251-константа; Sibling 17/0. |
| [nit tests45](CHECK-RECHECK2-5eba5135.md#nit-tests45) | Snoopy/loginmgr | Исправлено: профильный loginmgr тест закрепляет указанную границу. |
| [nit tests46](CHECK-RECHECK2-5eba5135.md#nit-tests46) | checker | Исправлено: тест согласует SITE_HOSTS, TOPIC_PATTERN и ownerOf; Kinozal 48/0. |
| [NEW-3](CHECK-RECHECK2-5eba5135.md#new-3) | checker | Исправлено: untimed NOT_NEED с superseded не получает free pass; UpdatePass 150/0. |
| [NEW-5](CHECK-RECHECK2-5eba5135.md#new-5) | root | Исправлено: AGENTS.md описывает переменный размер tmpfs. |
| [NEW-4](CHECK-RECHECK2-5eba5135.md#new-4) | root/checker | Исправлено: default матрица включает focused Kinozal в no-iconv образе; нога 48/0 и защищена от NOT CHECKED. |

## Новые крупные находки

- **NEW-A (P2, исправлено):** `tasks/matrix.sh` записывал `last-green` после любой выборочной ноги, включая одну `prod-kinozal`; `.git/hooks/pre-commit` сравнивает только digest и мог пропустить полный PHP-набор. Наблюдался маркер с третьей строкой `prod-kinozal`; он изолирован как `last-green.invalid-focused-20260925`. Теперь маркер пишет только полный запуск матрицы без аргументов. Повторный focused запуск его не создал.
- **NEW-B (P3, исправлено):** новая no-iconv нога могла дать ложный green при `0 tests` или `NOT CHECKED`, а секундный каталог запусков мог пересечься у двух процессов. Добавлены preflight отсутствия `iconv`, положительный счёт тестов, отказ на `NOT CHECKED` и уникальный короткий каталог. Обе мутации с кодом Docker `0` теперь приводят к exit 1 матрицы.

- **NEW-C (P2, исправлено):** account redirectTrust мог разрешить межхостовый переход с URL Basic или сырыми Cookie/Authorization/Proxy-Authorization заголовками, принадлежащими исходному origin. Snoopy теперь не доверяет такой переход; граница закреплена в Snoopy и CredentialBoundary тестах.
- **NEW-D (P3, исправлено):** параллельные `tasks/rt-lab.sh up/sync` делили `/tmp/rt-lab-files.z` и `/tmp/rt-lab.tar`, поэтому один контейнер мог получить экспорт другого. У каждой overlay-операции теперь отдельные архив и список; два параллельных mock sync получили разные архивы и exit 0.

- **NEW-E (P2, исправлено):** при активном `redirectTrust` анонимный межхостовый 30x допускал ответ чужого хоста с `Set-Cookie`; затем Ygg и другие account `login()` переносили эту cookie в плоскую банку перед POST пароля исходному хосту. Воспроизведено RED в CredentialBoundary 15/1; теперь активная account policy отказывает любому недоверенному редиректу, GREEN 15/0. Обычный анонимный Snoopy переход закреплён отдельно.
- **NEW-F (P3, исправлено):** тест загрузчика Ygg всегда задавал origin и не ловил опасную правку поставляемого default. Добавлен fresh-process случай без override; ручная мутация default на произвольный HTTPS origin дала 14/1, восстановленный файл 14/0.

- **NEW-G (P3, исправлено):** три topic-specific unclassified 403 с успешным download не доказывают постоянную стену details. Inferred latch мог скрыть удаление/новый hash следующей темы, если её details уже здоров, а download ещё отдаёт старый metainfo. Независимый review потребовал ограничить fallback одной темой и проверять details снова на следующей; тест на четвёртую тему был RED и после удаления inferred latch стал GREEN.

## Открытая задача по consumers9

Нужна область действия cookie по origin/домену, прежде чем разрешать межхостовый редирект потребителям `plugins/cookies` и `:COOKIE:`. `fetchComplex()` сейчас сливает оба источника в плоскую `$cookies`, а `_httpsrequest()` отправляет всю банку; политика лишь по общему registrable domain утечёт. Критерий: исходная cookie не уходит новому хосту без явной доверенной области, легальный переход Kinozal при наличии cookie работает, а тесты покрывают запрет чужого хоста, cookie цели и сохранение сессии при same-origin. Изолированное анонимное следование без source cookie может быть промежуточным решением, но не восстановит путь, где CDN требует эту cookie. Живой маршрут с авторизованной cookie пока не наблюдался; синтетическая модель не доказывает необходимость такого перехода на проде. Для первого чтения нужен числовой ID действующей публичной темы: один GET без -L, выводить лишь статус и host из Location. Анонимная проба сама по себе не устанавливает, нужна ли cookie на целевом хосте. Секреты не записывать и не выводить.

## Дополнительные мелкие исправления

- Отчёт больше не выводит идентичность **всего** продового дерева из пяти хешей: подтверждены только пять файлов и живое поведение двери XMLRPC.
- `tests/php-test.sh`: комментарий о размере `/tmp` больше не утверждает устаревшее фиксированное число.
- `tasks/matrix.sh`: изменение самого раннера теперь инвалидирует `last-green`; до правки он не входил в digest и старый маркер мог скрыть необходимость нового прогона.
- `plugins/extsearch/engines/YggTorrent.php`: неудача fetch на странице поиска после первой теперь завершает цикл без PHP Warning.

## Проверки и границы

- Для digest до правки получены разные значения при `LC_ALL=C` и `LC_ALL=en_US.utf8`; после правки значения совпали. Подменённый `git ls-files` для пустого/исчезнувшего списка теперь вызывает exit 1 без фиктивного digest.
- На PHP 8.5 до правки четыре `ReflectionProperty::setAccessible()` дали Deprecated; после правки оба тестовых класса выполнились без Deprecated и отказов.
- Профильные proxy тесты прошли на PHP 7.4/8.1/8.5; lab подтвердил реальные WebUI действия и raw read с rTorrent 0.16.22. Блок Snoopy/loginmgr прошёл профильные тесты и lint, checker7 52/0 на четырёх runtime. Первый полный PHP-прогон выявил один тест со старым ожидаемым поведением account redirect (одинаково на трёх PHP); контракт теста исправлен. Второй полный прогон: `local`, PHP 8.1 и PHP 7.4 — по 84 файла, exit 0, 0 failures; `prod-kinozal` без iconv — 1 файл, exit 0, 0 failures. Digest `1d926b226a6faab807ab446e2e8e88e77c3b6803e87c71bf8b28d50a3a4eaecb` совпал с `last-green`. Независимый review checker7 выявил и закрыл NEW-G. Живой прод не менялся.
