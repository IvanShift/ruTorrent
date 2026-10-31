<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/PluginEntryDriver.php');

/**
 * unpack extracts into a directory the archive tool writes through any
 * symbolic link along it, so the directory it is given has to stay inside
 * $topDirectory once its links are followed, not only as written. That is
 * the directory in the plugin's settings with the label and name added, or
 * the download's own directory when the setting is empty.
 *
 * The tree is real: a top directory with a zip in it, a directory outside
 * it, a link out and a link that stays inside. The automatic unpack runs
 * through update.php, the manual one through action.php, with unpack's
 * debug log off as shipped. What is read is whether a task was started:
 * PluginEntryDriver keeps its start.sh and never runs it.
 */
class UnpackTopDirectoryTest extends TestCase
{
	const HASH = '0123456789ABCDEF0123456789ABCDEF01234567';
	const REFUSED = ' unpack refused hash=0123456789ABCDEF0123456789ABCDEF01234567 reason=output-outside-top-directory';

	private $base;
	private $top;
	private $daemon;

	public function setUp()
	{
		$this->base = sys_get_temp_dir() . '/rutorrent-unpack-top-' . uniqid('', true);
		@mkdir($this->base . '/top/plain', 0777, true);
		@mkdir($this->base . '/outside/held', 0777, true);
		$this->base = realpath($this->base);
		$this->top = $this->base . '/top';
		file_put_contents($this->top . '/plain/a.zip', 'zip');
		file_put_contents($this->base . '/outside/held/b.zip', 'zip');
		symlink($this->base . '/outside', $this->top . '/out');
		symlink($this->top . '/plain', $this->top . '/in');
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

	/**
	 * Runs $script with unpack's settings stored as $settings, rtorrent
	 * answering $replies and $request as its argv or POST. Returns whether a
	 * task was started, what was printed and what the application logged.
	 */
	private function drive($script, array $settings, array $replies, array $request)
	{
		$this->stopDaemon();
		PluginEntryDriver::removeTree($this->base . '/profile');
		@unlink($this->base . '/application.log');
		$this->daemon = new FakeRtorrentDaemon(new FakeRtorrentMethodReplies($replies),
			$this->base . '/calls.log');
		$before = 'require_once(' . var_export(realpath(__DIR__ . '/../../../plugins/unpack/unpack.php'), true) . ');'
			. ' $topDirectory = ' . var_export($this->top . '/', true) . ';'
			. ' $up = new rUnpack();';
		foreach ($settings as $name => $value)
			$before .= ' $up->' . $name . ' = ' . var_export($value, true) . ';';
		$before .= ' $up->store();';
		$options = array('before' => $before);
		if ($script === 'plugins/unpack/update.php')
			$options['before'] .= ' $argv = ' . var_export($request, true) . '; $argc = count($argv);';
		else
			$options['post'] = $request;
		list($exit, $out, $err) = PluginEntryDriver::run($this->base, $this->daemon->port(), $script, $options);
		$this->assertSame(0, preg_match('/(Warning|Notice|Deprecated|Fatal error):/', $err),
			'unpack emits no PHP diagnostics: ' . $err);
		$log = $this->base . '/application.log';
		return array(
			'started' => count(PluginEntryDriver::taskScripts($this->base)) > 0,
			'scripts' => implode("\n", PluginEntryDriver::taskScripts($this->base)),
			'ret' => json_decode($out, true),
			'printed' => $out,
			'log' => is_file($log) ? file_get_contents($log) : '',
		);
	}

	/** The automatic unpack of the one-file download $archive, as rtorrent's finish hook starts it. */
	private function silent($path, $archive, array $settings = array())
	{
		return $this->drive('plugins/unpack/update.php', array('enabled' => 1, 'path' => $path) + $settings,
			array(), array('update.php', dirname($archive), basename($archive), '0', '', 'a', self::HASH, ''));
	}

	/** The manual unpack of the download's one archive, which rtorrent reports at $frozen. */
	private function manual($dir, $frozen)
	{
		return $this->drive('plugins/unpack/action.php', array(),
			array('f.get_frozen_path' => array($frozen), 'f.frozen_path' => array($frozen)),
			array('cmd' => 'unpack', 'hash' => self::HASH, 'dir' => $dir, 'mode' => 'zip', 'no' => '0'));
	}

	/**
	 * A manual unpack of the whole download: rtorrent reports its base path,
	 * label and name, then its files.
	 */
	private function whole($dir, $basePath, $label = '', array $settings = array())
	{
		return $this->drive('plugins/unpack/action.php', $settings, array(
			'd.get_base_path' => array($basePath), 'd.base_path' => array($basePath),
			'd.get_custom1' => array($label), 'd.custom1' => array($label),
			'd.get_name' => array('a'), 'd.name' => array('a'),
			'f.multicall' => array('a.zip'),
		), array('cmd' => 'unpack', 'hash' => self::HASH, 'dir' => $dir, 'mode' => '', 'no' => '0'));
	}

	public function testAutoUnpackThroughALinkOutIsNotStarted()
	{
		$r = $this->silent($this->top . '/out', $this->top . '/plain/a.zip');
		$this->assertTrue(!$r['started'],
			'an output directory that resolves outside the top directory is not unpacked into: ' . $r['log']);
	}

	public function testAutoUnpackIntoTheDownloadsOwnDirectoryThroughALinkOutIsNotStarted()
	{
		$r = $this->silent('', $this->top . '/out/held/b.zip');
		$this->assertTrue(!$r['started'],
			'nor is a download\'s own directory reached through a link out: ' . $r['log']);
	}

	public function testAutoUnpackThroughALinkThatStaysInsideIsStarted()
	{
		$r = $this->silent($this->top . '/in', $this->top . '/plain/a.zip');
		$this->assertTrue($r['started'],
			'a link that resolves inside the top directory is unpacked into: ' . $r['log']);
	}

	public function testAutoUnpackIntoTheDownloadsOwnDirectoryIsStarted()
	{
		$r = $this->silent('', $this->top . '/plain/a.zip');
		$this->assertTrue($r['started'],
			'with no output directory set, the download\'s own directory is used: ' . $r['log']);
	}

	public function testManualUnpackThroughALinkOutIsRefused()
	{
		$r = $this->manual($this->top . '/out', $this->top . '/plain/a.zip');
		$this->assertTrue(!$r['started'],
			'a manual unpack into a link out is not started: ' . $r['printed']);
	}

	public function testManualUnpackIntoTheDownloadsOwnDirectoryThroughALinkOutIsRefused()
	{
		$r = $this->manual('', $this->top . '/out/held/b.zip');
		$this->assertTrue(!$r['started'],
			'nor one into a download\'s own directory reached through a link out: ' . $r['log']);
	}

	public function testManualUnpackOfAWholeDownloadThroughALinkOutIsRefused()
	{
		$r = $this->whole($this->top . '/out', $this->top . '/plain/a.zip');
		$this->assertTrue(!$r['started'],
			'a whole download unpacked into a link out is not started: ' . $r['log']);
	}

	public function testManualUnpackOfAWholeDownloadInsideIsStarted()
	{
		$r = $this->whole($this->top . '/plain/new', $this->top . '/plain/a.zip');
		$this->assertTrue($r['started'],
			'a whole download unpacked inside the top directory is started: ' . $r['printed']);
	}

	public function testManualUnpackInsideIsStarted()
	{
		$r = $this->manual($this->top . '/plain/new', $this->top . '/plain/a.zip');
		$this->assertTrue($r['started'],
			'a manual unpack inside the top directory is started: ' . $r['printed']);
	}

	/**
	 * An automatic unpack has no one to answer, so its refusal goes to the
	 * application log with unpack's debug log off: the download's hash and a
	 * classified reason, not the paths.
	 */
	public function testAutoUnpackRefusalIsLogged()
	{
		$r = $this->silent($this->top . '/out', $this->top . '/plain/a.zip');
		$this->assertSame(1, substr_count($r['log'], 'unpack: automatic' . self::REFUSED),
			'the refusal is logged once with the hash and its reason: ' . $r['log']);
		$this->assertTrue(strpos($r['log'], $this->base) === false, 'without the paths: ' . $r['log']);
	}

	/** A refused manual unpack answers with the plugin's own directory error and is logged too. */
	public function testManualUnpackRefusalsAnswerInvalidDirectory()
	{
		$answers = array(
			'a file' => $this->manual($this->top . '/out', $this->top . '/plain/a.zip'),
			'a whole download' => $this->whole($this->top . '/out', $this->top . '/plain/a.zip'),
		);
		foreach ($answers as $what => $r) {
			$this->assertSame(array('no' => -1, 'pid' => 0, 'status' => 255, 'log' => array(),
				'errors' => array('Invalid directory.')), $r['ret'],
				'the manual unpack of ' . $what . ' answers with the directory error: ' . $r['printed']);
			$this->assertSame(1, substr_count($r['log'], 'unpack: manual' . self::REFUSED),
				'and logs the refusal: ' . $r['log']);
		}
	}

	/**
	 * A label is free text, and with "Torrent Label" appended it becomes part
	 * of the output directory. A label that climbs out through a directory
	 * the unpack has not made yet resolves outside the top directory once
	 * the archive tool's mkdir -p makes it, so it is refused in the automatic
	 * and the manual unpack of a whole download alike.
	 */
	public function testALabelThatClimbsOutOfTheTopDirectoryIsRefused()
	{
		$label = 'zz/../../../outside';
		$auto = $this->drive('plugins/unpack/update.php', array('enabled' => 1, 'addLabel' => 1), array(),
			array('update.php', $this->top . '/plain', 'plain', '1', $label, 'a', self::HASH, ''));
		$this->assertTrue(!$auto['started'],
			'an automatic unpack into a label that climbs out is not started: ' . $auto['log']);
		$manual = $this->whole('', $this->top . '/plain', $label, array('addLabel' => 1));
		$this->assertTrue(!$manual['started'],
			'nor is a manual unpack of the whole download: ' . $manual['printed']);
		$this->assertSame(array('Invalid directory.'), $manual['ret']['errors'] ?? null,
			'which answers with the plugin\'s directory error: ' . $manual['printed']);
	}

	/** A new label is made under the directory: the refusal above must not reach it. */
	public function testAutoUnpackIntoANewLabelIsStarted()
	{
		$r = $this->drive('plugins/unpack/update.php', array('enabled' => 1, 'addLabel' => 1), array(),
			array('update.php', $this->top . '/plain', 'a.zip', '0', 'Фильмы/2026', 'a', self::HASH, ''));
		$this->assertTrue($r['started'] && (strpos($r['scripts'], '/plain/Фильмы/2026/') !== false),
			'an archive is unpacked into its label, made under the directory: ' . $r['log'] . $r['scripts']);
	}

	public function testManualUnpackOfAWholeDownloadIntoANewLabelIsStarted()
	{
		$r = $this->whole('', $this->top . '/plain/a.zip', rawurlencode('Фильмы/2026'), array('addLabel' => 1));
		$this->assertTrue($r['started'] && (strpos($r['scripts'], '/plain/Фильмы/2026/') !== false),
			'a whole download is unpacked into its label, made under the directory: ' . $r['printed'] . $r['scripts']);
	}
}
