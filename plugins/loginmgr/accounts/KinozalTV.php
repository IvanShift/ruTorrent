<?php

require_once(__DIR__ . '/../../../php/kinozal.php');

class KinozalTVAccount extends commonAccount
{
	// The origin for an automatic refresh, which has no request of its own:
	// the first configured door (KinozalResponse::doors()).
	public $url = '';

	public function __construct()
	{
		$this->url = KinozalResponse::refreshOrigin();
	}

	public function fetch($client, $url, $login, $password, $method, $content_type, $body)
	{
		if(KinozalResponse::requestsBlocked($url)) return(false);
		$ret = parent::fetch($client, $url, $login, $password, $method, $content_type, $body);
		return(KinozalResponse::observeChallenge($client, $url) ? false : $ret);
	}

	protected function isOK($client)
	{
		if(KinozalResponse::observeChallenge($client)) return(null);
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
		// Session cookies belong to the host that set them, so log in on the
		// site the caller is about to use. test() admits only account hosts,
		// and a URL outside them is refused here as well, never redirected
		// to another origin with the password.
		$origin = KinozalResponse::siteOrigin($url);
		if($origin === null)
		{
			KinozalResponse::logLoginRefusal('login-origin-refused', $url);
			return(false);
		}
		if(KinozalResponse::requestsBlocked($origin)) return(false);
		// The login pause is asked before any request: when it holds, not even
		// login.php is fetched.
		if(self::loginPaused($client, $origin, KinozalResponse::loginPausedUntil())) return(false);
		// Replay of the captured login form admits the POST. A transport result
		// alone also accepts 403/5xx and unrelated landing pages.
		if(!self::holdsLoginForm($client, $origin))
		{
			$fetched = $client->fetch($origin . "/login.php");
			if(KinozalResponse::observeChallenge($client, $origin . "/login.php")) return(false);
			$form = $fetched && (int) $client->status === 200
				? KinozalResponse::hasLoginForm($client->results) : false;
			if($form !== true)
			{
				KinozalResponse::logLoginRefusal(self::formRefusal($client, $fetched, $form), $origin);
				return(false);
			}
		}
		// Asked again under the pause's lock, which records this POST: another
		// process may have sent one while this one fetched the form.
		if(self::loginPaused($client, $origin, KinozalResponse::reserveLogin())) return(false);
		$client->setcookies();
		$client->referer = $origin;
		$fetched = $client->fetch($origin . "/takelogin.php", "POST", "application/x-www-form-urlencoded",
			"username=" . rawurlencode($login) . "&password=" . rawurlencode($password));
		if(KinozalResponse::observeChallenge($client, $origin . "/takelogin.php")) return(false);
		if(!$fetched || (int) $client->status >= 400)
			return(self::credentialPostFailed($client, $origin));
		$client->setcookies();
		return(true);
	}

	// The credential POST went out, or may have, and brought no session,
	// however it ended: a refusal, a redirect to an error page, a lost
	// answer, a 429. Each is logged with the time the login pause lets the
	// next POST out. The mark tells the caller that asking another door would
	// only send the password again; a 429 is left unmarked, as the rate limit
	// answers for the whole site and stays for the caller to read.
	private static function credentialPostFailed($client, $origin)
	{
		$status = (int) $client->status;
		KinozalResponse::logLoginRefusal($status >= 400 ? 'credential-post-http-' . $status
			: 'credential-post-unanswered', $origin, true, KinozalResponse::loginPausedUntil());
		if(KinozalResponse::isRateLimited($client))
			return(false);
		$client->error = KinozalResponse::CREDENTIAL_POST_FAILED;
		return(false);
	}

	// Refuses the login while the pause holds, or while its record cannot be
	// kept, with the reason left on the client for the caller.
	private static function loginPaused($client, $origin, $until)
	{
		if($until === null)
			return(false);
		$client->error = KinozalResponse::LOGIN_PAUSED;
		if(is_infinite($until))
			KinozalResponse::logLoginRefusal('login-state-unavailable', $origin);
		else
			KinozalResponse::logLoginPaused($origin, $until);
		return(true);
	}

	// The answer that just walled the caller may already be this origin's
	// login page: a guest download.php redirects to it, and Snoopy followed.
	// That page is the successful GET with the expected form that a fresh
	// request would fetch again, one paced request later.
	private static function holdsLoginForm($client, $origin)
	{
		$answered = isset($client->lastResponseURL) ? (string) $client->lastResponseURL : '';
		return(UrlHost::sameOrigin($answered, $origin) && @parse_url($answered, PHP_URL_PATH) === '/login.php'
			&& (int) $client->status === 200 && KinozalResponse::hasLoginForm($client->results) === true);
	}

	// Why the login form could not be used. The shared rate limit is not
	// this door's refusal.
	private static function formRefusal($client, $fetched, $form)
	{
		if(KinozalResponse::isRateLimited($client))
			return('rate-limited');
		if(!$fetched)
			return('login-form-unreachable');
		if((int) $client->status !== 200)
			return('login-form-http-refusal');
		return($form === null ? 'login-form-unverified' : 'login-form-missing');
	}

	// Matched against the URL's host, not against the URL string: the pattern
	// this replaced also accepted https://evil.test/path/kinozal.guru/x, whose
	// host is the attacker's, and loginmgr would then have sent this account's
	// cookies there.
	public function test($url)
	{
		return(self::urlAddresses($url,KinozalResponse::accountHosts(),"https"));
	}
}
