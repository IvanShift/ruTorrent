<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/FakeRtorrentDaemon.php');
require_once(__DIR__ . '/../../php/RtorrentProbeFixture.php');
require_once(__DIR__ . '/../../php/PluginEntryDriver.php');

/**
 * plugins/httprpc/action.php, mode setsettings, sets rtorrent's default
 * directory from "sdirectory". A download added without a directory of its
 * own is written there, through any symbolic link along it, so the
 * directory has to stay inside $topDirectory once its links are followed,
 * not only as written.
 *
 * The tree is real: a top directory, a directory outside it, a link out, a
 * link that stays inside and a dangling link. What is read is whether
 * rtorrent was asked to set the directory.
 */
class HttprpcSetDirectoryTest extends TestCase
{
	private $base;
	private $top;
	private $daemon;
	private $printed;

	public function setUp()
	{
		$this->base = sys_get_temp_dir() . '/rutorrent-httprpc-setdir-' . uniqid('', true);
		@mkdir($this->base . '/profile/settings', 0700, true);
		@mkdir($this->base . '/top/plain', 0777, true);
		@mkdir($this->base . '/outside', 0777, true);
		$this->base = realpath($this->base);
		$this->top = $this->base . '/top';
		symlink($this->base . '/outside', $this->top . '/out');
		symlink($this->top . '/plain', $this->top . '/in');
		symlink($this->base . '/nowhere', $this->top . '/dangle');
	}

	public function tearDown()
	{
		if ($this->daemon !== null) {
			$this->daemon->stop();
			$this->daemon = null;
		}
		PluginEntryDriver::removeTree($this->base);
	}

	/** Sets the default directory through action.php; true when rtorrent was asked to. */
	private function setDirectory($directory)
	{
		if ($this->daemon !== null) {
			$this->daemon->stop();
		}
		RtorrentProbeFixture::warmSettingsCache($this->base . '/profile');
		// Serializable, so the official images without pcntl run it too.
		$this->daemon = new FakeRtorrentDaemon(new FakeRtorrentMethodReplies(array()), $this->base . '/calls.log');
		@unlink($this->base . '/application.log');
		// The driver seeds settings without aliases; drop them so the probed
		// ones in the profile's cache name the commands as rtorrent knows them.
		$before = '$p = new ReflectionProperty("rTorrentSettings", "theSettings");'
			. ' if(PHP_VERSION_ID < 80100) $p->setAccessible(true); $p->setValue(null, null);'
			. ' $topDirectory = ' . var_export($this->top . '/', true) . ';'
			. ' $HTTP_RAW_POST_DATA = ' . var_export('mode=setsettings&s=sdirectory&v='
				. rawurlencode($this->top . $directory), true) . ';';
		list($exit, $this->printed, $err) = PluginEntryDriver::run($this->base, $this->daemon->port(),
			'plugins/httprpc/action.php', array('before' => $before));
		$this->assertSame('', $err, 'action.php reports nothing on stderr');
		return $this->daemon->received('directory.default.set');
	}

	private function logged()
	{
		$log = $this->base . '/application.log';
		return is_file($log) ? file_get_contents($log) : '';
	}

	public function testALinkOutOfTheTopDirectoryIsNotSet()
	{
		$this->assertTrue(!$this->setDirectory('/out'),
			'a directory that resolves outside the top directory is not set: ' . json_encode($this->daemon->calls()));
	}

	public function testANewDirectoryUnderALinkOutIsNotSet()
	{
		$this->assertTrue(!$this->setDirectory('/out/new'),
			'nor one that does not exist yet under a link out: ' . json_encode($this->daemon->calls()));
	}

	public function testADanglingLinkIsNotSet()
	{
		$this->assertTrue(!$this->setDirectory('/dangle'),
			'nor a link that does not resolve: ' . json_encode($this->daemon->calls()));
	}

	public function testALinkThatStaysInsideIsSet()
	{
		$this->assertTrue($this->setDirectory('/in'),
			'a link that resolves inside the top directory is set: ' . json_encode($this->daemon->calls()));
	}

	public function testADirectoryInsideIsSet()
	{
		$this->assertTrue($this->setDirectory('/plain/new'),
			'a directory inside the top directory is set: ' . json_encode($this->daemon->calls()));
	}

	/**
	 * The answer to a refusal is the empty result it has always been, which
	 * gateways and plugins read; the application log says why, by a fixed
	 * category and without the path that was asked for.
	 */
	public function testARefusalIsLoggedByCategoryAndAnsweredAsBefore()
	{
		$this->assertTrue(!$this->setDirectory('/out/new'), 'the directory is not set');
		$this->assertSame('[]', $this->printed, 'the answer is still an empty result');
		$log = $this->logged();
		$this->assertSame(1, substr_count($log, 'setsettings: refused key=sdirectory reason=outside-top-directory'),
			'the refusal is logged once by its category: ' . $log);
		$this->assertTrue(strpos($log, $this->base) === false,
			'without the path that was asked for: ' . $log);
	}

	/** A directory refused as written was skipped silently before; it is logged by a category of its own. */
	public function testADirectoryRefusedAsWrittenIsLoggedToo()
	{
		$this->assertTrue(!$this->setDirectory('/magnet:?x'), 'the directory is not set');
		$this->assertSame(1, substr_count($this->logged(), 'setsettings: refused key=sdirectory reason=invalid-directory'),
			'and the refusal is logged by its own category: ' . $this->logged());
	}

	public function testAnAcceptedDirectoryLogsNoRefusal()
	{
		$this->assertTrue($this->setDirectory('/in'), 'the directory is set');
		$this->assertTrue(strpos($this->logged(), 'setsettings: refused') === false,
			'and no refusal is logged: ' . $this->logged());
	}
}
