# Comment to post on Novik/ruTorrent#3198 (reply to xirvik)

## Final concise reply after `c39d499d`

```markdown
Thanks, agreed. I moved the response guard into
`commonAccount::isOKPostFetch()`, so it now applies to all accounts by default
instead of being Kinozal-specific.

The base implementation distinguishes a valid authenticated response, an
explicit guest/login-wall response, and no usable response. Only the explicit
guest result triggers re-login. A transport failure, empty response, unfinished
redirect, or server error now returns failure while preserving the cached
session, so a temporary outage cannot cause repeated credential POSTs or delete
valid cookies. `304 Not Modified` remains valid for authenticated RSS requests.

Kinozal now keeps only its tracker-specific payload check. LostFilm is the only
account that overrides `isOKPostFetch()`; its recovery path now preserves the
base verdict. NNMClub and the other accounts need no file-level change because
they inherit this guard from `commonAccount`.

The branch is rebased onto current `master` as one commit. The full PHP test
harness passes on PHP 7.4, 8.1, and 8.5; the focused account suites, lint checks,
and adversarial mutations are also green.

Thanks for pointing this out.
```

Published state: `origin/up/kinozal-session=c39d499d`, exact five-file diff on
`upstream/master=781cee4e`, GitHub clean merge and 8/8 checks GREEN.

## Detailed rationale retained for review history

---

Agreed — it is not a Kinozal bug, it just showed up there first. Force-pushed a
rebase onto current master; the PR is now one commit and says what it does.

Putting the guard in the base class turned out to be right, but a plain
non-empty-body check in `isOKPostFetch()` would have swapped one bug for
another, so the shape changed while I was writing it.

**The cached path had no status check at all, and a failure there falls through
to a re-login.** That branch is

```php
$data->loaded &&
    $this->updateCached(...) &&
    $client->fetch($url,$method,$content_type,$body) &&
    $this->isOKPostFetch(...)
```

It asks only whether `Snoopy::fetch()` returned true, and that is true for a 500
as readily as for a 200 — so the error page went back to the caller as the page
or the torrent it asked for. But because this is the left side of an `||`, a
`false` here runs the whole login branch and then `$data->remove()`. Adding a
status test alone would have turned a tracker answering 503 for a minute into a
credential POST **per call** — callers loop over whole torrent lists — and an
empty cookie cache afterwards. That is how an account gets locked out.

So the answer is classified three ways rather than two:

| | |
|---|---|
| `ANSWER_LIVE` | the tracker answered from behind the login wall |
| `ANSWER_GUEST` | the tracker answered, and answered as a guest — the session is dead, log in again |
| `ANSWER_NONE` | nothing usable arrived — says nothing about the session, so report the failure and keep the cookies |

Only `ANSWER_GUEST` re-logs in. Every `isOK()` under `accounts/` answers the
first two by looking for a guest marker and none of them can answer the third,
because a page that never arrived carries no marker either — which is why all
three used to collapse into "the session is live".

Three details that are decisions rather than mechanics:

**304 is `LIVE`, not "an empty body".** `plugins/rss` sends `If-None-Match`
through `fetchComplex` and reads `$client->status` itself. An unchanged feed
answers 304 with no body by definition, so a bare non-empty-body rule would have
turned every poll of an authenticated feed into a fresh login — the same storm
as above, on a timer.

**3xx is `NONE`, not `LIVE`.** A followed redirect chain ends at the status of
its last hop, so a 3xx that reaches here means Snoopy stopped following, and the
boilerplate body a server puts on one carries no guest marker either. That was
the case my first version claimed to close and did not.

**The login answer keeps its own, looser predicate.** A login endpoint may
legitimately answer with no body — a 30x to the landing page, or a 200 whose
whole content is `Set-Cookie` — and it is the fetch that follows which proves
whether the session took. So the marker test is applied only when there is a
body to apply it to, and the status range stays the `200..399` the class has
always used there. Kinozal's `login()` therefore goes back to reporting whether
the exchange happened, like its twenty-four siblings, and its private copy of
the status guard goes with it.

**The body is checked for being a string, not just non-empty.** Snoopy does not
always leave one: on a build without `gzinflate()` it shells out to gzip and
hands `$results` to `exec()`, which replaces it with the command's output array.
Every marker test under `accounts/` is a `strpos()`, fatal on an array in PHP 8.
That is worked around here and fixed at the source in a separate PR, since a
dozen other consumers of `->results` have the same exposure.

**`check()` needed two fixes of its own.** It passed `login()` five by-reference
locals it never declared, so they arrived as `null` and an account whose
`login()` begins by fetching `$url` could not authenticate from the cron at all.
And it deleted the cached session whenever the renewal failed — this job exists
to *refresh* a session, so deleting one it merely failed to renew left the user
worse off than not running it.

**On your "with those two overriding":** MyAnonamouse and YggTorrent needed
nothing — they only add their own status checks *inside* `isOK()`, which the
base guard sits in front of quite happily. `LostFilm` is the account that
needed changing: it is the only override of `isOKPostFetch()` in the tree, and
both of its exits skipped the verdict. The fallthrough answered `true` on its
own (which on the cached path skipped `isOK()` with it, so this account was
checking nothing), and it answered about whatever was in `->results` — by then
the `details.php` page its recovery had fetched, not the answer the caller asked
for. Its download branch returned on `get_filename()`, which reads
`Content-Disposition` and never the status or the body.

## How to verify

`tests/plugins/loginmgr/CommonAccountTest.php` drives the real `fetch()` and
`check()` with a scripted client and a recording cache, because the claims are
about which branch runs, whether a credential POST is spent and whether the
cookie cache survives — none of which a predicate test can see. Ten separate
mutations of the commit were each caught by a different case.

`KinozalAccountTest`'s client double is corrected while nearby: it answered
`false` for 4xx/5xx where the real Snoopy answers `true`, so it was doing the
rejecting the code under test is supposed to do.

```sh
cd tests && bash php-test.sh
```

PHP 7.4, 8.1 and 8.5: full harness GREEN. Focused suites are 5/5 account
selection, 18/18 common-account and 9/9 Kinozal, with zero failures.

## Two things I found next door and sent separately

- **Account selection by URL substring.** `commonAccount::test()` prefix-matches
  the URL string, so `https://tracker.example@evil.test/` and
  `https://tracker.example.evil.test/` both select the account whose cookies are
  then sent to the attacker's host; seven accounts match their domain anywhere
  in the URL, which also accepts `https://evil.test/path/tracker.example/x`. The
  URL need not come from the user — `plugins/rss` follows feed links.
- **The Snoopy `exec()` body clobber** described above, fixed at the source.
