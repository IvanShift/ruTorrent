<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/FakeRtorrentDaemon.php');

/**
 * rThrottle::correct() puts back the throttle channel of every download whose
 * channel lost its limits, by name. The name it read from the daemon went
 * into two command strings as bare text -- d.set_throttle_name=<name>, and
 * the same inside cat=$d.stop=,$d.set_throttle_name=<name>,$d.start=, which
 * rtorrent parses twice -- so it was only ever safe while every name was the
 * thr_<n> that init() creates.
 *
 * While rXMLRPCRequest escaped what it read, a quote or a backslash in such a
 * name arrived pre-escaped for that bare paste; strings are the daemon's own
 * bytes now (AUD-ESC), and a comma or a '$' was never escaped at all. Only a
 * channel name init() creates is put back, rebuilt from its number.
 */
class ThrottleChannelNameTest extends TestCase
{
	const A = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
	const B = 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';
	const C = 'CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC';
	const D = 'DDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDD';

	private $base;
	private $daemon;

	public function setUp()
	{
		$this->base = sys_get_temp_dir() . '/rutorrent-throttle-name-' . getmypid();
		$this->removeTree($this->base);
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
		if (!is_dir($path) || is_link($path)) {
			@unlink($path);
			return;
		}
		foreach (array_diff(scandir($path), array('.', '..')) as $entry) {
			$this->removeTree($path . '/' . $entry);
		}
		@rmdir($path);
	}

	/** Run rThrottle::correct() against a daemon reporting these channel names. */
	private function correct(array $names)
	{
		$rows = array();
		foreach ($names as $hash => $name) {
			array_push($rows, $hash, $name, '-1', '-1');
		}
		$root = realpath(__DIR__ . '/../..');
		$this->daemon = new FakeRtorrentDaemon(new FakeRtorrentMethodReplies(array(
			'system.client_version' => array('0.16.25'),
			'system.api_version' => array(11),
			'directory.default' => array('/downloads'),
			'd.multicall2' => $rows, 'd.multicall' => $rows,
		)), $this->base . '/calls.log');
		$driver = $this->base . '/drive-throttle.php';
		file_put_contents($driver, "<?php\n"
			. '$_ENV[\'RU_PROFILE_PATH\'] = ' . var_export($this->base . '/profile', true) . ";\n"
			. 'require_once(' . var_export($root . '/conf/config.php', true) . ");\n"
			. '$scgi_host = "127.0.0.1";' . "\n"
			. '$scgi_port = ' . $this->daemon->port() . ";\n"
			. '$rpcTimeOut = 10;' . "\n"
			. '$log_file = ' . var_export($this->base . '/app.log', true) . ";\n"
			. 'chdir(' . var_export($root . '/plugins/throttle', true) . ");\n"
			. 'require_once(' . var_export($root . '/plugins/throttle/throttle.php', true) . ");\n"
			. 'echo json_encode(rThrottle::load()->correct());' . "\n");
		$output = array();
		exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=0 ' . escapeshellarg($driver) . ' 2>&1', $output);
		return implode("\n", $output);
	}

	/**
	 * Each branch call the daemon was sent, a lone call or a system.multicall
	 * member: its target and its two commands.
	 */
	private function branches()
	{
		$branches = array();
		foreach ($this->daemon->bodies() as $body) {
			preg_match_all('|<name>methodName</name><value><string>branch</string></value></member>'
				. '\s*<member><name>params</name><value><array><data>((?:\s*<value><string>[^<]*</string></value>)+)|',
				$body, $members, PREG_SET_ORDER);
			if (preg_match('|<methodName>branch</methodName><params>((?:\s*<param><value><string>[^<]*</string></value></param>)+)|',
				$body, $single) === 1) {
				$members[] = $single;
			}
			foreach ($members as $member) {
				preg_match_all('|<string>([^<]*)</string>|', $member[1], $params);
				$branches[] = array_map(function ($param) {
					return htmlspecialchars_decode($param, ENT_NOQUOTES);
				}, $params[1]);
			}
		}
		return $branches;
	}

	public function testOnlyAChannelInitCreatesIsPutBackAndItIsRebuiltFromItsNumber()
	{
		$out = $this->correct(array(
			self::A => 'thr_1',
			self::B => 'thr_1,$d.erase=',
			self::C => 'thr_1 "q" \\',
			self::D => 'thr_01',
		));
		$this->assertSame('true', $out, 'correct() completes: ' . $out);
		$branches = $this->branches();
		$this->assertSame(array(array(self::A, 'd.is_active=',
			'cat=$d.stop=,$d.throttle_name.set=thr_1,$d.start=', 'd.throttle_name.set=thr_1')),
			$branches, 'one download is put back on its channel: ' . json_encode($branches));
		$sent = implode("\n", $this->daemon->bodies());
		$this->assertTrue(strpos($sent, 'd.erase') === false,
			'a name carrying a command is never pasted into one');
		$this->assertTrue(strpos($sent, 'thr_1 "q"') === false && strpos($sent, 'thr_01') === false,
			'nor is any other name the plugin did not create');
	}

	public function testRepairSavesTheCurrentSessionAfterTheStopStartInTheSameRequest()
	{
		$out = $this->correct(array(self::A => 'thr_1', self::B => 'thr_2', self::C => 'other'));
		$this->assertSame('true', $out, 'the repair completes: ' . $out);
		$bodies = $this->daemon->bodies();
		$repair = end($bodies);
		preg_match_all('|<name>methodName</name><value><string>([^<]*)</string></value>|',
			$repair, $methods);
		$this->assertSame(array('branch', 'branch', 'd.save_resume', 'd.save_resume'), $methods[1],
			'the restart and its session saves are one daemon request, even if its reply is lost');
		preg_match_all('|<name>methodName</name><value><string>d.save_resume</string></value></member>\s*'
			. '<member><name>params</name><value><array><data>\s*<value><string>([^<]*)</string>|',
			$repair, $saved);
		$this->assertSame(array(self::A, self::B), $saved[1],
			'only repaired downloads save their latest state and stop generation');
	}

}
