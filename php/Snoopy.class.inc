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
		$previousScope = array($this->requestCookieHost, $this->requestCookieScheme,
			$this->requestScopedCookies, $this->requestOriginalCookies, $this->requestAccountCookies);
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
			if($acc)
				return($am->fetch( $acc, $this, $URI, $method, $content_type, $body ));
			return($this->fetch($URI,$method,$content_type,$body));
		}
		finally
		{
			// Source cookies and account sessions belong to this call.
			$this->cookies = $originalCookies;
			list($this->requestCookieHost, $this->requestCookieScheme,
				$this->requestScopedCookies, $this->requestOriginalCookies,
				$this->requestAccountCookies) = $previousScope;
		}
	}

	/** Log a suppressed plugin cookie without exposing cookie data or URL userinfo. */
	private static function logPluginCookieRefusal($host, $account)
	{
		static $logged = array();
		$key = $host . "\0" . (string) $account;
		if(isset($logged[$key]))
			return;
		$logged[$key] = true;
		$safeHost = preg_replace('/[^a-z0-9.\[\]:-]/i', '?', $host);
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
		if($this->requestCookieHost !== null && $host === $this->requestCookieHost
			&& ($this->requestCookieScheme !== 'https' || $scheme === 'https'))
			return $this->cookies;
		return $this->withoutSourceCookies($this->cookies);
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
			$this->requestAccountCookies[$name] = true;
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
			$this->lastredirectaddr = "";
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
						$ret = ( $this->_isproxy ?
							$this->_httprequest($URI,$fp,$URI,$method,$content_type,$body) :
							$this->_httprequest($path,$fp,$URI,$method,$content_type,$body) );
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
						if($this->redirectTrust !== null || $this->hasRedirectCredentials())
						{
							$this->error = self::CREDENTIAL_REDIRECT_REFUSED;
							$this->logRedirectRefusal($URI, $redirect);
							// The origin answered. A login caller may explicitly retain
							// its Set-Cookie without following the refused redirect.
							return($ret);
						}
					}
					$this->_redirectdepth++;
					try
					{
						$ret = $this->fetch($redirect);
					}
					finally
					{
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
		$host = preg_replace('/[^a-z0-9.\[\]:-]/i', '?', $host);
		$port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
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
			return(false);
		if(UrlHost::sameOrigin($source, $target) || UrlHost::isHttpsUpgrade($source, $target))
			return(true);
		// Account redirect trust scopes its cookie jar, never URL Basic or raw credentials.
		if($this->carriesOwnCredentials())
			return(false);
		// Account selection is also the authority to send that account's session.
		// It never authorizes a downgrade, even if a custom account accepts HTTP.
		return(strtolower((string) parse_url($source, PHP_URL_SCHEME)) === 'https'
			&& strtolower((string) parse_url($target, PHP_URL_SCHEME)) === 'https'
			&& is_callable($this->redirectTrust)
			&& call_user_func($this->redirectTrust, $source)
			&& call_user_func($this->redirectTrust, $target));
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

	protected function hasRedirectCredentials()
	{
		return(!empty($this->cookies) || $this->carriesOwnCredentials());
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
		if(!$literal && !$this->_isproxy)
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
	// limit. The external path exists only for PHP builds without zlib.
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
		if(!function_exists('proc_open'))
			return(false);
		$input = @tmpfile();
		if(!is_resource($input))
			return(false);
		try
		{
			$path = stream_get_meta_data($input)['uri'];
			if(@fwrite($input, $encoded) !== strlen($encoded) || !@fflush($input))
				return(false);
			$process = @proc_open(array(Utility::getExternal('gzip'), '-dc', $path),
				array(0 => array('file', '/dev/null', 'r'),
					1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w')),
				$pipes);
			if(!is_resource($process))
				return(false);
			$output = stream_get_contents($pipes[1], $limit + 1);
			if($output === false || strlen($output) > $limit)
				@proc_terminate($process);
			fclose($pipes[1]);
			$status = proc_close($process);
			return(is_string($output) && strlen($output) <= $limit && $status === 0
				? $output : false);
		}
		finally
		{
			fclose($input);
		}
	}

	function _httprequest($url,$fp,$URI,$http_method,$content_type="",$body="")
	{
		if($this->passcookies && $this->_redirectaddr)
			$this->setcookies();
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
		$requestCookies = $this->cookiesForRequest($URI);
		if(count($requestCookies))
		{
			$cookie_headers = 'Cookie: ';
			foreach( $requestCookies as $cookieKey => $cookieVal )
				$cookie_headers .= $cookieKey."=".$cookieVal."; ";
			$headers .= substr($cookie_headers,0,-2) . "\r\n";
		}
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
		$is_gzipped = false;
		$transferCodings = array();

		while($currentHeader = fgets($fp,$this->_maxlinelen))
		{
			if ($this->read_timeout > 0 && $this->_check_timeout($fp))
			{
				$this->status=-100;
				return false;
			}

			if(preg_match("/^\r?\n$/", $currentHeader))
			      break;

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
				return($this->refuseResponseBody('invalid-or-oversized-gzip'));
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
		if($this->passcookies && $this->_redirectaddr)
			$this->setcookies();
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
		$requestCookies = $this->cookiesForRequest($url);
		if( count($requestCookies) )
		{
			$cookie_str = 'Cookie: ';
			foreach( $requestCookies as $cookieKey => $cookieVal )
				$cookie_str .= $cookieKey."=".$cookieVal."; ";
			$headers[] = substr($cookie_str,0,-2);
		}
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
			$cmdline_params .= " --resolve ".escapeshellarg($this->host.":".$this->port.":".$pinned);
		}

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
			$result_headers = file($headerfile);
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
					$this->response_code = $header;
					if(preg_match("|^HTTP/[^\s]*\s(.*?)\s|",$this->response_code, $match))
						$this->status= $match[1];
				}
				if (self::isGzipContentEncoding($header))
					$is_gzipped = true;
				$this->headers[] = $header;
			}
			if($is_gzipped)
			{
				$results = $this->decodeGzipBody($results);
				if($results === false)
				{
					@unlink($headerfile);
					@unlink($contfile);
					return($this->refuseResponseBody('invalid-or-oversized-gzip'));
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
					unset($this->cookies[$match[1]], $this->requestAccountCookies[$match[1]]);
				}
				else
				{
					$this->cookies[$match[1]] = $match[2];
					$this->requestAccountCookies[$match[1]] = true;
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
