<?php

require_once(__DIR__.'/../urlhost.php');

class Requests
{
	private static function currentOrigin()
	{
		$scheme = isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
			? strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'])
			: (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https'
				: (isset($_SERVER['REQUEST_SCHEME']) ? strtolower($_SERVER['REQUEST_SCHEME']) : 'http'));
		$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
		return in_array($scheme, array('http', 'https'), true) && $host !== ''
			? $scheme.'://'.$host : null;
	}

	public static function isSameOriginAjaxRequest()
	{
		if(!isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
			$_SERVER['HTTP_X_REQUESTED_WITH'] !== 'XMLHttpRequest' ||
			(isset($_SERVER['HTTP_SEC_FETCH_SITE']) &&
				$_SERVER['HTTP_SEC_FETCH_SITE'] !== 'same-origin'))
			return false;
		$origin = self::currentOrigin();
		if($origin === null)
			return false;
		foreach(array('HTTP_ORIGIN', 'HTTP_REFERER') as $source)
			if(isset($_SERVER[$source]) && !UrlHost::sameOrigin($_SERVER[$source], $origin))
				return false;
		return true;
	}

	public static function requirePluginBootstrapRequest()
	{
		$allowed = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'GET';
		if($allowed && (!isset($_SERVER['RUTORRENT_INTERNAL_PLUGIN_HEALTH']) ||
			$_SERVER['RUTORRENT_INTERNAL_PLUGIN_HEALTH'] !== '1'))
		{
			// A cross-origin classic script has no custom AJAX header. A browser
			// cannot add it to a cross-origin request without a CORS preflight.
			$allowed = self::isSameOriginAjaxRequest();
		}
		if(!$allowed)
		{
			error_log('getplugins: refused request: same-origin AJAX required');
			header('HTTP/1.0 403 Forbidden', true, 403);
			die('Forbidden');
		}
		header('Vary: X-Requested-With, Sec-Fetch-Site, Origin, Referer');
	}

	public static function disableUnsupportedMethods()
	{
		if( isset($_SERVER['REQUEST_METHOD']) &&
			($_SERVER['REQUEST_METHOD']!='GET') &&
			($_SERVER['REQUEST_METHOD']!='POST') )
		{
			header('HTTP/1.0 405 Method Not Allowed', true, 405);
			die('Method Not Allowed');
		}
	}

	public static function requirePost()
	{
		if(!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST')
		{
			error_log('request: refused mutation: POST required');
			header('HTTP/1.0 405 Method Not Allowed', true, 405);
			header('Allow: POST');
			die('Method Not Allowed');
		}
	}

	public static function makeCSRFCheck()
	{
		global $enableCSRFCheck;
		if($enableCSRFCheck &&
			isset($_SERVER['REQUEST_METHOD']) &&
			($_SERVER['REQUEST_METHOD'] != "GET"))
		{
			global $enabledOrigins;
			$hasOrigin = array_key_exists('HTTP_ORIGIN', $_SERVER);
			$source = $hasOrigin ? $_SERVER['HTTP_ORIGIN'] :
				(isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : null);
			if(is_string($source) && $source !== '' && $source !== 'null')
			{
				$origin = self::currentOrigin();
				if($origin !== null && UrlHost::sameOrigin($source, $origin))
					return;
				// Explicit configured hosts retain their documented host-only meaning.
				$sourceScheme = strtolower((string) @parse_url($source, PHP_URL_SCHEME));
				$sourceHost = UrlHost::of($source);
				if(in_array($sourceScheme, array('http', 'https'), true) && $sourceHost !== null)
					foreach((array) $enabledOrigins as $allowedHost)
						if($sourceHost === UrlHost::normalize($allowedHost))
							return;
			}
			// A raw httprpc client has no browser Fetch Metadata or Origin/Referer.
			// Browser actions, including opaque origins, must never use this route exception.
			if($source === null && !isset($_SERVER['HTTP_SEC_FETCH_SITE']) &&
				isset($_SERVER['SCRIPT_NAME']) &&
				substr($_SERVER['SCRIPT_NAME'], -strlen('/plugins/httprpc/action.php')) === '/plugins/httprpc/action.php')
				return;
			error_log('csrf: refused POST: untrusted or missing origin');
			header('HTTP/1.0 403 Forbidden', true, 403);
			die('Forbidden');
		}
	}
}
