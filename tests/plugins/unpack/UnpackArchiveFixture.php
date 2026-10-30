<?php

/**
 * Writes the archives the unpack tests feed to the real unzip and unrar.
 * An entry is array(kind, name, value): 'f' a file and its content, 'd' a
 * directory, 'l' a symbolic link and its target, 'h' a hard link and the
 * archived name it links to, 'w' a Windows symbolic link and 'j' a junction
 * and their targets (these three RAR only). Names are stored as given. A zip
 * entry may add its Unix mode as a fourth element.
 *
 * Nothing on the image creates RAR archives, so rar() writes the RAR 5.0
 * format itself: stored entries, Unix host, symbolic and hard links as file
 * system redirection records. The layout follows RARLAB's format
 * description and was checked against unrar 7.23, which lists and tests
 * these archives as valid.
 */
final class UnpackArchiveFixture
{
	const HASH = '0123456789ABCDEF0123456789ABCDEF01234567';

	/** Every path under $directory, sorted, links shown with their targets. */
	public static function tree($directory)
	{
		$found = array();
		$entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,
			FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
		foreach($entries as $path => $entry)
			$found[] = substr($path, strlen($directory) + 1) . (is_link($path) ? ' -> ' . readlink($path) : '');
		sort($found);
		return $found;
	}

	/**
	 * A user of this checkout whose conf/users/<user>/config.php sends the
	 * application log to $log; removeUser() takes it away again.
	 */
	public static function userWithLog($log)
	{
		$user = 'unpacktest' . bin2hex(random_bytes(6));
		$directory = dirname(__DIR__, 3) . '/conf/users/' . $user;
		if(!mkdir($directory, 0700, true) || file_put_contents($directory . '/config.php',
			'<?php $log_file = ' . var_export($log, true) . ";\n") === false)
			throw new RuntimeException('cannot create the user ' . $user);
		return $user;
	}

	public static function removeUser($user)
	{
		$directory = dirname(__DIR__, 3) . '/conf/users/' . $user;
		@unlink($directory . '/config.php');
		@rmdir($directory);
	}

	public static function zip($path, array $entries)
	{
		$zip = new ZipArchive();
		if($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true)
			throw new RuntimeException('cannot create ' . $path);
		foreach($entries as $entry)
		{
			list($kind, $name, $value) = $entry;
			if($kind === 'd')
			{
				$zip->addEmptyDir($name);
				$name .= '/';
			}
			else
				$zip->addFromString($name, $value);
			$mode = ($kind === 'l') ? 0120777 : ($entry[3] ?? null);
			if($mode !== null)
				$zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, $mode << 16);
		}
		if(!$zip->close())
			throw new RuntimeException('cannot write ' . $path);
	}

	public static function rar($path, array $entries)
	{
		$time = pack('V', 1577836800);
		$archive = "Rar!\x1a\x07\x01\x00" . self::block(1, self::vint(0));
		foreach($entries as list($kind, $name, $value))
		{
			$extra = '';
			$data = null;
			if($kind === 'f')
			{
				// Flags: modification time and data CRC32 present.
				$body = self::vint(6) . self::vint(strlen($value)) . self::vint(0100644) . $time . pack('V', crc32($value));
				$data = $value;
			}
			else if($kind === 'd')
				$body = self::vint(3) . self::vint(0) . self::vint(040755) . $time;
			else
			{
				$body = self::vint(2) . self::vint(0) . self::vint(($kind === 'l') ? 0120777 : 0100644) . $time;
				// Redirection record: a Unix symbolic link, a Windows one, a
				// junction or a hard link.
				$record = self::vint(5) . self::vint(array('l' => 1, 'w' => 2, 'j' => 3, 'h' => 4)[$kind]) . self::vint(0)
					. self::vint(strlen($value)) . $value;
				$extra = self::vint(strlen($record)) . $record;
			}
			// Stored, Unix host, then the name.
			$body .= self::vint(0) . self::vint(1) . self::vint(strlen($name)) . $name;
			$archive .= self::block(2, $body, $extra, $data);
		}
		if(file_put_contents($path, $archive . self::block(5, self::vint(0))) === false)
			throw new RuntimeException('cannot write ' . $path);
	}

	/**
	 * The RAR 2.9 format: stored entries, Unix host. A symbolic link is a
	 * file entry with the link mode whose data is its target; the format has
	 * no hard links. Checked against unrar 7.23 like rar().
	 */
	public static function rar29($path, array $entries)
	{
		$archive = "Rar!\x1a\x07\x00" . self::block29(0x73, 0, pack('vV', 0, 0));
		foreach($entries as list($kind, $name, $value))
		{
			$data = ($kind === 'd') ? '' : $value;
			$mode = array('f' => 0100644, 'd' => 040755, 'l' => 0120777)[$kind];
			// Packed and unpacked size, Unix host, CRC32, DOS time of
			// 2020-01-01, version 2.9, stored, name size, mode, name.
			$fields = pack('VVCVVCCvV', strlen($data), strlen($data), 3, crc32($data), 20513 << 16, 29, 0x30,
				strlen($name), $mode) . $name;
			// 0x8000: the data size field is present; 0xe0: a directory.
			$archive .= self::block29(0x74, 0x8000 | (($kind === 'd') ? 0xe0 : 0), $fields) . $data;
		}
		if(file_put_contents($path, $archive . self::block29(0x7b, 0x4000, '')) === false)
			throw new RuntimeException('cannot write ' . $path);
	}

	private static function block29($type, $flags, $fields)
	{
		$header = pack('Cvv', $type, $flags, 7 + strlen($fields)) . $fields;
		return pack('v', crc32($header) & 0xffff) . $header;
	}

	private static function vint($value)
	{
		$bytes = '';
		do
		{
			$byte = $value & 0x7f;
			$value >>= 7;
			$bytes .= chr($byte | ($value ? 0x80 : 0));
		}
		while($value);
		return $bytes;
	}

	private static function block($type, $body, $extra = '', $data = null)
	{
		$flags = (($extra !== '') ? 1 : 0) | (($data !== null) ? 2 : 0);
		$fields = self::vint($type) . self::vint($flags);
		if($extra !== '')
			$fields .= self::vint(strlen($extra));
		if($data !== null)
			$fields .= self::vint(strlen($data));
		$fields .= $body . $extra;
		$size = self::vint(strlen($fields));
		return pack('V', crc32($size . $fields)) . $size . $fields . (string)$data;
	}
}
