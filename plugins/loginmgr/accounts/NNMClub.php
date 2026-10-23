<?php

class NNMClubAccount extends commonAccount
{
	public $url = "https://nnmclub.to";

	protected function isOK($client)
	{
		return( (strpos( $client->results, ' class="mainmenu">¬ход</a>' )===false) &&
			 (strpos( $client->results, "document.cookie='_ddn_" )===false) );
	}
	protected function login($client,$login,$password,&$url,&$method,&$content_type,&$body,&$is_result_fetched)
	{
		$is_result_fetched = false;
		// Neither a cookie name nor its value carries a control character, and
		// this pair is read out of a response body, where CR and LF both pass.
		// What is captured here is merged into the jar Snoopy writes into the
		// next request's Cookie header by hand. The trailing ";" means a name
		// or a value carrying one of those bytes names no cookie at all, which
		// is the refusal the bound is for: the login still goes out, without
		// a token no server issued.
		if($client->fetch( $this->url.'/forum/login.php' ) &&
			preg_match( "`document.cookie='_ddn_(?P<cname>[^=[:cntrl:]]+)=(?P<cvalue>[^;[:cntrl:]]*);`si", $client->results, $matches ))
		{
			$client->cookies = array_merge($client->cookies, array('_ddn_'.$matches["cname"]=>$matches["cvalue"]));
		}
		if($client->fetch( $this->url."/forum/login.php","POST","application/x-www-form-urlencoded",
			"&username=".rawurlencode($login)."&password=".rawurlencode($password)."&autologin=on&login=%C2%F5%EE%E4" ))
		{
			$client->setcookies();
			return(true);
		}
		return(false);
	}
	public function test($url)
	{
		// Check the host and path components: matching
		// the name anywhere in the string also accepted
		// https://evil.test/x/nnmclub.to/forum/dl.php, whose host is the
		// attacker's, and this account's cookies would have gone there; and a
		// regex over the whole string could not see a host spelled with the
		// root dot that the host test had just accepted.
		if(!self::urlAddresses($url,array(
			"nnm-club.ru","nnm-club.me","nnm-club.to","nnm-club.name","nnm-club.tv",
			"nnmclub.ru","nnmclub.me","nnmclub.to","nnmclub.name","nnmclub.tv"),"https"))
			return(false);
		$path = rawurldecode((string) @parse_url((string) $url, PHP_URL_PATH));
		// Equivalent login paths must not trigger a credential refresh loop.
		$segments = array();
		foreach(explode('/', $path) as $segment)
		{
			if($segment === '' || $segment === '.')
				continue;
			if($segment === '..')
				array_pop($segments);
			else
				$segments[] = $segment;
		}
		$normalizedPath = '/' . implode('/', $segments);
		if($normalizedPath !== '/' && preg_match('~/(?:\.{0,2})?$~', $path))
			$normalizedPath .= '/';
		return(strncasecmp($normalizedPath, '/forum/', strlen('/forum/')) === 0 &&
			strncasecmp($normalizedPath, "/forum/login.php", strlen("/forum/login.php")) !== 0);
	}
}
