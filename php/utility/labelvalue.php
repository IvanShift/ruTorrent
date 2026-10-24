<?php

class LabelValue
{
	/** d.custom1 is percent encoded; never infer a charset from its bytes. */
	public static function fromEncoded($encoded)
	{
		try
		{
			$value = @rawurldecode($encoded);
			if(!UTF::isString($value)) throw new RuntimeException();
			$key = @rawurlencode($value);
			if(!UTF::isString($key) || @rawurldecode($key) !== $value)
				throw new RuntimeException();
		}
		catch(Throwable $error)
		{
			// A missing mandatory byte codec must not select a different label.
			throw new RuntimeException('Could not encode torrent label');
		}
		return UTF::isValid($value) ? $value
			: array('name' => UTF::display($value), 'labelKey' => $key);
	}
}
