<?php

require_once( dirname(__FILE__)."/../../php/util.php" );
require_once( dirname(__FILE__)."/../../php/urlhost.php" );
require_once( dirname(__FILE__)."/../../php/cache.php" );
require_once( dirname(__FILE__)."/../../php/Snoopy.class.inc");
require_once( dirname(__FILE__)."/../../php/utility/json.php");
// accounts.php can be first included from Snoopy::fetchComplex() method scope.
global $yggTorrentOrigin;
eval( FileUtil::getPluginConf( 'loginmgr' ) );

class privateData
{
	public $hash = '';
	public $modified = false;
	public $cookies = null;
	public $scopedCookies = null;
	public $referer = null;
	public $loaded = false;

	static public function load( $owner, $client = null )
	{
		$rt = new privateData($owner);
		if($client)
		{
			$cache = new rCache('/accounts');
			if($cache->get($rt))
			{
				// Old caches have only flat cookies; their Path and Domain cannot
				// be reconstructed. Renew before allowing them onto the wire.
				if(!is_array($rt->scopedCookies))
				{
					FileUtil::toLog('loginmgr: legacy-cookie-cache-needs-refresh: '
						. preg_replace('/[^a-z0-9_.-]/i', '?', $owner)
						. '; cookie scope unavailable');
					return($rt);
				}
				// Keep cookies already supplied for this request; the saved session
				// keeps its previous priority when names overlap.
				$client->cookies = array_merge((array) $client->cookies, (array) $rt->cookies);
				if($client instanceof Snoopy)
				{
					$client->markAccountCookies($rt->cookies);
					$client->loadAccountResponseCookies($rt->scopedCookies);
				}
//				$client->referer = $rt->referer;
				$rt->loaded = true;
			}
		}
		return($rt);
	}

	public function __construct( $owner )
	{
		$this->hash = $owner.".dat";
		$this->scopedCookies = array();
		$this->loaded = false;
	}

	public function remove()
	{
		$cache = new rCache('/accounts');
		$cache->remove($this);
	}

	public function store( $client )
	{
		$this->cookies = ($client instanceof Snoopy)
			? $client->cookiesForAccountStorage() : $client->cookies;
		$this->scopedCookies = ($client instanceof Snoopy)
			? $client->responseCookiesForAccountStorage() : array();
		$this->referer = $client->referer;
		$cache = new rCache('/accounts');
		return($cache->set($this));
	}

	static public function getModified($owner)
	{
		$rt = new privateData($owner);
		$cache = new rCache('/accounts');
		return($cache->getModified($rt));
	}
}

abstract class commonAccount
{
	public $url = '';
	private static $missingOriginLogged = array();

	public function getName()
	{
		$className = get_class($this);
		$pos = strpos($className, "Account");
		if($pos!==false)
			$className = substr($className,0,$pos);
		return($className);
	}

	abstract protected function isOK($client);
	abstract protected function login($client,$login,$password,&$url,&$method,&$content_type,&$body,&$is_result_fetched);

	// This decision selects the account whose cookies are sent. UrlHost owns
	// the host identity rule; a prefix of the raw URL is not an identity.
	public function test($url)
	{
		$site = @parse_url((string) $this->url);
		if(!is_array($site) || empty($site["host"]) || empty($site["scheme"]))
			return(false);
		return(self::urlAddresses($url,array($site["host"]),$site["scheme"]));
	}

	// Compatibility wrapper for account subclasses. Host, scheme and path
	// matching live in UrlHost::urlIsOneOf(), shared with rutracker_check.
	static protected function urlAddresses($url,$hosts,$scheme = null,$pathPrefix = null)
	{
		return(UrlHost::urlIsOneOf($url,$hosts,$scheme,$pathPrefix));
	}

	protected static function queryValue($url, $name)
	{
		// Match PHP's decoded names and last-value rule for repeated keys.
		$parts = @parse_url((string) $url);
		if(!is_array($parts))
			return(false);
		parse_str(isset($parts['query']) ? $parts['query'] : '', $params);
		return(isset($params[$name]) && is_string($params[$name]) ? $params[$name] : false);
	}

	protected static function queryDigits($url, $name)
	{
		$value = self::queryValue($url, $name);
		// \z rejects a trailing line feed that $ would accept.
		return($value !== false && preg_match('/^\d+\z/', $value) ? $value : false);
	}

	protected function loadData( $client = null )
	{
		 return(privateData::load( $this->getName(), $client ));
	}

	protected function updateCached($client,&$url,&$method,&$content_type,&$body)
	{
		return(true);
	}

	// Three answers, not two. They call for three different repairs, and this
	// class used to make only two of them:
	//
	//   ANSWER_LIVE   the tracker answered from behind the login wall.
	//   ANSWER_GUEST  the tracker answered, and answered as a guest. The
	//                 session is dead and logging in again is the repair.
	//   ANSWER_NONE   nothing usable arrived. This says NOTHING about the
	//                 session, so logging in again is not a repair: it spends
	//                 a credential POST on a tracker that is already failing
	//                 and then throws away cookies never shown to be stale.
	//
	// Every isOK() under accounts/ tells the first two apart by looking for a
	// guest marker -- a login form, a registration link, a "you must log in"
	// line -- and none of them can answer the third, because a page that never
	// arrived carries no marker either. That is why all three used to collapse
	// into "the session is live", and why a 5xx error page went back to the
	// caller as the page or the torrent it had asked for.
	const ANSWER_NONE = 0;
	const ANSWER_GUEST = 1;
	const ANSWER_LIVE = 2;

	// What the client is holding after a fetch of the caller's own URL.
	protected function classifyAnswer($client)
	{
		if(!is_object($client))
			return(self::ANSWER_NONE);
		$status = (int) $client->status;
		// A conditional request the server honoured. It answered, it answered
		// the authenticated request, and it sent no body on purpose: the
		// caller asked for that (plugins/rss sends If-None-Match and reads
		// $client->status itself). Judging it by its absent body would turn
		// every unchanged poll of an authenticated feed into a fresh login.
		if($status===304)
			return(self::ANSWER_LIVE);
		// Only 2xx carries a body meant for the caller. A 3xx reaching here is
		// a redirect Snoopy did not follow -- a followed chain ends at the
		// status of its last hop -- and the boilerplate body a server puts on
		// one carries no guest marker either, so it would read as a live
		// session for want of evidence.
		if(($status<200) || ($status>=300))
			return(self::ANSWER_NONE);
		if(!$this->hasReadableBody($client))
			return(self::ANSWER_NONE);
		return($this->isOK($client) ? self::ANSWER_LIVE : self::ANSWER_GUEST);
	}

	// True when $client->results is something a strpos() marker test can read.
	// Failed or unsupported decompression cannot supply a readable marker.
	// Keep the type guard for other transports and test doubles: strpos() on
	// a non-string is fatal in PHP 8.
	protected function hasReadableBody($client)
	{
		return(is_object($client) && is_string($client->results) && ($client->results!==''));
	}

	// The login answer is a narrower question than the caller's answer: a
	// login endpoint may legitimately answer with no body at all -- a 30x to
	// the landing page, or a 200 whose whole content is Set-Cookie -- and it
	// is the fetch that follows which proves whether the session took. So the
	// marker test is applied only when there is a body to apply it to, and the
	// status range stays the one this class has always used here.
	protected function loginWasNotRefused($client)
	{
		if(!is_object($client))
			return(false);
		$status = (int) $client->status;
		if(($status<200) || ($status>=400))
			return(false);
		return(!$this->hasReadableBody($client) || $this->isOK($client));
	}

	protected function isOKPostFetch($client,$url,$method,$content_type,$body)
	{
		return($this->classifyAnswer($client)===self::ANSWER_LIVE);
	}

	private function hasLivePostFetchAnswer($client,$url,$method,$content_type,$body)
	{
		// An override may perform recovery, but its return value cannot replace
		// validation of the answer left on the client for the caller.
		return($this->isOKPostFetch($client,$url,$method,$content_type,$body) &&
			$this->classifyAnswer($client)===self::ANSWER_LIVE);
	}

	protected function withRedirectTrust($client, callable $body)
	{
		$scopedTrust = $client instanceof Snoopy;
		$previousTrust = $scopedTrust ? $client->redirectTrust : null;
		if($scopedTrust)
			$client->redirectTrust = array($this, 'test');
		try
		{
			return($scopedTrust ? $client->withAccountCookieScope($this->getName(), $body) : $body());
		}
		finally
		{
			if($scopedTrust)
				$client->redirectTrust = $previousTrust;
		}
	}

	public function fetch( $client, $url, $login, $password, $method, $content_type, $body )
	{
		return($this->withRedirectTrust($client, function() use($client,$url,$login,$password,$method,$content_type,$body)
		{
			$is_result_fetched = false;
			$data = $this->loadData($client);
			if($data->loaded &&
				$this->updateCached($client,$url,$method,$content_type,$body))
			{
				if(!$client->fetch($url,$method,$content_type,$body))
					return(false);
				// Taken before isOKPostFetch(), because an override may fetch
				// further pages onto this client and the question here is about
				// the answer to the caller's own URL.
				$answer = $this->classifyAnswer($client);
				if($this->hasLivePostFetchAnswer($client,$url,$method,$content_type,$body))
				{
					if($client instanceof Snoopy && $data instanceof privateData
						&& $data->scopedCookies !== $client->responseCookiesForAccountStorage()
						&& !$data->store($client))
						FileUtil::toLog('loginmgr: scoped-cookie-cache-write-failed: ' . $this->getName());
					return(true);
				}
				// Only a guest answer is evidence that the session died. Anything
				// else leaves that unproven, so report the failure and keep the
				// cookies rather than log in again against a tracker that is not
				// answering: one outage would otherwise cost a credential POST per
				// call for every caller looping over a torrent list, which is how
				// an account gets locked out.
				if($answer!==self::ANSWER_GUEST)
					return(false);
			}
			$ret = ( $this->login($client,$login,$password,$url,$method,$content_type,$body,$is_result_fetched) &&
				$this->loginWasNotRefused($client) &&
				($is_result_fetched || $client->fetch($url,$method,$content_type,$body)) &&
				$this->hasLivePostFetchAnswer($client,$url,$method,$content_type,$body) &&
				$data->store($client) );
			if(!$ret)
				$data->remove();
			return($ret);
		}));
	}

	public function check( $client, $login, $password, $auto )
	{
		return($this->withRedirectTrust($client, function() use($client,$login,$password,$auto)
		{
			// An account without a configured origin cannot renew a session.
			if(UrlHost::of($this->url) === null)
			{
				$name = $this->getName();
				if(!isset(self::$missingOriginLogged[$name]))
				{
					self::$missingOriginLogged[$name] = true;
					FileUtil::toLog('loginmgr: missing-origin: ' . $name . '; automatic refresh skipped');
				}
				return;
			}
			$modified = privateData::getModified($this->getName());
			if( ($modified===false) || ((time()-$modified)>=$auto))
			{
				// login() takes these by reference and several accounts read them
				// before writing: undeclared, they arrived as null, and an account
				// whose login() starts by fetching $url could never authenticate
				// from here at all.
				$url = $this->url;
				$method = "GET";
				$content_type = "";
				$body = "";
				$is_result_fetched = false;
				$data = $this->loadData();
				if($this->login($client,$login,$password,$url,$method,$content_type,$body,$is_result_fetched) &&
					$this->loginWasNotRefused($client))
					$data->store($client);
				// A login that did not come back is not evidence that the stored
				// session is stale, and this job exists to REFRESH a session:
				// deleting one it merely failed to renew leaves the user worse off
				// than not running at all. A session that really has died is
				// discovered, and replaced, by the next fetch().
			}
		}));
	}

}

class accountManager
{
	public $hash = "loginmgr.dat";
	public $modified = false;
	public $accounts = array();

	static public function load()
	{
		$cache = new rCache();
		$ar = new accountManager();
		return($cache->get($ar) ? $ar : false);
	}

	public function store()
	{
		$cache = new rCache();
		return($cache->set($this));
	}

	public function obtain( $dir = '../plugins/loginmgr/accounts' )
	{
		$oldAccounts = $this->accounts;
		$this->accounts = array();
		if( $handle = opendir($dir) )
		{
			while(false !== ($file = readdir($handle)))
			{
				if(is_file($dir.'/'.$file))
				{
					$name = basename($file,".php");
					$this->accounts[$name] = array( "name"=>$name, "path"=>FileUtil::fullpath($dir.'/'.$file), "object"=>$name."Account", "login"=>'', "password"=>'', "enabled"=>0, "auto"=>0 );
					if(array_key_exists($name,$oldAccounts) && array_key_exists("login",$oldAccounts[$name]))
					{
						$this->accounts[$name]["login"] = self::asText($oldAccounts[$name]["login"]);
						$this->accounts[$name]["password"] = self::asText($oldAccounts[$name]["password"]);
						$this->accounts[$name]["enabled"] = self::asFlag($oldAccounts[$name]["enabled"]);
						if(array_key_exists("auto",$oldAccounts[$name]))
							$this->accounts[$name]["auto"] = self::asNumber($oldAccounts[$name]["auto"]);
					}
				}
			}
			closedir($handle);
	        }
		ksort($this->accounts);
		$this->store();
		$this->setHandlers();
	}

	static protected function asFlag($value)
	{
		return(is_scalar($value) && $value && $value !== '0' ? 1 : 0);
	}

	static protected function asNumber($value)
	{
		return(is_scalar($value) ? intval($value) : 0);
	}

	static protected function asText($value)
	{
		return(is_scalar($value) ? strval($value) : '');
	}

	private function configurationRequired($nfo, $account = null)
	{
		if($account === null)
		{
			require_once($nfo["path"]);
			if(!method_exists($nfo["object"], 'configurationError'))
				return(false);
			$account = new $nfo["object"]();
		}
		return(is_callable(array($account, 'configurationError')) && $account->configurationError() !== '');
	}

	public function get()
	{
		$accounts = array();
		foreach( $this->accounts as $name=>$nfo )
			$accounts[self::asText($name)] = array(
				"login" => self::asText($nfo["login"] ?? ''),
				"password_set" => self::asText($nfo["password"] ?? '') === '' ? 0 : 1,
				"enabled" => self::asFlag($nfo["enabled"] ?? 0),
				"auto" => self::asNumber($nfo["auto"] ?? 0),
				"configurationRequired" => $this->configurationRequired($nfo));
		return("theWebUI.theAccounts = ".JSON::jsValue((object) $accounts).";\n");
	}

	public function set()
	{
		foreach( $this->accounts as $name=>$nfo )
		{
			if(isset($_POST[$name."_enabled"]))
				$this->accounts[$name]["enabled"] = self::asFlag($_POST[$name."_enabled"]);
			if(isset($_POST[$name."_login"]))
				$this->accounts[$name]["login"] = self::asText($_POST[$name."_login"]);
			// An empty edit keeps the stored value; only the separate clear flag removes it.
			if(isset($_POST[$name."_clear_password"]) && $_POST[$name."_clear_password"] === '1')
				$this->accounts[$name]["password"] = '';
			else if(isset($_POST[$name."_password"]) && $_POST[$name."_password"] !== '')
				$this->accounts[$name]["password"] = self::asText($_POST[$name."_password"]);
			if(isset($_POST[$name."_auto"]))
				$this->accounts[$name]["auto"] = self::asNumber($_POST[$name."_auto"]);
		}
		if(!$this->store())
			return(false);
		foreach($this->accounts as $name=>$nfo)
			$this->forgetSession($name);
		$this->setHandlers();
		return(true);
	}

	protected function forgetSession( $name )
	{
		$data = new privateData( $name );
		$data->remove();
	}

	public function getAccount( $url, &$httpsAccount = null )
	{
		// Report the already-tested HTTPS candidate for diagnostics only.
		// An HTTP URL still does not select that account.
		$httpsAccount = null;
		$httpHost = strtolower((string) @parse_url((string) $url, PHP_URL_SCHEME)) === 'http'
			? UrlHost::of($url) : null;
		$httpsUrl = $httpHost === null ? null : preg_replace('/^http:/i', 'https:', (string) $url, 1);
		$httpCandidates = array();
		foreach( $this->accounts as $name=>$nfo )
		{
			if($nfo["enabled"])
			{
				require_once( $nfo["path"] );
				$object = new $nfo["object"]();
				if($object->test($url))
					return( $name );
				if($httpsUrl !== null)
					$httpCandidates[$name] = $object;
			}
		}
		// Diagnose only after every account has declined the original HTTP URL.
		foreach($httpCandidates as $name => $object)
		{
			if(!$object->test($httpsUrl))
				continue;
			$httpsAccount = $name;
			static $httpWarnings = array();
			$key = $name . ' ' . preg_replace('/[^a-z0-9.\[\]:-]/i', '?', $httpHost);
			if(!isset($httpWarnings[$key]))
			{
				$httpWarnings[$key] = true;
				FileUtil::toLog('loginmgr: http-url-not-authenticated: ' . $key . '; use the tracker HTTPS URL');
			}
			break;
		}
		return(false);
	}

        public function fetch( $acc, $client, $url, $method="GET", $content_type="", $body="" )
	{
		if(array_key_exists($acc,$this->accounts))
		{
			$nfo = $this->accounts[$acc];
			require_once( $nfo["path"] );
			$object = new $nfo["object"]();
			return($object->fetch( $client, $url, $nfo["login"], $nfo["password"], $method, $content_type, $body ));
		}
		return(false);
	}

	public function getInfo()
	{
		$ret = array();
		foreach( $this->accounts as $name=>$nfo )
		{
			require_once( $nfo["path"] );
			$nfo["name"] = $name;
			$object = new $nfo["object"]();
			$nfo["url"] = $object->url;
			$nfo["configurationRequired"] = $this->configurationRequired($nfo, $object);
			unset($nfo["object"]);
			unset($nfo["path"]);
			// Nothing reads the password from here, and this answer is json
			// served to the browser like any other.
			$nfo["password_set"] = (self::asText($nfo["password"] ?? '')==="") ? 0 : 1;
			unset($nfo["password"]);
			$nfo["login"] = self::asText($nfo["login"] ?? '');
			$nfo["enabled"] = self::asFlag($nfo["enabled"] ?? 0);
			$nfo["auto"] = self::asNumber($nfo["auto"] ?? 0);
			$ret[] = $nfo;
		}
		return($ret);
	}

	public function hasAuto()
	{
		foreach( $this->accounts as $name=>$nfo )
			if($nfo["enabled"] && !empty($nfo["auto"]))
				return(true);
		return(false);
	}

	public function setHandlers()
	{
		if(rTorrentSettings::get()->linkExist)
		{
			$req =  new rXMLRPCRequest( $this->hasAuto() ?
				rTorrentSettings::get()->getAlignedScheduleCommand("loginmgr",86400,
					getCmd('execute').'={sh,-c,'.escapeshellarg(Utility::getPHP()).' '.escapeshellarg(dirname(__FILE__).'/update.php').' '.escapeshellarg(User::getUser()).' & exit 0}' ) :
				rTorrentSettings::get()->getRemoveScheduleCommand("loginmgr") );
			$req->success();
		}
	}

	public function checkAuto()
	{
		foreach( $this->accounts as $name=>$nfo )
		{
			if($nfo["enabled"] && !empty($nfo["auto"]))
			{
				require_once( $nfo["path"] );
				$object = new $nfo["object"]();
				$object->check( new Snoopy(), $nfo["login"], $nfo["password"], $nfo["auto"] );
			}
		}
	}
}
