<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/PluginEntryDriver.php');
require_once(__DIR__ . '/../../php/HttprpcListFixture.php');

/** Preserve the real system.multicall result slots, including optional name evidence. */
class AudFilePathReplies extends FakeRtorrentMethodReplies
{
	public function __invoke($request, $methods)
	{
		$values = parent::__invoke($request, $methods);
		if(strpos($request, '<methodName>system.multicall</methodName>') === false)
			return $values;
		$body = HttprpcListFixture::body(array_map(function($value) { return array($value); }, $values));
		// Keep this witness's literal quote/backslash spelling on the wire.
		return new FakeRtorrentRawReply(str_replace(array('&quot;', '&apos;'), array('"', "'"), $body));
	}
}

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
		$this->daemon = new FakeRtorrentDaemon(new AudFilePathReplies(array(
			'f.get_frozen_path' => $answers, 'f.frozen_path' => $answers,
		)), $this->base . '/calls.log', false, array(__FILE__));
		return PluginEntryDriver::run($this->base, $this->daemon->port(), $script, $options);
	}

	public function testAudDataSendsTheFileTheDaemonNamed()
	{
		foreach (array('open' => array($this->file), 'closed' => array('', $this->file)) as $state => $frozen) {
			list($exit, $out, $err) = $this->drive('plugins/data/action.php', $frozen,
				array('get' => array('hash' => self::HASH, 'no' => '0')));
			$this->assertSame(self::PAYLOAD, $out, $state . ' download: "Get data" sends the file: ' . $out . $err);
			$this->assertSame(0, $exit, $state . ' download: the script ends cleanly: ' . $err);
			$this->assertSame($state === 'open' ? 1 : 2, count($this->daemon->bodies()),
				'optional download-label evidence stays in the existing path request');
		}
		$lifecycle = array_values(array_filter($this->daemon->calls(), function($method) {
			return in_array($method, array('d.open', 'f.get_frozen_path', 'd.close'), true);
		}));
		$this->assertSame(array('d.open', 'f.get_frozen_path', 'd.close'),
			array_slice($lifecycle, -3),
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

	/**
	 * spectrogram titles its image with the file's name. The name must not
	 * depend on the locale: in C, PHP 7.4's basename() made Сериал.flac .flac.
	 */
	public function testSpectrogramTitlesTheImageWithTheFileNameInTheCLocale()
	{
		$file = $this->base . '/data/Сериал.flac';
		file_put_contents($file, self::PAYLOAD);
		list($exit, $out, $err) = $this->drive('plugins/spectrogram/action.php', array('', $file),
			array('post' => array('cmd' => 'sox', 'hash' => self::HASH, 'no' => '0'),
				'before' => 'setlocale(LC_CTYPE, "C");'));
		$scripts = PluginEntryDriver::taskScripts($this->base);
		$this->assertSame(1, count($scripts), 'spectrogram builds one task: ' . $out . $err);
		$this->assertTrue(isset($scripts[0]) && strpos($scripts[0], " -t 'Сериал.flac' ") !== false,
			'its title is the file\'s name: ' . ($scripts[0] ?? ''));
	}
}
