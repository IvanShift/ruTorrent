<?php

class RedactedAccount extends commonAccount
{
	public $url = "https://redacted.sh";

	protected function isOK($client)
	{
		return(strpos($client->results, 'id="loginform"')===false);
	}

	protected function login($client,$login,$password,&$url,&$method,&$content_type,&$body,&$is_result_fetched)
	{
		$is_result_fetched = false;
		$loginUrl = $this->url."/login.php";
		if(!$client->fetch($loginUrl))
		{
			FileUtil::toLog('loginmgr: Redacted login refused: login-page-unreachable');
			return(false);
		}
		// The retired .ch origin now serves a parked page. Require the login form
		// before sending credentials, even if a future origin answers HTTP 200.
		if((int)$client->status!==200 || !is_string($client->results) ||
			strpos($client->results, 'id="loginform" method="post"')===false ||
			strpos($client->results, 'name="username"')===false ||
			strpos($client->results, 'name="password"')===false)
		{
			FileUtil::toLog('loginmgr: Redacted login refused: unexpected-login-form');
			return(false);
		}
		$client->setcookies();
		$client->referer = $loginUrl;
		if(!$client->fetch($loginUrl,"POST","application/x-www-form-urlencoded",
			"username=".rawurlencode($login)."&password=".rawurlencode($password)."&keeplogged=1&login=Login") ||
			(int)$client->status<200 || (int)$client->status>=400)
		{
			FileUtil::toLog('loginmgr: Redacted login refused: credential-post-failed');
			return(false);
		}
		$client->setcookies();
		return(true);
	}
}
