# Владение `php/xmlrpc_path.php`: исправление схемы зависимостей

Дата: 2026-08-29. База: `upstream/master` = `755404f3`.

## Вердикт

Старое утверждение «SCGI владеет `php/xmlrpc_path.php`» **опровергнуто**.
SCGI-аудит правильно исключил этот файл; erasedata/post-API тексты ошибочно
сохранили старую зависимость.

На current upstream endpoint-local `httprpcResolvePath()` и
`rpc2_resolve_path()` уже реализуют load-path policy. Fork-файл сначала лишь
дедуплицировал их, а `filesystemIdentity()` получил позже только ради
destructive filesystem consumers:

- 11 calls в erasedata collector;
- 2 calls в erasedata remove-with-data;
- 3 calls в rutracker replacement transaction;
- 0 calls из SCGI transport.

## Решение

Обязательную ветку `up/xmlrpc-path` не создавать.

- `up/httprpc-refusals` сохраняет два endpoint-local resolver из upstream;
- `up/scgi-transport` меняет только transport и сохраняет
  `rpc2_resolve_path()`;
- canonical path + lstat/stat identity contract принадлежит уже заявленному
  `plugins/erasedata/filesystem.php` в package A;
- P0 использует erasedata-owned identity API через свою существующую
  зависимость от erasedata.

Таким образом, httprpc остаётся 5-файловым, SCGI — 7-файловым, erasedata A —
10-путевым. SCGI и A являются siblings после httprpc; SCGI не является
hard code dependency erasedata.

Optional dedup двух endpoint-local resolver можно когда-нибудь сделать
отдельным behavior-preserving 4-файловым cleanup, но он не входит в critical
delivery chain и не должен содержать filesystem identity.

## Обязательная защита нового владельца

В package A нужно держать RED/mutations для invalid/relative/NUL path,
existing file, symlink entry-vs-target identity, missing tail beneath symlink,
dangling/raced symlink, physical aliases и retention при identity uncertainty.
P0 отдельно должен доказать consumer wiring мутацией, возвращающей lexical-only
comparison.
