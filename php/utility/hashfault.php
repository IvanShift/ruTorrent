<?php

/**
 * One answer to "does this RPC fault prove that rTorrent has no torrent with
 * the requested hash?" -- the proof that lets a caller discard a load receipt,
 * skip a torrent or delete what only that torrent owned.
 *
 * rTorrent words this answer two ways and sends it with three codes:
 * - "Could not find info-hash." with -501 (0.9.0 to 0.14, xmlrpc-c);
 * - from 0.15, "invalid parameters: info-hash not found". It is a
 *   torrent::input_error, which the TinyXML2 backend reports as
 *   XMLRPC_INTERNAL_ERROR -500 and the xmlrpc-c backend (configure
 *   --with-xmlrpc-c) as XMLRPC_PARSE_ERROR -503: the torrent::local_error
 *   catch in src/rpc/xmlrpc_tinyxml2.cc and src/rpc/xmlrpc_c.cc.
 * Captured on 0.9.8, on 0.16.25 with TinyXML2 and on upstream master
 * 728790a2 (0.16.25) built with xmlrpc-c (2026-10-08); the release tags carry
 * no third wording. Three copies used to decide this with different
 * strictness: the core load receipt (code and text), erasedata's presence
 * probe (five spellings in any case, no code) and the retrackers worker
 * (code, integer type and text per daemon family).
 *
 * The text is compared exactly, untrimmed and case-sensitive, and the code
 * whenever the caller has it. $legacy selects the generation when the caller
 * knows which daemon answered; isMissingFromBackend() selects the XML-RPC
 * backend when the caller knows which one serialized the answer. Anything
 * else -- a reworded or padded text, another fault, a transport failure --
 * proves nothing, and the caller must treat the torrent's presence as unknown.
 *
 * presence() is the one d.hash probe that applies this rule: addtorrent.php
 * confirms a duplicate with it, rTorrent::magnetShown() a magnet's stub for
 * addtorrent.php, bulk_magnet, extsearch and rss, and erasedata, datadir and
 * rutracker_check reach it through plugins/erasedata/presence.php.
 *
 * A leaf on purpose: it requires nothing, so a plugin and its test harness can
 * load it without the application bootstrap. The core autoloader finds it too.
 * presence() needs php/xmlrpc.php, which its callers load.
 */
class HashFault
{
	const LEGACY_CODE = '-501';
	const LEGACY_MESSAGE = 'Could not find info-hash.';
	const CURRENT_MESSAGE = 'invalid parameters: info-hash not found';
	// The 0.15+ text per backend: TinyXML2 -500, xmlrpc-c -503.
	const CURRENT_CODES = array('tinyxml2' => '-500', 'xmlrpc-c' => '-503');

	const PRESENT = 1;
	const ABSENT = 0;
	const UNKNOWN = -1;

	static private function code($code)
	{
		if(is_int($code))
			$code = (string)$code;
		return(($code === null || is_string($code)) ? $code : false);
	}

	static public function isMissing($code, $message, $legacy = null)
	{
		$code = self::code($code);
		if($code === false || !is_string($message))
			return(false);
		$old = $message === self::LEGACY_MESSAGE && ($code === null || $code === self::LEGACY_CODE);
		$new = $message === self::CURRENT_MESSAGE &&
			($code === null || in_array($code, self::CURRENT_CODES, true));
		if($legacy === null)
			return($old || $new);
		return($legacy ? $old : $new);
	}

	// xmlrpc-c serializes every generation's answer, TinyXML2 only 0.15+.
	static public function isMissingFromBackend($backend, $code, $message)
	{
		$code = self::code($code);
		if(!is_string($backend) || !isset(self::CURRENT_CODES[$backend]) || !is_string($code) || !is_string($message))
			return(false);
		return(($message === self::CURRENT_MESSAGE && $code === self::CURRENT_CODES[$backend]) ||
			($backend === 'xmlrpc-c' && $message === self::LEGACY_MESSAGE && $code === self::LEGACY_CODE));
	}

	/**
	 * Whether rTorrent holds a torrent with this hash now: one d.hash read.
	 * Only the missing-hash answer above is absence; a transport failure, any
	 * other fault or a malformed answer is unknown, and only a d.hash that
	 * names the same hash is presence.
	 */
	static public function presence($hash)
	{
		$probe = new rXMLRPCRequest( new rXMLRPCCommand( getCmd("d.hash"), $hash ) );
		$probe->important = false;
		if(!$probe->run())
			return(self::UNKNOWN);
		if($probe->fault)
		{
			// The raw, untrimmed text decides; the trimmed copy only stands in
			// for a transport that leaves the raw one unset.
			$msg = isset($probe->rawFaultString) && is_string($probe->rawFaultString)
				? $probe->rawFaultString
				: (isset($probe->faultString) && is_string($probe->faultString) ? $probe->faultString : '');
			$code = is_array($probe->val) && isset($probe->val[0]) ? $probe->val[0] : null;
			return(self::isMissing($code, $msg) ? self::ABSENT : self::UNKNOWN);
		}
		if(count($probe->val) !== 1 || !is_string($probe->val[0]))
			return(self::UNKNOWN);
		return(strcasecmp($probe->val[0], $hash) === 0 ? self::PRESENT : self::UNKNOWN);
	}
}
