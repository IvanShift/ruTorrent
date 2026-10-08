<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/PluginEntryDriver.php');

/**
 * AUD-ESC-06: the plugins that open one file of a download -- data ("Get
 * data"), mediainfo, screenshots and spectrogram -- take its path from
 * rTorrent::getFilePath(), in the daemon's own bytes.
 *
 * Each runs from its own entry script against FakeRtorrentDaemon, which
 * answers the way both daemons do: only & < > become entities, a quote and a
 * backslash arrive literally. When rXMLRPCRequest escaped every string, a file
 * whose path held a quote or a backslash was "not found" by all four; data
 * carried a private copy of getFilePath() as well.
 */
class AudFilePathTest extends TestCase
{
	const HASH = '0123456789ABCDEF0123456789ABCDEF01234567';
	const AUD = 'aud "q" \b \'a\' &c';
	// The probe name plus the pairs an extra unescape would collapse.
	const AUD_PAIRS = 'aud "q" \b \'a\' &c \\\\x \\"y';
	const PAYLOAD = "aud payload\n";

	private $base;
	private $file;
	private $daemon;

	public function setUp()
	{
		$this->base = sys_get_temp_dir() . '/rutorrent-aud-filepath-' . getmypid();
		$directory = $this->base . '/data/' . self::AUD_PAIRS;
		mkdir($directory, 0700, true);
		$this->file = $directory . '/' . self::AUD . '.mkv';
		file_put_contents($this->file, self::PAYLOAD);
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
	 * $frozen lists what f.get_frozen_path answers on each call: a closed
	 * download answers '' first, and then the path inside d.open/d.close.
	 */
	private function drive($script, $frozen, array $options)
	{
		$this->stopDaemon();
		PluginEntryDriver::removeTree($this->base . '/profile');
		$answers = new FakeRtorrentPerCall(array_map(function ($path) {
			return array($path);
		}, $frozen));
		$this->daemon = new FakeRtorrentDaemon(new FakeRtorrentMethodReplies(array(
			'f.get_frozen_path' => $answers, 'f.frozen_path' => $answers,
		)), $this->base . '/calls.log');
		return PluginEntryDriver::run($this->base, $this->daemon->port(), $script, $options);
	}

	public function testAudDataSendsTheFileTheDaemonNamed()
	{
		foreach (array('open' => array($this->file), 'closed' => array('', $this->file)) as $state => $frozen) {
			list($exit, $out, $err) = $this->drive('plugins/data/action.php', $frozen,
				array('get' => array('hash' => self::HASH, 'no' => '0')));
			$this->assertSame(self::PAYLOAD, $out, $state . ' download: "Get data" sends the file: ' . $out . $err);
			$this->assertSame(0, $exit, $state . ' download: the script ends cleanly: ' . $err);
		}
		$this->assertSame(array('d.open', 'f.get_frozen_path', 'd.close'),
			array_slice($this->daemon->calls(), -3),
			'a closed download is opened only to read the path, and closed again');
	}

	public function testAudMediaTasksAreGivenTheFileTheDaemonNamed()
	{
		foreach (array('mediainfo' => 'mediainfo', 'screenshots' => 'ffmpeg', 'spectrogram' => 'sox') as $plugin => $cmd) {
			list($exit, $out, $err) = $this->drive('plugins/' . $plugin . '/action.php', array('', $this->file),
				array('post' => array('cmd' => $cmd, 'hash' => self::HASH, 'no' => '0')));
			$scripts = PluginEntryDriver::taskScripts($this->base);
			$this->assertSame(1, count($scripts), $plugin . ' builds one task: ' . $out . $err);
			$this->assertTrue(isset($scripts[0]) && strpos($scripts[0], escapeshellarg($this->file)) !== false,
				$plugin . ' runs its tool on the file the daemon named: ' . ($scripts[0] ?? ''));
		}
	}
}
