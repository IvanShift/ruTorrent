# `up/retrackers-recovery` — independent current-base review

Дата: 2026-08-29. База: `upstream/master=755404f3`. Fork использован только
как donor гипотез; production behavior после `511ed13f` не менялся.

## Verdict: CHANGES REQUIRED

Четырёхпутевой recovery carve нужен, а его исходные дефекты
production-reachable. Текущий fork переносить нельзя: он принимает return
`rTorrent::sendTorrent()` за подтверждение, что daemon загрузил torrent.

Это неверно. После успешного XMLRPC dispatch `sendTorrent()` возвращает
локально вычисленный info-hash; rTorrent завершает load позже через
`DownloadFactory`. Candidate может быть отвергнут уже после того, как PHP
получил этот hash. Текущий rollback проверяет тот же локальный return и также
ничего не подтверждает.

Свежие `41 tests, 0 failures` в fork suite — **false-green**: test double
моделирует `queueSend($hash)` как daemon acknowledgement и закрепляет ошибочный
контракт.

## Exact owner scope

Recovery имеет ровно четыре пути:

1. `plugins/retrackers/init.php` — только immutable handoff состояния,
   сохранённого первым hook до `d.stop`; сохранить anchored require из #3218;
2. `plugins/retrackers/run.sh` — quoted `cd`, PHP/script/argv и четвёртый
   аргумент pre-stop state;
3. `plugins/retrackers/update.php` — importable worker, входная валидация,
   immutable source snapshot, pre-erase gates, daemon confirmation и rollback;
4. новый `tests/plugins/retrackers/UpdateTest.php` — focused worker,
   entrypoint и shell contract tests.

Исторический non-guard snapshot `+1493/-45` не является final numstat: state
machine и тесты надо переписать.

Upstream `tests/plugins/retrackers/RetrackersUpdateSequenceTest.php` из #3212
содержит 12 test methods и должен остаться byte-for-byte. Его удаление в fork
diff — merge artifact, не часть пакета.

## Что исключено в P3

`plugins/retrackers/guard.php`, оба service-wrapper hunk в `init.php`,
worker-side `.chk-meta`/`chk-meta-old` check и их tests не входят.

Donor guard не доказан на non-empty daemon view и имеет подозрительную command
shape с вложенными `$equal={...}`. Worker fallback также выполняется уже после
hook `d.stop` и не восстанавливает started service torrent.

P3 строится после final P1 и approved recovery. Он отдельно обязан доказать на
real daemon, что обычный torrent исполняет оба hook, а label и marker каждый
подавляют stop/launch; fallback не оставляет torrent остановленным.

## Перепроверенные finding verdicts

| Гипотеза | Вердикт |
|---|---|
| initial transport/RPC fault после hook stop оставляет started torrent остановленным | подтверждено, production-reachable |
| pre-stop state нужно передать worker-у до initial RPC | подтверждено |
| live `custom4` больше нигде не нужен | опровергнуто как абсолют: argv — recovery authority, live value полезен как generation/race check |
| unquoted shell даёт injection через username | опровергнуто: `User::getUser()` sanitizes; shell не reparse variable substitution |
| unquoted shell ломает configured PHP path с whitespace/glob | подтверждено, reachable |
| source может исчезнуть/замениться или иметь другой info-hash до erase | подтверждено |
| malformed metainfo сейчас стирается | опровергнуто: upstream уже не входит в erase branch при decode errors |
| tracker mutation законно меняет info-hash | недостижимо при корректном `Torrent`; candidate gate остаётся regression shield |
| local return hash подтверждает candidate load | опровергнуто, blocking |
| immediate plain `d.hash` отличает candidate/original/foreign generation | опровергнуто |
| current rollback подтверждён | опровергнуто, blocking |
| `eraseIssued=true` до успешного erase безопасно подавляет restart | опровергнуто, reachable stopped-torrent loss |
| exact complete missing-hash fault можно тихо завершить | подтверждено; substring/raw logging запрещены |
| current service guard готов | опровергнуто; owner — P3 |

## Исправленный confirmation/rollback contract

1. До include/config/filesystem/RPC side effects валидировать dense argv:
   canonical 40-hex hash, sanitized user и exact state `'0'|'1'`.
2. Прочитать metainfo ровно один раз. Из одних immutable bytes создать
   independent candidate и raw-original; source/candidate hash сверить до
   `d.erase`.
3. Любой pre-erase отказ восстанавливает только torrent, который argv называет
   started, и классифицирует outcome без raw third-party text.
4. Candidate load получает cryptographically unique transaction marker в
   daemon custom field вместе с loop-suppression command. Return
   `sendTorrent()` означает только dispatch.
5. Выполнить bounded poll по expected hash **и exact marker**. Чистый hash с
   пустым/другим marker — `foreign`, не success.
6. Если candidate не подтверждён, dispatch byte-exact raw original с другим
   rollback marker и сохранёнными state/directory/label. Existing same-hash
   torrent не стирать: duplicate load должен fail non-destructively.
7. Poll различает `candidate`, `rollback`, `foreign`, `absent`, `unknown`.
   Success — только exact candidate; exact rollback — операция отменена и
   original восстановлен.
8. Rollback dispatch return тоже не confirmation: marker и expected run state
   читаются у daemon.
9. После возможного erase нельзя делать `d.start` только по hash: он уже может
   принадлежать чужой generation.
10. Без durable pre-erase journal crash window между clean erase и confirmed
    load остаётся явно задокументированным non-goal; in-memory rollback нельзя
    называть crash-safe transaction.

Точные poll deadline/interval и custom-field key должны быть заморожены в
approved implementation design до кода; тестовая injectable clock/reader не
должна превращаться в production-only `TEST_MODE` branch.

## Обязательный RED/mutation evidence

Natural current-base RED должен покрыть:

- started/stopped snapshot при initial fault;
- dispatch acknowledged, marker никогда не появился — не success, raw rollback;
- delayed candidate marker после uncertain return — candidate, без fallback;
- rollback return без rollback marker — terminal classified failure;
- candidate marker против foreign/empty marker;
- source replacement, wrong hash, malformed snapshot и candidate-hash mutation
  до erase;
- byte-identical rollback для valid metainfo, которое меняется при re-encode;
- transport/fault/missing erase outcomes как разные branches;
- invalid argv до side effects;
- PHP executable path с spaces и exact four shell args;
- сохранение всех 12 upstream #3212 tests.

Mandatory mutations: считать local hash success; принять plain hash без marker;
убрать candidate marker/bounded retry; превратить exact missing fault в substring;
принять missing text при failed transport; re-encode rollback; убрать любой hash
gate; считать rollback dispatch confirmation; post-erase `d.start` без ownership;
сломать quoting/state handoff; утечь raw fault sentinel.

Named test должен реально выполниться и стать RED без preceding fatal. После
restore обязателен свежий GREEN.

## Runtime и handoff gates

Mutating evidence — только disposable `tasks/rt-lab.sh`, никогда live service:

- supported oldest daemon и 0.16.21: started/stopped torrent, exact trackers,
  directory/label/state и candidate marker;
- доказать раздельность load response и поздней marker visibility;
- one-shot SCGI fault proxy: request forwarded/response lost и request dropped;
- daemon-confirmed rollback, не PHP-return;
- real stale-hash fault с exact normalized classifier;
- crash kill/recovery только если PR заявляет crash safety.

Дополнительно: focused suite, untouched #3212 suite, `sh -n`, PHP lint
7.4/8.1/8.5, full PHP 8.1/8.5, PHPStan, exact four-path diff, non-empty test-name
set, whole-file review. Агент push не выполняет.

## Approval condition

Scope approved; текущий implementation design и donor tests — нет. Реализация
начинается только после явного одобрения marker-based bounded confirmation/
rollback дизайна и выбранных poll constants.
