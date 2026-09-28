<?php

/*************************************************

Snoopy - the PHP net client
Author: Monte Ohrt <monte@ispi.net>
Copyright (c): 1999-2000 ispi, all rights reserved
Version: 1.0

 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

You may contact the author of Snoopy by e-mail at:
monte@ispi.net

Or, write to:
Monte Ohrt
CTO, ispi
237 S. 70th suite 220
Lincoln, NE 68510

The latest version of Snoopy can be obtained from:
http://snoopy.sourceforge.com

*************************************************/

require_once( 'util.php' );
require_once( 'settings.php' );
require_once( 'urlhost.php' );
require_once( 'publicsuffix.php' );

class Snoopy
{
	const RESPONSE_BODY_FAILED = -101;
	const CREDENTIAL_REDIRECT_REFUSED = 'credential-redirect-refused';

	/**** Public variables ****/

	/* user definable vars */

	var $host		=	"www.php.net";		// host name we are connecting to
	var $port		=	80;			// port we are connecting to
        var $proxy_proto	=       "http";			// http or https
	var $proxy_host		=	"";			// proxy host to use
	var $proxy_port		=	"";			// proxy port to use
	var $agent		=	"Snoopy v1.0";		// agent we masquerade as
	var $referer		=	"";			// referer info to pass
	var $cookies		=	array();		// array of cookies to pass
								// $cookies["username"]="joe";
	var $rawheaders		=	array();		// array of raw headers to send
								// $rawheaders["Content-type"]="text/html";

	var $maxredirs		=	5;			// http redirection depth maximum. 0 = disallow
	var $lastredirectaddr	=	"";			// contains address of last redirected address
	var $lastResponseURL	=	"";			// URL that produced the latest HTTP response
	// Scoped by loginmgr; both HTTPS URLs must belong to the selected account.
	public $redirectTrust = null;
	var $passcookies	=	true;			// pass set cookies back through redirects

	var $user		=	"";			// user for http authentication
	var $pass		=	"";			// password for http authentication

	// http accept types
	var $accept		=	"image/gif, image/x-xbitmap, image/jpeg, image/pjpeg, */*";

	var $results		=	"";			// where the content is put

	var $error		=	"";			// error messages sent here
	var $response_code	=	"";			// response code returned from server
	var $headers		=	array();		// headers returned from server sent here
	var $maxlength		=	4194304;		// max return data length (body)
	var $read_timeout	=	0;			// timeout on read operations, in seconds

	var $timed_out		=	false;			// if a read operation timed out
	var $status		=	0;			// http request status

	// send Accept-encoding: gzip?
	var $use_gzip		=	true;
	var $IP			=	null;
	var $useIpv4		=	false;

	// refuse targets that resolve to a private or otherwise non-routable address
	var $block_private	=	false;
	// hosts exempt from the check above, matched against the URL host verbatim
	var $private_allowlist	=	array();

	/**** Private variables ****/

	var $_maxlinelen	=	4096;			// max line length (headers)

	var $_httpversion	=	"HTTP/1.0";		// default http request version
	var $_mime_boundary	=	"";			// MIME boundary for multipart/form-data submit type
	var $_redirectaddr	=	false;			// will be set if page fetched is a redirect
	var $_redirectdepth	=	0;			// increments on an http redirect
	protected $requestCookieHost = null;
	protected $requestCookieScheme = '';
	protected $requestScopedCookies = array();
	protected $requestOriginalCookies = array();
	protected $requestAccountCookies = array();
	private $secureResponseCookies = array();
	private $loadedAccountCookies = array();
	private $responseCookieJar = array();
	private $responseCookieSequence = 0;
	private $selectedCookieAccount = null;
	private $requestChainSourceURL = '';
	const MAX_RESPONSE_COOKIES = 300;
	const MAX_DOMAIN_COOKIES = 50;
	const MAX_COOKIE_BYTES = 4096;

	var $_isproxy		=	false;			// set if using a proxy server
	var $_fp_timeout	=	30;			// timeout for socket connection
	var $_pinnedaddr	=	false;			// address the guard validated, connected to verbatim

	public function __construct()
	{
		global $httpIP;
		global $httpProxy;
		global $httpUserAgent;
		global $httpTimeOut;
		global $httpUseGzip;
		global $httpBlockPrivateNetworks;
		global $httpPrivateNetworkAllowlist;

		if(isset($httpUserAgent))
			$this->agent = $httpUserAgent;
		if(isset($httpTimeOut))
		{
			$this->_fp_timeout = $httpTimeOut;
			$this->read_timeout = $httpTimeOut;
		}
		if(is_array($httpProxy) && $httpProxy['use'])
		{
			$this->proxy_proto = $httpProxy['proto'];
			$this->proxy_host = $httpProxy['host'];
			$this->proxy_port = $httpProxy['port'];
		}
		if (isset($httpUseGzip))
			$this->use_gzip = $httpUseGzip;
		if(isset($httpBlockPrivateNetworks))
			$this->block_private = $httpBlockPrivateNetworks;
		if(isset($httpPrivateNetworkAllowlist) && is_array($httpPrivateNetworkAllowlist))
			$this->private_allowlist = $httpPrivateNetworkAllowlist;
		if(isset($httpIP))
			$this->IP = $httpIP;
	}

	static public function linkencode($p_url)
	{
		if(preg_match("/\%([0-9,A-F]{2})/i",$p_url)==1)
			return($p_url);
		$p_url = str_replace("\t","%09",$p_url);
		$uparts = @parse_url($p_url);
		$scheme = array_key_exists('scheme',$uparts) ? $uparts['scheme'] : "";
		$pass = array_key_exists('pass',$uparts) ? $uparts['pass']  : "";
		$user = array_key_exists('user',$uparts) ? $uparts['user']  : "";
		$port = array_key_exists('port',$uparts) ? $uparts['port']  : "";
		$host = array_key_exists('host',$uparts) ? $uparts['host']  : "";
		$path = array_key_exists('path',$uparts) ? $uparts['path']  : "";
		$query = array_key_exists('query',$uparts) ? $uparts['query']  : "";
		$fragment = array_key_exists('fragment',$uparts) ? $uparts['fragment']  : "";

		if(!empty($scheme))
			$scheme .= '://';

		if(!empty($pass) && !empty($user))
		{
			$user = rawurlencode($user).':';
			$pass = rawurlencode($pass).'@';
		}
		elseif(!empty($user))
			$user .= '@';

		if(!empty($port) && !empty($host))
			$host = ''.$host.':';

		if(!empty($path))
		{
			$arr = preg_split("/([\/;=])/", $path, -1, PREG_SPLIT_DELIM_CAPTURE);
			$path = "";
			foreach($arr as $var)
			{
				switch($var)
				{
					case "/":
					case ";":
					case "=":
						$path .= $var;
						break;
					default:
						$path .= rawurlencode($var);
				}
			}
			// legacy patch for servers that need a literal /~username
			$path = str_replace("/%7E","/~",$path);
		}
		else
		    $path="/";

		if(!empty($query))
		{
			$arr = preg_split("/([&=;])/", $query, -1, PREG_SPLIT_DELIM_CAPTURE);
			$query = "?";
			foreach($arr as $var)
			{
				switch($var)
				{
					case "&":
					case "=":
					case ";":
						$query .= $var;
						break;
					default:
						$query .= urlencode(str_replace("+", " ", $var));
				}
			}
		}
		if(!empty($fragment))
			$fragment = '#'.urlencode($fragment);
		return implode('', array($scheme, $user, $pass, $host, $port, $path, $query, $fragment));
	}

	static public function getURLCookies(&$url)
	{
		$cookies = array();
		$pos = strpos($url,':COOKIE:');
		if($pos!==false)
		{
			foreach(explode(';',substr($url,$pos+8)) as $item)
			{
				$pair = explode('=',trim($item),2);
				if(count($pair) === 2 && $pair[0] !== '')
					$cookies[$pair[0]] = $pair[1];
			}
			$url = substr($url,0,$pos);
		}
		return($cookies);
	}

	static public function isSuccessfulResponse($client)
	{
		return is_object($client) && isset($client->status)
			&& $client->status >= 200 && $client->status < 300
			&& (!isset($client->error) || $client->error === '');
	}

	static public function isTorrentResponse($client)
	{
		if(!self::isSuccessfulResponse($client) || !isset($client->results))
			return false;
		$body = $client->results;
		// Metainfo is a bencoded dictionary. The parser's raw mode avoids
		// interpreting a remote response as a local filename.
		if(!is_string($body) || $body === '' || $body[0] !== 'd')
			return false;
		require_once(dirname(__FILE__) . '/Torrent.php');
		$torrent = Torrent::fromRawBytes($body);
		return !$torrent->errors() && is_array($torrent->info) && count($torrent->info) > 0;
	}

	public function fetchComplex($URI,$method="GET",$content_type="",$body="")
	{
		// The cookie suffix is not part of the URL authority. Parse the host
		// only after removing it, including for pathless source URLs.
		$urlCookies = self::getURLCookies($URI);
		$parts = @parse_url($URI);
		$this->host = is_array($parts) && isset($parts["host"]) ? $parts["host"] : '';
		$originalCookies = $this->cookies;
		$originalSecureCookies = $this->secureResponseCookies;
		$previousCookieAccount = $this->selectedCookieAccount;
		$this->selectedCookieAccount = null;
		$previousScope = array($this->requestCookieHost, $this->requestCookieScheme,
			$this->requestScopedCookies, $this->requestOriginalCookies, $this->requestAccountCookies,
			$this->loadedAccountCookies, $this->requestChainSourceURL);
		$this->requestCookieHost = UrlHost::of($URI);
		$this->requestCookieScheme = is_array($parts) && isset($parts['scheme'])
			? strtolower($parts['scheme']) : '';
		$this->requestScopedCookies = array();
		$this->requestOriginalCookies = $originalCookies;
		$this->requestAccountCookies = array();
		try
		{
			$pluginCookies = array();
			$pluginCookieRefused = false;
			if(rTorrentSettings::get()->isPluginRegistered('cookies') && $this->requestCookieHost !== null)
			{
				// Go back to ruTorrent root folder and include cookies.php plugin file
				require_once(dirname(__FILE__)."/../plugins/cookies/cookies.php");
				$store = rCookies::load();
				$pluginCookies = $store->getCookiesForURL($URI);
				$pluginCookieRefused = $this->requestCookieScheme === 'http'
					&& !empty($store->getCookiesForHost($this->requestCookieHost));
			}
			$this->requestScopedCookies = array_merge($pluginCookies, $urlCookies);
			$this->cookies = array_merge($this->cookies, $this->requestScopedCookies);
			$acc = false;
			$httpsAccount = null;
			if(rTorrentSettings::get()->isPluginRegistered('loginmgr'))
			{
				// Go back to ruTorrent root folder and include accounts.php plugin file
				require_once(dirname(__FILE__)."/../plugins/loginmgr/accounts.php");
				$am = accountManager::load();
				if($am===false)
				{
					$am = new accountManager();
					$am->obtain();
				}
				$acc = $am->getAccount($URI, $httpsAccount);
			}
			if($pluginCookieRefused)
				self::logPluginCookieRefusal($this->requestCookieHost, $acc ? $acc : $httpsAccount);
			$this->selectedCookieAccount = $acc ?: null;
			if($acc)
			{
				$accountClass = $am->accounts[$acc]['object'];
				$account = new $accountClass();
				$configuredPort = self::effectivePort($account->url);
				if($configuredPort !== null && self::effectivePort($URI) !== $configuredPort)
				{
					$this->error = self::CREDENTIAL_REDIRECT_REFUSED;
					self::logAccountPortRefusal($this->requestCookieHost,
						self::effectivePort($URI), $configuredPort);
					return false;
				}
				return($am->fetch( $acc, $this, $URI, $method, $content_type, $body ));
			}
			return($this->fetch($URI,$method,$content_type,$body));
		}
		finally
		{
			// Source cookies and account sessions belong to this call.
			$this->cookies = $originalCookies;
			$this->secureResponseCookies = $originalSecureCookies;
			$this->selectedCookieAccount = $previousCookieAccount;
			list($this->requestCookieHost, $this->requestCookieScheme,
				$this->requestScopedCookies, $this->requestOriginalCookies,
				$this->requestAccountCookies, $this->loadedAccountCookies,
				$this->requestChainSourceURL) = $previousScope;
		}
	}

	/** Log a suppressed plugin cookie without exposing cookie data or URL userinfo. */
	private static function logPluginCookieRefusal($host, $account)
	{
		static $logged = array();
		$key = $host;
		if(isset($logged[$key]))
			return;
		$logged[$key] = true;
		$safeHost = self::safeLogHost($host);
		$details = 'source=cookies host=' . $safeHost;
		if(is_string($account) && $account !== '')
			$details .= ' account=' . preg_replace('/[^a-z0-9_.-]/i', '?', $account);
		FileUtil::toLog('cookies: http-refused: ' . $details . '; use HTTPS URL');
	}

	/** Keep caller cookies and account sessions on allowed redirects. */
	private function withoutSourceCookies($cookies)
	{
		foreach($this->requestScopedCookies as $name => $value)
		{
			if(!array_key_exists($name, $cookies) || $cookies[$name] !== $value
				|| isset($this->requestAccountCookies[$name]))
				continue;
			if(array_key_exists($name, $this->requestOriginalCookies))
				$cookies[$name] = $this->requestOriginalCookies[$name];
			else
				unset($cookies[$name]);
		}
		return $cookies;
	}

	protected function cookiesForRequest($url)
	{
		$host = UrlHost::of($url);
		$scheme = strtolower((string) @parse_url($url, PHP_URL_SCHEME));
		$cookies = $this->requestCookieHost !== null && $host === $this->requestCookieHost
			&& ($this->requestCookieScheme !== 'https' || $scheme === 'https')
			? $this->cookies : $this->withoutSourceCookies($this->cookies);
		if($scheme !== 'https')
		{
			foreach(array('secure-response' => $this->secureResponseCookies,
				'loginmgr-session' => $this->loadedAccountCookies) as $source => $restricted)
			{
				$refused = false;
				foreach($restricted as $name => $value)
					if(array_key_exists($name, $cookies) && $cookies[$name] === $value)
					{
						unset($cookies[$name]);
						$refused = true;
					}
				if($refused)
					self::logHttpsCookieRefusal($host, $source);
			}
		}
		return $cookies;
	}

	/** Public caller cookies stay flat; response cookies retain their wire scope. */
	private function cookiePairsForRequest($url)
	{
		$flat = $this->cookiesForRequest($url);
		if($this->_redirectdepth > 0
			&& !UrlHost::sameOrigin($this->requestChainSourceURL, $url)
			&& !UrlHost::isHttpsUpgrade($this->requestChainSourceURL, $url))
			foreach($flat as $name => $value)
				if($this->redirectTrust === null || !isset($this->loadedAccountCookies[$name])
					|| $this->loadedAccountCookies[$name] !== $value)
					unset($flat[$name]);
		$pairs = array();
		foreach($flat as $name => $value)
			$pairs[] = array('name' => $name, 'value' => $value);
		$host = UrlHost::of($url);
		$scheme = strtolower((string) @parse_url($url, PHP_URL_SCHEME));
		$path = (string) @parse_url($url, PHP_URL_PATH);
		if($path === '' || $path[0] !== '/')
			$path = '/';
		$this->purgeResponseCookies();
		$matching = array();
		foreach($this->responseCookieJar as $key => $entry)
		{
			if($entry['account'] !== $this->cookiePartition()
				|| array_key_exists($entry['name'], $flat)
				|| ($entry['hostOnly'] ? $host !== $entry['domain']
					: !UrlHost::isOneOf($host, array($entry['domain'])))
				|| !self::cookiePathMatches($path, $entry['path']))
				continue;
			if($entry['secure'] && $scheme !== 'https')
			{
				self::logHttpsCookieRefusal($host, 'secure-response');
				continue;
			}
			$this->responseCookieJar[$key]['lastUsed'] = ++$this->responseCookieSequence;
			$matching[] = $entry;
		}
		usort($matching, function($a, $b) {
			$length = strlen($b['path']) - strlen($a['path']);
			return $length ?: $a['created'] - $b['created'];
		});
		foreach($matching as $entry)
			$pairs[] = array('name' => $entry['name'], 'value' => $entry['value']);
		return $pairs;
	}

	private static function cookiePathMatches($requestPath, $cookiePath)
	{
		if(strncmp($requestPath, $cookiePath, strlen($cookiePath)) !== 0)
			return false;
		return $requestPath === $cookiePath || substr($cookiePath, -1) === '/'
			|| substr($requestPath, strlen($cookiePath), 1) === '/';
	}

	private function cookieHeaderForRequest($url)
	{
		foreach($this->rawheaders as $name => $value)
			if(strcasecmp((string) $name, 'Cookie') === 0)
				return '';
		$pairs = $this->cookiePairsForRequest($url);
		$values = array();
		foreach($pairs as $pair)
			$values[] = $pair['name'] . '=' . $pair['value'];
		return $values ? 'Cookie: ' . implode('; ', $values) : '';
	}

	private function cookiePartition()
	{
		return $this->selectedCookieAccount === null ? 'anonymous'
			: 'account:' . $this->selectedCookieAccount;
	}

	/** Capture against the URL that answered, before another redirect changes headers. */
	private function captureResponseCookies($url)
	{
		$host = UrlHost::of($url);
		if($host === null)
			return;
		$sourceScheme = strtolower((string) @parse_url($url, PHP_URL_SCHEME));
		$path = (string) @parse_url($url, PHP_URL_PATH);
		$lastSlash = strrpos($path, '/');
		$defaultPath = $lastSlash === false || $lastSlash === 0 ? '/'
			: substr($path, 0, $lastSlash);
		$now = time();
		$seen = 0;
		foreach($this->headers as $header)
		{
			if(++$seen > 100)
			{
				self::logCookieRefusal($host, 'header-limit');
				break;
			}
			if(!preg_match('/^Set-Cookie:[ \t]*(.*)$/i', trim($header), $match))
				continue;
			if(strlen($header) > 8192
				|| (strlen($header) >= $this->_maxlinelen - 1 && substr($header, -1) !== "\n"))
			{
				self::logCookieRefusal($host, 'oversized-or-truncated-header');
				continue;
			}
			$parts = explode(';', $match[1]);
			$nameValue = trim(array_shift($parts));
			$equal = strpos($nameValue, '=');
			if($equal === false)
			{
				self::logCookieRefusal($host, 'invalid-name-or-value');
				continue;
			}
			$name = trim(substr($nameValue, 0, $equal));
			$value = trim(substr($nameValue, $equal + 1));
			if(!preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D', $name)
				|| preg_match('/[\x00-\x1f\x7f]/', $value)
				|| strlen($name) + strlen($value) > self::MAX_COOKIE_BYTES)
			{
				self::logCookieRefusal($host, 'invalid-name-or-value');
				continue;
			}
			$attributes = array();
			foreach($parts as $part)
			{
				$equal = strpos($part, '=');
				$key = strtolower(trim($equal === false ? $part : substr($part, 0, $equal)));
				if($key !== '')
					$attributes[$key] = $equal === false ? '' : trim(substr($part, $equal + 1));
			}
			if(isset($attributes['secure']) && $sourceScheme !== 'https')
			{
				self::logCookieRefusal($host, 'secure-from-http-refused');
				continue;
			}
			$domain = $host;
			$hostOnly = true;
			if(isset($attributes['domain']))
			{
				$domain = strtolower($attributes['domain']);
				if(substr($domain, 0, 1) === '.')
					$domain = substr($domain, 1);
				try
				{
					$valid = filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) === false
						&& !PublicSuffix::isPublicSuffix($host)
						&& !PublicSuffix::isPublicSuffix($domain)
						&& UrlHost::isOneOf($host, array($domain));
				}
				catch(Exception $e)
				{
					$valid = false;
				}
				if(!$valid)
				{
					self::logCookieDomainRefusal($host);
					continue;
				}
				$hostOnly = false;
			}
			$cookiePath = isset($attributes['path']) && substr($attributes['path'], 0, 1) === '/'
				? $attributes['path'] : $defaultPath;
			if((stripos($name, '__Secure-') === 0 && !isset($attributes['secure']))
				|| (stripos($name, '__Host-') === 0
					&& (!isset($attributes['secure']) || !$hostOnly
						|| !isset($attributes['path']) || $cookiePath !== '/')))
			{
				self::logCookieRefusal($host, 'invalid-prefix');
				continue;
			}
			$expires = null;
			if(isset($attributes['expires']))
			{
				$parsed = strtotime($attributes['expires']);
				if($parsed !== false)
					$expires = $parsed;
			}
			if(isset($attributes['max-age']) && preg_match('/^-?[0-9]+$/D', $attributes['max-age']))
			{
				$age = (float) $attributes['max-age'];
				$expires = $age <= 0 ? $now : ($age >= PHP_INT_MAX - $now
					? PHP_INT_MAX : $now + (int) $age);
			}
			$key = $this->cookiePartition() . "\0" . $domain . "\0" . (int) $hostOnly
				. "\0" . $cookiePath . "\0" . $name;
			$this->purgeResponseCookies();
			if($sourceScheme !== 'https'
				&& $this->overwritesSecureCookie($name, $domain, $cookiePath))
			{
				self::logCookieRefusal($host, 'secure-overwrite-refused');
				continue;
			}
			if($expires !== null && $expires <= $now)
			{
				unset($this->responseCookieJar[$key]);
				continue;
			}
			$created = isset($this->responseCookieJar[$key])
				? $this->responseCookieJar[$key]['created'] : ++$this->responseCookieSequence;
			$this->responseCookieJar[$key] = array(
				'account' => $this->cookiePartition(), 'name' => $name, 'value' => $value,
				'domain' => $domain, 'hostOnly' => $hostOnly, 'path' => $cookiePath,
				'secure' => isset($attributes['secure']), 'expires' => $expires,
				'created' => $created, 'lastUsed' => ++$this->responseCookieSequence,
			);
			$this->enforceCookieLimits($domain);
		}
	}

	private function overwritesSecureCookie($name, $domain, $path)
	{
		foreach($this->responseCookieJar as $entry)
			if($entry['account'] === $this->cookiePartition()
				&& $entry['name'] === $name && $entry['secure']
				&& (UrlHost::isOneOf($domain, array($entry['domain']))
					|| UrlHost::isOneOf($entry['domain'], array($domain)))
				&& self::cookiePathMatches($path, $entry['path']))
				return true;
		return false;
	}

	private static function logCookieDomainRefusal($host)
	{
		self::logCookieRefusal($host, 'domain-refused');
	}

	private static function logCookieRefusal($host, $reason)
	{
		static $logged = array();
		$key = $reason . ' ' . $host;
		if(isset($logged[$key]))
			return;
		$logged[$key] = true;
		FileUtil::toLog('Snoopy: response-cookie-' . $reason . ' host=' . self::safeLogHost($host));
	}

	private function purgeResponseCookies()
	{
		$now = time();
		foreach($this->responseCookieJar as $key => $entry)
			if($entry['expires'] !== null && $entry['expires'] <= $now)
				unset($this->responseCookieJar[$key]);
	}

	private function enforceCookieLimits($domain)
	{
		$partition = $this->cookiePartition();
		while(count($this->responseCookieJar) > self::MAX_RESPONSE_COOKIES
			|| $this->countDomainCookies($domain, $partition) > self::MAX_DOMAIN_COOKIES)
		{
			$domainOver = $this->countDomainCookies($domain, $partition) > self::MAX_DOMAIN_COOKIES;
			$oldestKey = null;
			$oldestUse = PHP_INT_MAX;
			$oldestPriority = PHP_INT_MAX;
			foreach($this->responseCookieJar as $key => $entry)
			{
				if($domainOver && ($entry['domain'] !== $domain || $entry['account'] !== $partition))
					continue;
				$priority = $entry['secure'] ? 1 : 0;
				if($priority < $oldestPriority
					|| ($priority === $oldestPriority && $entry['lastUsed'] < $oldestUse))
				{
					$oldestKey = $key;
					$oldestUse = $entry['lastUsed'];
					$oldestPriority = $priority;
				}
			}
			if($oldestKey === null)
				break;
			unset($this->responseCookieJar[$oldestKey]);
		}
	}

	private function countDomainCookies($domain, $partition)
	{
		$count = 0;
		foreach($this->responseCookieJar as $entry)
			if($entry['domain'] === $domain && $entry['account'] === $partition)
				$count++;
		return $count;
	}

	private static function logAccountPortRefusal($host, $requested, $configured)
	{
		FileUtil::toLog('Snoopy: account-port-refused host=' . self::safeLogHost($host)
			. ' requested=' . (int) $requested . ' configured=' . (int) $configured);
	}

	private static function logHttpsCookieRefusal($host, $source)
	{
		static $logged = array();
		$key = $source . ' ' . $host;
		if(isset($logged[$key]))
			return;
		$logged[$key] = true;
		FileUtil::toLog('Snoopy: ' . $source . '-cookie-http-refused host=' . self::safeLogHost($host));
	}

	private static function safeLogHost($host)
	{
		return preg_replace('/[^a-z0-9.\[\]:-]/i', '?', (string) $host);
	}

	/** Source-specific cookies must not turn into a saved loginmgr session. */
	public function cookiesForAccountStorage()
	{
		return $this->withoutSourceCookies($this->cookies);
	}

	/** A cached account session may legitimately reuse a source cookie name. */
	public function markAccountCookies($cookies)
	{
		foreach((array) $cookies as $name => $value)
		{
			$this->requestAccountCookies[$name] = true;
			$this->loadedAccountCookies[$name] = $value;
		}
	}

	public function fetch($URI,$method="GET",$content_type="",$body="")
	{
		$parts = @parse_url((string) $URI);
		$scopedCredentials = is_array($parts) && isset($parts['user']);
		if($scopedCredentials)
		{
			$previousUser = $this->user;
			$previousPass = $this->pass;
			$this->user = isset($parts['user']) ? rawurldecode($parts['user']) : '';
			$this->pass = isset($parts['pass']) ? rawurldecode($parts['pass']) : '';
		}
		try
		{
			return $this->fetchRequest($URI,$method,$content_type,$body);
		}
		finally
		{
			// URL userinfo belongs to this request chain, not to a reused client.
			if($scopedCredentials)
			{
				$this->user = $previousUser;
				$this->pass = $previousPass;
			}
		}
	}

	private function fetchRequest($URI,$method,$content_type,$body)
	{
		$this->error = "";
		if($this->_redirectdepth === 0)
		{
			$this->lastredirectaddr = "";
			$this->lastResponseURL = "";
		}
		$parts = parse_url($URI);
		if( !is_array($parts) || !isset($parts["scheme"]) || !isset($parts["host"]) )
		{
			return(false);
		}
		rTorrentSettings::get()->pushEvent( "HostConnect", array
		(
			"client" => &$this,
			"uri" => &$URI,
			"method" => &$method,
			"content_type" => &$content_type,
			"body" => &$body
		) );
		if($this->_redirectdepth === 0)
			$this->requestChainSourceURL = $URI;
		$this->port = empty($parts["port"]) ? (($parts["scheme"]=="https") ? 443 : 80) : $parts["port"];
		$this->host = $parts["host"];
		$this->_isproxy = (!empty($this->proxy_host) && !empty($this->proxy_port));
		$this->_pinnedaddr = false;
		if($this->block_private && ($parts["scheme"]=="http" || $parts["scheme"]=="https") && !$this->checkTarget($this->host))
			return(false);
		$ret = true;
		switch($parts["scheme"])
		{
			case "dummy":
			{
				break;
			}
			case "http":
			{
				if($this->proxy_proto!='https')
				{
					if(($fp = $this->connect())!==false)
					{
						$path = (isset($parts["path"]) ? $parts["path"] : "/").(isset($parts["query"]) ? "?".$parts["query"] : "");
						$requestTarget = $path;
						if($this->_isproxy)
						{
							$requestTarget = $URI;
							if($this->_pinnedaddr!==false)
							{
								$pinned = filter_var($this->_pinnedaddr,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)!==false
									? '['.$this->_pinnedaddr.']' : $this->_pinnedaddr;
								// A proxy must receive the checked IP in the absolute URI;
								// Host and cookie scope still refer to the original URL.
								$requestTarget = 'http://'.$pinned.($this->port == 80 ? '' : ':'.$this->port).$path;
							}
						}
						$ret = $this->_httprequest($requestTarget,$fp,$URI,$method,$content_type,$body);
						fclose($fp);
					}
					else
						$ret = false;
					break;
				}
			}
			case "https":
			{
				$ret = $this->_httpsrequest($URI,$content_type,$body,$method);
				break;
			}
			default:
			{
				// not a valid protocol
				$this->error = 'Invalid protocol "'.$parts["scheme"].'"\n';
				$ret = false;
				break;
			}
		}
		if($ret)
		{
			$this->lastResponseURL = $URI;
			if($this->passcookies)
				$this->captureResponseCookies($URI);
			rTorrentSettings::get()->pushEvent( "URLFetched", array
			(
				"client" => $this,
				"uri" => $URI,
				"method" => $method,
				"content_type" => $content_type,
				"body" => $body
			) );
			if($this->_redirectaddr)
			{
				// Callers classify login redirects even when credentials prevent following them.
				$this->lastredirectaddr = $this->_redirectaddr;
				$redirect = $this->_redirectaddr;
				if($this->maxredirs > $this->_redirectdepth)
				{
					$trusted = $this->trustsRedirect($URI, $redirect);
					if(!$trusted)
					{
						// Do not import this response's cookies on a different origin,
						// including a later explicit fetch on the same client.
						$this->_redirectaddr = false;
						// Account operations must not import cookies from a foreign
						// landing page into Snoopy's flat jar, even before login.
						if($this->redirectTrust !== null || $this->carriesOwnCredentials() || $this->cookiesForRequest($redirect))
						{
							$this->error = self::CREDENTIAL_REDIRECT_REFUSED;
							$this->logRedirectRefusal($URI, $redirect);
							// The origin answered. A login caller may explicitly retain
							// its Set-Cookie without following the refused redirect.
							return($ret);
						}
					}
					$this->_redirectdepth++;
					$previousHeaders = $this->rawheaders;
					if(!UrlHost::sameOrigin($URI, $redirect))
						foreach($this->rawheaders as $name => $value)
							if(in_array(strtolower((string) $name), array('if-none-match', 'if-modified-since'), true))
								unset($this->rawheaders[$name]);
					try
					{
						$ret = $this->fetch($redirect);
					}
					finally
					{
						$this->rawheaders = $previousHeaders;
						// A failed nested target may return before its transport clears this
						// pending import. Never carry the source response into a later fetch.
						$this->_redirectaddr = false;
						$this->_redirectdepth--;
					}
				}
				else
					$this->_redirectaddr = false;
			}
		}
		else
			$this->_redirectaddr = false;
		return($ret);
	}

	protected static function redirectOriginForLog($url)
	{
		$parts = @parse_url((string) $url);
		if(!is_array($parts) || !isset($parts['scheme']))
			return 'unknown';
		$scheme = strtolower($parts['scheme']);
		if($scheme !== 'http' && $scheme !== 'https')
			return 'unknown';
		$host = UrlHost::of($url);
		if($host === null)
			return 'unknown';
		// A Location header is remote input: never log its path, query or userinfo.
		$host = self::safeLogHost($host);
		$port = self::effectivePort($url);
		return $scheme . '://' . $host . ':' . $port;
	}

	protected function logRedirectRefusal($source, $target)
	{
		static $logged = array();
		$key = self::redirectOriginForLog($source) . ' -> ' . self::redirectOriginForLog($target);
		if(isset($logged[$key]))
			return;
		$logged[$key] = true;
		FileUtil::toLog('Snoopy: ' . self::CREDENTIAL_REDIRECT_REFUSED . ': ' . $key . '; redirect target not requested, original response retained');
	}

	protected function trustsRedirect($source, $target)
	{
		$targetParts = @parse_url((string) $target);
		if(!is_array($targetParts) || isset($targetParts['user']))
			return false;
		if($this->redirectTrust === null)
			return UrlHost::sameOrigin($source, $target) || UrlHost::isHttpsUpgrade($source, $target);
		if(!is_callable($this->redirectTrust)
			|| !call_user_func($this->redirectTrust, $source)
			|| !call_user_func($this->redirectTrust, $target))
			return false;
		// The selected account's configured port is an authorization boundary.
		// RFC cookie Domain matching above deliberately does not use ports.
		$configuredPort = $this->configuredAccountPort();
		$sourcePort = self::effectivePort($source);
		$targetPort = self::effectivePort($target);
		if($configuredPort !== null
			&& ($sourcePort !== $configuredPort || $targetPort !== $configuredPort))
			return false;
		if(UrlHost::sameOrigin($source, $target))
			return strtolower((string) @parse_url($source, PHP_URL_SCHEME)) === 'https';
		if($this->carriesOwnCredentials())
			return false;
		// A standard-port same-host HTTP to HTTPS upgrade keeps the existing
		// anonymous path. Account cookies themselves remain HTTPS-only.
		if($configuredPort === null && UrlHost::isHttpsUpgrade($source, $target))
			return true;
		return strtolower((string) @parse_url($source, PHP_URL_SCHEME)) === 'https'
			&& strtolower((string) @parse_url($target, PHP_URL_SCHEME)) === 'https'
			&& $sourcePort !== null && $sourcePort === $targetPort;
	}

	private static function effectivePort($url)
	{
		$parts = @parse_url((string) $url);
		if(!is_array($parts) || !isset($parts['scheme']))
			return null;
		$scheme = strtolower($parts['scheme']);
		if($scheme !== 'http' && $scheme !== 'https')
			return null;
		return isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
	}

	private function configuredAccountPort()
	{
		$trust = $this->redirectTrust;
		return is_array($trust) && isset($trust[0]) && is_object($trust[0])
			&& isset($trust[0]->url) ? self::effectivePort($trust[0]->url) : null;
	}

	protected function carriesOwnCredentials()
	{
		if($this->user !== '' || $this->pass !== '')
			return(true);
		// Conditional validators may follow anonymous redirects; these headers may not.
		foreach($this->rawheaders as $name => $value)
			if(in_array(strtolower($name), array('cookie', 'authorization', 'proxy-authorization'), true))
				return(true);
		return(false);
	}

	protected function checkTarget($host)
	{
		if(in_array($host,$this->private_allowlist,true))
			return(true);
		$literal = filter_var(trim($host,"[]"),FILTER_VALIDATE_IP)!==false;
		$addresses = static::resolveHost($host);
		if(empty($addresses))
		{
			$this->error = 'Refusing to fetch: cannot resolve host "'.$host.'".';
			return(false);
		}
		foreach($addresses as $address)
			if(!self::isPublicAddress($address))
			{
				$this->error = 'Refusing to fetch: host "'.$host.'" resolves to the non-public address '.$address.'.';
				return(false);
			}
		// A second lookup at connect time could answer differently, so the
		// address that passed the check is the one the request goes to.
		if(!$literal)
			$this->_pinnedaddr = $addresses[0];
		return(true);
	}

	static public function resolveHost($host)
	{
		$host = trim($host,"[]");
		if(filter_var($host,FILTER_VALIDATE_IP)!==false)
			return(array($host));
		$addresses = array();
		$records = @dns_get_record($host,DNS_A|DNS_AAAA);
		if(is_array($records))
			foreach($records as $record)
			{
				if(isset($record["ip"]))
					$addresses[] = $record["ip"];
				if(isset($record["ipv6"]))
					$addresses[] = $record["ipv6"];
			}
		if(empty($addresses))
		{
			$byname = @gethostbynamel($host);
			if(is_array($byname))
				$addresses = $byname;
		}
		return(array_values(array_unique($addresses)));
	}

	static public function isPublicAddress($address)
	{
		if(filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false)
			return(false);
		// Ranges filter_var accepts but that are not reachable on the public internet
		foreach(array("100.64.0.0/10","192.0.0.0/24","192.88.99.0/24","198.18.0.0/15") as $cidr)
			if(self::addressInCIDR($address,$cidr))
				return(false);
		return(true);
	}

	static protected function addressInCIDR($address,$cidr)
	{
		$ip = ip2long($address);
		list($network,$bits) = explode("/",$cidr);
		$network = ip2long($network);
		if($ip===false || $network===false)
			return(false);
		$mask = -1 << (32-intval($bits));
		return(($ip & $mask)===($network & $mask));
	}

	function get_header( $name )
	{
		foreach( $this->headers as $i=>$header )
			if(preg_match_all("/^$name:\s*(.*)/i",$header,$matches))
				return( $matches[1][0] );
		return(false);
	}

	function get_filename()
	{
		foreach( $this->headers as $i=>$header )
			if(preg_match_all("/^Content-Disposition:.*(\s|;)filename=\"(.*)\.torrent\"/i",$header,$matches) && (count($matches)>2))
				return( str_replace( '/', '_', rawurldecode($matches[2][0]).".torrent" ) );
		return(false);
	}

	function get_modified()
	{
		$value = $this->get_header( 'Last-Modified' );
		return( $value ? strtotime($value) : false );
	}

	protected function redirectFromHeader($header, $scheme)
	{
		$header = rtrim($header, "\r\n");
		if(!preg_match('/^(Location:|URI:)[ \t]*(.+)/i', $header, $matches)
			&& !preg_match('/(^Refresh:\s+\d+\s*;\s*url\s*=\s*)(.+)/i', $header, $matches))
			return false;
		$target = trim($matches[2]);
		if($target === '')
			return false;
		// A network-path reference names its own authority and inherits only
		// the current scheme; it is not a path on the requested host.
		if(strpos($target, '//') === 0)
			return $scheme . ':' . $target;
		if(strpos($target, '://') !== false)
			return $target;
		$host = isset($this->rawheaders['Host']) ? $this->rawheaders['Host'] : $this->host;
		return $scheme . '://' . $host . ':' . $this->port
			. ($target[0] === '/' ? $target : '/' . $target);
	}

	protected function refuseResponseBody($reason)
	{
		$this->status = self::RESPONSE_BODY_FAILED;
		$this->error = $reason;
		$this->results = '';
		return(false);
	}

	protected function refuseGzipBody()
	{
		if(!$this->hasZlibGzip())
			return($this->refuseResponseBody('gzip-decoder-unavailable'));
		return($this->refuseResponseBody('invalid-or-oversized-gzip'));
	}

	protected static function isGzipContentEncoding($header)
	{
		return(preg_match('/^Content-Encoding[ \t]*:[ \t]*gzip[ \t]*$/i',
			rtrim($header, "\r\n")) === 1);
	}

	protected static function readFixedBytes($fp, $length)
	{
		$body = '';
		while(strlen($body) < $length)
		{
			$part = fread($fp, min(4096, $length - strlen($body)));
			if($part === false || $part === '')
				return(false);
			$body .= $part;
		}
		return($body);
	}

	protected function readChunkedBody($fp)
	{
		$body = '';
		$limit = (int)$this->maxlength;
		if($limit < 0)
			return(false);
		while(true)
		{
			$line = fgets($fp, $this->_maxlinelen);
			if($line === false || !preg_match('/^([0-9a-f]+)(?:;[^\r\n]*)?\r?\n$/i',
				$line, $match))
				return(false);
			$size = hexdec($match[1]);
			if($size > $limit - strlen($body))
				return(false);
			if($size == 0)
				break;
			$part = self::readFixedBytes($fp, (int)$size);
			if($part === false || self::readFixedBytes($fp, 2) !== "\r\n")
				return(false);
			$body .= $part;
		}
		$trailerBytes = 0;
		while(($line = fgets($fp, $this->_maxlinelen)) !== false)
		{
			$trailerBytes += strlen($line);
			if($trailerBytes > 8192)
				return(false);
			if($line === "\r\n" || $line === "\n")
				return($body);
			if(!preg_match('/^[^\r\n]+\r?\n$/', $line))
				return(false);
		}
		return(false);
	}

	protected function hasZlibGzip()
	{
		return(function_exists('inflate_init') && function_exists('inflate_add')
			&& function_exists('inflate_get_status') && function_exists('inflate_get_read_len'));
	}

	// Decode bounded chunks so a small compressed answer cannot expand without
	// limit. The old external fallback trusted gzip's exit status, which can ignore trailing bytes.
	protected function decodeGzipBody($encoded)
	{
		$limit = (int)$this->maxlength;
		if(!is_string($encoded) || strlen($encoded) < 10 || $limit < 0
			|| strlen($encoded) > $limit || substr($encoded, 0, 2) !== "\x1f\x8b")
			return(false);
		if($this->hasZlibGzip())
		{
			$decoded = '';
			$length = strlen($encoded);
			$consumed = 0;
			while($consumed < $length)
			{
				// gzip accepts zero padding after the final member.
				if(strspn($encoded, "\0", $consumed) === $length - $consumed)
					break;
				$inflater = @inflate_init(ZLIB_ENCODING_GZIP);
				if($inflater === false)
					return(false);
				for($offset = $consumed; $offset < $length; $offset += 1024)
				{
					$chunk = substr($encoded, $offset, 1024);
					$mode = $offset + strlen($chunk) === $length ? ZLIB_FINISH : ZLIB_SYNC_FLUSH;
					$part = @inflate_add($inflater, $chunk, $mode);
					if($part === false || strlen($part) > $limit - strlen($decoded))
						return(false);
					$decoded .= $part;
					if(inflate_get_status($inflater) === ZLIB_STREAM_END)
						break;
				}
				if(inflate_get_status($inflater) !== ZLIB_STREAM_END)
					return(false);
				$read = inflate_get_read_len($inflater);
				if($read <= 0)
					return(false);
				$consumed += $read;
			}
			return($decoded);
		}
		return(false);
	}

	function _httprequest($url,$fp,$URI,$http_method,$content_type="",$body="")
	{
		if(empty($url))
			$url = "/";
		$headers = $http_method." ".$url." ".$this->_httpversion."\r\n";
		if(!empty($this->agent))
			$headers .= "User-Agent: ".$this->agent."\r\n";
		if(!empty($this->host) && !isset($this->rawheaders['Host']))
			$headers .= "Host: ".$this->host.($this->port == 80 ? "" : ":".$this->port)."\r\n";
		if(!empty($this->accept))
			$headers .= "Accept: ".$this->accept."\r\n";
		if($this->use_gzip && $this->hasZlibGzip())
			$headers .= "Accept-encoding: gzip\r\n";
		if(!empty($this->referer))
			$headers .= "Referer: ".$this->referer."\r\n";
		$cookieHeader = $this->cookieHeaderForRequest($URI);
		if($cookieHeader !== '')
			$headers .= $cookieHeader . "\r\n";
		foreach( $this->rawheaders as $headerKey => $headerVal )
			$headers .= $headerKey.": ".$headerVal."\r\n";
		if(!empty($content_type))
		{
			$headers .= "Content-type: $content_type";
			if ($content_type == "multipart/form-data")
				$headers .= "; boundary=".$this->_mime_boundary;
			$headers .= "\r\n";
		}
		if(!empty($body))
			$headers .= "Content-length: ".strlen($body)."\r\n";
		if(!empty($this->user) || !empty($this->pass))
			$headers .= "Authorization: Basic ".base64_encode($this->user.":".$this->pass)."\r\n";

		$headers .= "\r\n";

		// set the read timeout if needed
		if ($this->read_timeout > 0)
			// socket_set_timeout() is the same function under a name PHP 8.5
			// deprecates; stream_set_timeout() has existed since PHP 4.3.
			stream_set_timeout($fp, $this->read_timeout);
		$this->timed_out = false;
		fwrite($fp,$headers.$body,strlen($headers.$body));
		$this->_redirectaddr = false;
		$this->headers = array();
		$this->status = 0;
		$this->response_code = '';
		$is_gzipped = false;
		$transferCodings = array();
		$interimBlocks = 0;

		while($currentHeader = fgets($fp,$this->_maxlinelen))
		{
			if ($this->read_timeout > 0 && $this->_check_timeout($fp))
			{
				$this->status=-100;
				return false;
			}

			if(preg_match("/^\r?\n$/", $currentHeader))
			{
				$status = (int) $this->status;
				if($status >= 100 && $status < 200 && $status !== 101)
				{
					if(++$interimBlocks > 5)
						return $this->refuseResponseBody('too-many-interim-responses');
					$this->headers = array();
					$this->_redirectaddr = false;
					$this->response_code = '';
					$this->status = 0;
					$is_gzipped = false;
					$transferCodings = array();
					continue;
				}
				break;
			}

			if(preg_match('/^(Location:|URI:|Refresh:)/i', $currentHeader))
			{
				$redirect = $this->redirectFromHeader($currentHeader, 'http');
				if($redirect !== false)
					$this->_redirectaddr = $redirect;
			}
			if(preg_match("|^HTTP/|",$currentHeader))
			{
		                if(preg_match("|^HTTP/[^\s]*\s(.*?)\s|",$currentHeader, $status))
				{
					$this->status= $status[1];
				}
				$this->response_code = $currentHeader;
			}

			if (self::isGzipContentEncoding($currentHeader))
				$is_gzipped = true;
			if(preg_match('/^Transfer-Encoding[ \t]*:/i', $currentHeader))
			{
				if(preg_match('/^Transfer-Encoding[ \t]*:[ \t]*(.*)\r?\n$/i',
					$currentHeader, $match))
					foreach(explode(',', $match[1]) as $coding)
						$transferCodings[] = strtolower(trim($coding));
				else
					$transferCodings[] = '';
			}

			$this->headers[] = $currentHeader;
		}

		if($this->response_code === '')
			return $this->refuseResponseBody('missing-final-response');

		$is_chunked = $transferCodings === array('chunked')
			|| $transferCodings === array('gzip', 'chunked');
		$transferGzip = $transferCodings === array('gzip', 'chunked');
		if($transferCodings && !$is_chunked)
		{
			return($this->refuseResponseBody('unsupported-transfer-encoding'));
		}
		$results = $is_chunked ? $this->readChunkedBody($fp) : '';
		if($is_chunked && $results === false)
		{
			if($this->_check_timeout($fp))
			{
				$this->status = -100;
				$this->results = '';
				return(false);
			}
			return($this->refuseResponseBody('invalid-or-oversized-chunked'));
		}
		if(!$is_chunked)
		{
			while($data = fread($fp, 4096))
			{
				$results .= $data;
				if(strlen($results) > $this->maxlength)
					break;
			}
			if(strlen($results) > $this->maxlength)
			{
				return($this->refuseResponseBody('oversized-response'));
			}
		}
		foreach(array($transferGzip, $is_gzipped) as $encodedGzip)
		{
			if(!$encodedGzip)
				continue;
			$results = $this->decodeGzipBody($results);
			if($results === false)
			{
				return($this->refuseGzipBody());
			}
		}

		if ($this->read_timeout > 0 && $this->_check_timeout($fp))
		{
			$this->status=-100;
			return false;
		}

		$this->results = $results;
		return true;
	}

	function _httpsrequest($url,$content_type="",$body="",$http_method="GET")
	{
		$headers = array();
		if(!empty($this->agent))
			$headers[] = "User-Agent: ".$this->agent;
		if(!empty($this->host) && !isset($this->rawheaders['Host']))
			$headers[] = "Host: ".$this->host.($this->port == 443 ? "" : ":".$this->port);
		if(!empty($this->accept))
			$headers[] = "Accept: ".$this->accept;
		if($this->use_gzip && $this->hasZlibGzip())
			$headers[] = "Accept-Encoding: gzip";
		if(!empty($this->referer))
			$headers[] = "Referer: ".$this->referer;
		$cookieHeader = $this->cookieHeaderForRequest($url);
		if($cookieHeader !== '')
			$headers[] = $cookieHeader;
		foreach( $this->rawheaders as $headerKey => $headerVal )
			$headers[] = $headerKey.": ".$headerVal;
		if(!empty($content_type))
		{
			if ($content_type == "multipart/form-data")
				$headers[] = "Content-type: $content_type; boundary=".$this->_mime_boundary;
			else
				$headers[] = "Content-type: $content_type";
		}
		if(!empty($body))
			$headers[] = "Content-length: ".strlen($body);
		if(!empty($this->user) || !empty($this->pass))
			$headers[] = "Authorization: Basic ".base64_encode($this->user.":".$this->pass);

		$cmdline_params = '';
		// Legacy 3-arg callers keep curl's own method choice; explicit callers
		// (fetch with any method) get the method forwarded verbatim.
		if(func_num_args() >= 4)
			$cmdline_params .= " -X ".escapeshellarg(strtoupper($http_method));
		foreach( $headers as $header )
			$cmdline_params .= " -H ".escapeshellarg($header);

		if(!empty($body))
			$cmdline_params .= " -d ".escapeshellarg($body);

		if($this->read_timeout > 0)
			$cmdline_params .= " -m ".$this->read_timeout;

		if(!is_null($this->IP))
			$cmdline_params .= " --interface ".$this->IP;

		if($this->useIpv4)
			$cmdline_params .= " --ipv4";

		if($this->_pinnedaddr!==false)
		{
			$pinned = (filter_var($this->_pinnedaddr,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)!==false)
				? "[".$this->_pinnedaddr."]" : $this->_pinnedaddr;
			// With a proxy, --resolve only changes local DNS; the proxy would
			// still resolve a hostname in CONNECT or an absolute HTTP target.
			if($this->_isproxy && parse_url($url,PHP_URL_SCHEME)==='http')
			{
				$path = parse_url($url,PHP_URL_PATH) ?: '/';
				$query = parse_url($url,PHP_URL_QUERY);
				$target = 'http://'.$pinned.($this->port == 80 ? '' : ':'.$this->port)
					.$path.($query === null ? '' : '?'.$query);
				$cmdline_params .= ' --request-target '.escapeshellarg($target);
			}
			else
			{
				$pinOption = $this->_isproxy ? '--connect-to' : '--resolve';
				$pinValue = $this->_isproxy
					? $this->host.':'.$this->port.':'.$pinned.':'.$this->port
					: $this->host.':'.$this->port.':'.$pinned;
				$cmdline_params .= ' '.$pinOption.' '.escapeshellarg($pinValue);
			}
		}

		if($this->block_private)
			// Environment NO_PROXY/HTTPS_PROXY must not change which pinning path curl uses.
			$cmdline_params .= ' --noproxy '.escapeshellarg($this->_isproxy ? '' : '*');

		if($this->_isproxy)
			$cmdline_params .= " --proxy-insecure --proxy ".(empty($this->proxy_proto) ? '' : $this->proxy_proto.'://').$this->proxy_host.":".$this->proxy_port;

		$headerfile = FileUtil::getTempFilename('https-header');
		$contfile = FileUtil::getTempFilename('https-body');

		# accept self-signed certs
		$cmdline_params .= " -k -s ";
		// The shipped curl (8.22) stops even an unknown-length response during
		// transfer, before the temporary body file can grow without bound.
		$maxDownload = max(1, (int)$this->maxlength);
		exec(escapeshellarg(Utility::getExternal('curl'))." -g --max-filesize ".$maxDownload
			." -D ".escapeshellarg($headerfile)." -o ".escapeshellarg($contfile)." ".$cmdline_params." ".escapeshellarg($url),$results,$return);
		$this->_redirectaddr = false;
		$this->headers = array();
		$this->status = $return;
		$this->response_code = "";
		$this->results = "";
		$is_gzipped = false;
		if($return)
		{
			$this->error = $return === 63 ? 'oversized-response'
				: "Error: cURL could not retrieve the document, error $return.";
		}
		else
		{
			$limit = max(0, (int)$this->maxlength);
			$results = @file_get_contents($contfile, false, null, 0, $limit + 1);
			if($results === false || strlen($results) > $limit)
			{
				@unlink($headerfile);
				@unlink($contfile);
				return($this->refuseResponseBody('unreadable-or-oversized-response'));
			}
			$result_headers = @file($headerfile);
			if(!is_array($result_headers))
			{
				@unlink($headerfile);
				@unlink($contfile);
				return $this->refuseResponseBody('missing-final-response');
			}
			foreach($result_headers as $header)
			{
				if(preg_match('/^(Location:|URI:|Refresh:)/i', $header))
				{
					$redirect = $this->redirectFromHeader($header, 'https');
					if($redirect === false)
						continue;
					$this->_redirectaddr = $redirect;
				}
				if(preg_match("|^HTTP/|",$header))
				{
					// curl -D can include CONNECT and 100 blocks before the
					// origin answer. Only the final block owns its cookies.
					if($this->response_code !== '')
					{
						$this->headers = array();
						$this->_redirectaddr = false;
						$is_gzipped = false;
					}
					$this->response_code = $header;
					if(preg_match("|^HTTP/[^\s]*\s(.*?)\s|",$this->response_code, $match))
						$this->status= $match[1];
				}
				if (self::isGzipContentEncoding($header))
					$is_gzipped = true;
				$this->headers[] = $header;
			}
			if($this->response_code === ''
				|| ((int) $this->status >= 100 && (int) $this->status < 200))
			{
				@unlink($headerfile);
				@unlink($contfile);
				return $this->refuseResponseBody('missing-final-response');
			}
			if($is_gzipped)
			{
				$results = $this->decodeGzipBody($results);
				if($results === false)
				{
					@unlink($headerfile);
					@unlink($contfile);
					return($this->refuseGzipBody());
				}
			}
			$this->results = $results;
		}
		@unlink($headerfile);
		@unlink($contfile);
		return(!$return);
	}

	function setcookies()
	{
		foreach($this->headers as $header)
		{
			if(preg_match("/^set-cookie:[\s]+([^=]+)=([^;]+)/i", $header,$match))
			{
				if($match[2]=="deleted")
				{
					unset($this->cookies[$match[1]], $this->requestAccountCookies[$match[1]],
						$this->secureResponseCookies[$match[1]], $this->loadedAccountCookies[$match[1]]);
				}
				else
				{
					$this->cookies[$match[1]] = $match[2];
					$this->requestAccountCookies[$match[1]] = true;
					if(isset($this->loadedAccountCookies[$match[1]]))
						$this->loadedAccountCookies[$match[1]] = $match[2];
					$attributes = substr($header, strlen($match[0]));
					if(preg_match('/(?:^|;)\s*secure\s*(?:=|;|$)/i', $attributes))
						$this->secureResponseCookies[$match[1]] = $match[2];
					else
						unset($this->secureResponseCookies[$match[1]]);
				}
			}
		}
	}

	function _check_timeout($fp)
	{
		if ($this->read_timeout > 0)
		{
			$fp_status = socket_get_status($fp);
			if ($fp_status["timed_out"])
			{
				$this->timed_out = true;
				return true;
			}
		}
		return false;
	}

	function connect()
	{
		$fp = false;
		if($this->_isproxy)
		{
			$host = $this->proxy_host;
			$port = $this->proxy_port;
		}
		else
		{
			$host = ($this->_pinnedaddr===false) ? $this->host : $this->_pinnedaddr;
			$port = $this->port;
		}
		if(filter_var(trim($host,"[]"),FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)!==false)
			$host = "[".trim($host,"[]")."]";
		$this->status = 0;
		if(function_exists("stream_socket_client") && !is_null($this->IP))
		{
			$opts = array('socket' => array('bindto' => $this->IP.':0'));
			$context = stream_context_create($opts);
			$fp = stream_socket_client("tcp://".$host.":".$port, $errno, $errstr, $this->_fp_timeout, STREAM_CLIENT_CONNECT, $context);
		}
		else
			$fp = fsockopen($host,$port,$errno,$errstr,$this->_fp_timeout);
		if(!$fp)
		{
			// socket connection failed
			$this->status = $errno;
			switch($errno)
			{
				case -3:
					$this->error="socket creation failed (-3)";
				case -4:
					$this->error="dns lookup failure (-4)";
				case -5:
					$this->error="connection refused or timed out (-5)";
				default:
					$this->error="connection failed (".$errno.")";
			}
		}
		return($fp);
	}
}
