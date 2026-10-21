<?php

require_once(__DIR__.'/urlhost.php');

class Requests
{
	private static function normalizedPublicBase($value)
	{
		if(!is_string($value) || preg_match('/[\x00-\x20\x7f\\\\]/', $value) ||
			filter_var($value, FILTER_VALIDATE_URL) === false)
			return false;
		$parts = @parse_url($value);
		if(!is_array($parts) || empty($parts['host']) || empty($parts['scheme']) ||
			!in_array(strtolower($parts['scheme']), array('http', 'https'), true) ||
			isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']))
			return false;
		return rtrim($value, '/').'/';
	}

	/** Public RSS links need an explicit base when the proxy rewrites Host or the prefix. */
	public static function publicBaseURL($scriptSuffix)
	{
		global $publicURL;
		$environment = getenv('RU_PUBLIC_URL');
		// An empty environment value permits config.local.php overrides.
		$usesEnvironment = $environment !== false && $environment !== '';
		$configured = $usesEnvironment ? $environment : ($publicURL ?? '');
		if($configured !== '' && $configured !== null)
		{
			$base = self::normalizedPublicBase($configured);
			if($base !== false)
				return $base;
			error_log('public-url: refused setting='.($usesEnvironment ? 'RU_PUBLIC_URL' : 'publicURL')
				.'; expected an absolute HTTP(S) application base without credentials, query or fragment');
			return false;
		}
		// Forwarded-Host and CSRF grants do not identify the public application URL.
		$origin = self::currentOrigin();
		$originParts = $origin === null ? false : @parse_url($origin);
		$script = $_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? '');
		if(is_string($scriptSuffix) && $scriptSuffix !== '' && is_string($script) &&
			isset($script[0]) && $script[0] === '/' &&
			substr($script, -strlen($scriptSuffix)) === $scriptSuffix && is_array($originParts) &&
			!isset($originParts['path']) && !isset($originParts['query']) && !isset($originParts['fragment']))
		{
			$base = self::normalizedPublicBase($origin.substr($script, 0, -strlen($scriptSuffix)).'/');
			if($base !== false)
				return $base;
		}
		error_log('public-url: refused request origin or script path; configure RU_PUBLIC_URL for a rewriting proxy');
		return false;
	}

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

    private static function invalidTrustedOrigin()
    {
        static $reported = false;
        if(!$reported) {
            $reported = true;
            $setting = getenv('RU_TRUSTED_ORIGINS') === false ? 'trustedOrigins' : 'RU_TRUSTED_ORIGINS';
            error_log('request-policy: trusted-origin-invalid setting='.$setting
                .'; expected an explicit scheme://host[:port] without credentials, path, query or fragment');
        }
        return false;
    }

	private static function matchesTrustedOrigin($source, $allowed)
	{
		if(!is_string($allowed))
            return self::invalidTrustedOrigin();
        if(!is_string($source))
            return false;
		$parts = @parse_url($allowed);
		if(!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) ||
			isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) ||
			isset($parts['fragment']) || (!empty($parts['path']) && $parts['path'] !== '/') || strpos($parts['host'], '*') !== false)
			return self::invalidTrustedOrigin();
		$scheme = strtolower($parts['scheme']);
		if(in_array($scheme, array('http', 'https'), true))
			return UrlHost::sameOrigin($source, $allowed);
		// Browser extensions are opt-in exact origins, never wildcard schemes.
		if(!in_array($scheme, array('moz-extension', 'chrome-extension'), true) || isset($parts['port']))
			return self::invalidTrustedOrigin();
		$actual = @parse_url($source);
		return is_array($actual) && isset($actual['scheme']) && !isset($actual['port']) &&
			strtolower($actual['scheme']) === $scheme && UrlHost::of($source) === UrlHost::of($allowed);
	}

	private static function isAllowedOrigin($source, $legacyHostException = false)
	{
		$origin = self::currentOrigin();
		if($origin !== null && UrlHost::sameOrigin($source, $origin))
			return true;
		global $trustedOrigins;
		// The bootstrap guard precedes util.php, including for old persisted configs.
		$environmentOrigins = getenv('RU_TRUSTED_ORIGINS');
		$allowedOrigins = $environmentOrigins === false ? (array) ($trustedOrigins ?? array()) :
			preg_split('/\s*,\s*/', $environmentOrigins, -1, PREG_SPLIT_NO_EMPTY);
		foreach($allowedOrigins as $allowed)
			if(self::matchesTrustedOrigin($source, $allowed))
				return true;
		if($legacyHostException)
		{
			// Retain the older documented host-only POST exception separately.
			global $enabledOrigins;
			$scheme = strtolower((string) @parse_url($source, PHP_URL_SCHEME));
			$host = UrlHost::of($source);
			if(in_array($scheme, array('http', 'https'), true)
				&& UrlHost::isExactlyOneOf($host, (array) ($enabledOrigins ?? array())))
				return true;
		}
		return false;
	}

	public static function isSameOriginAjaxRequest()
	{
		if(!isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
			$_SERVER['HTTP_X_REQUESTED_WITH'] !== 'XMLHttpRequest' ||
			(isset($_SERVER['HTTP_SEC_FETCH_SITE']) &&
				$_SERVER['HTTP_SEC_FETCH_SITE'] !== 'same-origin'))
			return false;
        $hasSource = false;
        foreach(array('HTTP_ORIGIN', 'HTTP_REFERER') as $source) {
            if(isset($_SERVER[$source])) {
                $hasSource = true;
                if(!self::isAllowedOrigin($_SERVER[$source])) return false;
            }
        }
        // An explicit source can use the same configured grant as POST, even
        // when a proxy supplied an ambiguous scheme list. Headerless AJAX still
        // requires a well-formed request origin.
        return $hasSource || self::currentOrigin() !== null;
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
			header('Content-Type: text/plain; charset=UTF-8');
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
				if(self::isAllowedOrigin($source, true))
					return;
			}
			// Raw XMLRPC clients at either server-owned door may omit browser headers.
			// Browser actions, including opaque origins, never use this exception.
			$rpc2Script = realpath(dirname(__DIR__, 2).'/rpc2.php');
			$httprpcScript = realpath(dirname(__DIR__, 2).'/plugins/httprpc/action.php');
			if($source === null && !isset($_SERVER['HTTP_SEC_FETCH_SITE']) &&
				(($httprpcScript !== false && isset($_SERVER['SCRIPT_FILENAME']) &&
					realpath($_SERVER['SCRIPT_FILENAME']) === $httprpcScript) ||
					($rpc2Script !== false &&
						(($_SERVER['RUTORRENT_XMLRPC_ENDPOINT'] ?? null) === 'on') &&
						isset($_SERVER['SCRIPT_FILENAME']) &&
						realpath($_SERVER['SCRIPT_FILENAME']) === $rpc2Script)))
				return;
			error_log('csrf: refused POST: untrusted or missing origin');
			header('HTTP/1.0 403 Forbidden', true, 403);
			header('Content-Type: text/plain; charset=UTF-8');
			die('Forbidden');
		}
	}
}
