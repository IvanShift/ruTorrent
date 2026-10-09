
    Plug-in "autotools" version, 2012-02-15

    for rutorrent Webui (rtorrent web gui)
    (https://github.com/Novik/ruTorrent)


    Plugin Author: Dmitry Romanovsky (dmrom)

Plugin Features:
1. "AutoLabel": Automatic creation of labels when adding a new torrent to rutorrent WebUI

2. "AutoMove":  Automatically moves torrents which have finished downloading from the
                rtorrent download directory to a new directory.  rtorrent will seed from\
                this new location.

3. "Autowatch: Automatically add torrents to rtorrent via a nested set of watch directories.


---------------------------------------------------------------------------------------------
"AutoLabel"
---------------------------------------------------------------------------------------------
Label will be created automatically if:

- Label is created by template, that is set in Autotools options page.
- Label is created only if "Label" field in the "add torrent" window is empty.

Template variables:

{DIR}
  If your download directory is "directory = /usr/p2p/downloads"
  and the new torrent is set to download to /usr/p2p/downloads/Video/DVD/ then
  the variable would be "Video/DVD"

{TRACKER)
  The variable would be set to tracker name.

{NOW}
  The variable would be set to the current date using strftime() function.
  Default format is "%Y-%m-%d". It is possible to set custom format using
  syntax: "{NOW[:<format>]}", for example: "{NOW:%Y-%m-%d %H:%M}"


---------------------------------------------------------------------------------------------
"AutoMove"
---------------------------------------------------------------------------------------------
Downloaded torrent data is moved to a new directory preserving the directory scruture relative to the
original download directory set in ".rtorrent.rc"

This works in much the same way as AutoLabel.  If you create a nested hierarchy of Directories like:
/usr/p2p/downloads/Video/DVD/
/usr/p2p/downloads/Video/Blueray/
/usr/p2p/downloads/Video/TV/

and your download path is set to /usr/p2p/downloads in .rtorrent.rc, torrents saved
in these subdirectories will be automatically moved, on completion of downloading,
to the "AutoMove" Directory so, for example, if you set the "AutoMove" Directory
to /media/p2p/ and you set a torrent to download to /usr/p2p/downloads/Video/DVD/
when it is finished downloading, it will be moved to /media/p2p/Video/DVD/

Torrents will then seed from this new location.

It was planned to use this plugin with devices mounted from another location, SMB or NFS shares.
This solution works on FreeBSD, but according to Novik "under the 2.4 linux kernel
(which is used on many routers), the smbfs and nfs mounts will not work.  Calling mmap,
does not work in these conditions".
If possible, use FreeBSD or any linux kernel past 2.6.26

Move refuses an existing destination instead of replacing another payload. With
native file-job support, rTorrent owns a durable queue and Stop/Close lease;
the PHP payload journal records publication and recovery. Running, paused-open,
stopped-closed and a later user Stop keep their intended state. Cross-filesystem
moves copy to a private stage before publishing and removing the source.

When no native Move capability is present, Move uses the synchronous upstream
workflow and displays a warning about crash and retrackers reload limitations.
An incomplete or unconfirmed native ABI pauses new Move admission visibly.
Copy, HardLink and SoftLink continue to use their existing transaction worker.

The native queue waits without consuming failure attempts when the source or
transport is temporarily unavailable. Six counted preflight, storage or worker
failures hold the job visibly. Inspect and explicitly retry it with:
  php tools/autotools-move-reset.php HASH --user USER
  php tools/autotools-move-reset.php HASH --user USER --apply
The second command resets the retry counter; it preserves any captured lease.

Inspect current Move obligations with:
  php tools/autotools-move-readiness.php [--user USER]
The read-only report includes loaded and durably saved native Move jobs, payload
journals, unsupported documents and unknown AutoTools metadata. It returns 0
when clear, 1 when work remains, and 2 when the inventory cannot be confirmed.
Unsupported state is held visibly with its path or torrent hash and key; the
tool does not import, adopt, delete or infer ownership of that state.
The report is a snapshot. It neither stops new admission nor attests other
plugins' queues and shared file-operation leases.

For your convenience, it is recommended that you install the plugin "_getdir"
This will make navigating the filesystem from the webgui much easier.

After file transfer the plugin searches for file ".mailto" in directories,
from "/media/p2p/Video/Movie/ downto "/media/p2p/. If this file is found,
e-mail will be sent according to information from this file.
"CC:" and "BCC:" params are optional
Sample file is (without "===" lines):
===========================================
TO   : user@domain.ru
CC   : cc_user@domain.ru
BCC  : bcc_user@domain.ru
FROM : Torrent Downloader<admin@domain.ru>
SUBJECT : Torrent "{TORRENT}" is finished!
Hello, User!

  Requested torrent

  "{TORRENT}"

  was successifully downloaded.
===========================================


----------------------------------------------------------------------------------------------
"AutoWatch"
----------------------------------------------------------------------------------------------

*.torrent files are placed in nested subdirectories of a desired structure on
some base directory.  This base directory is set in the plugin settings.

This works very much like normal watch directories in rtorrent, except in a nested
hierarchy.  Torrents added in this way, will be downloaded to a corresponding directory
in the path of whatever dir you've set in .rtorrent.rc

With this, you can create a system of watch directories to drop .torrent files into.



----------------------------------------------------------------------------------------------
Version History:
----------------------------------------------------------------------------------------------

    2012-02-15:
    - "CC:" and "BCC:" options for .mailto
    - Minor changes

    2010-06-26:
    - Plugin was adapted for ruTorrent 3.1
    - Function AutoLabel can be configured by templates

    1.5
    - Plugin was adapted for ruTorrent 3.0

    1.4
    - Added function AutoWatch
    - Removed scripts *. sh, to initialize the plug-in rtorrent.rc recommended
      initplugins.php use the script from the main directory ruTorrent:
      execute = (sh,-c, full_path_to_php full_path_to_rutorrent / initplugins.php &)

    1.3
    - Fixed bug that caused it to crash rTorrent, if the names of subdirectories
      torrent service marks are used, such as, 'etc.
    - An attempt to avoid unnecessary reheshirovaniya, which sometimes occurs.

    1.2
    - Plugin renamed and merged with autotools automove 1.0
    - Added ability to set the settings in the options ruTorrent

    1.1
    - Ensuring compatibility with plug-ins retrackers and edit
    - Plugin now runs until retracker (runlevel.info: 5)

    1.0
    - First version

----------------------------------------------------------------------------------------------
Thanks to Thomas Burgess for readme translation.
----------------------------------------------------------------------------------------------
