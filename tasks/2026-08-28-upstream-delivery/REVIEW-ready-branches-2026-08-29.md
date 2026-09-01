# Готовые upstream-ветки: контрольный срез 2026-08-31

Текущий общий реестр: `STATUS-18-PACKAGES-2026-09-01.md`.

Историческая контрольная база первых четырёх веток: `upstream/master` =
`755404f3`. PHP74 candidate был построен на `eeae9f3a` и уже принят upstream как
#3224. Socket handoff перебазирован на current upstream `f19c9d86`. Kinozal
повторно перебазирован на `upstream/master=781cee4e`; его patch-id до/после
rebase совпадает. Владелец опубликовал Kinozal candidate как `c39d499d`.

| Ветка | Вершина | Точный scope | Свежая проверка | Статус |
|---|---|---:|---|---|
| `up/fileutil-defects` | `79190927` | 7 файлов, `+514/-10` | PHP 8.5/8.1: 48 файлов, 303 теста, 1815 assertions; PHPStan; mutations/direct probes | **accepted upstream #3231** |
| `up/test-harness` | `8eafb529` | 4 test-файла, `+49/-17` | PHP 8.5/8.1: 47 файлов, 287 тестов, 1781 `Passed:`; 7/7 mutations RED | **accepted upstream #3232**; follow-up #3239 |
| `up/rtorrent-0-16-21` | `48bc6d4b` | 3 test-файла, `+9/-4` | Jest 20/196; PHP 8.5/8.1: 46 файлов, 285 методов, 1790 assertions; 2 независимых review | **accepted upstream #3230/#3236** |
| `up/kinozal-session` | `c39d499d` | 5 файлов, `+653/-28` | Full PHP 7.4/8.1/8.5; focused 5/5 + 18/18 + 9/9; пять lint; 3 mutations RED; fresh independent review APPROVED; GitHub 8/8 GREEN | **accepted upstream #3198** как `495e2a54` |
| `up/php74-torrent-properties` | `286dd24b` | 3 файла, `+14/-9` | PHP 7.4/8.1 full harness; PHPStan floor; two RED/mutations; GitHub matrix | **accepted upstream #3224**; integrated `acbf5691`; #3225 follow-up in `7a78c606` |
| `up/setsettings-socket-alloc` | `938ff6ff` | 4 файла, final reviewed scope | focused/full Jest; PHP 7.4/8.1/8.5; mutations; maintainer review fixes | **accepted upstream #3227** как `7d2a69db` |
| `up/httprpc-refusals` | `c7a431aa` | 5 файлов, `+437/-14` | focused PHP 7.4/8.1/8.5; PHPStan; integration review | **accepted upstream #3228** как `7e77ebf0` |
| `up/scgi-transport` | `33934444` | 7 paths, `+1584/-51` от старого parent | full PHP matrix; real rTorrent 0.9.8/0.16.21 UNIX-SCGI probes | local-only; rebase на `495e2a54` и новый review перед PR |

## Ограничение PHP 7.4

Текущий upstream по-прежнему документирует PHP 7.4, но его собственный
`php/Torrent.php` после #3213 не компилируется там из-за native `mixed`
properties. Поэтому свежие полные матрицы выше относятся к PHP 8.5/8.1.
FileUtil отдельно прошёл PHP 7.4 lint на всех семи изменённых путях; падение
полного suite воспроизводится на неизменённом upstream-файле и не является
регрессией ни одной готовой ветки.

## Ветки, которые нельзя смешивать с готовыми

- `up/history-service-labels` (`4cf3bd69`) **никогда не отправлять**: она
  теряет history/Pushbullet для допустимых пользовательских dot-labels.

Старый socket donor `ad0dd8e4` также не отправлять: его terminal-lock race
закрыта только в superseding branch `d548016b`.

## Remote handoff

FileUtil принят как #3231, rTorrent 0.16.21 как #3230/#3236, test harness как
#3232, socket как #3227, httprpc как #3228, Kinozal как #3198. PHP74 lane
принята upstream, включая #3229. SCGI остаётся единственной local-only веткой
этой таблицы и требует rebase/review перед публикацией. Kinozal exact lease
ниже уже успешно использован и повторно выполнять его не нужно:

```sh
cd /home/dev/Documents/my_projects/.rutorrent-worktrees/pr-loginmgr
git push --force-with-lease=refs/heads/up/kinozal-session:4cf74c52447bf7b044c57d8b903c57efe35f0c85 \
  origin refs/heads/up/kinozal-session:refs/heads/up/kinozal-session
```

При отказе lease сначала fetch и inspection; не заменять его обычным force.
