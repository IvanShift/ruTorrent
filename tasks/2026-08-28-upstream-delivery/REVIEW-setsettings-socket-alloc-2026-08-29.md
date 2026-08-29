# `up/setsettings-socket-alloc` — final independent design review

Дата: 2026-08-29. Current upstream: `755404f3`. Reviewed branch:
`ad0dd8e4`, parent `fde9863b`.

## Verdict

**APPROVED design / current branch NOT READY.** Lock-through-terminal design
correct и остаётся в exact 4-path scope. Tip `ad0dd8e4` всё ещё содержит
воспроизведённую race и не должен отправляться до RED/fix/mutations, rebase и
final verification.

## Independently reproduced race

В `ad0dd8e4` `finishSettingsSave()`:

1. забирает и очищает `deferredSettingsSave`;
2. снимает `settingsSavePending` и включает Save;
3. только затем начинает async `setuisettings` через `save(reply)`.

Save B может поэтому отправить новый `/RPC2`, пока UI request A ещё pending.
Поздний success A вызывает reload во время RPC B.

Disposable-export evidence:

- unmodified focused suite: 36/36 GREEN;
- production-flow probe зафиксировал `lock=false` во время первого
  `setuisettings`, третий AJAX после Save B и reload A при `lock=true` уже для B;
- четыре replacement-contract tests ожидаемо RED: success ordering, UI HTTP
  failure, timeout и status 0;
- existing test `defers a reload-capable WebUI save until direct recovery is
  terminal` false-green: он прямо ожидает unlock до terminal UI response.

## Approved terminal contract

- Same-browser Save lock живёт от initial setsettings через rollback/
  reconciliation и, если есть, deferred `setuisettings`.
- На UI success reply/reload callback вызывается **при удержанном lock**; lock
  снимается только после callback через `finally`-equivalent, чтобы exception не
  оставил UI навсегда locked.
- UI HTTP error, timeout и status 0 идут по existing diagnostic path, снимают
  lock и никогда не вызывают reload callback.
- Без deferred UI save lock снимается на существующей terminal RPC/
  reconciliation boundary.
- WebUI-only save остаётся immediate; новый lock относится только к UI write,
  отложенному за rTorrent transaction.
- Если helper может вызвать early `save()` no-op (`configured === false`), этот
  branch обязан стать terminal и снять lock.

Новый transport/PHP abstraction не требуется.

## Required RED и mutations

Focused tests обязаны доказать:

1. pending deferred UI request держит Save disabled; повторный click не
   отправляет ни второй UI request, ни новый `/RPC2`;
2. success/reload callback наблюдает held lock, release происходит после его
   возврата;
3. HTTP failure, timeout и status 0 каждый показывают штатную ошибку, не
   reload-ят и unlock-ят;
4. deferred non-reload UI save также releases на terminal response;
5. direct/httprpc rollback/reconciliation serialization не регрессирует;
6. early UI-save no-op не оставляет lock.

Mandatory named mutations:

- release до deferred UI save;
- bypass `settingsSavePending` guard;
- release до success callback;
- убрать release отдельно из каждой failure branch;
- вызвать reload callback из failure branch.

Каждый named test должен реально выполниться и стать RED, после restore —
свежий GREEN.

## Exact scope и current-base compatibility

`fde9863b..ad0dd8e4` — ровно 4 paths, historical snapshot `+910/-15`:

- `js/options.js` `+1/-1`;
- `js/rtorrent.js` `+168/-7`;
- `js/webui.js` `+53/-3`;
- `tests/js/setsettings.spec.js` `+688/-4`.

Все четыре base blobs byte-identical между `fde9863b` и `755404f3`; patch
проходит `git apply --check`. Новый terminal fix затрагивает только уже
принадлежащие scope `js/webui.js` и test file. Final numstat перемеряется.

Ready `up/rtorrent-0-16-21` меняет другие три test paths и не prerequisite.
Combined disposable export дал 2 suites / 56 tests GREEN; syntax checks четырёх
socket paths exit 0.

## Final handoff gates

После implementation: focused RED/GREEN/mutations, full Jest, `node --check`
трёх production JS, full PHP 8.5/8.1, exact four-path diff, current-upstream
rebase, independent whole-file review и свежие counts. Push выполняет только
владелец.
