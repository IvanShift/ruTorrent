<?php

class mTeamAccount extends commonAccount
{
	public $url = "https://mteam.fr";

	protected function isOK($client)
	{
		return(strpos($client->results, "Les www du lien mteam.fr ne sont plus valides.")===false);
	}

	protected function loadData( $client = null )
	{
		$rt = parent::loadData($client);
		if($client && $rt->loaded)
			$client->referer = $rt->referer;
		return($rt);
	}

	protected function login($client,$login,$password,&$url,&$method,&$content_type,&$body,&$is_result_fetched)
	{
		$is_result_fetched = false;
		if($client->fetch( $this->url ))
		{
			$client->setcookies();
			$client->referer = $this->url;

		if($client->fetch( $this->url."/actions_login.php?action=login","POST","application/x-www-form-urlencoded",
				"type=POST&pseudo=".rawurlencode($login)."&password=".rawurlencode($password) ))
			{
				$client->setcookies();
				return(true);
			}
		}
		return(false);
	}
}
