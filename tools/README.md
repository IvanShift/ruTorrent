# Fork tools

Local campaign notes and lab evidence live in the ignored `tasks/` directory and are never pushed. The maintained tools are tracked here.

| File | Purpose |
| --- | --- |
| [matrix.sh](matrix.sh) | Local matrix of the PHP suites: local PHP, `php:8.1-cli`, `php:7.4-cli`, and the production image without iconv. Each leg runs on its own export with its own `TMPDIR`. |
| [rt-lab.sh](rt-lab.sh) | A disposable lab instance on a chosen image with the working tree overlaid (`up` / `sync` / `down`). Mutating probes belong here, never on a live instance. |
| [retrackers-clear-markers.php](retrackers-clear-markers.php) | Clears a stuck retrackers recovery marker. `tests/plugins/erasedata/RepairToolRefusalTest.php` covers its refusals. |

Both shell tools resolve the repository root as their parent directory, so they must stay one level below it.

## Manual retrackers recovery for `candidate-claim`

A `v1:candidate-claim:<tx>` marker with an empty `retrackers-recovery-ack` means the original torrent may already be erased while its replacement has not finished. The plugin keeps refusing with `receipt-ledger-corrupt`, and deleting the marker by itself restores neither the torrent nor its data. Do not apply the rule for an old `v1:original:*` marker to this case.

1. Stop new retrackers operations and make sure its worker is not running. Save a copy of the rTorrent session files and of the plugin's state directory before any repair.
2. Run `retrackers-clear-markers.php` without arguments inside the container. It only reads the daemon and prints the hash, name, marker type, whether the ack is empty, and the fingerprint of the current generation. Match the hash against the log line and against the state of the old and new torrents. To apply, choose one row and keep its fingerprint.
3. For `candidate-claim`, check whether a completed successor torrent exists with the expected metainfo, path and files. If the replacement is incomplete, restore the original torrent and data from the saved state, or finish or cancel the transaction by hand, and keep the marker until then. A missing `wa:` after a restart does not prove that the replacement finished: those receipts lived only in the daemon's memory.
4. Only after verifying a consistent result, run the tool with `apply HASH FINGERPRINT` for the chosen row. It refuses when the marker, the ack or `local_id` changed, or when the receipt ledger is not empty. It clears only the chosen marker and ack in the daemon's memory, requests `session.save` and reads them back. An answer to `session.save` means the write was queued, not that the file is on disk. Repeat the report and check the chosen hash; before restarting the daemon, wait until its session file is saved and check its contents, and repeat the report after the restart. Then start the plugin and check its log. If the call or the in-memory confirmation fails, the tool exits non-zero; it cannot see a failed background write. Re-check the report before retrying.

This is a manual procedure; the plugin never clears markers automatically at startup.

In the Docker image, run the tool as the daemon's user, for example `docker exec -u torrent <container> php85 /rutorrent/app/tools/retrackers-clear-markers.php`.
