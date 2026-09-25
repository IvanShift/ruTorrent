<?php

// Set this in conf/config.php, plugin conf.local.php, or per-user plugin conf.php to the exact
// HTTPS origin you trust for YggTorrent, with an optional port, an ASCII
// (punycode for IDN) host, and no path.
// No mirror is trusted by default. Empty/invalid configuration disables Ygg
// session downloads and automatic login and emits a configuration diagnostic.
// Copy this line into a local override and set the verified origin there:
// $yggTorrentOrigin = 'https://tracker.example';
