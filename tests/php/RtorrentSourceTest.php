<?php

/**
 * Regression tests for rTorrent::getSource().
 *
 * rTorrent 0.16 writes magnet metadata to <hash>.meta as the raw bencoded
 * info dictionary. Treating that path as an ordinary source file creates a
 * torrent *of the .meta file* and reports a stable, unrelated info hash.
 */

define('TESTLIB_HANDLER_STUBS', true);
require_once(__DIR__ . '/../plugins/rutracker_check/TestLib.php');

eval(loadClassDefinition(
	__DIR__ . '/../../php/rtorrent.php',
	'rTorrent'
));

class RtorrentSourceTest
{
	const INFO_HASH = '2E1B01E70020BA7C1BC960ADC9724B19C5D80D18';

	private function rawInfo()
	{
		// Literal captured shape: this is the complete BEP-9 metadata payload,
		// not a full .torrent envelope. The expected SHA-1 above was derived
		// independently from these 82 bytes.
		return 'd6:lengthi1e4:name8:seed.bin12:piece lengthi16384e6:pieces20:'
			. str_repeat("\0", 20) . 'e';
	}

	private function withSourceDirectory($body)
	{
		$dir = sys_get_temp_dir() . '/rt-source-' . bin2hex(random_bytes(5));
		if(!mkdir($dir, 0777, true))
			throw new RuntimeException('Unable to create source test directory');
		try
		{
			$body($dir . '/');
		}
		finally
		{
			strictRemoveTree($dir);
		}
	}

	public function testRawMagnetMetadataIsReadAsTheInfoDictionaryItContains()
	{
		$this->withSourceDirectory(function($dir) {
			$path = $dir . self::INFO_HASH . '.meta';
			file_put_contents($path, $this->rawInfo());
			rXMLRPCRequest::queue(array('get_session', 'd.get_tied_to_file'), true, false,
				array($dir, $path));

			$torrent = rTorrent::getSource(self::INFO_HASH);

			strictAssertTrue($torrent instanceof Torrent,
				'raw magnet metadata produces a readable Torrent object');
			strictAssertSame(false, $torrent->errors(),
				'the raw info dictionary is wrapped without a parse error');
			strictAssertSame(self::INFO_HASH, $torrent->hash_info(),
				'the recovered source keeps the daemon torrent info hash');
			strictAssertSame('seed.bin', $torrent->name(),
				'the recovered source exposes the metadata name, not the .meta filename');
		});
	}

	public function testRawMetadataWhoseBytesDoNotMatchTheRequestedHashIsRefused()
	{
		$this->withSourceDirectory(function($dir) {
			$expected = str_repeat('A', 40);
			$path = $dir . $expected . '.meta';
			file_put_contents($path, $this->rawInfo());
			rXMLRPCRequest::queue(array('get_session', 'd.get_tied_to_file'), true, false,
				array($dir, $path));

			strictAssertSame(false, rTorrent::getSource($expected),
				'raw metadata is never accepted for a different torrent hash');
		});
	}

	public function testMalformedRawMetadataIsRefusedEvenWhenItsBytesMatchTheHash()
	{
		$this->withSourceDirectory(function($dir) {
			$raw = 'd4:name8:truncated';
			$hash = strtoupper(sha1($raw));
			$path = $dir . $hash . '.meta';
			file_put_contents($path, $raw);
			rXMLRPCRequest::queue(array('get_session', 'd.get_tied_to_file'), true, false,
				array($dir, $path));

			strictAssertSame(false, rTorrent::getSource($hash),
				'a matching SHA-1 cannot turn malformed bencode into metainfo');
		});
	}

	public function testOrdinarySessionTorrentStillUsesTheFullMetainfoFile()
	{
		$this->withSourceDirectory(function($dir) {
			$path = $dir . self::INFO_HASH . '.torrent';
			$raw = 'd8:announce31:http://tracker.invalid/announce4:info'
				. $this->rawInfo() . 'e';
			file_put_contents($path, $raw);
			rXMLRPCRequest::queue(array('get_session', 'd.get_tied_to_file'), true, false,
				array($dir, ''));

			$torrent = rTorrent::getSource(self::INFO_HASH);

			strictAssertTrue($torrent instanceof Torrent,
				'an ordinary session .torrent remains readable');
			strictAssertSame('http://tracker.invalid/announce', $torrent->announce(),
				'the full metainfo envelope is preserved for ordinary session files');
		});
	}
}

$suite = new StrictTestSuite();
$suite->addFromObject(new RtorrentSourceTest());
exit($suite->run());
