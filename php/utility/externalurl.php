<?php

// Check addresses from a feed or stored look-at template
// before storing them. js/common.js applies the browser's URL parser again
// in isExternalURL() before window.open(); that is the final decision. This
// PHP check covers known invalid authorities, not every WHATWG/IDNA rule.
//
// Callers: plugins/rss/rss.php, for a feed item's link and permalink in the
// rss branch and the atom branch, and plugins/lookat/lookat.php, for a
// stored template. Both of those reach openExternalURL() in the browser.
//
// tests/fixtures/openable-addresses.json records, for one address per row,
// what isExternalURL() answers and what this answers. Both suites assert
// their own column for the covered addresses.

class ExternalURL
{
	// Reject stored addresses with a disallowed scheme or known invalid
	// authority. isExternalURL() in js/common.js also parses the address before
	// opening it; checking the scheme alone would keep inert feed links.
	//
	// What is accepted: an http, https, ftp, ftps or magnet address, or a
	// scheme-relative one, which takes the scheme of the page. What is not: a
	// scheme outside that list, and a reference that names no scheme of its
	// own. isExternalURL() opens that second kind, because window.open()
	// resolves it against the page it is on -- but a stored address must not be
	// able to put something in front of a user that navigates back into the
	// panel it came from, so the set here is deliberately the smaller one.
	static public function isOpenable( $url )
	{
		if(!is_string($url))
			return(false);
		// The parser removes tab, LF and CR from anywhere in the address and
		// trims leading and trailing C0 controls and spaces before it reads
		// the scheme, so "ht\ttps://host/x" names http as surely as
		// " javascript:" names javascript.
		$url = str_replace(array("\t","\n","\r"),'',trim($url,"\x00..\x20"));
		preg_match('~^(?:(https?|ftps?|magnet):)?(//)?~i',$url,$m);
		$scheme = isset($m[1]) ? strtolower($m[1]) : '';
		$hasAuthority = isset($m[2]) && ($m[2]==='//');
		if(($scheme==='') && !$hasAuthority)
			// A reference the browser would resolve against the panel's own
			// page. isExternalURL() opens one, because window.open() does; a
			// feed must not be able to put one in front of the user.
			return(false);
		if($scheme==='magnet')
			// An opaque body, with no authority to make a host of.
			return(true);
		$rest = substr($url,strlen($m[0]));
		// ftps is not one of the schemes the parser calls special, so it takes
		// an opaque body the way magnet does. http, https and ftp are, and the
		// parser refuses one of those without a host.
		if(!$hasAuthority)
			return(($scheme==='ftps') || ($rest!==''));
		return(self::hasOpenableHost($rest,$scheme!=='ftps'));
	}

	// Check a stored URL's authority. Special-scheme hosts must exist and
	// their decoded form must pass the checks below. FTPS has an opaque host:
	// it may be empty and may keep malformed percent escapes. This check also
	// validates bracketed IPv6 literals and numeric ports.
	static protected function hasOpenableHost( $rest, $special )
	{
		$authority = preg_split('~[/?#]~',$rest,2)[0];
		$credentials = false;
		if(($at = strrpos($authority,'@'))!==false)
		{
			$credentials = true;
			$authority = substr($authority,$at+1);
		}
		if(substr($authority,0,1)==='[')
		{
			if(($close = strpos($authority,']'))===false)
				return(false);
			if(filter_var(substr($authority,1,$close-1),FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)===false)
				return(false);
			$port = substr($authority,$close+1);
		}
		else
		{
			$host = $authority;
			$port = '';
			if(($colon = strrpos($host,':'))!==false)
			{
				$port = substr($host,$colon);
				$host = substr($host,0,$colon);
			}
			if($host==='')
				// A special scheme always needs a host. A scheme that does not
				// may leave it out, but not while naming a user or a port.
				return(!$special && !$credentials && ($port===''));
			// Special-scheme hosts decode escapes and require valid UTF-8;
			// opaque ftps hosts keep escapes and may encode a raw DEL.
			$host = $special ? rawurldecode($host) : $host;
			if(preg_match('~[\x00-\x20#/:<>?@\[\\\\\]^|]~',$host)===1 ||
				($special && (strpos($host,'%')!==false || strpos($host,"\x7F")!==false ||
					preg_match('//u',$host)!==1 ||
					// These valid UTF-8 code points are invalid browser domains.
					preg_match('~[\x{0080}-\x{009F}\x{00A0}\x{00A8}\x{00AF}\x{00B4}\x{00B8}]~u',$host)===1)))
				return(false);
		}
		if($port==='')
			return(true);
		if(preg_match('~^:([0-9]*)$~',$port,$p)!==1)
			return(false);
		return(($p[1]==='') || ((int)$p[1]<=65535));
	}
}
