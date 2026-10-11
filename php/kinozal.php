<?php

require_once(__DIR__ . '/urlhost.php');

// Shared by the optional loginmgr and rutracker_check plugins. Keep this leaf
// independent of either plugin's configuration and client classes.
class KinozalResponse
{
	const SITE_HOSTS = array('kinozal.tv', 'kinozal.me', 'kinozal.guru');

	/** True for a guest page, false for no marker, null when parsing failed. */
	public static function isGuestPage($body)
	{
		if(!is_string($body))
			return(null);
		// Both markers come from captured anonymous pages: the registration
		// anchor in get_srv_details.php and the password input in login.php.
		// A path in the details file listing is neither of those elements.
		if(preg_match_all('~<(a|input)(?=\s|/?>)((?:[^\'">]|"[^"]*"|\'[^\']*\')*)>~is',
			$body, $tags, PREG_SET_ORDER) === false)
			return(null);
		foreach($tags as $tag)
		{
			if(preg_match_all('~(?:^|\s+)([^\s=/\'"<>`]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s\'"=<>`]+)))?~s',
				$tag[2], $matches, PREG_SET_ORDER) === false)
				return(null);
			$attributes = array();
			foreach($matches as $match)
			{
				$name = strtolower($match[1]);
				if(!array_key_exists($name, $attributes))
					$attributes[$name] = ($match[2] ?? '') !== '' ? $match[2]
						: (($match[3] ?? '') !== '' ? $match[3] : ($match[4] ?? ''));
			}
			if((strtolower($tag[1]) === 'a' && self::isRegistrationLink($attributes['href'] ?? null))
				|| (strtolower($tag[1]) === 'input' && ($attributes['name'] ?? null) === 'password'))
				return(true);
		}
		return(false);
	}

	private static function isRegistrationLink($href)
	{
		if(!is_string($href))
			return(false);
		$href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$parts = @parse_url($href);
		if(!is_array($parts) || !in_array($parts['path'] ?? '', array('/signup.php', 'signup.php'), true))
			return(false);
		// Relative links share the page's host. Absolute links must name a
		// Kinozal host; finding its name in userinfo or a query proves nothing.
		if(isset($parts['host']))
			return((!isset($parts['scheme']) || in_array(strtolower($parts['scheme']), array('http', 'https'), true))
				&& UrlHost::isOneOf(UrlHost::of($href), self::SITE_HOSTS));
		return(!isset($parts['scheme']));
	}
}
