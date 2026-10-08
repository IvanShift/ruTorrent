<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/PluginEntryDriver.php');

/**
 * AUD-ESC-06/12/14 for unpack: the manual "Unpack" action reads the
 * archive's path, the download's base path, its label and its name from the
 * daemon, and they reach the unpack script in the daemon's own bytes.
 *
 * plugins/unpack/action.php runs against FakeRtorrentDaemon; the task it
 * builds is read back from start.sh (PluginEntryDriver) and never run. When
 * rXMLRPCRequest escaped every string, a base path with a quote or a
 * backslash was not a directory, and unzip_file.sh's mkdir -p created
 * `Show \"X\"` beside the data.
 */
class AudUnpackPathTest extends TestCase
{
	const HASH = '0123456789ABCDEF0123456789ABCDEF01234567';
	const AUD = 'aud "q" \b \'a\' &c';
	// The probe name plus the pairs an extra unescape would collapse.
	const AUD_PAIRS = 'aud "q" \b \'a\' &c \\\\x \\"y';

	private $base;
	private $directory;
	private $daemon;

	public function setUp()
	{
		$this->base = sys_get_temp_dir() . '/rutorrent-aud-unpack-' . getmypid();
		$this->directory = $this->base . '/data/' . self::AUD_PAIRS;
		mkdir($this->directory, 0700, true);
		file_put_contents($this->directory . '/inner.zip', 'not really a zip');
	}

	public function tearDown()
	{
		$this->stopDaemon();
		PluginEntryDriver::removeTree($this->base);
	}

	private function stopDaemon()
	{
		if ($this->daemon !== null) {
			$this->daemon->stop();
			$this->daemon = null;
		}
	}

	private function unpack($mode, $no, array $replies)
	{
		$this->stopDaemon();
		PluginEntryDriver::removeTree($this->base . '/profile');
		$this->daemon = new FakeRtorrentDaemon(new FakeRtorrentMethodReplies($replies),
			$this->base . '/calls.log');
		// Label and name subdirectories on, as a user can set them.
		$before = 'require_once(' . var_export(realpath(__DIR__ . '/../../../plugins/unpack/unpack.php'), true) . ');'
			. ' $up = new rUnpack(); $up->addLabel = 1; $up->addName = 1; $up->store();';
		list($exit, $out, $err) = PluginEntryDriver::run($this->base, $this->daemon->port(),
			'plugins/unpack/action.php', array('before' => $before,
			'post' => array('cmd' => 'unpack', 'hash' => self::HASH, 'dir' => '', 'mode' => $mode, 'no' => $no)));
		// rTask reports its refused launch on stderr; nothing else may be there.
		$this->assertSame(0, preg_match('/(Warning|Notice|Deprecated|Fatal error):/', $err),
			'the unpack action emits no PHP diagnostics: ' . $err);
		return array(PluginEntryDriver::taskScripts($this->base), $out);
	}

	private static function frozen(array $answers)
	{
		$calls = new FakeRtorrentPerCall(array_map(function ($path) {
			return array($path);
		}, $answers));
		return array('f.get_frozen_path' => $calls, 'f.frozen_path' => $calls);
	}

	public function testAudArchiveFromAClosedDownloadIsUnpackedBesideItself()
	{
		$archive = $this->directory . '/inner.zip';
		list($scripts, $out) = $this->unpack('zip', '3', self::frozen(array('', $archive)));
		$this->assertSame(1, count($scripts), 'one unpack task is built: ' . $out);
		$expected = escapeshellarg('unzip') . ' ' . escapeshellarg($archive) . ' '
			. escapeshellarg($this->directory . '/');
		$this->assertTrue(isset($scripts[0]) && strpos($scripts[0], 'unzip_file.sh') !== false
			&& strpos($scripts[0], $expected) !== false,
			'the archive the daemon named is unpacked into its own directory: ' . ($scripts[0] ?? ''));
	}

	public function testAudArchiveWithoutAPathStartsNoTask()
	{
		list($scripts, $out) = $this->unpack('zip', '3', self::frozen(array('', '')));
		$this->assertSame(array(), $scripts,
			'a download that names no file is not unpacked into the current directory: ' . $out);
	}

	public function testAudDownloadIsUnpackedIntoItsLabelAndNameDirectories()
	{
		list($scripts, $out) = $this->unpack('', '0', array(
			'd.get_base_path' => array($this->directory), 'd.base_path' => array($this->directory),
			// A label another client wrote without percent-encoding.
			'd.get_custom1' => array(self::AUD), 'd.custom1' => array(self::AUD),
			'd.get_name' => array(self::AUD_PAIRS), 'd.name' => array(self::AUD_PAIRS),
			'f.multicall' => array('inner.zip'),
		));
		$this->assertSame(1, count($scripts), 'one unpack task is built: ' . $out);
		$expected = escapeshellarg($this->directory . '/') . ' '
			. escapeshellarg($this->directory . '/' . self::AUD . '/' . self::AUD_PAIRS . '/');
		$this->assertTrue(isset($scripts[0]) && strpos($scripts[0], 'unzip_dir.sh') !== false
			&& strpos($scripts[0], $expected) !== false,
			'the base path is a directory and the label and name are the daemon\'s own: ' . ($scripts[0] ?? ''));
	}
}
