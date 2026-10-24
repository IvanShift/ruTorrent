<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/PluginEntryDriver.php');

/**
 * AUD-ESC-14 for _getdir: the label list offered by the directory and label
 * pickers is read from d.custom1 in the daemon's own bytes. ruTorrent writes
 * labels percent-encoded; another client may write them as they are.
 */
class AudLabelsTest extends TestCase
{
	const AUD = 'aud "q" \b \'a\' &c';
	// The probe name plus the pairs an extra unescape would collapse.
	const AUD_PAIRS = 'aud "q" \b \'a\' &c \\\\x \\"y';

	private $base;
	private $daemon;

	public function setUp()
	{
		$this->base = sys_get_temp_dir() . '/rutorrent-aud-getdir-' . getmypid();
		mkdir($this->base, 0700, true);
	}

	public function tearDown()
	{
		if ($this->daemon !== null)
			$this->daemon->stop();
		$this->daemon = null;
		PluginEntryDriver::removeTree($this->base);
	}

	public function testAudLabelsAreTheDaemonsOwnBytes()
	{
		$labels = array(self::AUD, rawurlencode(self::AUD_PAIRS), ' ', rawurlencode(self::AUD));
		$this->daemon = new FakeRtorrentDaemon(new FakeRtorrentMethodReplies(array(
			'd.multicall' => $labels, 'd.multicall2' => $labels,
		)), $this->base . '/calls.log');
		list($exit, $out, $err) = PluginEntryDriver::run($this->base, $this->daemon->port(),
			'plugins/_getdir/info.php', array('get' => array('mode' => 'labels')));
		$this->assertSame(0, $exit, 'info.php ends cleanly: ' . $err);
		$this->assertSame(array('labels' => array(' ', self::AUD, self::AUD_PAIRS)), json_decode($out, true),
			'one entry per distinct label, including a literal space, in its own bytes: ' . $out);
	}
}
