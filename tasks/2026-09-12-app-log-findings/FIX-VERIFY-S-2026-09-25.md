# FIX VERIFY S — 2026-09-25

Область: S1–S32 и N-S1/N-S2 из `REVIEW-25a0e2cb.md`, сверенные с `VERIFY-S-25a0e2cb.md` и `CHECK-FIX-CHECK-RECHECK2-5eba5135.md`. Исходные три отчёта не менялись. Исправления лежат в общей рабочей копии; этот файл не означает commit, push, deploy или проверку живого сервиса.

## Результат по каждому пункту

| ID | Статус | Что сделано или точная граница |
|---|---|---|
| S1 | исправлено | Анонимный межхостовый 302 со свежим `Set-Cookie` снова выполняется; cookie источника не импортируется. RED→GREEN в `SnoopyTest`. |
| S2 | исправлено | `fetch()` декодирует URL userinfo через `rawurldecode`; прямой и `linkencode()` пути закреплены. |
| S3 | исправлено | `finally` после вложенного redirect всегда гасит отложенный `_redirectaddr`, включая отказ `checkTarget()`. |
| S4 | исправлено | Один `carriesOwnCredentials()` для обеих веток; тесты с raw Authorization и Proxy-Authorization, с `redirectTrust` и без него. |
| S5 | исправлено покрытие | Отдельные тесты на HTTPS→HTTP и недоверенный исходный URL; обе ослабляющие мутации дают именованный сбой. |
| S6 | исправлено | Тело POST перестало считаться credential; переход идёт как GET без тела. Старый неверный POST вариант в `CredentialBoundaryTest` обновлён. |
| S7 | исправлено | HTTP и HTTPS разбирают `Location:`/`URI:` без пробела; пустой/неразбираемый адрес не превращается в редирект на корень. RED→GREEN на реальном fake-curl транспорте. |
| S8 | исправлено | Ygg extsearch проверяет регистрацию и наличие файлов loginmgr; stale search даёт понятную строку, stale download — `false`. Тесты для отсутствующего каталога и отключённого плагина. |
| S9 | исправлено | `commonAccount::check()` пишет `loginmgr: missing-origin: <Account>` один раз за процесс и не начинает credential flow. |
| S10 | исправлено | `getAccount()` для отвергнутого HTTP URL проверяет HTTPS вариант включённого аккаунта и однократно пишет host-only `http-url-not-authenticated`; query/passkey исключены. `FetchComplexSourcesTest` с реальными файловыми кэшами и настоящим `fetchComplex()` задаёт три разных cookie источника, доказывает отсутствие loginmgr-сессии в HTTP-запросе и её наличие в HTTPS-контроле. Он намеренно не закрепляет отправку cookie двух других источников по HTTP как желательную; N-S1 открыт. |
| S11 | исправлено | NNMClub проверяет нормализованные `/forum/` и `login.php` по одному пути; тесты держат ветку `..` и выход из `/forum/`. |
| S12 | исправлено | Поставляемый `loginmgr/conf.php` теперь комментарий с примером, без исполняемого default. Копии шаблона в local и per-user override проходят RED→GREEN. |
| S13 | исправлено | Причина отказа торрента из RSS item попадает в UI error; используется только фиксированный классифицированный токен. История `Failed` сохранена. Отказ остаётся терминальным и при status 2xx с `Location`; см. NEW-S-2XX. |
| S14 | исправлено покрытие | Fake-curl тест проверяет Basic и на первом HTTPS запросе, и отсутствие на следующем. |
| S15 | минимум исправлен | Сквозной тест на настоящем `_httpsrequest()` и fake curl ловит импорт `Set-Cookie` в чужой запрос; комментарий о двойнике исправлен. Два двойника не объединялись: общая фикстура увеличила бы поверхность без оставшегося поведенческого пробела. |
| S16 | исправлено | Комментарий поясняет, что `shipped default` проверяет отслеживаемый `conf/config.php`. |
| S17 | исправлено | Докблок `UrlHost::urlIsOneOf()` прямо описывает связь account selection с redirect trust. |
| S18 | исправлено | README различает account scope и анонимный переход после S1/S6, описывает ошибки RSS feed/item и HTTP cookie caveat. |
| S19 | исправлено | `commonAccount::withRedirectTrust()` централизует установку и восстановление политики для `fetch()`/`check()`. |
| S20 | исправлено | Один private `configurationRequired()` helper вместо двух проверок по имени Ygg; ленивую загрузку остальных аккаунтов сохранили. |
| S21 | исправлено по смыслу | Общий `queryDigits()` выполняет `parse_str`, выбор последнего повтора и строгую проверку цифр; host/path остаются в конкретных аккаунтах, потому что их правила различаются. |
| S22 | исправлено | Комментарий о разделителях перенесён непосредственно к их проверке. |
| S23 | исправлено | Мёртвая ветка `>65535` удалена; `:65536` остаётся `invalid-url`, `:0` — `bad-port`; `:443x` теперь отвергается как `invalid-url` до построения доверенного origin. |
| S24 | исправлено | Alert виден только у включённого Ygg и меняется сразу при Enabled toggle. Jest тест и точные пути в en/ru. Недостающие ключи остальных языков добавляет параллельный агент в рамках O9. |
| S25 | исправлено покрытие | В `SnoopyTest` добавлены core проверки reset error, depth/import, log latch, Basic и raw headers без аккаунта; тесты loginmgr оставлены для интеграции. |
| S26 | исправлено | `normalize(non-string) === ''` проверяется строго; мёртвый guard пустого кандидата удалён. |
| S27 | исправлено | Удалены мёртвый ключ `ruTrackerAccount` и шесть дублирующих HTTP случаев; общий перебор аккаунтов остаётся. |
| S28 | исправлено | Удалены две проверки, не дававшие сигнал в анонимной цепочке. S1 теперь держит отдельный точный core тест. |
| S29 | исправлено | Комментарий объясняет уникальную пару origin для процессного log latch; core тест использует собственную пару. |
| S30 | исправлено | Userinfo Ygg перенесён в единый список недоверенных URL в `CredentialBoundaryTest`; три одинаковых setup менеджера заменены `yggManager()`. |
| S31 | исправлено | Ygg набор направляет журнал в свой `TMPDIR`; тест сбрасывает и восстанавливает private static latch через ReflectionProperty, больше не зависит от порядка. |
| S32 | исправлено | `Snoopy::CREDENTIAL_REDIRECT_REFUSED` используют Snoopy, оба RSS пути и mock; выходные строки остаются прежними. |
| N-S1 | **открыто, P2, старый долг** | Cookie плагина `cookies` хранятся как `host => name/value`, без scheme/Secure. Минимальный запрет на HTTP при известном HTTPS аккаунте меняет работающий контракт: ручная запись `rutracker.org|sid=...` и ссылка `http://rutracker.org/forum/dl.php?t=42` сейчас отправляют cookie, а после запрета станут гостевым запросом. Из записи нельзя установить, была ли HTTP отправка намеренной. Нужны решение о старых записях и формат/политика Secure; код плагина за пределами назначенной области. `:COOKIE:` остаётся отдельным явным источником. Живой риск не измерялся. |
| N-S2 | принятое поведение | Условные валидаторы анонимного запроса могут повторяться после межхостового redirect; это записано в комментарии Snoopy и core тесте. Идентифицирующий реальный ETag не предъявлен; срезание заголовков сейчас не обосновано. |
| NEW-S-IMS | исправлено, старый дефект | RSS отправлял нестандартный `If-Last-Modified` вместо `If-Modified-Since`. Проверено по [RFC 9110 §13.1.3](https://www.rfc-editor.org/rfc/rfc9110.html#section-13.1.3); RED→GREEN в `RSSTest`. |
| NEW-S-LOG | исправлено, nit | Отказ redirect теперь журналирует только нормализованные `scheme://host:effective-port` для обеих сторон. HTTPS downgrade и смена порта на том же host различимы; path, query и userinfo не попадают в журнал. RED→GREEN в `SnoopyTest`; старый host-only latch обновлён на origin pair. |
| NEW-S-2XX | исправлено, условный P3 | Snoopy обрабатывает `Location` независимо от HTTP status: fake-curl доказал `200` вместе с `credential-redirect-refused`. Раньше RSS `getTorrent()` после установки причины входил в ветку 2xx и мог сохранить тело как torrent; `fetch()` мог принять тело как feed. Оба пути теперь сразу возвращают отказ, сохраняя классифицированную причину. Два адресных RSS теста были RED и стали GREEN; torrent тест перехватывал старую ветку до записи файла. |

## Сверка с прежним CHECK-FIX

- `snoopy22`/`consumers9`: S1 восстанавливает анонимное следование без импорта cookie источника; проблема общей host-only банки и HTTP plugin cookie остаётся N-S1.
- `NEW-C`, `snoopy20`: S4/S5 добавили отсутствовавшие raw Authorization, Proxy-Authorization, scheme и source-scope тесты; `NEW-E` account-scope запрет сохранён.
- `consumers10`, `loginmgr15`, `tests28`: S8, S11 и S25 закрывают указанные пробелы.
- `consumers12`, `loginmgr14`, `new-2`: S12/S20/S23/S24 и README уточняют источники origin и видимость; фактическую конфигурацию прода не меняли.

## Проверки

- RED перед поведенческими правками: S1, S2, S3, S6, S7, S8, S9, S10, S11, S12, S13, S23, S24, NEW-S-IMS, NEW-S-LOG, NEW-S-2XX. Все они затем стали GREEN.
- Фокусные PHP наборы с отдельным `TMPDIR`: `SnoopyTest` 40/0, `CredentialBoundaryTest` 16/0, `AccountSelectionTest` 12/0, `CommonAccountTest` 18/0, `YggConfigurationTest` 17/0, `RuTrackerDomainListTest` 5/0, новый `FetchComplexSourcesTest` 1/0, `UrlHostTest` и `RSSTest` без `Failed:`/fatal через штатный TestCase runner; оба новых RSS 2xx теста выполнились. Синтаксис 16 изменённых PHP файлов и нового теста проверен `php -l`.
- Jest: `loginmgr-warning.spec.js` и `lang.spec.js` — 8/8; `git diff --check` по назначенным файлам без ошибок.
- Изолированные мутации: снятие условия импорта cookie в реальном HTTPS транспорте, удаление Proxy-Authorization, HTTPS условия цели и проверки source из redirect trust дают 1–2 именованных падения `SnoopyTest`; baseline 40/0, без fatal. Для нового S10 теста отключение проверки схемы `UrlHost::urlIsOneOf()` в отдельной копии даёт именованный отказ `HTTPS loginmgr session must not reach the HTTP request`; в общей копии тест зелёный.
- Полная PHP/JS матрица, образ Docker и живой сервис здесь не запускались. Проверки других агентов и общий gate должны быть отражены корневым исполнителем отдельно.

## Независимый просмотр X-изменений

Read-only просмотр текущего `git diff` для `php/xmlrpc_proxy.php`, `php/xmlrpc_proxy_policy.php`, `rpc2.php`, `plugins/httprpc/action.php` и `conf/xmlrpc_proxy.php` не дал новой конкретной регрессии. Четыре профильных набора (`XMLRPCProxyTest`, `XMLRPCProxyContractTest`, `XMLRPCProxyEntrypointTest`, `XMLRPCProxyPolicyParityTest`) на этом дереве дали exit 0 и 0 именованных `Failed`/fatal. Это локальная проверка текущего снимка; X-файлы здесь не менялись.
