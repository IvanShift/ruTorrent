<?php

require_once(__DIR__ . '/../../../php/kinozal.php');

class KinozalTVAccount extends commonAccount
{
	public $url = "https://kinozal.guru";

	protected function isOK($client)
	{
		// A downloaded torrent is payload, not a page. It may carry any bytes
		// at all -- a /signup.php URL in its comment field included -- and the
		// registration-link marker below would then read a perfectly good
		// torrent as a login wall, costing a re-login and the cached session
		// on every download of it. Nothing that opens a bencoded dictionary
		// came from the web front end.
		if((strncmp($client->results,'d',1)===0) && (strpos($client->results,'4:info')!==false))
			return(true);
		$guest = KinozalResponse::isGuestPage($client->results);
		return($guest === null ? null : !$guest);
	}
	protected function login($client,$login,$password,&$url,&$method,&$content_type,&$body,&$is_result_fetched)
	{
		$is_result_fetched = false;
		if($client->fetch( $this->url ))
		{
			$client->setcookies();
			$client->referer = $this->url;
			if($client->fetch( $this->url."/takelogin.php","POST","application/x-www-form-urlencoded",
				"username=".rawurlencode($login)."&password=".rawurlencode($password) ))
			{
				$client->setcookies();
				return(true);
			}
		}
		return(false);
	}
	// Matched against the URL's host, not against the URL string: the pattern
	// this replaced also accepted https://evil.test/path/kinozal.guru/x, whose
	// host is the attacker's, and loginmgr would then have sent this account's
	// cookies there.
	public function test($url)
	{
		return(self::urlAddresses($url,KinozalResponse::SITE_HOSTS,"https"));
	}
}
