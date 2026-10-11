<?php

require_once(__DIR__ . '/urlhost.php');

// Shared by the optional loginmgr and rutracker_check plugins. Keep this leaf
// independent of either plugin's configuration and client classes.
class KinozalResponse
{
	const SITE_HOSTS = array('kinozal.tv', 'kinozal.me', 'kinozal.guru');

	// Scan every tag so guest-looking text inside another tag's quoted
	// attributes is not read as a live element. Attribute text is anchored
	// after the name: alternatives begin with disjoint characters, so possessive
	// quantifiers consume the same text, a run of unquoted text in one step
	// instead of one frame per byte. That pattern exhausted the JIT stack at
	// ~8 KB of unquoted attribute text in one tag, and the recursion limit
	// without JIT at ~50 KB, and its failure made the whole page unverified.
	const OPENER_PATTERN = '~<!--|<!?/?([a-z][a-z0-9:-]*)(?=\s|/?>)~i';
	// Quotes start a value only after '='. The captured form's name=upt"
	// has a stray quote inside a bare value, which browsers keep as data.
	const TAG_TEXT_PATTERN = '~\G(?:\s++|[^\s=/\'"<>]++(?:\s*+=\s*+(?:"[^"]*+"|\'[^\']*+\'|[^\s\'">][^\s>]*+))?|[=/\'"]++)*+~';
	// Like that pattern, an unterminated tag is retried from the next opener,
	// which may lie inside the text the failed attempt already scanned. Each
	// such scan is linear, but a page of unterminated openers would rescan
	// its tail once per opener: minutes for 4 MiB. The pattern's errors used
	// to end that early; this bound (tens of milliseconds) ends it now.
	const RESCAN_LIMIT = 67108864;
	// These HTML elements carry text, not nested a/input elements.
	const TEXT_ELEMENTS = array('script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes');
	// One attribute: a name at the start or after whitespace, then an optional
	// value. The lookbehind replaces a leading \s+ that rescanned a whitespace
	// run from each of its positions.
	const ATTRIBUTE_PATTERN = '~(?:^|(?<=\s))([^\s=/\'"<>`]++)(?:\s*+=\s*+(?:"([^"]*+)"|\'([^\']*+)\'|([^\s\'"=<>`]++)))?~';

	// One application-log line per cycle: a cycle asks once per topic.
	private static $failureLogged = false;
	private static $requestsBlocked = false;
	private static $cycleSkipLogged = false;
	private static $loginRefusalLogged = false;

	public static function resetCycle()
	{
		self::$requestsBlocked = self::$cycleSkipLogged = self::$failureLogged = self::$loginRefusalLogged = false;
	}

	public static function requestsBlocked()
	{
		return(self::$requestsBlocked);
	}

	// Pure response recognition also used through RuTrackerFetchError's public
	// compatibility entry point. Headers and markers are from anonymous captures;
	// a generic Cloudflare origin-error footer is not challenge evidence.
	public static function isChallenge($headers, $body)
	{
		foreach((array) $headers as $line)
			if(is_string($line) && preg_match('/^cf-mitigated:\s*challenge\b/i', trim($line)))
				return(true);
		if(!is_string($body) || $body === '')
			return(false);
		if(preg_match('~<title>\s*Just a moment(?:\.\.\.|…)?\s*</title>~i', $body) === 1)
			return(true);
		// Visit each slash-delimited segment once. Retrying a variable-length
		// path regex at every repeated prefix was quadratic and exhausted PCRE,
		// hiding a real marker later in the same response. A gap (including an
		// empty segment) breaks the path; arbitrary nonempty segments preserve it.
		$previousEnd = -1;
		$cdn = $prefix = $orchestrate = false;
		for($offset = 0;
			preg_match('~/([^/\s\"\'<>]++)(?=/)~', $body, $segment, PREG_OFFSET_CAPTURE, $offset) === 1; )
		{
			$start = $segment[0][1];
			if($start !== $previousEnd) $cdn = $prefix = $orchestrate = false;
			$value = strtolower($segment[1][0]);
			if($prefix && $orchestrate && $value === 'chl_page') return(true);
			$orchestrate = $prefix && $value === 'orchestrate';
			if($cdn && $value === 'challenge-platform') $prefix = true;
			$cdn = ($value === 'cdn-cgi');
			$offset = $previousEnd = $start + strlen($segment[0][0]);
		}
		return(false);
	}

	public static function observeChallenge($client)
	{
		$body = is_object($client) ? ($client->results ?? null) : null;
		// Interstitial body markers describe HTML. A bencoded dictionary may
		// contain the same strings as payload; its parser decides its validity.
		if(is_string($body) && strncmp($body, 'd', 1) === 0) $body = null;
		if(is_object($client) && self::isChallenge($client->headers ?? array(), $body))
		{
			self::$requestsBlocked = true;
			self::logCycleSkip('cloudflare-challenge', 'all');
		}
		return(self::$requestsBlocked);
	}

	public static function logCycleSkip($reason, $scope)
	{
		if(!self::$cycleSkipLogged && class_exists('FileUtil'))
		{
			self::$cycleSkipLogged = true;
			FileUtil::toLog('kinozal: Kinozal checks skipped for the rest of this cycle: scope='
				. $scope . ' reason=' . $reason
				. ($reason === 'cloudflare-challenge' ? '; logging in again cannot bypass the challenge' : '')
				. '; retried next cycle');
		}
	}

	public static function logLoginRefusal($reason)
	{
		if(!self::$loginRefusalLogged && class_exists('FileUtil'))
		{
			self::$loginRefusalLogged = true;
			FileUtil::toLog('kinozal: login refused (' . $reason . '); no credential POST was sent');
		}
	}

	/** True for a guest page, false for no marker, null when parsing failed. */
	public static function isGuestPage($body)
	{
		return(self::scanPage($body, false));
	}

	// The captured login door has a POST form naming username and password.
	// Registration links alone do not admit a credential request.
	public static function hasLoginForm($body)
	{
		return(self::scanPage($body, true));
	}

	private static function scanPage($body, $loginForm)
	{
		if(!is_string($body))
			return(null);
		// Both markers come from captured anonymous pages: the registration
		// anchor in get_srv_details.php and the password input in login.php.
		// A path in the details file listing is neither of those elements.
		$length = strlen($body);
		$rescanned = 0;
		$form = false;
		$username = $password = false;
		for($offset = 0;
			($found = preg_match(self::OPENER_PATTERN, $body, $opener, PREG_OFFSET_CAPTURE, $offset)) === 1; )
		{
			$start = $opener[0][1];
			$text = $start + strlen($opener[0][0]);
			if($opener[0][0] === '<!--')
			{
				$end = strpos($body, '-->', $text);
				if($end === false)
					return(self::parseFailed('unterminated-comment'));
				$offset = $end + 3;
				continue;
			}
			$name = strtolower($opener[1][0]);
			if(preg_match(self::TAG_TEXT_PATTERN, $body, $attributes, 0, $text) !== 1)
				return(self::parseFailed());
			$end = $text + strlen($attributes[0]);
			if(($end >= $length) || ($body[$end] !== '>'))
			{
				if(!in_array($name, array('a', 'input'), true))
					return(self::parseFailed('unterminated-tag'));
				// An unmatched quote also scans the tail that did not match.
				// Charge its full extent, not only the successful prefix.
				$rescanned += $length - $start;
				if($rescanned > self::RESCAN_LIMIT)
					return(self::parseFailed('rescan-limit'));
				$offset = $start + 1;
				continue;
			}
			$offset = $end + 1;
			if($body[$start + 1] === '/' || $body[$start + 1] === '!')
			{
				if($name === 'form') $form = $username = $password = false;
				continue;
			}
			if($loginForm && $name === 'form')
			{
				$method = self::firstAttribute($attributes[0], 'method');
				$action = self::firstAttribute($attributes[0], 'action');
				if($method === false || $action === false) return(self::parseFailed());
				$form = is_string($method) && strtolower($method) === 'post'
					&& self::isTrustedLink($action, array('/takelogin.php', 'takelogin.php'));
				$username = $password = false;
				continue;
			}
			if($name === 'plaintext')
				return(false);
			if(in_array($name, self::TEXT_ELEMENTS, true))
			{
				$closed = preg_match('~</' . $name . '(?=\s|/?>)~i', $body, $closing, PREG_OFFSET_CAPTURE, $offset);
				if($closed !== 1)
					return(self::parseFailed($closed === false ? null : 'unterminated-text-element'));
				$offset = $closing[0][1];
				continue;
			}
			if($name !== 'a' && $name !== 'input')
				continue;
			$anchor = ($name === 'a');
			$value = self::firstAttribute($attributes[0], $anchor ? 'href' : 'name');
			if($value === false)
				return(self::parseFailed());
			if($loginForm)
			{
				if(!$anchor && $form)
				{
					if($value === 'username') $username = true;
					if($value === 'password') $password = true;
					if($username && $password) return(true);
				}
			}
			elseif($anchor ? self::isTrustedLink($value, array('/signup.php', 'signup.php')) : ($value === 'password'))
				return(true);
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

	private static function isTrustedLink($href, $paths)
	{
		if(!is_string($href))
			return(false);
		$href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$parts = @parse_url($href);
		if(!is_array($parts) || !in_array($parts['path'] ?? '', $paths, true))
			return(false);
		// Relative links share the page's host. Absolute links must name a
		// Kinozal host; finding its name in userinfo or a query proves nothing.
		if(isset($parts['host']))
			return((!isset($parts['scheme']) || in_array(strtolower($parts['scheme']), array('http', 'https'), true))
				&& UrlHost::isOneOf(UrlHost::of($href), self::SITE_HOSTS));
		return(!isset($parts['scheme']));
	}
}
