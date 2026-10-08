<?php

/**
 * rTorrent::isMetadataStubName(): the one rule for "this is a BEP-9 magnet
 * stub of <hash>", used by rTorrent::getSource(), erasedata's metadata-only
 * cleanup and the retrackers worker. The three sites used to disagree about
 * case: the core and erasedata folded it, retrackers did not.
 */

define('TESTLIB_HANDLER_STUBS', true);
require_once(__DIR__ . '/../plugins/rutracker_check/TestLib.php');

eval(loadClassDefinition(
	__DIR__ . '/../../php/rtorrent.php',
	'rTorrent'
));

class MetadataStubNameTest
{
	const INFO_HASH = '2E1B01E70020BA7C1BC960ADC9724B19C5D80D18';

	private function rawInfo()
	{
		return 'd6:lengthi1e4:name8:seed.bin12:piece lengthi16384e6:pieces20:'
			. str_repeat("\0", 20) . 'e';
	}

	public function testTheNamesTheDaemonsWriteAreStubs()
	{
		// d.name, the session copy's info name and the stub file's basename,
		// captured on rTorrent 0.16.25 and 0.9.8 for magnets given in lowercase
		// hex, uppercase hex and base32 (L3 lab, 2026-10-08).
		foreach(array('F3DBF29FD8F3B026E968FF05E344B79E5543157A', '85390EC6D9151D6953DD206D144A92F0287D2853',
			'925C8B5702AED392FF4ED5A2EE24411691B0F97D') as $hash)
		{
			strictAssertSame(true, rTorrent::isMetadataStubName($hash . '.meta', $hash),
				'the captured stub name of ' . $hash);
			strictAssertSame(true, rTorrent::isMetadataStubName($hash . '.meta', strtolower($hash)),
				'a caller that spells the hash in lowercase still names the same stub');
		}
	}

	public function testOnlyTheDaemonSpellingIsAStub()
	{
		$hash = self::INFO_HASH;
		// The first two used to be stubs for getSource() and erasedata
		// (strcasecmp) but not for retrackers (===).
		foreach(array(strtolower($hash) . '.meta' => 'a lowercase name', $hash . '.META' => 'an uppercase suffix',
			substr($hash, 0, 20) . strtolower(substr($hash, 20)) . '.meta' => 'a mixed-case name',
			'x' . $hash . '.meta' => 'a prefix', $hash . '.meta.torrent' => 'a suffix', $hash => 'no suffix',
			str_repeat('A', 40) . '.meta' => 'another hash') as $name => $what)
			strictAssertSame(false, rTorrent::isMetadataStubName($name, $hash), $what . ' is not this stub');
		foreach(array(array(null, $hash), array(array(), $hash), array($hash . '.meta', null),
			array(substr($hash, 1) . '.meta', substr($hash, 1)), array('G' . substr($hash, 1) . '.meta', 'G' . substr($hash, 1)),
			array($hash . "\n.meta", $hash . "\n")) as $case)
			strictAssertSame(false, rTorrent::isMetadataStubName($case[0], $case[1]),
				'a malformed name or hash is never a stub: ' . json_encode($case));
	}

	public function testGetSourceReadsRawInfoOnlyUnderTheDaemonSpelling()
	{
		$dir = sys_get_temp_dir() . '/rt-stub-name-' . bin2hex(random_bytes(5)) . '/';
		if(!mkdir($dir, 0700, true))
			throw new RuntimeException('Unable to create the stub name test directory');
		try
		{
			$raw = $this->rawInfo();
			file_put_contents($dir . self::INFO_HASH . '.meta', $raw);
			rXMLRPCRequest::queue(array('get_session', 'd.get_tied_to_file'), true, false,
				array($dir, $dir . self::INFO_HASH . '.meta'));
			$torrent = rTorrent::getSource(strtolower(self::INFO_HASH));
			strictAssertTrue($torrent instanceof Torrent && $torrent->hash_info() === self::INFO_HASH,
				'the daemon-spelled stub file is read as the raw info dictionary for either hash spelling');
			$lower = $dir . strtolower(self::INFO_HASH) . '.meta';
			file_put_contents($lower, $raw);
			rXMLRPCRequest::queue(array('get_session', 'd.get_tied_to_file'), true, false,
				array($dir, $lower));
			// An ordinary file path keeps the ordinary reading (a torrent of the
			// file); only the daemon's stub may be wrapped as this hash's info.
			$other = rTorrent::getSource(self::INFO_HASH);
			strictAssertTrue(!($other instanceof Torrent) || $other->hash_info() !== self::INFO_HASH,
				'a lowercase .meta file is no daemon stub, so its bytes are not wrapped as the requested info dictionary');
		}
		finally
		{
			strictRemoveTree(rtrim($dir, '/'));
		}
	}
}

$suite = new StrictTestSuite();
$suite->addFromObject(new MetadataStubNameTest());
exit($suite->run());
