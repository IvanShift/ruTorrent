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
  * Keep `share/`, `conf/`, `tests/` and `tools/` out of what the web server will serve. `share/` holds the profile data — settings, stored torrents, and the tracker credentials the `loginmgr` plugin keeps — and it sits under the document root by default. Point `$profilePath` in `conf/config.php` at a directory outside the document root, such as `/var/lib/rutorrent`: that is the one measure nothing else has to be right for. Apache denies the four directories from the `.htaccess` file already in each of them; nginx and lighttpd do not read `.htaccess` and need the rules in [`nginx-example.conf`](nginx-example.conf) — written for the prefix the install is served under, and placed before the `.php` handler.

### Static asset deployment

After copying a release and any plugin/theme assets into their final location,
run `php tools/update-asset-version.php` in that directory before serving it.
Run it again after changing static assets. The tool gives core, language and
injected plugin assets one content-derived cache key, independent of the
application's display version. Unchanged files keep the same key. It runs once
at deployment; web requests do not scan files or invoke Git.

A plain checkout is suitable for development when the server revalidates static
files (`Cache-Control: no-cache`). Its language/plugin fallback uses one fresh
key per page load. Production deployments should run the tool and revalidate
`index.html` so clients learn each new deployment's key; long-lived caching of
the index can keep an old asset key in use. Keep `tools/` inaccessible over HTTP,
including when ruTorrent is served below a URL prefix.

### Trusted browser origins

The default browser origin must match the request's scheme, host and port.
For explicitly trusted additional origins, set `$trustedOrigins` in
`conf/config.local.php` to an array of complete origins, or provide the
comma-separated `RU_TRUSTED_ORIGINS` environment value. Entries use HTTP(S)
origins with their scheme and port, or exact `moz-extension://ID` /
`chrome-extension://ID` origins. Wildcards are not supported. The older
`$enabledOrigins` array remains a compatibility setting with hostname-based
semantics. Forward the external `Host` and a trusted `X-Forwarded-Proto` through
a reverse proxy; `X-Forwarded-Host` is ignored.

### Public feed URLs behind a proxy

If a reverse proxy rewrites `Host` or the application prefix, set
`RU_PUBLIC_URL=https://public.example/torrents/`, or set `$publicURL` to that
absolute application base in `conf/config.local.php`. A nonempty environment
value overrides the local setting; an empty or unset value permits it. RSS
channel, image and download links use this base, including its port and path prefix. Credentials,
query strings and fragments are refused with a configuration diagnostic.
Without this setting the feed uses the direct request Host, the server TLS scheme (or the first
`X-Forwarded-Proto` entry when TLS is terminated upstream) and the server's script path. A CSRF trusted origin
does not select the feed URL. Torrent-upload redirects are relative, so the
browser preserves the public origin and prefix.

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

`$rpcTransferTimeOut` (default `null`) bounds the complete request write and
response read together. rTorrent computes a whole multicall before it writes its
first byte, so a large or restarting session can need longer than connecting.
`null` uses the greater of the connection timeout and PHP's finite positive
`default_socket_timeout` (or 60 seconds). Progress cannot extend this total budget.
For a large response over a slow connection, set a finite positive value such as
`180` for an 80-second transfer and allow enough time in PHP and the web server.
Both timeouts are capped at 2147483 seconds; there is no unlimited mode.
`php env_check.php` reports unusable settings. A transfer timeout names the budget
and the setting to raise in the application log.

rTorrent supplies a required `Content-Length`; ruTorrent stops at that exact body
instead of waiting for the peer to close the socket. `$rpcMaxResponseBytes`
defaults to 64 MiB and may be raised to rTorrent's 100 MiB wire ceiling. Set an
integer byte count, not `"64M"`; an invalid limit refuses the call. Responses over
1 MiB spill to a temporary file under `FileUtil::getTempDirectory()` when possible;
without a usable temporary directory, the bounded response stays in memory and
may need about twice its size. The socket readiness wait uses `stream_select()`
when available and bounded backoff otherwise.

The tested rTorrent 0.16.21 has a separate 60-second SCGI request deadline.
A long synchronous call can finish its work after that deadline but lose its
reply: in a measured 65-second call, rTorrent closed the connection without
response headers when the call ended. Raising `$rpcTransferTimeOut` cannot
recover a reply discarded by that daemon timer, and the client need not fail
at exactly 60 seconds. Check other rTorrent releases separately before
assuming the same limit. A connection closed before response headers can
also have other causes; ruTorrent reports the close and offers the deadline
as a possible explanation.

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

## HTTP response cookies

Snoopy keeps response cookies in memory with host-only/Domain, Path, Secure and expiry scope. The response-jar limits are 50 cookies per account/domain, 300 per client and 4096 bytes per cookie name/value. A response also admits at most 100 Set-Cookie fields, each at most 8192 bytes including attributes; unrelated headers do not consume that allowance. Refusals and capacity evictions use the existing application logger without cookie values. An unavailable logger cannot abort the HTTP request. Explicit caller cookies and raw headers remain outside these response-cookie limits. A raw `Cookie` header takes precedence over generated cookies; if a caller supplies case-variant duplicates, the first one is used. `passcookies=false` disables automatic capture; explicit `setcookies()` still supports legacy callers.

For larger response sessions, adjust the applicable `MAX_RESPONSE_COOKIES`, `MAX_DOMAIN_COOKIES`, `MAX_COOKIE_BYTES`, `MAX_COOKIE_HEADERS` and `MAX_COOKIE_HEADER_BYTES` constants in `php/Snoopy.class.inc`. The socket reader's existing `_maxlinelen` must also exceed the complete header length; its default is 4096. The focused operator test verifies all five cookie limits at `PHP_INT_MAX` with `_maxlinelen=65536`: 150 short cookies plus a 12000-byte cookie value reach a real loopback HTTP peer. Validate the corresponding capacity expectations and your largest session when maintaining different local limits.

`setcookies()` explicitly exports accepted response cookies to the public `cookies` array. Cookies collected along followed redirects remain in the scoped jar for account persistence; terminal responses also stay private until that explicit export. The fetched-response parser exports only accepted live cookies and deletions. Rejected, unprocessed, expired or evicted values cannot enter a new cache through this facade. In-memory provenance binds each exported name to its exact cookie tuple, so deletion of another Path or Domain preserves the live exported value. An equal preexisting caller value remains manual input even after its response tuple expires. Unchanged derived values cannot bypass scope on the same client; clearing or changing a facade entry releases its hidden provenance. A changed caller value overrides by name; assigning the same exported value again cannot express a new override through that array, so use an explicit raw `Cookie` header when needed. Loginmgr persists accepted response cookies with their scope in the selected account partition; explicit caller cookies keep the existing flat representation. Sessions from different accounts remain isolated.

Cookie Domain checks use the bundled ICANN and PRIVATE Public Suffix List from [publicsuffix.org](https://publicsuffix.org/list/public_suffix_list.dat), version `2026-09-24_13-26-36_UTC`, upstream commit `a179a48c465e818cfd8d626691cb317985da87fb`. Its MPL-2.0 notice is preserved in `php/public_suffix_list.dat`. To update it, replace the complete snapshot from that official source, preserve its notice/version, update the expected SHA-256 digest in `php/publicsuffix.php`, review the snapshot provenance and the PSL rule assertions in the test fixture, and run `PublicSuffixTest` and `SnoopyResponseCookieTest`. No list is downloaded at runtime. Missing, unreadable or damaged data logs the affected `php/public_suffix_list.dat` path and refuses Domain cookies while host-only and caller cookies remain usable. Repairing that file allows the same process to retry; failures are not cached. ASCII IDN/punycode and IP Domain attributes are refused until matching IDNA handling is available.
