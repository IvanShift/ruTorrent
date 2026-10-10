<?php

// Shared with settings admission: these are the captured loader and native
// command boundaries, not a general-purpose URL syntax validator.
final class RetrackersTrackerURL
{
	public static function normalize($url)
	{
		return(trim($url, " \t\r\n\v\f"));
	}

	public static function type($url)
	{
		return(strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0 ? '1' :
			(strpos($url, 'udp://') === 0 ? '2' : false));
	}

	public static function representable($value)
	{
		return(is_string($value) && ($value === '' || $value[0] !== '$') &&
			strpos($value, "\0") === false && strpos($value, "\r") === false);
	}

	public static function additionFailure($url)
	{
		if (!is_string($url)) return('invalid-tracker-type');
		if (self::type(self::normalize($url)) === false) return('unsupported-tracker-scheme');
		if (self::normalize($url) !== $url) return('surrounding-tracker-whitespace');
		if (!self::representable($url)) return('invalid-tracker-characters');
		return(null);
	}
}
