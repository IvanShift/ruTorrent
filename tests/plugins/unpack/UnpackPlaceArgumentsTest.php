<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/PluginEntryDriver.php');
require_once(__DIR__ . '/UnpackArchiveFixture.php');

/**
 * Every unpack script hands what an extractor wrote to place.php, which
 * needs PHP, whether the unpack was automatic or manual, the download's hash
 * and the user to log a refusal. unpack.php passes them as arguments 7-10 to
 * each of its three kinds of task; the task is read back from start.sh and
 * never run (PluginEntryDriver).
 */
class UnpackPlaceArgumentsTest extends TestCase
{
	const HASH = UnpackArchiveFixture::HASH;
	const USER = 'unpackargs';

	private $base;
	private $daemon;

	public function setUp()
	{
		$this->base = sys_get_temp_dir() . '/rutorrent-unpack-place-args-' . getmypid();
		@mkdir($this->base . '/data/whole', 0700, true);
		$this->base = realpath($this->base);
		file_put_contents($this->base . '/data/a.zip', 'zip');
		file_put_contents($this->base . '/data/whole/b.zip', 'zip');
	}

	public function tearDown()
	{
		if ($this->daemon !== null)
			$this->daemon->stop();
		$this->daemon = null;
		PluginEntryDriver::removeTree($this->base);
	}

	/**
	 * The unpack command $entry builds, as a user. The driver's bootstrap
	 * settled the profile path and the login with no user; the login is
	 * asked again, and the task stays where taskScripts() reads it.
	 */
	private function command($entry, array $replies, array $options)
	{
		$this->daemon = new FakeRtorrentDaemon(new FakeRtorrentMethodReplies($replies), $this->base . '/calls.log');
		$options['before'] = '$_SERVER["REMOTE_USER"] = ' . var_export(self::USER, true) . ';'
			. ' $login = new ReflectionProperty("User", "userLoginInstance");'
			. ' if(PHP_VERSION_ID < 80100) $login->setAccessible(true); $login->setValue(null, null);'
			. ' file_put_contents(' . var_export($this->base . '/php', true) . ', Utility::getPHP());'
			. ' require_once(' . var_export(realpath(__DIR__ . '/../../../plugins/unpack/unpack.php'), true)
			. '); $up = new rUnpack(); $up->enabled = 1; $up->store();' . ($options['before'] ?? '');
		list($exit, $out, $err) = PluginEntryDriver::run($this->base, $this->daemon->port(), $entry, $options);
		$scripts = PluginEntryDriver::taskScripts($this->base);
		$this->assertSame(1, count($scripts), 'one unpack task is built: ' . $out . $err);
		// The unpack script and its arguments, each one quoted.
		preg_match("~^'[^\n]*/plugins/unpack/un[a-z_]+\\.sh' .*?(?= 2>> )~m", $scripts[0] ?? '', $command);
		preg_match_all("~'(?:[^']|'\\\\'')*'~", $command[0] ?? '', $arguments);
		return $arguments[0];
	}

	private function assertPlacementArguments(array $arguments, $how)
	{
		$this->assertSame(11, count($arguments), 'the script gets ten arguments: ' . implode(' ', $arguments));
		$this->assertSame(array(escapeshellarg(file_get_contents($this->base . '/php')), "'" . $how . "'",
			"'" . self::HASH . "'", "'" . self::USER . "'"), array_slice($arguments, 7),
			'PHP, "' . $how . '", the hash and the user end the command');
	}

	public function testAutomaticUnpack()
	{
		$this->assertPlacementArguments($this->command('plugins/unpack/update.php', array(), array('before' =>
			' $argv = ' . var_export(array('update.php', $this->base . '/data', 'a.zip', '0', '', 'a', self::HASH, '',
			self::USER), true) . '; $argc = count($argv);')), 'automatic');
	}

	public function testManualUnpackOfOneArchive()
	{
		$frozen = array($this->base . '/data/a.zip');
		$this->assertPlacementArguments($this->command('plugins/unpack/action.php',
			array('f.get_frozen_path' => $frozen, 'f.frozen_path' => $frozen),
			array('post' => array('cmd' => 'unpack', 'hash' => self::HASH, 'dir' => '', 'mode' => 'zip', 'no' => '0'))),
			'manual');
	}

	public function testManualUnpackOfTheWholeDownload()
	{
		$this->assertPlacementArguments($this->command('plugins/unpack/action.php', array(
			'd.get_base_path' => array($this->base . '/data/whole'), 'd.base_path' => array($this->base . '/data/whole'),
			'd.get_custom1' => array(''), 'd.custom1' => array(''),
			'd.get_name' => array('whole'), 'd.name' => array('whole'),
			'f.multicall' => array('b.zip'),
		), array('post' => array('cmd' => 'unpack', 'hash' => self::HASH, 'dir' => '', 'mode' => '', 'no' => '0'))),
			'manual');
	}
}
