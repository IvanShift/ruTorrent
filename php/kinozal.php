<?php

require_once(__DIR__ . '/urlhost.php');

if(!defined('KINOZAL_REQUEST_PAUSE'))
	// Seconds between requests to Kinozal, from any caller and any process: a
	// courtesy to the mirrors' rate limit. Measured 2026-10-14 on
	// kinozal.jumpingcrab.com: 30 requests a second apart were all answered,
	// 30 back to back drew 429 from the sixth on, and the limiter then refused
	// everything from that IP, on both mirrors, for hours. Declared with
	// if(!defined()) so a test can shorten it; production uses this value.
	define('KINOZAL_REQUEST_PAUSE', 2);
if(!defined('KINOZAL_RATE_LIMIT_PAUSE'))
	// Seconds for which Kinozal requests are refused unsent (request-throttled)
	// after an HTTP 429, doubling while the limiter keeps refusing, up to a
	// day. One hourly cycle, so a ban is not prodded every hour; on 2026-10-14
	// the limiter refused two probes an hour apart, the ban still in force.
	define('KINOZAL_RATE_LIMIT_PAUSE', 3600);

// Shared by the optional loginmgr and rutracker_check plugins. Keep this leaf
// independent of either plugin's configuration files and client classes: its
// one setting, $kinozalHosts, is global so that both plugins read it alike.
class KinozalResponse
{
	// The site's own registered domains. kinozal.me and kinozal.guru answer a
	// Cloudflare challenge on every *.php path since 2026-09-09; kinozal.tv
	// refuses connections.
	const SITE_HOSTS = array('kinozal.tv', 'kinozal.me', 'kinozal.guru');
	// Official mirrors, announced in t.me/kinozaltv_official (posts 226 and
	// 234, 2026-07-01 and 2026-09-05). Measured 2026-10-14: plain nginx without
	// that challenge, one IP for both, and the same account, hashes and
	// metainfo. A mirror is a door, not an identity: torrents it serves still
	// name kinozal.me in their comment, so topic ownership and announce
	// authority stay on SITE_HOSTS. Listed whole because both live in shared
	// free-DNS zones, where a sibling label belongs to somebody else -- and
	// the label itself may change hands if Kinozal loses it.
	const MIRROR_HOSTS = array('kinozal.jumpingcrab.com', 'kinozal.cloudns.nz');
	// Left in a client's $error by the KinozalTV account when its credential
	// POST went out and brought no session, however it ended.
	const CREDENTIAL_POST_FAILED = 'credential-post-failed';

	private static $doors = null;

	// The doors to Kinozal, in order: $kinozalHosts when it is set (in
	// conf/config.php, which loginmgr and rutracker_check both load), else the
	// mirrors. It orders the update checker, names the automatic refresh's
	// login origin, and decides which mirrors receive the account session.
	// Each entry must be one of SITE_HOSTS or MIRROR_HOSTS exactly; a list
	// naming anything else is refused as a whole, visibly, rather than
	// silently trimmed into an order the administrator did not write.
	public static function doors()
	{
		global $kinozalHosts;
		if(self::$doors !== null)
			return(self::$doors);
		if(!isset($kinozalHosts))
			return(self::$doors = self::MIRROR_HOSTS);
		$known = array_merge(self::SITE_HOSTS, self::MIRROR_HOSTS);
		$doors = array();
		foreach(is_array($kinozalHosts) && $kinozalHosts ? $kinozalHosts : array(null) as $host)
		{
			$host = UrlHost::normalize($host);
			if(!UrlHost::isExactlyOneOf($host, $known))
			{
				if(class_exists('FileUtil'))
					FileUtil::toLog('kinozal: config: invalid $kinozalHosts; using ' . implode(', ', self::MIRROR_HOSTS)
						. '; allowed hosts: ' . implode(', ', $known));
				return(self::$doors = self::MIRROR_HOSTS);
			}
			if(!in_array($host, $doors, true))
				$doors[] = $host;
		}
		return(self::$doors = $doors);
	}

	// Every host that may receive the account's session: the site's own
	// domains, and the mirrors among the doors.
	public static function accountHosts()
	{
		return(array_values(array_unique(array_merge(self::SITE_HOSTS, self::doors()))));
	}

	// The login door for a request URL: the account host that URL addresses
	// (dl.kinozal.me logs in on kinozal.me), or null.
	public static function siteOrigin($url)
	{
		$site = self::site($url);
		return($site === null ? null : 'https://' . $site);
	}

	private static function site($url)
	{
		$host = UrlHost::of($url);
		if($host === null)
			return(null);
		foreach(self::accountHosts() as $candidate)
			if(UrlHost::isOneOf($host, array($candidate)))
				return($candidate);
		return(null);
	}

	// Where a refresh, which has no request of its own, logs in.
	public static function refreshOrigin()
	{
		return('https://' . self::doors()[0]);
	}

	// From the first call on, every request this process sends to a Kinozal
	// host keeps one pace and one 429 pause, shared with every other process
	// that called it: the update checker, a manual batch, and fetches through
	// the KinozalTV account. A process that never loads the account or the
	// checker -- an anonymous RSS fetch or add-by-URL -- is not paced.
	public static function throttle()
	{
		if(class_exists('Snoopy', false) && method_exists('Snoopy', 'throttleHosts'))
			Snoopy::throttleHosts('kinozal', array_merge(self::SITE_HOSTS, self::MIRROR_HOSTS),
				KINOZAL_REQUEST_PAUSE, KINOZAL_RATE_LIMIT_PAUSE, 86400);
	}

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
	private static $loginRefusalLogged = array();
	// Sites that answered a challenge, until the next checker cycle or for
	// the rest of any other process.
	private static $blockedSites = array();

	public static function resetCycle()
	{
		self::$failureLogged = false;
		self::$loginRefusalLogged = self::$blockedSites = array();
		self::$doors = null;
	}

	// Whether $url's site answered a challenge: logging in again cannot pass
	// one, so no request goes there for the rest of the cycle or process.
	public static function requestsBlocked($url)
	{
		$site = self::site($url);
		return(isset(self::$blockedSites[$site !== null ? $site : (string) UrlHost::of($url)]));
	}

	// Whether the client's answer is the shared rate limit: an HTTP 429, or
	// Snoopy's refusal during the pause it keeps after one. Without Snoopy
	// loaded, nothing was refused by its throttle.
	public static function isRateLimited($client)
	{
		return(is_object($client) && ((int) ($client->status ?? 0) === 429
			|| (class_exists('Snoopy', false) && ($client->error ?? null) === Snoopy::REQUEST_THROTTLED)));
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

	// Whether the client's answer is a challenge. Blocks the site that gave
	// it -- the URL that answered, else $url -- and leaves every other site,
	// a mirror without Cloudflare included, untouched.
	public static function observeChallenge($client, $url = null)
	{
		$body = is_object($client) ? ($client->results ?? null) : null;
		// Interstitial body markers describe HTML. A bencoded dictionary may
		// contain the same strings as payload; its parser decides its validity.
		if(is_string($body) && strncmp($body, 'd', 1) === 0) $body = null;
		if(!is_object($client) || !self::isChallenge($client->headers ?? array(), $body))
			return(false);
		$answered = isset($client->lastResponseURL) && (string) $client->lastResponseURL !== ''
			? $client->lastResponseURL : $url;
		$site = self::site($answered);
		$site = $site !== null ? $site : (string) UrlHost::of($answered);
		if(!isset(self::$blockedSites[$site]))
		{
			self::$blockedSites[$site] = true;
			if(class_exists('FileUtil'))
				FileUtil::toLog('kinozal: ' . ($site !== '' ? $site : 'a Kinozal site')
					. ' answered a Cloudflare challenge; it is not asked again until the next cycle;'
					. ' logging in again cannot bypass the challenge');
		}
		return(true);
	}

	// $post: the credential POST was sent and refused; otherwise the login
	// form could not be used and no credentials were sent.
	public static function logLoginRefusal($reason, $origin, $post = false)
	{
		$host = (string) UrlHost::of($origin);
		if(isset(self::$loginRefusalLogged[$host]) || !class_exists('FileUtil'))
			return;
		self::$loginRefusalLogged[$host] = true;
		FileUtil::toLog('kinozal: login on ' . ($host !== '' ? $host : 'an unlisted site') . ' refused ('
			. $reason . ')' . ($post ? '' : '; no credential POST was sent'));
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
				&& UrlHost::isOneOf(UrlHost::of($href), self::accountHosts()));
		return(!isset($parts['scheme']));
	}
}
