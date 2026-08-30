# План: чистый upstream carve для setsettings/socket allocation

**Дата:** 2026-08-28
**Финальная база:** `upstream/master` = `f19c9d86`
**Ветка:** `up/setsettings-socket-alloc`
**Рабочее дерево:** `.worktrees/up-setsettings-socket-alloc`

## Implementation closure — 2026-08-30

**APPROVED / implemented / locally integrated.** Upstream-clean branch
`up/setsettings-socket-alloc` points to
`d548016babea5ba557fad2c13afc0234a335a420`, one commit with direct parent
`f19c9d86df72ad6b1720f31252297340049e5eab`. Its exact scope is four paths,
`+1229/-19`; neither merge commits nor excluded tracker/stale-details hunks are
present.

The former `ad0dd8e4` Save-B/reload-A race is closed. The lock now spans the
deferred `setuisettings` terminal outcome, including callback exceptions,
non-reload success, HTTP error, timeout, status zero, early no-op and scoped
HTTP 401. Ordinary unrelated 401 requests retain the global authentication
reload; initial rTorrent write/restore timeout or status zero remains
intentionally indeterminate and locked for manual recovery.

Fresh candidate evidence: package/upstream focused Jest 59/59, full Jest
263/263, three Node checks, host PHP 8.5 and root `php:8.1-cli` exit 0. Both the
fix-5 scoped re-review and final whole-branch review are APPROVED with no
Critical/Important/Minor findings. Full record:
`VERIFICATION-setsettings-socket-alloc-2026-08-30.md`.

The fork `master` first received upstream #3196/#3222/#3223 baseline as
`ed71bee5`, then the four-path package as `f547b2f3`, and finally accepted
#3224/#3225/#3226 delta as `7a78c606`. Independent integration review is
APPROVED and the later package rebase has an equal range-diff/patch hash. No
push or deployment was performed; backup ref
`backup/master-before-setsettings-integration-20260830` remains at `acbf5691`.

## Граница

Старый план измерял полное расхождение пяти файлов как `+540/-23`. Перемеренная
посылка смешивала независимые изменения: stale-details, tracker UI/JSDoc и полный
аудит alias-таблицы. В этот PR входят только четыре файла и только browser-side
setsettings/socket-allocation контракт:

- `js/options.js`
- socket/setsettings-хунки `js/rtorrent.js`
- targeted request-state хунки `js/webui.js`
- `tests/js/setsettings.spec.js`

Финальная чистая посылка измерена как `+1229/-19`. Прежние `+910/-15` и
`+431/-9` были промежуточными snapshot и не включали все найденные при
перепроверке terminal/401 safety fixes.
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
7. Повторный Save не может уйти, пока setsettings, возможный rollback, итоговый
   refresh и deferred `setuisettings` не достигли terminal outcome. Success/reload
   callback исполняется при held lock и release происходит после callback;
   UI error/timeout/status 0 release-ят без reload. Early UI-save no-op также
   terminal. Это закрывает stale restore/reload из того же UI; внешний клиент
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
   отключить snapshot/restore; вернуть прежний parse/log order; отключить refresh
   или pending-save guard; release до deferred UI request; release до success
   callback; убрать release отдельно из каждой UI failure branch; вызвать reload
   на failure; оставить early UI-save no-op без release. Каждая мутация должна
   уронить названный focused test, после чего исходное состояние полностью
   восстанавливается.
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
