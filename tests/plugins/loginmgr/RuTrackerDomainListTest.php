<?php

require_once(__DIR__ . '/../../php/TestCase.php');

/**
 * ruTrackerAccount's domain list -- the hosts this account will send the
 * rutracker.org session to -- and its deliberate difference from the RuTracker
 * host list plugins/rutracker_check keeps.
 *
 * Two lists name RuTracker hosts in this repository and they answer different
 * questions. RuTrackerDetector::TRACKER_HOSTS answers "is this host
 * RuTracker's?", for attribution and for the requests that plugin sends
 * itself, so it carries t-ru.org and rutracker.cc as well as the forum
 * mirrors. The list here answers "may this host be handed this account's
 * cookies and download POSTs?", which is the forum mirrors and nothing else.
 * The password is posted only to the account's configured rutracker.org URL.
 *
 * Keep the trust decisions separate. plugin.info declares
 * rutracker_check dependent on loginmgr, so loginmgr may not require a file
 * from it. This suite is the substitute, and it is deliberately asymmetric:
 * it fails when FORUM_HOSTS is edited, and when the detector stops calling
 * rutracker.cc, t-ru.org or one of these four mirrors RuTracker's. It does
 * NOT fail when a host is ADDED to the detector, because nothing here
 * enumerates that list; bringing a new mirror to this account stays a manual
 * decision, which is what the comment in RUTracker.php asks for.
 */

require_once(__DIR__ . '/../../../plugins/loginmgr/accounts.php');
require_once(__DIR__ . '/../../../plugins/loginmgr/accounts/RUTracker.php');
require_once(__DIR__ . '/../../../plugins/rutracker_check/detector.php');

function rtdForumHosts()
{
    $account = new ReflectionClass('ruTrackerAccount');
    $hosts = $account->hasConstant('FORUM_HOSTS') ? $account->getConstant('FORUM_HOSTS') : null;
    if (!is_array($hosts) || count($hosts) === 0) {
        throw new RuntimeException(
            'ruTrackerAccount::FORUM_HOSTS must be the single list of the mirrors this account claims'
        );
    }
    return $hosts;
}

// getDownloadId() is protected because nothing outside the account calls it;
// a subclass reaches it without reflection, on every PHP this repository runs.
class RtdAccountProbe extends ruTrackerAccount
{
    public function downloadId($url)
    {
        return $this->getDownloadId($url);
    }
}

function rtdDownloadId($url)
{
    $probe = new RtdAccountProbe();
    return $probe->downloadId($url);
}

$tests = array(
    'the list is exactly the four forum mirrors' => function () {
        // Written down so that adding a fifth is a decision rather than an
        // edit: whoever adds one has to come here, read why rutracker.cc and
        // t-ru.org are absent, and say the same about the new host.
        testAssertSame(
            array('rutracker.org', 'rutracker.cr', 'rutracker.net', 'rutracker.nl'),
            rtdForumHosts(),
            'ruTrackerAccount::FORUM_HOSTS changed'
        );
    },

    'every listed mirror is claimed, and only under /forum/' => function () {
        foreach (rtdForumHosts() as $host) {
            testAssertSame(true, (bool) (new ruTrackerAccount())->test('https://' . $host . '/forum/dl.php?t=1'),
                $host . ' must be claimed');
            // The site's own links carry the www label.
            testAssertSame(true, (bool) (new ruTrackerAccount())->test('https://www.' . $host . '/forum/viewtopic.php?t=1'),
                'www.' . $host . ' must be claimed');
            testAssertSame(false, (bool) (new ruTrackerAccount())->test('https://' . $host . '/other/'),
                $host . ' outside /forum/ must not be claimed');
        }
    },

    'a dl.php url on every claimed mirror is recognised as a download' => function () {
        // getDownloadId() decides whether updateCached() and login() send the
        // fetch with the bb_dl cookie and as a POST. It used to spell the
        // domains out a second time, so a mirror added to test() alone would
        // have been claimed and then fetched as a plain GET with neither.
        foreach (rtdForumHosts() as $host) {
            testAssertSame('1', rtdDownloadId('https://' . $host . '/forum/dl.php?t=1'),
                'dl.php on ' . $host . ' must be recognised');
            testAssertSame('1', rtdDownloadId('https://www.' . $host . '/forum/dl.php?t=1'),
                'dl.php on www.' . $host . ' must be recognised');
        }
    },

    'the api and announce hosts rutracker_check knows are not this account\'s' => function () {
        // Both directions are asserted: the detector really does call these
        // hosts RuTracker's, and this account really does refuse them. The
        // difference is the point -- t-ru.org is the BitTorrent announce host,
        // and rutracker.cc appears in this codebase only as
        // api.rutracker.cc/v1/static/ and feed.rutracker.cc/atom/
        // (plugins/rutracker_check/forumindex.php), URLs no account claims.
        foreach (array('rutracker.cc', 'api.rutracker.cc', 'bt.t-ru.org') as $host) {
            testAssertSame(true, RuTrackerDetector::isTrackerHost($host),
                $host . ' is one rutracker_check calls RuTracker\'s');
            testAssertSame(false, (bool) (new ruTrackerAccount())->test('https://' . $host . '/forum/dl.php?t=1'),
                $host . ' must not receive this account\'s session');
        }
    },

    'every mirror this account claims is one rutracker_check also calls RuTracker\'s' => function () {
        // The containment that must hold: a host trusted with the session here
        // and unknown there would be a mirror the checker cannot attribute a
        // torrent to.
        foreach (rtdForumHosts() as $host) {
            testAssertSame(true, RuTrackerDetector::isTrackerHost($host),
                $host . ' must also be RuTracker\'s to rutracker_check');
        }
    },
);

exit(testRunCases($tests));
