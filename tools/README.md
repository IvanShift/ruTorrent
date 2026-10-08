# Fork tools

Local campaign notes and lab evidence live in the ignored `tasks/` directory and are never pushed. The maintained tools are tracked here.

| File | Purpose |
| --- | --- |
| [matrix.sh](matrix.sh) | Local matrix of the PHP suites: local PHP, `php:8.1-cli`, `php:7.4-cli`, and the production image without iconv. Each leg runs on its own export with its own `TMPDIR`. |
| [rt-lab.sh](rt-lab.sh) | A disposable lab instance on a chosen image with the working tree overlaid (`up` / `sync` / `down`). Mutating probes belong here, never on a live instance. Overlaid files take the packaged modes (0644, executables 0755), so the image's native actor-path admission accepts them after a restart. `up` labels its container `rt-lab.owner=<name>`, and `up` and `down` remove only such a container, together with its anonymous volumes. `tests/tools/rt-lab-test.sh` covers both. |
| [retrackers-clear-markers.php](retrackers-clear-markers.php) | Clears stuck retrackers recovery markers: report first, then `apply HASH FINGERPRINT [HASH FINGERPRINT ...]` with pairs from that report. A refusal says when the receipt ledger is live. `tests/plugins/erasedata/RepairToolRefusalTest.php` and `tests/plugins/retrackers/ClearMarkersToolTest.php` cover it. |
| [escaped-values-scan.php](escaped-values-scan.php) | Lists the downloads whose stored label or directory still carries damage from the escaping `rXMLRPCRequest` applied to every string before round 5 (`%5C%22` labels; directories with a literal `\"` or doubled `\\` set by a replacement, a move or the datadir dialog), one classified line per finding. The scan reads the downloads read-only (one `d.multicall`, then path checks) and changes no download. ruTorrent's own settings refresh may run first, as for any entry point: while the profile's `settings/rtorrent.dat` is missing or in the pre-round-5 format, as right after the upgrade, the tool first sends the reads of `rTorrentSettings::obtain()`, `network.xmlrpc.size_limit.set 16777216` (the request every WebUI load sends) and, with a local daemon, `execute.capture` of `sh -c "id -u ; id -G ; echo ~ "`, and rewrites that cache. Run it as the daemon's user where rTorrent's paths resolve: `docker exec -u torrent <container> php85 /rutorrent/app/tools/escaped-values-scan.php`. Exit 0 nothing found, 1 findings listed, 2 usage or unreadable daemon. `tests/php/EscapedValuesScanToolTest.php` covers it. |
| [retrackers-retry-reset.php](retrackers-retry-reset.php) | Clears one reviewed, exhausted retrackers queue retry state, so the dispatcher requeues that job. Without arguments it lists every terminal job; `HASH` is a dry run; `HASH --apply` clears it; `--user=NAME` selects a per-user profile and is needed only with `$forbidUserSettings = false`. `tests/plugins/retrackers/RetryResetToolTest.php` and `RetryResetDiagnosticsTest.php` cover it. |

Both shell tools resolve the repository root as their parent directory, so they must stay one level below it.

See [erasedata queue attestation](../plugins/erasedata/QUEUE-ATTESTATION.md) for standalone sealing and the Docker image's explicit offline operator helper.

## Manual retrackers recovery for `candidate-claim`

A `v1:candidate-claim:<tx>` marker with an empty `retrackers-recovery-ack` means the original torrent may already be erased while its replacement has not finished. The plugin keeps refusing with `receipt-ledger-corrupt`, and deleting the marker by itself restores neither the torrent nor its data. Do not apply the rule for an old `v1:original:*` marker to this case.

1. Stop new retrackers operations and make sure its worker is not running. Save a copy of the rTorrent session files and of the plugin's state directory before any repair.
2. Run `retrackers-clear-markers.php` without arguments inside the container. It only reads the daemon and prints the hash, name, marker type, whether the ack is empty, and the fingerprint of the current generation. Match the hash against the log line and against the state of the old and new torrents. To apply, choose one row and keep its fingerprint.
3. For `candidate-claim`, check whether a completed successor torrent exists with the expected metainfo, path and files. If the replacement is incomplete, restore the original torrent and data from the saved state, or finish or cancel the transaction by hand, and keep the marker until then. A missing `wa:` after a restart does not prove that the replacement finished: those receipts lived only in the daemon's memory.
4. Only after verifying a consistent result, run the tool with `apply HASH FINGERPRINT` for the chosen row. Several reviewed pairs may follow in one run; every pair is checked against that run's report before anything is cleared, and each is cleared and read back on its own. It refuses when the marker, the ack or `local_id` changed, or when the receipt ledger is not empty. It clears only the chosen marker and ack in the daemon's memory, requests `session.save` and reads them back. An answer to `session.save` means the write was queued, not that the file is on disk. Repeat the report and check the chosen hash; before restarting the daemon, wait until its session file is saved and check its contents, and repeat the report after the restart. Then start the plugin and check its log. If the call or the in-memory confirmation fails, the tool exits non-zero; it cannot see a failed background write. Once a pair is cleared, every exit first requests `session.save` and names the pairs that request covers; when that request gets no answer, the tool names the pairs cleared only in daemon memory. Re-check the report before retrying.

A refusal that names a non-empty receipt ledger means retrackers is running in that daemon and may still own the marker. Clear it only where retrackers has not started: inside the new container after it starts (retrackers then starts without a restart; the fingerprint changes after a restart because `local_id` does), or on a container you are retiring, with the copy of the tool that shipped with that container.

This is a manual procedure; the plugin never clears markers automatically at startup.

In the Docker image, run the tool as the daemon's user, for example `docker exec -u torrent <container> php85 /rutorrent/app/tools/retrackers-clear-markers.php`.

## Exhausted retrackers queue jobs

A queued retrackers job retries with backoff. A terminal job carries `d.custom=retrackers-retry` = `r1:<Q>:6:0:<reason>`, and the worker log line `queue-retry-exhausted` names this tool. On a daemon that advertises `system.retrackers.retry_waits`, a paused source, a running hash check or an SCGI outage is a wait: the first deferral of any kind is attempt 1; a wait keeps the count; every capture or preflight refusal adds one attempt; the job becomes terminal after six refusals, or after five if a wait came first. Waits never make a job terminal. On an older daemon these states add an attempt too, so any six deferrals make the job terminal. A daemon with worker-exit recovery counts a worker that dies after capturing its torrent as a capture refusal: it rolls the transaction back and records the attempt. A terminal job is never retried on its own, and the WebUI raw door refuses `d.custom.set` by design.

1. Fix the cause first, for example resume the torrent or let its hash check finish.
2. List the terminal jobs. A job whose claim is held right now is listed as not resettable, and each job names its own refusal. A torrent removed while the list is read is skipped with a note.
3. Run a dry run for one hash, then repeat it with `--apply`. The tool checks the caller's exact Q, a `none` or `original` claim and the terminal value. It then clears only that retry state with one conditional daemon call and saves the session. A lost reply is read back, never resent.

```sh
docker exec -u torrent <container> php85 /rutorrent/app/tools/retrackers-retry-reset.php
docker exec -u torrent <container> php85 /rutorrent/app/tools/retrackers-retry-reset.php <HASH>
docker exec -u torrent <container> php85 /rutorrent/app/tools/retrackers-retry-reset.php <HASH> --apply
```

The queue belongs to the ruTorrent profile that ran the WebUI, which is not always the HTTP login:

- With `$forbidUserSettings = true` every login shares one unnamed profile. That is the Docker image's default (`RU_FORBID_USER_SETTINGS`). Run the tool without `--user`. A `--user=<login>` given anyway selects the same shared profile, and the tool says so.
- With `$forbidUserSettings = false` each HTTP login has its own profile: add `--user=<login>` before the hash. Without HTTP authentication that profile name is empty.

The worker's `queue-retry-exhausted` log line and the WebUI notice print the exact command: this install's PHP and the tool's absolute path, with `--user` when it is needed. Inside a Docker container they print the `docker exec -u <user> <container> ...` form for the daemon's user; fill in the container name.

The exit status:

- 0: the jobs were listed or the reset was done.
- 1: the reset was refused and nothing changed. This includes a hash this rTorrent does not hold (`torrent-absent`).
- 2: a usage error, a daemon without the native queue commands, a daemon the tool cannot reach, or an unconfirmed answer. A socket the user may not open is named with its uid; run the tool as the daemon's user.
