# ruTorrent

ruTorrent is a front-end for the popular Bittorrent client [rtorrent](http://rakshasa.github.io/rtorrent).

This project is released under the GPLv3 license, for more details, take a look at the LICENSE.md file in the source.

## Main features

* Lightweight server side, so it can be installed on old and low-end servers and even on some SOHO routers
* Extensible - there are several plugins and everybody can create their own one
* Nice look ;) 

## Screenshots

[![](https://github.com/Novik/ruTorrent/wiki/images/scr1_small.jpg)](https://github.com/Novik/ruTorrent/wiki/images/scr1_big.jpg)
[![](https://github.com/Novik/ruTorrent/wiki/images/scr2_small.jpg)](https://github.com/Novik/ruTorrent/wiki/images/scr2_big.jpg)
[![](https://github.com/Novik/ruTorrent/wiki/images/scr3_small.jpg)](https://github.com/Novik/ruTorrent/wiki/images/scr3_big.jpg)

## Download

 * [Stable version](https://github.com/Novik/ruTorrent/releases/latest) — the latest tagged release
 * [Current master](https://github.com/Novik/ruTorrent/tarball/master) — what the next release is cut from

## Getting started

  * There's no installation routine or compilation necessary. The sources are cloned/unpacked into a directory which is setup as document root of a web server of your choice (for detailed instructions see the [webserver wiki article](https://github.com/Novik/ruTorrent/wiki/WebSERVER)).
  * After setting up the webserver `ruTorrent` itself needs to be configured. Instructions can be found in various articles in the [wiki](https://github.com/Novik/ruTorrent/wiki).

### Application logging

`RU_LOG_FILE` selects ruTorrent's shared application log. Its backward-compatible
default remains `/tmp/errors.log`, but that historical filename does not make it
an errors-only stream: operational notices and explicitly enabled plugin
diagnostics use the same sink. Leave the configured path blank to disable file
logging. Log routing and rotation remain deployment responsibilities.

The bundled `rutracker_check` plugin keeps `$rutrackerCheckDebug` disabled by
default. Enabling it intentionally sends sanitised diagnostics to the shared
application log. A physically separate plugin debug file should be an explicit
local opt-in customization; it is not required by, and must not silently replace,
the existing `RU_LOG_FILE` behavior.

### Talking to rtorrent

Two separate budgets in `conf/config.php` govern one XML-RPC call, because they
answer different questions.

`$rpcTimeOut` (default `5`, seconds) is the **connect** timeout: how long to wait
for rtorrent to accept a socket. A local SCGI socket answers in microseconds, so
a tight value is right here — when rtorrent is down, the panel should say so at
once rather than hang.

`$rpcTransferTimeOut` (default `null`) is how long to wait for rtorrent to
**answer**, once connected. rtorrent computes a whole multicall before it writes
its first byte, so on a large session — or one still loading from disk after a
restart — a reply can legitimately take far longer than opening the socket did.
`null` means PHP's `default_socket_timeout`, usually 60 seconds. Set a number to
override it without touching `php.ini`. It is independent of `$rpcTimeOut`: the
two bound different phases, and neither constrains the other.

The same transfer value is one absolute budget for sending the complete SCGI
request and the idle timeout while reading its reply. rTorrent supplies a
required `Content-Length`; ruTorrent therefore stops at that exact body instead
of waiting for the peer to close the socket. `$rpcMaxResponseBytes` defaults to
64 MiB and may be raised only as far as rTorrent's supported 100 MiB wire
ceiling.

Note that the browser gives up independently: `webui.reqtimeout` (Settings →
Interface, default 10 seconds) aborts the request no matter how patient PHP is.
Raising `$rpcTransferTimeOut` past that only helps the paths the browser is not
waiting on, such as the plugins rtorrent runs on a schedule.

## Contributing

Pull requests target **`master`**. There is no `develop` branch — it was retired,
and a release is simply a tag on `master`.

Using AI tooling to write a patch is fine. Opening what it produced without
reading it is not. Whatever wrote the code, the pull request is yours, and that
means three things before you open it:

1. **Check that it is sane.** Read the whole diff yourself. A PR that reformats
   files it had no reason to touch, or that contains changes you cannot explain
   in your own words, will be closed rather than reviewed.
2. **Vouch for it.** Run it on a real ruTorrent instance that you actually use —
   your own, with your torrents and plugins, left running long enough to see it
   behave. A container spun up to prove the diff applies is not a test. Say in
   the PR what you ran it against and what you saw.
3. **Answer reviews personally.** Review comments are addressed to you. If the
   replies are generated and unread, the PR stops there.

Keep the diff scoped to the change. Whitespace churn, unrelated reformatting and
rewritten vendored files make a patch unreviewable, and a patch nobody can
review does not get merged.

## Requirements

* **PHP 7.4 or newer**, with the `json` and `pcre` extensions. The `simplexml`,
  `curl`, `mbstring` and `zlib` extensions are recommended — some plugins and the
  XMLRPC proxy need them — as are the `php`, `curl` and `gzip` command-line
  programs.
* **rtorrent**: the `0.9.8` baseline and the `0.16.x` series are supported.
  Other versions may or may not work.
* A web server with ruTorrent's directory as its document root (see the
  [webserver wiki article](https://github.com/Novik/ruTorrent/wiki/WebSERVER)).

### Checking your environment

A checker is bundled with ruTorrent. Run it from the command line, from inside
your ruTorrent directory:

```
php env_check.php
```

It verifies the PHP version and extensions, the external programs plugins rely
on, common `conf/config.php` mistakes, and connects to rtorrent to report and
classify its version and to check that ruTorrent's event handlers are registered
with it. It exits `0` when everything required passes and `1` otherwise, so it
can be used in scripts. For safety it runs on the command line only and refuses
to run under a web server.
