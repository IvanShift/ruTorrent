<?php

/**
 * Stateless HTTP failure diagnostics shared by the checker, NNMClub and Kinozal.
 * classify() reduces Snoopy's error sentence to a greppable token;
 * isChallenge() identifies a Cloudflare interstitial for endpoint routing.
 * Both methods are leaf helpers: no dependencies, state, or third-party text returned.
 */
class RuTrackerFetchError
{
	/**
	 * Whether an answer is Cloudflare's managed challenge -- the interstitial
	 * no credential and no retry gets past.
	 *
	 * One live Kinozal capture (2026-09-12) carried the header and the
	 * orchestrate/chl_page script. Cloudflare documents `cf-mitigated: challenge`
	 * on every challenge response; the body markers have only that one capture
	 * as provenance. Snoopy keeps raw response header lines in $client->headers.
	 * The body fallback matches the interstitial's own
	 * markers alone: the interstitial's orchestrate/chl_page script or its title.
	 * The generic challenge-platform prefix also serves JavaScript Detections
	 * on ordinary pages and is not interstitial evidence.
	 *
	 * Deliberately NOT the word "cloudflare". Every error page Cloudflare
	 * serves for an origin that is down -- 52x, "Web server is down" -- names
	 * Cloudflare in its footer and carries none of the markers above. A
	 * handler that ROUTES on this answer must not read an outage as a wall, or
	 * a ten-minute origin restart sends a whole cycle through its expensive
	 * door. NNMClubCheckImpl::looksLikeChallengePage() keeps a broader test
	 * for HTTP-200 topic pages without a download link: pages carrying
	 * cf-chl, turnstile, captcha, cloudflare, just a moment, or
	 * challenge-platform are retryable; other unreadable pages are errors.
	 * Non-200 responses are rejected before that predicate runs.
	 */
	static public function isChallenge( $headers, $body )
	{
		foreach( (array) $headers as $line )
		{
			if( is_string( $line ) && preg_match( '/^cf-mitigated:\s*challenge\b/i', trim( $line ) ) )
				return true;
		}
		if( !is_string( $body ) || $body === '' )
			return false;
		return preg_match( '~<title>\s*Just a moment(?:\.\.\.|…)?\s*</title>|/cdn-cgi/challenge-platform/(?:[^/\s\"\'<>]+/)*orchestrate/chl_page/~i', $body ) === 1;
	}

	/**
	 * Classify one Snoopy error message.
	 *
	 * Classified rather than echoed, as AGENTS.md's diagnostics rule requires
	 * of a routine plugin log. A token greps, and it cannot carry a passkey
	 * whatever a later merge teaches a vendored file to put in its messages:
	 * php/Snoopy.class.inc is third-party code, and the probe URLs this plugin
	 * fetches spell the user's passkey in their query string. Anything not
	 * recognised becomes 'unclassified'; the text itself is never returned.
	 *
	 * Whitespace is normalised first. A leading space or an internal run makes
	 * an anchored pattern miss, and the message would then land on
	 * 'unclassified' for a reason that has nothing to do with what it says.
	 *
	 * A non-string is treated as no message at all. Snoopy initialises the
	 * field to "" and only ever assigns strings to it (php/Snoopy.class.inc),
	 * so no production path reaches this; it is the NNMClub copy's boundary
	 * rather than the checker copy's cast, because casting is what would turn
	 * an array into an "Array to string conversion" warning and an object
	 * without __toString into a fatal.
	 *
	 * @param mixed $error Snoopy's $error field, or anything at all
	 * @return string One of the fixed tokens, 'unclassified', or '' when
	 *                Snoopy wrote no message
	 */
	static public function classify( $error )
	{
		if(!is_string($error)) return('');
		$error = trim(preg_replace('/\s+/', ' ', $error));
		if($error === '') return('');
		// Classify Snoopy's own errors. A refused credential redirect
		// retains the redirect response status, so plugin consumers that log only
		// status < 100 do not emit redirect-refused; the class remains available
		// to callers that inspect the error field directly.
		$classes = array(
			'invalid-protocol' => '/^Invalid protocol\b/i',
			'refused-unresolvable-host' => '/^Refusing to fetch: cannot resolve host\b/i',
			'refused-non-public-address' => '/^Refusing to fetch: host .* non-public address\b/i',
			'redirect-refused' => '/^credential-redirect-refused$/i',
			'curl-transfer' => '/cURL could not retrieve/i',
			'socket-create' => '/^socket creation failed\b/i',
			'dns-lookup' => '/^dns lookup failure\b/i',
			'connect-refused' => '/^connection refused or timed out\b/i',
			'connect-errno' => '/^connection failed\b/i',
			'too-many-interim-responses' => '/^too-many-interim-responses$/i',
			'missing-final-response' => '/^missing-final-response$/i',
			'unsupported-transfer-encoding' => '/^unsupported-transfer-encoding$/i',
			'invalid-or-oversized-chunked' => '/^invalid-or-oversized-chunked$/i',
			'oversized-response' => '/^oversized-response$/i',
			'invalid-or-oversized-gzip' => '/^invalid-or-oversized-gzip$/i',
			'gzip-decoder-unavailable' => '/^gzip-decoder-unavailable$/i',
			'unreadable-or-oversized-response' => '/^unreadable-or-oversized-response$/i',
		);
		foreach($classes as $token => $pattern)
			if(preg_match($pattern, $error)) return($token);
		return('unclassified');
	}
}
