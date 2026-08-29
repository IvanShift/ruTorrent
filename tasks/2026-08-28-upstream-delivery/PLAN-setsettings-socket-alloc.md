# План: чистый upstream carve для setsettings/socket allocation

**Дата:** 2026-08-28
**База:** `upstream/master` = `fde9863b`
**Ветка:** `up/setsettings-socket-alloc`
**Рабочее дерево:** `.worktrees/up-setsettings-socket-alloc`

## Статус после финальной перепроверки — 2026-08-29

Текущая вершина `ad0dd8e4` ещё **не готова** и остаётся на старой базе
`fde9863b`. После завершения rTorrent batch/reconciliation она снимает
`settingsSavePending` до terminal-состояния отложенного `setuisettings`.
Пользователь успевает начать Save B, после чего поздний success Save A может
перезагрузить страницу во время `/RPC2` B. Production-flow probe воспроизвёл
эту же браузерную гонку; существующий тест ошибочно фиксирует небезопасный
unlocked interval.

Обязательная последняя поправка:

1. держать Save-lock до terminal outcome отложенного UI-запроса;
2. вызывать reload-capable success callback, пока lock ещё удерживается, и
   только затем завершать lock;
3. при UI error/timeout/status 0 показать штатную ошибку, снять lock и не
   перезагружать страницу;
4. тестом доказать, что повторный Save/click во время UI request не отправляет
   новый `/RPC2`, а failure снимает lock без reload;
5. после RED/fix/mutations и повторного review перебазировать один commit на
   `755404f3`, затем перемерить точный scope и полные матрицы.

До этого нельзя использовать старые focused/full counts как финальный handoff
и нельзя отправлять ветку.

## Граница

Старый план измерял полное расхождение пяти файлов как `+540/-23`. Перемеренная
посылка смешивала независимые изменения: stale-details, tracker UI/JSDoc и полный
аудит alias-таблицы. В этот PR входят только четыре файла и только browser-side
setsettings/socket-allocation контракт:

- `js/options.js`
- socket/setsettings-хунки `js/rtorrent.js`
- targeted request-state хунки `js/webui.js`
- `tests/js/setsettings.spec.js`

Размер новой чистой посылки измеряется после реализации: прежние `+431/-9`
описывали только fork-state и не включали найденные при перепроверке safety fixes.
Явно исключены существующие tracker/stale-details хунки `js/webui.js`, весь
`tests/js/rtorrent.spec.js` и свойство `chkmsg` в JSDoc `js/rtorrent.js`.

### Task 1: Исправить типизацию числовых настроек и откат socket allocation

**Requirements**

1. Все пять числовых полей блока Other Limiting должны получать класс `num`, чтобы
   `theWebUI.setSettings()` отправлял ключи `n...` и значения XML-RPC типа `i8`.
   Это делает `max_open_files` и `max_open_http` достижимыми для socket shim.
2. Пустое значение любого `n...` ключа должно сериализоваться как `i8:0`, как в
   PHP-двери `plugins/httprpc/action.php`; пустая строковая настройка остаётся
   строкой.
3. Перед изменением socket allocation browser batch должен прочитать действующие
   `min_alloc`/`max_alloc` каждой затронутой категории. Если любой member batch
   faulted, прежние bounds восстанавливаются отдельным `restoresocketalloc` batch с
   одним итоговым `system.sockets.adjust_alloc`.
4. Restore выполняется один раз за batch, работает для `files` и `http`, сохраняет
   snapshot между частями split batch и не запускается, если bounds не вернулись
   числами или весь исходный batch принят.
5. Direct XML-RPC fault разбирается до показа diagnostics: пользователь получает
   ровно одно сообщение с причиной отказа, а success callback не исполняется как
   будто request был принят.
6. После отказа и завершения rollback WebUI перечитывает `getsettings` и обновляет
   model/controls фактическими значениями. HTTP-fault httprpc-пути делает тот же
   refresh после того, как серверный rollback уже завершён.
7. Повторный Save не может уйти, пока setsettings, возможный rollback и итоговый
   refresh не закончены. Это закрывает stale restore из того же UI; внешний клиент
   остаётся вне транзакционной гарантии rTorrent и явно документируется как residual.
8. Поведение фиксируется в `tests/js/setsettings.spec.js` для 0.16.19 и 0.16.21:
   wire keys/types, очищенное число, reads-before-writes и все ветки restore.
   Тесты также держат fault visibility, post-rollback reconciliation и pending-save
   serialization для direct и httprpc transport outcomes.

**TDD and verification**

1. Сначала перенести/написать focused tests и запустить
   `npm test -- --runInBand tests/js/setsettings.spec.js`; до production-хунков
   новые проверки должны быть красными по заявленным причинам.
2. Внести минимальные production-хунки в `js/options.js`, `js/rtorrent.js` и
   `js/webui.js`; не переносить существующие посторонние webui-хунки из fork master.
3. Запустить focused Jest, полный Jest и `node --check` для изменённых JS-файлов.
4. Запустить полный PHP runner на локальном PHP 8.5 и root `php:8.1-cli` без
   `--user`, как требует актуальный delivery README.
5. Мутационно доказать независимые границы: убрать `num`; убрать empty-to-zero;
   отключить snapshot/restore; вернуть прежний parse/log order; отключить refresh или
   pending-save guard. Каждая мутация должна уронить названный focused test, после
   чего исходное состояние полностью восстанавливается.
6. Проверить `git diff --check`, точный name-status/numstat и отсутствие всех
   исключённых хунков. Сделать один upstream-коммит с объяснением причины и
   измеренной проверкой.

**Plan conflicts resolved**

- Актуальный `tasks/2026-08-28-upstream-delivery/README.md` сильнее старого
  rebuild-плана: PHP 8.1 запускается root, без `--user`.
- Требование scoped PR сильнее механического переноса полного состояния пяти
  файлов: посторонние хунки исключаются. `js/webui.js` теперь входит только с новым
  targeted request-state исправлением; старые `+540/-23` и `+431/-9` не являются
  размером итоговой посылки и заменяются новым измерением.
- Строки 0.16.21 в focused setsettings matrix остаются здесь: они проверяют ровно
  socket-allocation batch. Общие alias- и partial-seed проверки остаются для
  отдельного compatibility PR.
- Межклиентскую транзакционность rTorrent и единый внешний numeric grammar этот PR
  не обещает. Он сериализует только собственные Save/restore операции WebUI и
  сохраняет существующую httprpc numeric семантику; остаточные ограничения войдут
  в review/PR текст.
