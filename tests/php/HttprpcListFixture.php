<?php

/** Structured system.multicall replies; keep the list separate from optional paths. */
class HttprpcListFixture
{
	public static function value($value)
	{
		if(!is_array($value))
		{
			$text = (string)$value;
			return preg_match('//u', $text) === 1
				? '<value><string>'.htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</string></value>'
				: '<value><base64>'.base64_encode($text).'</base64></value>';
		}
		$struct = count($value) && array_keys($value) !== range(0, count($value)-1);
		$xml = $struct ? '<value><struct>' : '<value><array><data>';
		foreach($value as $key=>$item)
			$xml .= $struct ? '<member><name>'.$key.'</name>'.self::value($item).'</member>' : self::value($item);
		return $xml.($struct ? '</struct></value>' : '</data></array></value>');
	}

	public static function body($results)
	{
		return '<?xml version="1.0"?><methodResponse><params><param>'.self::value($results).'</param></params></methodResponse>';
	}

	public static function reply($rows)
	{
		return new FakeRtorrentRawReply(self::body(array(array($rows), array(array()), array(''))));
	}
}
