# Пакеты №5/№14 — завершение, review и интеграция

Дата: 2026-09-05. Вердикт: **CLOSED LOCALLY / INTEGRATED INTO MASTER**.
Работа остановлена после этих двух пакетов. Push, PR и deployment не выполнялись.

## Результат и точные refs

| Граница | Commit |
|---|---|
| Master до интеграции; пользовательские изменения сохранены | `ebb60a7eb272b82d6bbdc7b1cfaa5427be3c6cb0` |
| №5: исправления финального независимого review | `fde65fe594ee013c6a6bc5e1019bf41abc2f9f3a` |
| №5: окончательный donor, совместимость shell-теста | `1c810568198e2d26c0c707b52774846d69123069` |
| №14: окончательный donor с Unicode fix | `626c522ac14e4bb0dd5d666423d76fc8828ca3cb` |
| Интеграция №14 | `7af7ee388c26e76287fe4b10cb56200092d1adb9` |
| Интеграция №5 | `665713707ded6c6820814ef2151ab42d03abac43` |
| Upstream sync / проверенный итоговый код master | `ee96fab10a41662e50baa402d88241576ad4b460` |
| Свежий upstream/master, входит в ancestry master | `b4e84b641ebef7adeb91ec3830d3934bd1885bd1` |
| Свежий origin/master, push не выполнялся | `72d1885ca02358bb1420d1a812450b61e77521f7` |

Документирующий commit следует после code SHA `ee96fab1`; его hash не вписывается
в самого себя. Основной checkout находится на `master`. Интеграционная ветка:
`codex/integrate-packages-5-14-20260905`. Ни rebase, ни force-push не было.

№5 перенесён только по шести разрешённым путям, пять из них реально изменились.
Вся старая donor-ветка не сливалась: её merge-base `f19c9d86` и старые
prerequisites №3/№4 приводили к 12 конфликтным путям и риску отката core.
`guard.php` сохранён; активных ссылок на него из retrackers/core/httprpc не найдено.
Текущий `run.sh` master с подавлением stdout/stderr сохранён без изменения.
№14 объединён из исправленной ветки; его семь owned paths точно совпадают с
donor. Пять перенесённых путей №5 побайтно совпадают с окончательным donor;
шестой owned path — run.sh — намеренно сохраняет единственный master delta
`>/dev/null 2>&1`. Его argv/exit semantics проверены реальным subprocess.
После проверки интеграционная ветка перенесена в master через fast-forward.

## №5: что нашёл reviewer и что исправлено

Единственный разрешённый независимый reviewer закончил работу с **APPROVED**.
Оригинальные отчёты, все 42 старых сценария и их смысловая crosswalk сохранены:
[PACKAGE-5-FINAL-REVIEW-2026-09-05.md](PACKAGE-5-FINAL-REVIEW-2026-09-05.md).

| Находка | Итог |
|---|---|
| I1: после неизвестного ответа worker только спал и не видел восстановление | **Подтверждена, исправлена**: одноразовая мутация отделена от продолжающихся read-only проверок; повторной отправки нет |
| I2: известные terminal old-commit outcomes оставляли lease навсегда | **Подтверждена, исправлена**: закрываются неиспользованные handles и retire receipts без изменения чужого/неопределённого объекта |
| I3: неизвестный la/ca/ra arm reply позволял dispatch по поздним keys | **Подтверждена, исправлена**: нужен точный ARMED sentinel; unknown arm остаётся видимым restart-required |
| I4: до erase не сверялись исходная tracker topology и resume extras | **Подтверждена, исправлена**: source/live/resume gate до staging/arm, по реальным loader rules двух tagged families |
| M1: new.static добавлял PHPStan diagnostic при интеграции | **Подтверждена, исправлена**: final protected constructor; полный CI scan теперь без ошибок |
| M2: потерян реальный subprocess-тест shell argv | **Подтверждена, исправлена**: настоящий run.sh, сложные пути, пустой аргумент и exit 23 |
| Повторный review: initial-only restrictions мешали post-event extras | **Подтверждена, исправлена**: initial eligibility отделена от post-event tuple; HTTP/DHT extras сохраняются, partial cleanup не получает лишних прав |

Топология использует тот же bencode scanner, не вторую грамматику. Retained
descriptors ограничены 16,384 узлами. Independent boundary probe под 128M:
16,383 leaves + tier accepted при 12 MiB peak; следующая leaf refused при 14 MiB.
Исходные info/resume spans сохраняются; глобальный memory_limit не повышается.
Tagged loader provenance и ссылки сохранены в независимом review.

Пять свежих обратных мутаций пойманы именованными assertions без раннего fatal:
отключение read wait, source gate, exact arm, terminal retirement и смешение
initial/post-event restrictions. Восстановление production bytes проверено.

После APPROVED изменён только новый shell fixture. Полный harness подменяет
текстовый __DIR__ даже внутри строки generated PHP; fixture теперь получает
явный receipt path. Это также совместимо с quiet run.sh master. Два временных
argv.json от неудачного прогона удалены. Hook дважды честно отказал до
исправления, после исправления оба commits прошли полный hook; bypass не было.

## №14: повторная проверка и узкое исправление

Donor `671e00f0` действительно исправлял прежние Critical paths; утверждение,
что внешний агент ничего не исправил, к этой версии неприменимо.
Новый independent probe подтвердил один Important: base64 URI с U+FFFE/U+FFFF
проходил valid-UTF8 guard и создавал неразбираемый XML как trusted send.
Natural RED: 8/8 outputs на host PHP 8.5 и PHP 7.4.

Исправление `626c522a`: запрещённые XML 1.0 code points отвергаются до
transport; допустимые соседние Unicode code points и raw binary metainfo
остаются допустимыми. Три новых тестовых метода проверяют четыре URI-family.
Обратная мутация старой проверки вызывает именно эти RED, после возврата GREEN.

Дополнительно перепроверены nested/filter parser, terminal malformed refusal,
отсутствие частичной отправки после отказа load-member, normalized outer fault
identity, оба настоящих скопированных entrypoint и буквальные wire transcripts.
Пять public method-name sets: 84→155, 7→7, 10→19, 2→2, 9→9; missing=0.
Fixture keys 70→70, missing=0. Старые inner-name/malformed-pass-through
ожидания заменены осознанно по утверждённому контракту, не ради зелёного теста.

## Проверки именно итогового upstream merge

Все строки ниже относятся к code tree `ee96fab1`, кроме явно отмеченных
дополнительных donor/mutation проверок.

| Проверка | Результат |
|---|---|
| PHP 7.4.33, №5, literal 128M | 221 methods / 1097 assertions, GREEN |
| PHP 8.1.34, №5, literal 128M | 221 / 1097, GREEN |
| Frozen sequence, обе версии | 12 methods / 40 assertions, GREEN |
| №14, PHP 7.4/8.1, по пять suites | 192 methods / 2542 assertions на каждой версии, GREEN |
| Полный host PHP 8.5.4 harness | 78 файлов; 7137 Passed: / 773 ok - строк; exit 0 |
| Полный Jest, существующие локальные dependencies | 23 suites / 336 tests, GREEN |
| PHPStan 2.2.9, точная полная CI-команда/config | errors=0, file_errors=0 |
| PHP lint обеих packages/settings; sh -n run.sh | GREEN |
| Frozen sequence base→final | 12 имён и весь class-through-EOF побайтно сохранены |
| Семантика 42 старых worker scenarios | Каждый отражён в durable review crosswalk; obsolete expectations явно заменены |
| Пять перенесённых №5 / семь №14 owned paths donor→final | git diff --exit-code: 0; run.sh отдельно сохраняет master quiet-output delta |
| git diff --check; upstream ancestry | GREEN |

Passed:/ok - — строки разных harness-форматов, не число уникальных test methods.
Складывать эти counters с focused totals нельзя. Полный PHP запускался из tests
в обычном PID namespace: sandbox может завершить TaskTest не по причине кода.
Entrypoint tests проверены там, где разрешён loopback bind.

Дополнительно исправленный №5 проходил PHP 8.5.10 shipped image: 221/1097;
sequence 12/40. Штатный image не имеет tokenizer/posix/pcntl. Поэтому не
заявляется полный GREEN всего harness в этом образе: новый proxy tokenizer guard
там недоступен. На точной базе восемь EntrypointsTest assertions тоже требуют
отсутствующий tokenizer. Guard не ослаблялся. Host и PHP 7.4/8.1 обеспечили этот gate.

## Настоящие rTorrent 0.9.8 и 0.16.21

На каждом daemon поднят отдельный --network none контейнер с итоговым кодом:
настоящий candidate creation fault → owned cleanup → rollback исходных bytes;
скрыты шесть уже принятых mutation replies и 12 последующих read responses.
Итог: **WORKER_END_TO_END_GREEN**, **DELAYED_READS 12**,
**LATE_CALLBACKS_NOOP 11**, lifecycle DONE завершён, markers очищены, финальный
ledger содержит только допустимые profile records. Повторной mutation нет.

`WORKER_RESULT.ok=true` — итог; сохранённый рядом
`failure=terminal-cleanup-pending` — последняя transient diagnostic, не failure
финального результата. Полные trace и итоговые scalar/tracker snapshots сохранены.
Первый запуск final 0.9.8 не стартовал из-за имени бинарника php85: его образ имеет
php. Повтор с правильным executable прошёл; bootstrap failure не выдан за GREEN.

Images: 0.9.8 `rutorrent-pkg5-rt098:local`,
sha256:a21c602a318026c78dc5537eaeee48ecbd178807f557472e36749c14587376bc;
0.16.21 `ivanshift/rutorrent:latest`,
sha256:9e024dbd49ec9d2d3b5a1164ff6f57afc80207221b6a959b67b115f732c6ddb4.
Ни mutating live-service probe, ни build/pull/deploy не выполнялись.

## Upstream delta и корректировка контрактной базы

После свежего fetch upstream остаётся `b4e84b64`: 7 commits / 11 paths
от предыдущего `cd814cb5`, версия 5.3.14. Merge чистый. `settings.php`
просмотрен отдельно: запрос XMLRPC limit вынесен из read batch, чтения смещены
согласованно; запрашивается 16,777,216 вместо 67,108,863.

64 MiB metainfo input cap №5 — НЕ гарантия такого XMLRPC request budget.
Пакет отдельно ограничивает wire по rTorrentSettings::maxContentSize() до старой
мутации; это version-derived cap, не измерение текущей настройки daemon. Новый
settings limit не расширяется нашим кодом. Контракты №5/№14 сохраняют
утверждённую семантику и ownership, но их integration base теперь включает этот
settings delta. Он прошёл реальные settings bootstrap и worker на обеих families.
0.16.22 здесь не запускался, совместимость с ним не объявляется измеренной.
Другие контракты не переписаны и новые пакеты не начаты.

## Артефакты и воспроизведение

Каталог вне upstream diff:
`/home/dev/Documents/my_projects/ruTorrent/.superpowers/package-5-14-integration/`.

- `final-combined-php74.log`, `final-combined-php81.log`,
  `final-combined-full-host.log`, `final-combined-jest.log`,
  `final-combined-phpstan.json`.
- `final-live-098-retry.log`, `final-live-1621.log`,
  `final-live-098/`, `final-live-1621/`: RAW requests/responses и snapshots.
- `final-frozen-sequence.log`; `verify-sequence-preservation.php`,
  `verify-surface.php`; `check-combined.sh`, `verify-retrackers.php`,
  `check-proxy.sh`, `verify-proxy.php`; `run-integrated-lab.sh`.
- `review5/`: исходные независимые probes/evidence и два отчёта;
  их тексты также сохранены в Git в FINAL-REVIEW документе.
- `5-mutation-*.log`, `5-mutations-restored-green.log`,
  `14-uri-red-host.log`, `14-uri-red-php74.log`, `14-fixed-*.log`.
- Промежуточные combined-shell/port/bootstrap RED logs сохранены, но не
  подменяют перечисленные окончательные acceptance logs.

Минимальный воспроизводимый полный host gate:

```sh
cd /home/dev/Documents/my_projects/ruTorrent/tests
bash php-test.sh
npm test -- --runInBand
```

Не запускать полный PHP harness внутри ограниченного PID sandbox. Для старого
donor assertions должны быть включены через tests/php-test.ini. Прямой запуск
файла класса без настоящего runner не считается выполнением suite.

Проверенные production SHA-256:

```text
plugins/retrackers/update.php
c6992d5a02f342943cc7cf076301bccf989660d02ebc8c8677158b775cf45231
tests/plugins/retrackers/UpdateTest.php
0a9b6f9ec5030bb06e8cd57f0c299feed567c35d9a2c054123c530f1917e0e5d
php/xmlrpc_proxy.php
4d21573c4a881e3230724c4517f6daf6f384cd9dabb013e12250bdffefc1c5a9
```

## Точка остановки

Закрыто **8/18**: №1–5, №13–15. Не закрыто **10**: №6–12, №16–18.
№6 имеет внешние candidates, но их приёмка в этой сессии не выполнялась.
№7 теперь ждёт только №6; №12 — №10, поскольку №5 закрыт.
PR/upstream delivery не равняется implementation closure: новые №5/№14
локально интегрированы, но ещё не опубликованы и не приняты upstream.

Далее только по новому поручению: сверить refs/dirty tree, повторно проверить
внешний №6 на новой базе без механического слияния, затем его зависимые пакеты.
Для upstream нужны отдельные чистые branches, не push donor №5 со старыми
prerequisites и не PR из fork master. Пользовательские четыре log-файла и
worktrees сохранены. Никакие внешние ветки №6 и другие пакеты не менялись.
Четыре собственных disposable rTorrent-контейнера и их временные anonymous
volumes удалены после копирования evidence; другие сервисы не затронуты.
