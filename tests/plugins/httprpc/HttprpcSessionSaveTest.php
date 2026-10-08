<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/FakeRtorrentDaemon.php');

/**
 * A change made in the WebUI is saved with the change, not at the next
 * periodic session.save.
 *
 * rTorrent keeps a download's label and other custom fields, priority, state,
 * file priorities, tracker switches, views and throttle in its session files
 * and writes them only when something saves the download. Its own
 * session.save does that for every download on a timer (d.save_resume each, on
 * 0.9.8 and on 0.16), so a change made a few seconds before a crash or a
 * `docker kill` was lost: measured on the round-3 image for a label, 0 of 2
 * kept (ACC-OWN-A-02), and on round 4 for a priority (R4-NF-2).
 *
 * Every httprpc mode that changes such a setting now ends its multicall with
 * rXMLRPCRequest::addSessionSaves(): one d.save_resume per download it
 * changed, after the changes. Modes that change nothing kept in the session
 * send none.
 */
class HttprpcSessionSaveTest extends TestCase
{
	const A = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
	const B = 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';

	private $base;
	private $daemon;

	public function setUp()
	{
		$this->base = sys_get_temp_dir() . '/rutorrent-httprpc-session-save-' . getmypid();
		@mkdir($this->base . '/profile/settings', 0700, true);
	}

	public function tearDown()
	{
		if ($this->daemon !== null) {
			$this->daemon->stop();
			$this->daemon = null;
		}
		$this->removeTree($this->base);
	}

	private function removeTree($path)
	{
		if (!is_dir($path)) {
			return;
		}
		foreach (glob($path . '/*') as $entry) {
			is_dir($entry) ? $this->removeTree($entry) : @unlink($entry);
		}
		@rmdir($path);
	}

	/** Runs plugins/httprpc/action.php with this form body; returns its output. */
	private function post($body)
	{
		$root = realpath(__DIR__ . '/../../..');
		if ($this->daemon !== null) {
			$this->daemon->stop();
		}
		$this->daemon = new FakeRtorrentDaemon(
			new FakeRtorrentAddTorrentReplies(array(), array(), false),
			$this->base . '/calls.log');
		$driver = $this->base . '/drive-httprpc.php';
		file_put_contents($driver, "<?php\n"
			. '$_ENV[\'RU_PROFILE_PATH\'] = ' . var_export($this->base . '/profile', true) . ";\n"
			. 'require_once(' . var_export($root . '/conf/config.php', true) . ");\n"
			. '$scgi_host = "127.0.0.1";' . "\n"
			. '$scgi_port = ' . $this->daemon->port() . ";\n"
			. '$rpcTimeOut = 10;' . "\n"
			. '$rpcLogCalls = false;' . "\n"
			. '$log_file = ' . var_export($this->base . '/app.log', true) . ";\n"
			. '$HTTP_RAW_POST_DATA = ' . var_export($body, true) . ";\n"
			. 'chdir(' . var_export($root . '/plugins/httprpc', true) . ");\n"
			. 'require(' . var_export($root . '/plugins/httprpc/action.php', true) . ");\n");
		$output = array();
		exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=0 ' .
			escapeshellarg($driver) . ' 2>&1', $output);
		return implode("\n", $output);
	}

	/**
	 * The members of the last request the mode sent -- the change itself,
	 * after the settings reads at startup -- as method(first argument).
	 */
	private function lastRequest()
	{
		$bodies = $this->daemon->bodies();
		$body = end($bodies);
		if ($body === false) {
			return array();
		}
		if (strpos($body, '<methodName>system.multicall</methodName>') === false) {
			preg_match('|<methodName>([^<]*)</methodName>\s*<params>\s*<param><value><string>([^<]*)</string>|',
				$body, $single);
			return $single ? array($single[1] . '(' . $single[2] . ')') : array();
		}
		preg_match_all('|<name>methodName</name><value><string>([^<]*)</string></value></member>'
			. '\s*<member><name>params</name><value><array><data>\s*<value><string>([^<]*)</string>|',
			$body, $members, PREG_SET_ORDER);
		return array_map(function ($member) {
			return $member[1] . '(' . $member[2] . ')';
		}, $members);
	}

	private function saves($request)
	{
		return array_values(array_filter($request, function ($member) {
			return strpos($member, 'd.save_resume(') === 0;
		}));
	}

	public function testEachRelabelledDownloadIsSavedInTheSameRequest()
	{
		$out = $this->post('mode=setlabel&hash=' . self::A . '&hash=' . self::B . '&v=tv%20shows');
		$this->assertEquals(array(
			'd.custom1.set(' . self::A . ')', 'd.custom1.set(' . self::B . ')',
			'd.save_resume(' . self::A . ')', 'd.save_resume(' . self::B . ')',
		), $this->lastRequest(),
			'one multicall sets both labels and then saves both downloads: '
				. json_encode($this->daemon->calls()));
		$this->assertTrue(strpos(implode("\n", $this->daemon->bodies()), 'tv shows') !== false,
			'the label itself reaches rTorrent unchanged');
		$this->assertEquals(array(), array_values(array_filter($this->daemon->calls(), function ($call) {
			return $call === 'session.save' || $call === 'd.save_full_session';
		})), 'only the relabelled downloads are saved, and only their resume files: '
			. json_encode($this->daemon->calls()));
		$this->assertTrue(strpos($out, 'Could not') === false && strpos($out, 'Refused') === false,
			'the action answers as before: ' . substr($out, 0, 200));
	}

	public function testOneDownloadIsSavedOnce()
	{
		$this->post('mode=setlabel&hash=' . self::A . '&v=');
		$this->assertEquals(array('d.custom1.set(' . self::A . ')', 'd.save_resume(' . self::A . ')'),
			$this->lastRequest(), 'removing a label is saved the same way');
		$this->post('mode=setlabel&hash=' . self::A . '&hash=' . strtolower(self::A) . '&v=x');
		$this->assertEquals(array('d.save_resume(' . self::A . ')'), $this->saves($this->lastRequest()),
			'a download named twice is saved once: ' . json_encode($this->lastRequest()));
	}

	/**
	 * Each mode that changes something rTorrent keeps in the session: the form
	 * the WebUI posts, and the downloads whose saves must close the request.
	 */
	private function persistingModes()
	{
		$a = self::A;
		$b = self::B;
		return array(
			'dsetprio' => array("mode=dsetprio&hash=$a&hash=$b&v=3", array($a, $b)),
			'setprio' => array("mode=setprio&hash=$a&v=0&v=1&s=2", array($a)),
			'trkstate' => array("mode=trkstate&hash=$a&v=0&s=1", array($a)),
			'start' => array("mode=start&hash=$a&hash=$b", array($a, $b)),
			'stop' => array("mode=stop&hash=$a&hash=$b", array($a, $b)),
			'pause' => array("mode=pause&hash=$a", array($a)),
			'unpause' => array("mode=unpause&hash=$a", array($a)),
			'setprops' => array("mode=setprops&hash=$a&s=superseed&v=1&s=pex&v=0", array($a)),
		);
	}

	public function testEveryModeThatChangesASessionSettingEndsWithItsSaves()
	{
		foreach ($this->persistingModes() as $mode => $case) {
			$out = $this->post($case[0]);
			$request = $this->lastRequest();
			$expected = array_map(function ($hash) {
				return 'd.save_resume(' . $hash . ')';
			}, $case[1]);
			$this->assertEquals($expected, array_slice($request, -count($expected)),
				$mode . ' ends its request with one save per download it changed: ' . json_encode($request));
			$this->assertEquals($expected, $this->saves($request),
				$mode . ' saves each download once: ' . json_encode($request));
			$this->assertTrue(count($request) > count($expected),
				$mode . ' still sends its change before the saves: ' . json_encode($request));
			$this->assertTrue(strpos($out, 'Refused') === false && strpos($out, 'Could not') === false,
				$mode . ' answers as before: ' . substr($out, 0, 160));
		}
	}

	public function testModesThatChangeNothingKeptInTheSessionSendNoSave()
	{
		$a = self::A;
		foreach (array('recheck' => "mode=recheck&hash=$a", 'updatetracker' => "mode=updatetracker&hash=$a",
			'remove' => "mode=remove&hash=$a") as $mode => $body) {
			$this->post($body);
			$this->assertEquals(array(), $this->saves($this->lastRequest()),
				$mode . ' sends no save: ' . json_encode($this->lastRequest()));
		}
	}

	public function testATrustedSetterRefusesAMalformedHashBeforeSendingAnything()
	{
		foreach (array('setprio' => 'mode=setprio&hash=not-a-hash&v=0&s=2',
			'trkstate' => 'mode=trkstate&hash=not-a-hash&v=0&s=1') as $mode => $body) {
			$out = $this->post($body);
			$this->assertTrue(strpos($out, 'Refused: missing or invalid torrent hash.') !== false,
				$mode . ' refuses a malformed hash: ' . substr($out, 0, 160));
			$this->assertEquals(array(), array_values(array_filter($this->daemon->calls(), function ($call) {
				return in_array($call, array('f.priority.set', 'f.set_priority', 't.is_enabled.set',
					't.set_enabled', 'd.save_resume'), true);
			})), $mode . ' sends none of its commands: ' . json_encode($this->daemon->calls()));
		}
	}
}
