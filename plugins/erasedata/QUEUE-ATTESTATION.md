# Erasedata queue admission and recovery

A rejected deletion queue disables removal with data and holds cleanup. It does not disable ordinary torrent management or the independent tracker checker. Admission diagnostics identify the refusal and its recovery action; ownership/mode refusals include the inspected physical path and UID/GID/mode/link count. Initialization checks admission once; every destructive worker checks the live queue again. This source document is also available in the fork repository; Docker images may omit Markdown files.

## Existing standalone profiles

The legacy queue is `<settings>/erasedata`. Its directory must belong to the service UID and have exactly mode `0700`. Its physical ancestry must belong to root or the service UID. Ordinary ancestors must have no group/other write permission; sticky parents owned by root or the service UID have a separate admission rule. A symbolic link to settings does not hide an unsafe physical ancestor.

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

The command does not execute, reinterpret or discard pending jobs. Older images also refuse queues containing only `.lock` files. If the installed command refuses, preserve the named state and investigate it offline; do not remove a live lock to make the command accept it. Restarting, disabling permission checks, creating only one sibling, or a recursive `chown` does not establish attestation. The plugin deliberately provides no web-accessible way for its service UID to manufacture a root-owned witness.

## Native rename support

Erasedata's native helper is declared by `tools/native-helpers.list`, installed as `/usr/local/bin/rutorrent-erasedata-rename-noreplace`, and built from this fork's source. The [tools documentation](../../tools/README.md) describes the shared manifest and helper consumers. Standalone installations must compile the declared C source with the manifest's compiler flags, then install the resulting executable at the declared path through their administrator. Keep `php/nativehelpers.php` and `tools/native-helpers.list` available to PHP even when HTTP access to `tools/` is denied. A missing or malformed helper manifest and a missing binary are explicit refusals; a missing helper disables erasedata at initialization.

Before arming a removal or sending `d.erase`, admission runs `--probe DIRECTORY` on the queue and the existing payload parents, caching only the same resolved parent path and physical identity within that admission. Support demonstrated on `/data` or `/tmp` does not establish support on another mount or bind alias. Unusable parents refuse removal while the torrent and payload remain present. Cleanup preparation for tracker replacement and drain recovery use the same actual-parent preflight before staging or erase. That preflight, `ErasedataFilesystemOps::preflightParents()`, also admits datadir payload moves and AutoTools Move: a destination directory that does not exist yet is probed at its deepest existing ancestor. Private `tmp` to `list` publication uses ordinary rename under the hash lock and has no capability probe. Captures probe the public source parent; attested private capture roots are never probed. Restore invokes the shared no-replace helper directly, without a second probe.

The helper proves file and directory rename-without-replacement semantics in `.rutorrent-rename-probe-<pid>-<hex>` workspaces. An interrupted probe is recovered on the next probe only after checking directory identity, UID, mode, a private single-link lock and the fixed plain file/empty-directory entries. A held flock identifies an active probe. The helper waits at most 60 seconds for the parent's flock; a holder that outlasts this refuses the probe with status 4 and `errno=110`, whose logged action names the flock. One preflight remembers a refusal per parent as well as a success, so a drain pass that holds its locks waits once per parent and each member still gets its classified outcome. Unknown entries, links, foreign ownership or unsafe modes are preserved and refused. Queue admission attests recognized probe residue before retirement can treat it as infrastructure. That read asks the helper's own flock on the workspace's parent directory, without waiting. While that flock is busy the read is skipped and the workspace counts as valid; busy proves a live holder of the parent's lock, not that the holder is a helper. Once the flock is obtained no helper runs and none can pass the lock, so the strict checks read stable state, and only a root that a lookup answers is gone is not a refusal. When the lock cannot be asked for, the same strict checks run without it, and a live probe can make them refuse visibly until a later pass. For Linux directory-descriptor paths, `proc_open` explicitly inherits an open directory resource into the child; the helper uses the child's own descriptor even when PHP-FPM is non-dumpable.

On EINVAL, ENOSYS or EOPNOTSUPP from `renameat2(RENAME_NOREPLACE)`, as WSL2 drvfs over 9p answers, the shared helper checks destination absence with `fstatat(AT_SYMLINK_NOFOLLOW)` and then uses `renameat` under the caller's transaction lock. Existing entries, including dangling symlinks, refuse as collisions. This fallback relies on the project's trusted same-UID process model and caller locking; it does not protect against a malicious same-UID writer between the check and rename. EACCES, EROFS, EXDEV and other real filesystem failures still refuse. rTorrent's session sidecar publication and the Docker image's startup probe report implement or read the same rule; the C source names both.

The helper's exit statuses are defined once, in `ErasedataFilesystemOps::RENAME_HELPER_STATUS` (`filesystem.php`). `RenameHelperStatusTest.php` checks this table, the C source and the helper's shell test against it:

| Status | Reason | Meaning |
| --- | --- | --- |
| `0` | `ok` | The rename happened, or the probed directory supports native no-replace rename. |
| `1` | `rename-noreplace-collision` | The destination exists, including a dangling symlink. Both entries are preserved. |
| `2` | `rename-noreplace-invalid-arguments` | The helper was called with relative or identical paths. |
| `3` | `rename-noreplace-unsupported` | Even after the fallback, plain `renameat` refused with EINVAL, ENOSYS or EOPNOTSUPP, for example for a directory moved into its own subtree. From `--probe`, the self-check also saw a rename replace an existing entry. |
| `4` | `rename-noreplace-error` | Another filesystem error, such as EACCES, EROFS or EXDEV, or ETIMEDOUT when `--probe` gave up waiting for its parent's flock. |
| `5` | `rename-noreplace-fallback` | `--probe` only: the directory is usable through the caller-locked fallback. Callers accept `0` and `5` as usable probes, and only `0` from a rename. |

Diagnostics retain the numeric native errno and preserve non-UTF8 path bytes as base64. Each consumer logs the refusal under its own name (`erasedata:`, `datadir:` or `autotools: move <hash>`). Retry reporting belongs to the drain's durable report memory.
