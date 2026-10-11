<?php

require_once(__DIR__ . '/urlhost.php');

// Shared by the optional loginmgr and rutracker_check plugins. Keep this leaf
// independent of either plugin's configuration and client classes.
class KinozalResponse
{
	const SITE_HOSTS = array('kinozal.tv', 'kinozal.me', 'kinozal.guru');

	// An <a> or <input> start tag opener, then its attribute text anchored
	// after the name. Together they match what one backtracking pattern
	// matched: the alternatives begin with disjoint characters, so possessive
	// quantifiers consume the same text, a run of unquoted text in one step
	// instead of one frame per byte. That pattern exhausted the JIT stack at
	// ~8 KB of unquoted attribute text in one tag, and the recursion limit
	// without JIT at ~50 KB, and its failure made the whole page unverified.
	const OPENER_PATTERN = '~<(a|input)(?=\s|/?>)~i';
	const TAG_TEXT_PATTERN = '~\G(?:[^\'">]++|"[^"]*+"|\'[^\']*+\')*+~';
	// Like that pattern, an unterminated tag is retried from the next opener,
	// which may lie inside the text the failed attempt already scanned. Each
	// such scan is linear, but a page of unterminated openers would rescan
	// its tail once per opener: minutes for 4 MiB. The pattern's errors used
	// to end that early; this bound (tens of milliseconds) ends it now.
	const RESCAN_LIMIT = 67108864;
	// One attribute: a name at the start or after whitespace, then an optional
	// value. The lookbehind replaces a leading \s+ that rescanned a whitespace
	// run from each of its positions.
	const ATTRIBUTE_PATTERN = '~(?:^|(?<=\s))([^\s=/\'"<>`]++)(?:\s*+=\s*+(?:"([^"]*+)"|\'([^\']*+)\'|([^\s\'"=<>`]++)))?~';

	// One application-log line per process: a cycle asks once per topic.
	private static $failureLogged = false;

	/** True for a guest page, false for no marker, null when parsing failed. */
	public static function isGuestPage($body)
	{
		if(!is_string($body))
			return(null);
		// Both markers come from captured anonymous pages: the registration
		// anchor in get_srv_details.php and the password input in login.php.
		// A path in the details file listing is neither of those elements.
		$length = strlen($body);
		$rescanned = 0;
		for($offset = 0;
			($found = preg_match(self::OPENER_PATTERN, $body, $opener, PREG_OFFSET_CAPTURE, $offset)) === 1; )
		{
			$start = $opener[0][1];
			$text = $start + strlen($opener[0][0]);
			if(preg_match(self::TAG_TEXT_PATTERN, $body, $attributes, 0, $text) !== 1)
				return(self::parseFailed());
			$end = $text + strlen($attributes[0]);
			if(($end >= $length) || ($body[$end] !== '>'))
			{
				$rescanned += $end - $start;
				if($rescanned > self::RESCAN_LIMIT)
					return(self::parseFailed('rescan-limit'));
				$offset = $start + 1;
				continue;
			}
			$anchor = (strtolower($opener[1][0]) === 'a');
			$value = self::firstAttribute($attributes[0], $anchor ? 'href' : 'name');
			if($value === false)
				return(self::parseFailed());
			if($anchor ? self::isRegistrationLink($value) : ($value === 'password'))
				return(true);
			$offset = $end + 1;
		}
		return($found === false ? self::parseFailed() : false);
	}

	// The first value of $wanted, as a browser reads a repeated attribute:
	// null when the tag has none, false when PCRE failed.
	private static function firstAttribute($text, $wanted)
	{
		for($offset = 0;
			($found = preg_match(self::ATTRIBUTE_PATTERN, $text, $match, PREG_OFFSET_CAPTURE, $offset)) === 1;
			$offset = $match[0][1] + strlen($match[0][0]))
		{
			if(strtolower($match[1][0]) !== $wanted)
				continue;
			foreach(array(2, 3, 4) as $group)
				if(isset($match[$group]) && ($match[$group][0] !== ''))
					return($match[$group][0]);
			return('');
		}
		return($found === false ? false : null);
	}

	// A failed parse proves neither a session nor a login wall. Callers treat
	// null as unverified; the line keeps that refusal visible at the shipped
	// log level and carries a classified reason, never page text.
	private static function parseFailed($reason = null)
	{
		if($reason === null)
		{
			$code = preg_last_error();
			$reasons = array(PREG_INTERNAL_ERROR => 'internal', PREG_BACKTRACK_LIMIT_ERROR => 'backtrack-limit',
				PREG_RECURSION_LIMIT_ERROR => 'recursion-limit', PREG_BAD_UTF8_ERROR => 'bad-utf8',
				PREG_BAD_UTF8_OFFSET_ERROR => 'bad-utf8-offset', PREG_JIT_STACKLIMIT_ERROR => 'jit-stack-limit');
			$reason = 'pcre-' . (isset($reasons[$code]) ? $reasons[$code] : (string) $code);
		}
		if(!self::$failureLogged && class_exists('FileUtil'))
		{
			self::$failureLogged = true;
			FileUtil::toLog('kinozal: guest-page check failed (' . $reason
				. '); the answer is treated as unverified, not as a live session or a guest page');
		}
		return(null);
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
