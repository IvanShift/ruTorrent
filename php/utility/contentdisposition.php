<?php

/**
 * The Content-Disposition value that offers a download under a given name.
 *
 * The name is often a daemon string -- a torrent's or a file's name -- and,
 * since rXMLRPCRequest hands those out in the daemon's own bytes, it can carry
 * a '"' or a '\' (AUD-ESC). Pasted between quotes, either one ends or bends the
 * quoted-string, and the browser saves the file under a truncated name.
 *
 * So the value carries the name twice (RFC 6266 section 4.3):
 *  - filename*=UTF-8''<percent-encoded bytes> (RFC 8187, formerly 5987), which
 *    every current browser prefers, holding the name exactly;
 *  - filename="<fallback>" for an agent that ignores it: printable ASCII,
 *    every other byte replaced with '_', and '"' and '\' as quoted-pairs.
 * A name that is not UTF-8 has no honest filename*, so it gets the fallback
 * only. An old MSIE that decoded a percent-encoded filename= keeps getting
 * one, as before. A leaf: it needs nothing else from the application.
 */
class ContentDisposition
{
	public static function attachment( $name, $userAgent = null )
	{
		$name = (string)$name;
		if(is_string($userAgent) && (strpos($userAgent,'MSIE')!==false))
			$fallback = rawurlencode($name);
		else
			$fallback = str_replace(array('\\', '"'), array('\\\\', '\\"'),
				preg_replace('/[^\x20-\x7E]/', '_', $name));
		$value = 'attachment; filename="'.$fallback.'"';
		if(preg_match('//u', $name) === 1)
			$value .= "; filename*=UTF-8''".rawurlencode($name);
		return($value);
	}
}
