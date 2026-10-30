<?php

require_once(__DIR__.'/TestCase.php');
require_once(__DIR__.'/GlobalSettingsFixture.php');
require_once(__DIR__.'/FakeRtorrentDaemon.php');
require_once(__DIR__.'/HttprpcListFixture.php');
require_once(__DIR__.'/TestTree.php');
require_once(__DIR__.'/../../php/rtorrent.php');
require_once(__DIR__.'/../../php/torrentfilenames.php');

/**
 * rTorrent::getSourcePaths(), TorrentFileNames::titles() and downloadName()
 * against answers captured on 2026-10-30 from two rtorrent 0.16.27 daemons
 * with libtorrent 47114f09: rakshasa master 9801dfed, which writes every
 * byte inside <string>, and rutorrent-native 83c9b8be, which writes a value
 * that is not XML text as <base64>. Each torrent's name and tied file, and
 * the session directory, hold a backslash, a quote, bytes that are not
 * UTF-8 or a C1 control. The fixtures name the probe directory /srv/probe;
 * each case moves it into its own directory.
 *
 * The fork's reader hands out the daemon's bytes and unescapeValue() returns
 * them unchanged; unescaping them again would lose the session directory
 * and every tied path holding \\ or \".
 */
class TorrentNamesCapturedRepliesTest extends TestCase
{
	const DAEMONS = array('rakshasa-9801dfed', 'native-83c9b8be');
	const SESSION = "S\\\"q\\\\b\xFF\x85\xC2\x85";
	const MIXED = '75F922C3E13806024B6227DC46F07D38A9E45AA6';
	// Spare replies: a request nobody expected is answered and recorded.
	const TRAP = array(array('', ''), array('', ''));

	private $previousSettings;
	private $previousGlobals;
	private $base;
	private $daemon;

	// The probe torrents by their info hash: key, then name.
	private static function cases()
	{
		return array(
			'182DE0D964DB82D5E2DADD41FA300EAEACF55EF5' => array('bs', "bs\\x"),
			'55D8A68DCC5725CE5CAB73C8DF99A736E38D71E3' => array('dbs', "dbs\\\\x"),
			'897041A0468A647A95FC7C22AC1DC1723EB0A52B' => array('q', "q\"x"),
			'EB43A906C2B1253C57D5AB06A7AE5772C68E010F' => array('bq', "bq\\\"x"),
			'E5DDABF41D3FF140821240335A79516832B37737' => array('cp1251', "\xCF\xF0\xE8\xE2\xE5\xF2"),
			'3F59A6B4908EB83B67858DCFEAD63990D8D9D2C4' => array('c1raw', "c1\x85\x9B"),
			'C80B4D503BA4FACB5562136D588F21905C3372F7' => array('c1utf8', "nel\xC2\x85"),
			self::MIXED => array('mixed', "m\xCF\\\\\"\x85"),
			'D633C81AE24D732292D080CB8FE8F2DD270C9A86' => array('amp', 'e&amp;<x>'),
			'CF04AA512F8778E3558F3D3C1FD43B15013D8614' => array('cr', "cr\rx"),
		);
	}

	private static function bencode($value)
	{
		if(is_int($value))
			return 'i'.$value.'e';
		if(is_string($value))
			return strlen($value).':'.$value;
		if(array_keys($value) === range(0, count($value) - 1))
			return 'l'.implode('', array_map(array(__CLASS__, 'bencode'), $value)).'e';
		ksort($value, SORT_STRING);
		$out = 'd';
		foreach($value as $key=>$item)
			$out .= self::bencode((string)$key).self::bencode($item);
		return $out.'e';
	}

	// The probe's metainfo: a name that is not UTF-8 has aliases, so a
	// label shows which raw bytes were matched.
	private static function info($key, $name)
	{
		$info = array('files'=>array(array('length'=>1, 'path'=>array($name.'.bin'))), 'name'=>$name,
			'piece length'=>16384, 'pieces'=>str_repeat("\x01", 20), 'private'=>1);
		if(!UTF::isValid($name))
		{
			$info['name.utf-8'] = 'alias-'.$key;
			$info['files'][0]['path.utf-8'] = array('alias-'.$key.'.bin');
		}
		return $info;
	}

	public function setUp()
	{
		$this->previousGlobals = GlobalSettingsFixture::scgi();
		$this->base = sys_get_temp_dir().'/rutorrent-names-captured-'.uniqid('', true);
		mkdir($this->base, 0700);
		chmod($this->base, 0700);
		$this->base = realpath($this->base);
		$settings = (new ReflectionClass('rTorrentSettings'))->newInstanceWithoutConstructor();
		$settings->iVersion = 0x101B;
		$settings->apiVersion = 11;
		$settings->aliases = array();
		$this->previousSettings = self::swapSettings($settings);
		$GLOBALS['scgi_host'] = '127.0.0.1';
		$GLOBALS['rpcTimeOut'] = 5;
		$GLOBALS['rpcTransferTimeOut'] = 5;
		unset($GLOBALS['rpcMaxResponseBytes']);
		$GLOBALS['rpcLogCalls'] = false;
		$GLOBALS['rpcLogFaults'] = false;
		$GLOBALS['log_file'] = $this->base.'/errors.log';
		$GLOBALS['tempDirectory'] = $this->base;
		$GLOBALS['tempDirectory_init_done'] = false;
	}

	public function tearDown()
	{
		try
		{
			self::swapSettings($this->previousSettings);
			if($this->daemon !== null)
				$this->daemon->stop();
			$this->daemon = null;
			TestTree::remove($this->base);
		}
		finally
		{
			$this->previousGlobals->restore();
		}
	}

	private static function swapSettings($settings)
	{
		return Closure::bind(function($settings) {
			$previous = rTorrentSettings::$theSettings;
			rTorrentSettings::$theSettings = $settings;
			return $previous;
		}, null, 'rTorrentSettings')->__invoke($settings);
	}

	// A captured answer with the probe directory moved to $root.
	private static function captured($kind, $daemon, $root)
	{
		$xml = file_get_contents(__DIR__.'/fixtures/names-'.$kind.'-rtorrent-0.16.27-'.$daemon.'.xml');
		$xml = preg_replace_callback('#<base64>([^<]*)</base64>#', function($match) use($root) {
			return '<base64>'.base64_encode(str_replace('/srv/probe', $root, base64_decode($match[1], true))).'</base64>';
		}, $xml);
		return HttprpcListFixture::raw(str_replace('/srv/probe', $root, $xml));
	}

	private function daemon($replies)
	{
		if($this->daemon !== null)
			$this->daemon->stop();
		$this->daemon = new FakeRtorrentDaemon(array_merge($replies, self::TRAP), $this->base.'/calls-'.uniqid().'.log');
		$GLOBALS['scgi_port'] = $this->daemon->port();
	}

	// The session copies the daemon wrote, under this case's directory.
	private function sessionCopies($root)
	{
		mkdir($root.'/'.self::SESSION, 0700, true);
		foreach(self::cases() as $hash=>list($key, $name))
		{
			$info = self::info($key, $name);
			$this->assertSame($hash, strtoupper(sha1(self::bencode($info))), $key.': the fixture rebuilds the probe torrent');
			file_put_contents($root.'/'.self::SESSION.'/'.$hash.'.torrent', self::bencode(array('info'=>$info)));
		}
	}

	public function testSourcePathsKeepEveryByteBothDaemonsWrote()
	{
		foreach(self::DAEMONS as $daemon)
		{
			$root = $this->base.'/'.$daemon;
			$this->daemon(array(self::captured('sourcepaths', $daemon, $root)));
			$paths = rTorrent::getSourcePaths();
			$hashes = array_keys($paths);
			sort($hashes);
			$expected = array_keys(self::cases());
			sort($expected);
			$this->assertSame($expected, $hashes, $daemon.': one entry for every torrent');
			foreach(self::cases() as $hash=>list($key, $name))
				$this->assertSame(array($root.'/'.self::SESSION.'/', $root.'/watch/'.$name.'.torrent'), $paths[$hash] ?? null,
					$daemon.' '.$key.': the session directory and the tied file are the daemon\'s bytes');
		}
	}

	public function testTitlesFindTheSessionCopyThroughTheCapturedPaths()
	{
		foreach(self::DAEMONS as $daemon)
		{
			$root = $this->base.'/'.$daemon;
			$this->sessionCopies($root);
			$this->daemon(array(self::captured('sourcepaths', $daemon, $root)));
			$names = array();
			foreach(self::cases() as $hash=>list($key, $name))
				$names[$hash] = $name;
			$expected = $names;
			foreach(array('cp1251', 'c1raw', 'mixed') as $legacy)
				foreach(self::cases() as $hash=>list($key, $name))
					if($key === $legacy)
						$expected[$hash] = 'alias-'.$key;
			$this->assertSame($expected, TorrentFileNames::titles($names),
				$daemon.': every legacy title is its alias and every other title stays as it is');
		}
	}

	public function testDownloadNameReadsTheCapturedLivePath()
	{
		foreach(self::DAEMONS as $daemon)
		{
			$root = $this->base.'/'.$daemon;
			$this->sessionCopies($root);
			$this->daemon(array(self::captured('fileinfo-mixed', $daemon, $root), self::captured('source-mixed', $daemon, $root)));
			$name = self::cases()[self::MIXED][1];
			$this->assertSame('alias-mixed.bin', TorrentFileNames::downloadName(self::MIXED, 0, $root.'/data/'.$name.'/'.$name.'.bin'),
				$daemon.': the live path with \\\\ and " matches the metainfo file, whose alias names the download');
		}
	}
}
