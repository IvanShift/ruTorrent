# Готовые upstream-ветки: контрольный срез 2026-08-29

Контрольная база: `upstream/master` = `755404f3`. Remote сверялся read-only;
push не выполнялся. Все четыре готовые ветки состоят ровно из одного commit с
прямым parent `755404f3`.

| Ветка | Вершина | Точный scope | Свежая проверка | Статус |
|---|---|---:|---|---|
| `up/fileutil-defects` | `79190927` | 7 файлов, `+514/-10` | PHP 8.5/8.1: 48 файлов, 303 теста, 1815 assertions; PHPStan; mutations/direct probes | готова |
| `up/test-harness` | `8eafb529` | 4 test-файла, `+49/-17` | PHP 8.5/8.1: 47 файлов, 287 тестов, 1781 `Passed:`; 7/7 mutations RED | готова |
| `up/rtorrent-0-16-21` | `48bc6d4b` | 3 test-файла, `+9/-4` | Jest 20/196; PHP 8.5/8.1: 46 файлов, 285 методов, 1790 assertions; 2 независимых review | готова |
| `up/kinozal-session` | `de98a49a` | 5 файлов, `+636/-28` | focused 35/35; PHP 8.5/8.1: 47 файлов, 1779 `Passed:`, 147 `ok`; PHPStan; mutation | готова локально, remote устарел |

## Ограничение PHP 7.4

Текущий upstream по-прежнему документирует PHP 7.4, но его собственный
`php/Torrent.php` после #3213 не компилируется там из-за native `mixed`
properties. Поэтому свежие полные матрицы выше относятся к PHP 8.5/8.1.
FileUtil отдельно прошёл PHP 7.4 lint на всех семи изменённых путях; падение
полного suite воспроизводится на неизменённом upstream-файле и не является
регрессией ни одной готовой ветки.

## Ветки, которые нельзя смешивать с готовыми

- `up/setsettings-socket-alloc` (`ad0dd8e4`) всё ещё основана на старом
  `fde9863b` и **не готова**: deferred `setuisettings` может перезагрузить
  страницу во время уже начатого следующего Save. Сначала RED/fix/re-review,
  затем rebase.
- `up/history-service-labels` (`4cf3bd69`) **никогда не отправлять**: она
  теряет history/Pushbullet для допустимых пользовательских dot-labels.

## Remote handoff

FileUtil, test-harness и rTorrent 0.16.21 локальны и не отправлялись. Открытая
Kinozal-ветка на `origin` остаётся на `4cf74c52`, поэтому владельцу нужен
только explicit lease:

```sh
cd /home/dev/Documents/my_projects/.rutorrent-worktrees/pr-loginmgr
git push --force-with-lease=refs/heads/up/kinozal-session:4cf74c52447bf7b044c57d8b903c57efe35f0c85 \
  origin up/kinozal-session:up/kinozal-session
```

При отказе lease сначала fetch и inspection; не заменять его обычным force.
