<?php

/**
 * Snoopy's failure sentence, reduced to one greppable token.
 *
 * A leaf on purpose: it requires nothing and touches no state, so both readers
 * of Snoopy's error field can share it. ruTrackerChecker::makeClient()
 * (check.php) and NNMClubCheckImpl::guestFetch() (trackers/nnmclub.php) each
 * carried their own copy of the same eight token/pattern pairs, and the copies
 * had already drifted: one collapsed internal whitespace before matching, the
 * other only trimmed, so the same re-spaced or wrapped sentence was
 * 'connect-errno' in one log line and 'unclassified' in the other.
 *
 * Neither owner could hold it. Inside ruTrackerChecker it would make the
 * handler load a checker to classify a string, which the handler's own suite
 * does not do -- it stubs that class -- and inside the NNMClub handler it
 * would make the checker's shared diagnostics depend on one tracker's file.
 * A file with no dependencies is what both can require instead.
 */
class RuTrackerFetchError
{
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
	 * @return string One of the eight tokens, 'unclassified', or '' when
	 *                Snoopy wrote no message
	 */
	static public function classify( $error )
	{
		if(!is_string($error)) return('');
		$error = trim(preg_replace('/\s+/', ' ', $error));
		if($error === '') return('');
		// php/Snoopy.class.inc's eight strings, in the order that file writes
		// them.
		$classes = array(
			'invalid-protocol' => '/^Invalid protocol\b/i',
			'refused-unresolvable-host' => '/^Refusing to fetch: cannot resolve host\b/i',
			'refused-non-public-address' => '/^Refusing to fetch: host .* non-public address\b/i',
			'curl-transfer' => '/cURL could not retrieve/i',
			'socket-create' => '/^socket creation failed\b/i',
			'dns-lookup' => '/^dns lookup failure\b/i',
			'connect-refused' => '/^connection refused or timed out\b/i',
			'connect-errno' => '/^connection failed\b/i',
		);
		foreach($classes as $token => $pattern)
			if(preg_match($pattern, $error)) return($token);
		return('unclassified');
	}
}
