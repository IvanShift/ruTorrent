<?php

class UTF
{
	/** Keep strict type checks usable when one optional predicate is disabled. */
	public static function isString($value)
	{
		try {
			$result = @is_string($value);
			if($result === true || $result === false) return $result;
		} catch(Throwable $error) {}
		try { return @gettype($value) === 'string'; }
		catch(Throwable $error) { return false; }
	}

	// Strict UTF-8, the same check as PCRE's u modifier and json_encode(), but
	// independent of PCRE and converters.
	public static function isValid($text)
	{
		if(!self::isString($text)) return false;
		for($offset = 0; isset($text[$offset]);)
		{
			$length = self::characterLength($text, $offset);
			if($length === 0) return false;
			$offset += $length;
		}
		return true;
	}

	private static function characterLength($text, $offset)
	{
		$first = $text[$offset];
		if($first <= "\x7F") return 1;
		if($first >= "\xC2" && $first <= "\xDF") $length = 2;
		else if($first >= "\xE0" && $first <= "\xEF") $length = 3;
		else if($first >= "\xF0" && $first <= "\xF4") $length = 4;
		else return 0;
		if(!isset($text[$offset + $length - 1])) return 0;
		$minimum = $first === "\xE0" ? "\xA0" : ($first === "\xF0" ? "\x90" : "\x80");
		$maximum = $first === "\xED" ? "\x9F" : ($first === "\xF4" ? "\x8F" : "\xBF");
		if($text[$offset + 1] < $minimum || $text[$offset + 1] > $maximum) return 0;
		// Not a loop: under PHP 8.1.34's tracing JIT a for loop here started at
		// $part = 0 and refused valid text.
		if($length > 2 && ($text[$offset + 2] < "\x80" || $text[$offset + 2] > "\xBF")) return 0;
		if($length > 3 && ($text[$offset + 3] < "\x80" || $text[$offset + 3] > "\xBF")) return 0;
		return $length;
	}

	// Text for display. Valid UTF-8 comes back unchanged. Otherwise each byte
	// that is not part of a character, and each control but tab, CR and LF,
	// becomes \xNN, and a backslash is doubled. So a valid name that spells
	// "\xFF" reads like a name holding the byte 0xFF: compare names by their
	// bytes, never by this label.
	public static function display($text)
	{
		$text = (string)$text;
		// Keep valid strings exactly as supplied, including literal backslashes.
		for($offset = 0; isset($text[$offset]);)
		{
			$length = self::characterLength($text, $offset);
			if($length === 0) break;
			$offset += $length;
		}
		if(!isset($text[$offset])) return $text;

		// A byte lookup avoids depending on optional formatting/conversion functions.
		static $escaped = null;
		if($escaped === null)
		{
			$escaped = array();
			$hex = '0123456789ABCDEF';
			$high = "\x00\x10\x20\x30\x40\x50\x60\x70\x80\x90\xA0\xB0\xC0\xD0\xE0\xF0";
			$low = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F";
			for($hi = 0; $hi < 16; $hi++)
				for($lo = 0; $lo < 16; $lo++)
					$escaped[$high[$hi] | $low[$lo]] = '\\x'.$hex[$hi].$hex[$lo];
		}
		$out = '';
		for($offset = 0; isset($text[$offset]);)
		{
			$byte = $text[$offset];
			$length = self::characterLength($text, $offset);
			if($length === 0 || ($byte < "\x20" && $byte !== "\t" && $byte !== "\r" && $byte !== "\n") || $byte === "\x7F")
			{
				$out .= $escaped[$byte];
				$offset++;
			}
			else if($byte === '\\')
			{
				$out .= '\\\\';
				$offset++;
			}
			else
				for($part = 0; $part < $length; $part++) $out .= $text[$offset++];
		}
		return $out;
	}

	// What utf8_encode() returns, which PHP 8.2 deprecates: each byte read
	// as ISO-8859-1.
	public static function latin1ToUtf8($str)
	{
		return(preg_replace_callback('`[\x80-\xFF]`', function($m)
		{
			$c = ord($m[0]);
			return(chr(0xC0 | ($c >> 6)).chr(0x80 | ($c & 0x3F)));
		}, $str));
	}

	// What utf8_decode() returns, which PHP 8.2 deprecates: a character
	// outside ISO-8859-1 becomes '?', and so does each malformed sequence,
	// found and delimited the way utf8_decode() does because
	// htmlspecialchars() uses the same decoder.
	public static function utf8ToLatin1($str)
	{
		$valid = htmlspecialchars_decode(
			htmlspecialchars($str, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_NOQUOTES);
		return(preg_replace_callback('`[^\x00-\x7F]`u', function($m)
		{
			$c = $m[0];
			return(((strlen($c) == 2) && (ord($c[0]) <= 0xC3)) ?
				chr(((ord($c[0]) & 0x1F) << 6) | (ord($c[1]) & 0x3F)) : '?');
		}, $valid));
	}

	public static function isInvalidUTF8($str)
	{
		return !self::isValid($str);
	}

	public static function win2utf($str)
	{
		$outstr='';
		$recode=array
		(
			0x0402,0x0403,0x201A,0x0453,0x201E,0x2026,0x2020,0x2021,
			0x20AC,0x2030,0x0409,0x2039,0x040A,0x040C,0x040B,0x040F,
			0x0452,0x2018,0x2019,0x201C,0x201D,0x2022,0x2013,0x2014,
			0x0000,0x2122,0x0459,0x203A,0x045A,0x045C,0x045B,0x045F,
			0x00A0,0x040E,0x045E,0x0408,0x00A4,0x0490,0x00A6,0x00A7,
			0x0401,0x00A9,0x0404,0x00AB,0x00AC,0x00AD,0x00AE,0x0407,
			0x00B0,0x00B1,0x0406,0x0456,0x0491,0x00B5,0x00B6,0x00B7,
			0x0451,0x2116,0x0454,0x00BB,0x0458,0x0405,0x0455,0x0457,
			0x0410,0x0411,0x0412,0x0413,0x0414,0x0415,0x0416,0x0417,
			0x0418,0x0419,0x041A,0x041B,0x041C,0x041D,0x041E,0x041F,
			0x0420,0x0421,0x0422,0x0423,0x0424,0x0425,0x0426,0x0427,
			0x0428,0x0429,0x042A,0x042B,0x042C,0x042D,0x042E,0x042F,
			0x0430,0x0431,0x0432,0x0433,0x0434,0x0435,0x0436,0x0437,
			0x0438,0x0439,0x043A,0x043B,0x043C,0x043D,0x043E,0x043F,
			0x0440,0x0441,0x0442,0x0443,0x0444,0x0445,0x0446,0x0447,
			0x0448,0x0449,0x044A,0x044B,0x044C,0x044D,0x044E,0x044F
		);
		$and=0x3F;
		for($i=0;$i<strlen($str);$i++)
		{
			$octet=array();
			if(ord($str[$i])<0x80)
				$strhex=ord($str[$i]);
			else
				$strhex=$recode[ord($str[$i])-128];
			if($strhex<0x0080)
				$octet[0]=0x0;
			elseif($strhex<0x0800)
			{
				$octet[0]=0xC0;
				$octet[1]=0x80;
			}
			elseif($strhex<0x10000)
			{
				$octet[0]=0xE0;
				$octet[1]=0x80;
				$octet[2]=0x80;
			}
			elseif($strhex<0x200000)
			{
				$octet[0]=0xF0;
				$octet[1]=0x80;
				$octet[2]=0x80;
				$octet[3]=0x80;
			}
			elseif ($strhex<0x4000000)
			{
				$octet[0]=0xF8;
				$octet[1]=0x80;
				$octet[2]=0x80;
				$octet[3]=0x80;
				$octet[4]=0x80;
			}
			else
			{
				$octet[0]=0xFC;
				$octet[1]=0x80;
				$octet[2]=0x80;
				$octet[3]=0x80;
				$octet[4]=0x80;
				$octet[5]=0x80;
				}
				for($j=(count($octet)-1);$j>=1;$j--)
			{
				$octet[$j]=$octet[$j] + ($strhex & $and);
				$strhex=$strhex>>6;
			}
			$octet[0]=$octet[0] + $strhex;
			for($j=0;$j<count($octet);$j++)
				$outstr.=chr($octet[$j]);
		}
		return($outstr);
	}

	private static function maybe_mb_convert_to_utf8($string) {
		return function_exists('mb_convert_encoding') ? mb_convert_encoding($string, "UTF-8", "auto") : $string;
	}

	public static function raw_url_encode($string){
		return rawurlencode(Self::maybe_mb_convert_to_utf8($string));
	}

	public static function raw_url_decode($string){
		return rawurldecode(Self::maybe_mb_convert_to_utf8($string));
	}
}
