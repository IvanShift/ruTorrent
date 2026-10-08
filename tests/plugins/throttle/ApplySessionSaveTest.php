<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/FakeRtorrentDaemon.php');

/**
 * A throttle channel chosen in the WebUI is saved with the change.
 *
 * rTorrent keeps d.throttle_name in a download's session files and writes them
 * only when something saves the download; only its periodic session.save did
 * after the WebUI's change, so a crash or `docker kill` before it lost the
 * channel (R4-NF-2). rThrottle::apply() now ends its request with one
 * d.save_resume per download it changed (rXMLRPCRequest::addSessionSaves()).
 */
class ThrottleApplySessionSaveTest extends TestCase
{
	const A = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
	const B = 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';

	private $base;
	private $daemon;

	public function setUp()
	{
		$this->base = sys_get_temp_dir() . '/rutorrent-throttle-apply-' . getmypid();
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

	/** Runs plugins/throttle/action.php for this apply form; returns its output. */
	private function apply($post)
	{
		$root = realpath(__DIR__ . '/../../..');
		$this->daemon = new FakeRtorrentDaemon(
			new FakeRtorrentAddTorrentReplies(array(), array(), false),
			$this->base . '/calls.log');
		$driver = $this->base . '/drive-throttle.php';
		file_put_contents($driver, "<?php\n"
			. '$_ENV[\'RU_PROFILE_PATH\'] = ' . var_export($this->base . '/profile', true) . ";\n"
			. '$_SERVER[\'REQUEST_METHOD\'] = \'POST\';' . "\n"
			. '$_POST = ' . var_export($post, true) . ";\n"
			. 'require_once(' . var_export($root . '/conf/config.php', true) . ");\n"
			. '$enableCSRFCheck = false;' . "\n"
			. '$scgi_host = "127.0.0.1";' . "\n"
			. '$scgi_port = ' . $this->daemon->port() . ";\n"
			. '$rpcTimeOut = 10;' . "\n"
			. '$log_file = ' . var_export($this->base . '/app.log', true) . ";\n"
			. 'chdir(' . var_export($root . '/plugins/throttle', true) . ");\n"
			. 'require(' . var_export($root . '/plugins/throttle/action.php', true) . ");\n");
		$output = array();
		exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=0 ' .
			escapeshellarg($driver) . ' 2>&1', $output);
		return implode("\n", $output);
	}

	/** method(first argument) of each member of the request that applied the channel. */
	private function applyRequest()
	{
		foreach ($this->daemon->bodies() as $body) {
			if (strpos($body, '<string>branch</string>') === false
				&& strpos($body, '<methodName>branch</methodName>') === false) {
				continue;
			}
			preg_match_all('|<name>methodName</name><value><string>([^<]*)</string></value></member>'
				. '\s*<member><name>params</name><value><array><data>\s*<value><string>([^<]*)</string>|',
				$body, $members, PREG_SET_ORDER);
			return array_map(function ($member) {
				return $member[1] . '(' . $member[2] . ')';
			}, $members);
		}
		return array();
	}

	public function testEachDownloadGivenAChannelIsSavedAfterIt()
	{
		$this->apply(array('apply' => '1', 'v' => '2',
			'hash' => array(self::A, 'not-a-hash', self::B)));
		$this->assertEquals(array(
			'branch(' . self::A . ')', 'branch(' . self::B . ')',
			'd.save_resume(' . self::A . ')', 'd.save_resume(' . self::B . ')',
		), $this->applyRequest(), 'the channels, then one save per download that got one: '
			. json_encode($this->daemon->calls()));
		$this->assertTrue(strpos(implode("\n", $this->daemon->bodies()), 'thr_2') !== false,
			'the chosen channel reaches rTorrent');
	}

	public function testNoDownloadMeansNoRequest()
	{
		$this->apply(array('apply' => '1', 'v' => '2', 'hash' => array('not-a-hash')));
		$this->assertEquals(array(), array_values(array_filter($this->daemon->calls(), function ($call) {
			return $call === 'branch' || $call === 'd.save_resume';
		})), 'nothing is sent for a form without a valid download: ' . json_encode($this->daemon->calls()));
	}
}
