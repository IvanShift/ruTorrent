<?php

/**
 * XML-RPC replies written as rtorrent writes them: an integer as <i8>, text
 * as <string> with only &, < and > escaped. Text that is not XML -- bytes
 * that are not UTF-8, a C0 control other than TAB and LF -- is <base64>, as
 * rutorrent-native writes it; rakshasa's rtorrent writes those bytes raw
 * inside <string>, which $raw selects. Both shapes were captured on
 * 2026-10-30 (fixtures/names-*-rtorrent-0.16.27-*.xml).
 */
class HttprpcListFixture
{
	// The columns of an httprpc list row, hash first, that rtorrent sends as
	// numbers; name, label, base path, message and comment are text.
	const LIST_INTEGERS = array(1, 2, 3, 4, 6, 7, 8, 9, 10, 11, 12, 13, 14, 16, 17, 18, 19, 20,
		21, 22, 23, 24, 25, 27, 28, 29, 32, 33, 34, 35);
	// The f.multicall columns after the path: chunks done, chunks, bytes, priority.
	const FILE_INTEGERS = array(1, 2, 3, 4);

	public static function value($value, $raw = false)
	{
		if(is_int($value))
			return '<value><i8>'.$value.'</i8></value>';
		if(!is_array($value))
		{
			$text = (string)$value;
			if(!$raw && ((preg_match('//u', $text) !== 1) || (preg_match('/[\x00-\x08\x0B-\x1F]/', $text) === 1)))
				return '<value><base64>'.base64_encode($text).'</base64></value>';
			return '<value><string>'.strtr($text, array('&'=>'&amp;', '<'=>'&lt;', '>'=>'&gt;')).'</string></value>';
		}
		$struct = count($value) && array_keys($value) !== range(0, count($value)-1);
		$xml = $struct ? '<value><struct>' : '<value><array><data>';
		foreach($value as $key=>$item)
			$xml .= $struct ? '<member><name>'.$key.'</name>'.self::value($item, $raw).'</member>' : self::value($item, $raw);
		return $xml.($struct ? '</struct></value>' : '</data></array></value>');
	}

	public static function body($results, $raw = false)
	{
		return '<?xml version="1.0"?><methodResponse><params><param>'.self::value($results, $raw).'</param></params></methodResponse>';
	}

	// Rows whose listed columns hold digits get them as integers, as rtorrent sends them.
	public static function typed($rows, $integers)
	{
		foreach($rows as &$row)
			foreach($integers as $column)
				if(isset($row[$column]) && is_string($row[$column]) && (preg_match('/\A-?[0-9]{1,18}\z/', $row[$column]) === 1))
					$row[$column] = (int)$row[$column];
		unset($row);
		return $rows;
	}

	// A FakeRtorrentDaemon reply entry: this XML with the daemon's SCGI headers.
	public static function raw($xml)
	{
		return FakeRtorrentDaemon::raw("Status: 200 OK\r\nContent-Type: text/xml\r\nContent-Length: ".strlen($xml)."\r\n\r\n".$xml);
	}

	// The answer to httprpc's list request: one d.multicall, rows only.
	public static function reply($rows)
	{
		return new FakeRtorrentRawReply(self::body(self::typed($rows, self::LIST_INTEGERS)));
	}
}
