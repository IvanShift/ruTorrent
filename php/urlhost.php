<?php

/**
 * One answer to "is this URL's host one of ours?", for every plugin that has
 * to decide an identity from a URL.
 *
 * Three places used to answer it, each in its own words: loginmgr chose the
 * account whose cookies go out with a request (upstream #3205, #3206),
 * rutracker_check decided which announce rows are RuTracker's own and which
 * hosts may certify a topic alive, and a Kinozal registration built the same
 * anchored test out of a host list by hand. Every one of them exists because
 * a substring test over the URL string had been trusted before it -- and a
 * substring test accepts "https://evil.test/x/tracker.example/dl.php", whose
 * host is the attacker's, "https://tracker.example@evil.test/", where the
 * tracker's name is the userinfo, and "tracker.example.evil.test", where it is
 * one label of a longer domain. Two of those bit for real: cookies handed to
 * the wrong host (loginmgr, 2026-08), and a tracker's verdict written for
 * another tracker's topic (rutracker_check, 2026-09-14).
 *
 * So the host is taken out of the URL by parse_url(), never searched for in
 * it, and compared whole: equal to a listed host, or a subdomain of one --
 * "bt.t-ru.org" is "t-ru.org"'s, "nott-ru.org" is not. Two spellings of the
 * same host are folded first, because whoever writes the URL picks them: case
 * (DNS names have none) and a trailing dot (the DNS root; "bt.t-ru.org." is
 * the host "bt.t-ru.org", and an anchored test that forgot the dot once sent
 * RuTracker's own row down the foreign path).
 *
 * A leaf on purpose: it requires nothing and touches no state, so a plugin
 * can take it without taking another plugin.
 */
class UrlHost
{
	/**
	 * A host name folded to the one spelling the tests below compare: lower
	 * case, without the root dot. Anything that is not a string folds to ''.
	 */
	static public function normalize($host)
	{
		if(!is_string($host))
			return '';
		return strtolower(rtrim($host, '.'));
	}

	/**
	 * The host of a URL, normalized, or null when the URL has none that
	 * parse_url() can find -- which is the answer for a URL that only
	 * mentions a host somewhere in its path or query. A scheme-relative
	 * reference (//host/path) has a host; callers needing HTTPS must also
	 * check the scheme. IDN spellings are compared as supplied, without
	 * Unicode/punycode conversion.
	 */
	static public function of($url)
	{
		$host = @parse_url((string) $url, PHP_URL_HOST);
		if(!is_string($host) || $host === '')
			return null;
		$host = self::normalize($host);
		return $host === '' ? null : $host;
	}

	/** Allow only the default-port HTTP to HTTPS upgrade on the same host. */
	static public function isHttpsUpgrade($source, $target)
	{
		$a = @parse_url((string) $source);
		$b = @parse_url((string) $target);
		if(!is_array($a) || !is_array($b) || !isset($a['scheme'], $b['scheme']))
			return false;
		$host = self::of($source);
		return strtolower($a['scheme']) === 'http' && strtolower($b['scheme']) === 'https'
			&& $host !== null && $host === self::of($target)
			&& (!isset($a['port']) || $a['port'] === 80)
			&& (!isset($b['port']) || $b['port'] === 443);
	}

	/** Compare normalized HTTP(S) scheme, host and effective port. */
	static public function sameOrigin($left, $right)
	{
		$a = @parse_url((string) $left);
		$b = @parse_url((string) $right);
		if(!is_array($a) || !is_array($b) || !isset($a['scheme'], $b['scheme']))
			return false;
		$scheme = strtolower($a['scheme']);
		if(!in_array($scheme, array('http', 'https'), true) || $scheme !== strtolower($b['scheme']))
			return false;
		$host = self::of($left);
		$port = $scheme === 'https' ? 443 : 80;
		return $host !== null && $host === self::of($right)
			&& (isset($a['port']) ? $a['port'] : $port) === (isset($b['port']) ? $b['port'] : $port);
	}

	/**
	 * True when $host is one of $hosts or a subdomain of one. Both sides are
	 * normalized; an empty host or an empty candidate matches nothing.
	 */
	static public function isOneOf($host, $hosts)
	{
		$host = self::normalize($host);
		if($host === '')
			return false;
		foreach((array) $hosts as $candidate)
		{
			$candidate = self::normalize($candidate);
			if(($host === $candidate) || (substr($host, -strlen($candidate) - 1) === '.' . $candidate))
				return true;
		}
		return false;
	}

	/**
	 * True when $url's host is one of $hosts (or a subdomain of one), its
	 * scheme is $scheme when one is named, and its path starts with
	 * $pathPrefix when one is named. Port is not part of this host predicate;
	 * use sameOrigin() when it matters. Loginmgr account test() methods that
	 * call this select accounts and bound Snoopy's cross-origin session trust.
	 *
	 * The scheme may not be weakened: an https site matched over http would
	 * have the cookies put on the wire in clear by the very first request,
	 * before any redirect could upgrade it, and a feed link is not written
	 * by the user. The other direction is allowed -- an https URL to a site
	 * still configured http simply fails to connect, and costs nothing.
	 */
	static public function urlIsOneOf($url, $hosts, $scheme = null, $pathPrefix = null)
	{
		$parts = @parse_url((string) $url);
		if(!is_array($parts) || empty($parts['host']))
			return false;
		if($scheme !== null)
		{
			$urlScheme = strtolower(isset($parts['scheme']) ? $parts['scheme'] : '');
			if(($urlScheme !== strtolower($scheme)) && ($urlScheme !== 'https'))
				return false;
		}
		if($pathPrefix !== null)
		{
			$path = isset($parts['path']) ? $parts['path'] : '/';
			if(strncasecmp($path, $pathPrefix, strlen($pathPrefix)) !== 0)
				return false;
		}
		return self::isOneOf($parts['host'], $hosts);
	}
}
