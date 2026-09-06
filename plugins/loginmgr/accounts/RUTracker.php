<?php

class ruTrackerAccount extends commonAccount
{
	public $url = "https://rutracker.org";

	// RuTracker's forum mirrors, in one place: test() decides whether this
	// account claims a URL at all, and getDownloadId() decides whether the
	// claimed URL is the dl.php download that updateCached() and login() send
	// with the bb_dl cookie and as a POST. Spelled out twice, as it was, a
	// mirror added to the first alone is claimed and then fetched as a plain
	// GET with neither.
	//
	// Deliberately not the same list as RuTrackerDetector::TRACKER_HOST_PATTERN
	// (plugins/rutracker_check/detector.php), which also carries t-ru.org and
	// rutracker.cc. That constant answers "is this host RuTracker's?", for
	// attribution and for the requests that plugin sends itself; this one
	// answers "may this host be handed this account's cookies, and a credential
	// POST when they go stale?". t-ru.org is the BitTorrent announce host, and
	// rutracker.cc reaches this repository only as api.rutracker.cc/v1/static/
	// and feed.rutracker.cc/atom/ (RuTrackerForumIndex): no account claims
	// those URLs today, and listing .cc here would leave them one path test
	// away from this account's login flow.
	//
	// Nor can the two lists become one constant: plugin.info has
	// rutracker_check depending on loginmgr, so the dependency may not run the
	// other way, and this account has to work with that plugin absent.
	// tests/plugins/loginmgr/RuTrackerDomainListTest.php pins the difference
	// instead. It is not a symmetric guard, and it is worth knowing which half
	// it holds: editing THIS list fails it, and so does dropping rutracker.cc,
	// t-ru.org or any of these four mirrors from the detector. A host ADDED to
	// the detector alone fails nothing, because no assertion enumerates that
	// list -- so a new RuTracker mirror still has to be brought here by hand.
	const FORUM_HOSTS = array("rutracker.org","rutracker.cr","rutracker.net","rutracker.nl");

	protected function isOK($client)
	{
		return(strpos( $client->results, ' name="login_password"' )==false);
	}
	protected function updateCached($client,&$url,&$method,&$content_type,&$body)
	{
		$id = $this->getDownloadId($url);
		if($id!==false)
		{
			$client->referer = "https://rutracker.org/forum/viewtopic.php?t=".$id;
			$client->cookies["bb_dl"]=$id;
			$method = "POST";
			$content_type = "application/x-www-form-urlencoded";
			$body = '';
		}
		return(true);
	}
	protected function getDownloadId($url)
	{
		$hosts = array();
		foreach(self::FORUM_HOSTS as $host)
			$hosts[] = preg_quote($host,"/");
		if(preg_match( "/(\.|)(".implode("|",$hosts).")\/forum\/dl\.php\?t=(?P<id>\d+)$/si", $url, $matches ))
			return($matches["id"]);
		return(false);
	}
	protected function login($client,$login,$password,&$url,&$method,&$content_type,&$body,&$is_result_fetched)
	{
		$is_result_fetched = false;
		$id = $this->getDownloadId($url);
		if($id===false)
		{
			$redirect = $url;
			$referer = "https://rutracker.org/forum/index.php";
			$is_result_fetched = true;
		}
		else
		{
			$redirect = "https://rutracker.org/forum/viewtopic.php?t=".$id;
			$referer = "https://rutracker.org/forum/viewtopic.php?t=".$id;
		}
		if($client->fetch( $this->url."/forum/login.php","POST","application/x-www-form-urlencoded",
			"redirect=".rawurlencode($redirect)."&login_username=".rawurlencode($login)."&login_password=".rawurlencode($password)."&login=%C2%F5%EE%E4" ))
		{
			$client->setcookies();
			$client->referer = $referer;
			if($id!==false)
			{
				$client->cookies["bb_dl"]=$id;
				$method = "POST";
				$content_type = "application/x-www-form-urlencoded";
				$body = '';
			}
			return(true);
		}
		return(false);
	}
	// Matched against the URL's host, not against the URL string: the pattern
	// this replaced also accepted https://evil.test/path/rutracker.org/forum/x, whose
	// host is the attacker's, and loginmgr would then have sent this account's
	// cookies there.
	public function test($url)
	{
		return(self::urlAddresses($url,self::FORUM_HOSTS,null,"/forum/"));
	}
}
