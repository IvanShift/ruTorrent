# Erasedata queue admission and recovery

A rejected deletion queue disables removal with data and holds cleanup. It does not disable ordinary torrent management or the independent tracker checker. The application log names the inspected physical path, refusal reason, actual UID/GID/mode/link count, expected properties and the offline recovery action. An initialization checks admission once; every destructive worker checks the live queue again.

## Existing standalone profiles

The legacy queue is `<settings>/erasedata`. Its directory must belong to the service UID and have exactly mode `0700`. Its physical ancestry must belong to root or the service UID. Ordinary ancestors must have no group/other write permission; root-owned sticky temporary parents have a separate admission rule. A symbolic link to settings does not hide an unsafe physical ancestor.

An old writable queue is held even if it appears empty or contains only lock files. The plugin cannot prove that its former contents were trusted and does not repair that provenance by changing permissions.

Stop rTorrent, PHP workers and all other writers before maintenance. Preserve and inspect the named queue's `*.list`, `*.pending`, journal and unexpected entries. Establish that existing obligations are trusted before accepting them. On the inspected physical paths, remove group/other write permission from each ancestor named by the refusal and set the existing queue to `0700`:

```sh
chmod go-w /actual/profile/settings
chmod 00700 /actual/profile/settings/erasedata
```

Replace these example paths with the log's actual paths; named user profiles may also require targeted changes to `share/users` and the user's directory. Do not run a recursive ownership change over the installation or discard pending records. A standalone installation can retain this sealed legacy queue; it does not need a Docker first-use witness. Restart and confirm that queue admission succeeds before requesting deletion with data.

## Docker protected profiles

For the stock `share/settings` profile, the protected queue is `erasedata-jobs/default` beside `share`; named profiles under `share/users/<name>/settings` use a stable `user-<SHA256(name)>` leaf. Custom profile layouts retain their legacy queue.

Protected admission checks the sibling `.erasedata-first-use` with `lstat`: it must be a regular file owned by UID `0`, have exactly mode `0400` and exactly one hard link. Symlinks, another owner, another mode and multiple links produce separate `witness-type`, `witness-owner`, `witness-mode` and `witness-links` refusals. The image provisions an empty `root:root` witness. Its contents are not application instructions. Backups must preserve its root ownership and mode; the service UID must never be able to rewrite it.

The `erasedata-jobs` root must be a real directory owned by the service UID with no group/other access; the image provisions `UID:GID 0700`. Both its parent ancestry and selected leaf must pass admission. An existing legacy queue is never hidden by a new protected root, even when the old queue is empty.

For Docker installations, use the image's existing **offline** operator command `attest-erasedata-queue --attest-clean-legacy-queue`, as documented in [docker-rutorrent upgrading and troubleshooting](https://github.com/IvanShift/docker-rutorrent#upgrading-and-troubleshooting). Use the installation's actual image, `/config` mount and numeric `UID:GID`, with its application container stopped. This command validates all default and named queues before mutation. It refuses active supervisors, links, foreign ownership, writable ancestors, nonempty queues and unexpected entries. It removes only inspected empty old queue directories and provisions both the protected root and empty root-owned witness together.

The command does not execute, reinterpret or discard pending jobs. If it refuses, preserve the named state and investigate it offline. Restarting, disabling permission checks, creating only one sibling, or a recursive `chown` does not establish attestation. The plugin deliberately provides no web-accessible way for its service UID to manufacture a root-owned witness.

## Native rename support

Erasedata's native helper is declared by `tools/native-helpers.list`, installed as `/usr/local/bin/rutorrent-erasedata-rename-noreplace`, and built from this fork's source. See [native helper installation](../../tools/README.md). A missing or malformed helper manifest and a missing binary are explicit refusals.

Before capture or restore, the caller runs `--probe DIRECTORY` on each actual parent filesystem. Support demonstrated on `/data` or `/tmp` does not establish support on another mount. The helper proves file and directory rename-without-replacement semantics and cleans its private probe entries. For Linux directory-descriptor paths, `proc_open` explicitly inherits an open directory resource into the child. Both capability probes and atomic rename use the child's own inherited descriptor, preserving the same directory inode even when a PHP-FPM worker is non-dumpable. The helper does not require access to the parent's `/proc/<pid>/fd` table or replace the action path with a mutable pathname.

The helper returns `0` on success, `1` for a destination collision, `2` for invalid arguments, `3` when atomic no-replace is unsupported, and `4` for another filesystem error. Refusals name the actual path and preserve source/destination bytes. There is no check/rename or link/unlink fallback: those sequences cannot guarantee that a concurrent replacement remains intact. Use a filesystem and kernel that pass the actual-parent probe, and preserve held jobs until support is available.
