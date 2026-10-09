# Recovering a held directory change

A held job keeps its daemon Stop/Close claim. Start and another directory
change stay blocked until the exact job finishes or safely cancels. Its
`x-datadir-outcome` notice and server log name the protected JSON document,
the torrent hash (the key), and the refusal. A `.move` receipt beside the
document records the payload identities and witness directory.

Preserve the document, receipt, witnesses, session, and both payload roots.
Do not delete a journal entry, clear a daemon claim, rewrite paths, unescape
stored bytes, or remove unknown workspace files to bypass a refusal.

After repairing an unavailable filesystem, permissions, or another reported
cause, retry one job as the service Unix user, using its actual PHP binary:

```sh
php85 /rutorrent/app/plugins/datadir/recover.php --retry HASH USER
```

Use the 40 hexadecimal torrent hash and its ruTorrent profile name; pass
`''` for the default profile. The command acquires the same profile lock as
scheduled recovery and reuses the exact job, native lease and filesystem
proofs. Exit 0 means completion or proven cancellation; exit 1 retains the
job and reports its document and cause. An unavailable or busy lock also
leaves the job intact. This command does not force a lease release.

`job-paths-ambiguous-unmoved-unproven` means an old release stored escaped
paths and at least one payload file is no longer proven unmoved. Neither
stored spelling identifies the intended payload by itself. An operator
must establish the real payload and receipt-bound device/inode identities
before restoring any interrupted movement. Retry can cancel only after
every source file again has its receipt-bound identity and its destination
has no published file. If that cannot be established, keep the job and
payloads and investigate offline; a generic force-cleanup is unsafe.

Before rolling back to a release with the old XML-RPC string parser,
finish or safely cancel all jobs on this release. Raw quote/backslash jobs
and cancellations with a persisted refusal use version 3 and old readers
retain them. Do not downgrade their version to make an old reader accept
them: its path comparisons can change bytes or select a neighbouring file.
