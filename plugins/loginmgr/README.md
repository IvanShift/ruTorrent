# YggTorrent trusted origin

Set `$yggTorrentOrigin` in `conf/config.php` to the exact HTTPS origin you trust.
In docker-rutorrent this file lives on the persistent configuration volume and
survives image updates. Add this line inside the existing PHP file, before any
closing `?>` tag:

```php
$yggTorrentOrigin = 'https://tracker.example'; // Replace with your verified tracker origin.
```

Plugin `plugins/loginmgr/conf.local.php` can override this value; when per-user
settings are enabled, `conf/users/<user>/plugins/loginmgr/conf.php` takes precedence
over both. A plugin-local file inside a container image is not persistent unless
you bind-mount it separately.

An optional port from 1 through 65535 is supported. Do not include credentials,
a path, query or fragment. The configured host is exact: sibling hosts and subdomains are separate
trust decisions. Enter internationalized host names in ASCII punycode (`xn--...`).
Host case and a trailing DNS root dot are normalized.

No current mirror is assumed. Until this setting is valid, YggTorrent session
downloads and automatic login are disabled. The application log (`$log_file`)
names the setting, rejection reason and all configuration override locations once per
request (once per scheduled refresh run) on a Ygg-shaped download or an explicit
account refresh. A per-user `conf/users/<user>/config.php` can also set the
origin before these plugin files are loaded. The account settings page shows
when an enabled Ygg account needs configuration. Unrelated URL selection stays quiet.
Existing enabled accounts need this configuration before they can use their
stored session again. The YggTorrent extsearch engine uses this same origin for
search and download links; it stays disabled until the setting is valid.

Snoopy keeps credentials on same-origin redirects and on a default-port HTTP
(80) to HTTPS (443) upgrade to the same host. During loginmgr fetch/refresh,
HTTPS redirects may also stay within the selected account's URL policy. Every
other account-scoped cross-origin redirect is refused, even before the first
session cookie exists. Outside account scope, a cross-origin redirect is
refused when the client already carries cookies, URL Basic credentials or raw
Cookie/Authorization headers. An anonymous redirect may proceed; any
Set-Cookie from its source response is discarded rather than sent to the new
host. On refusal the original HTTP response and Set-Cookie remain available
to the login handler, and the application log records
`credential-redirect-refused`. RSS shows the reason for both feed and item
download failures. These rules do not change Snoopy's existing TLS certificate
verification policy.

HTTP download links do not receive stored loginmgr sessions for accounts configured
with HTTPS, including Kinozal, RuTracker, NNMClub, TapochekNet, Toloka and Zamunda.
The cookies plugin and explicit `:COOKIE:` values have separate rules and may
still send cookies over HTTP. A one-time log hint names an HTTP link that would
match an enabled account over HTTPS.
Update manually entered URLs, RSS sources and `rssurlrewrite` rules to use the
tracker's HTTPS URL. Otherwise the request is anonymous and may return a guest
page instead of a torrent file.
