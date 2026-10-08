<?php

$_ENV['RU_PROFILE_PATH'] = sys_get_temp_dir().'/rutorrent-extratio-checklabels-'.getmypid();

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/FakeRtorrentDaemon.php');
require_once(__DIR__ . '/../../../php/xmlrpc.php');
require_once(__DIR__ . '/../../../plugins/extratio/rules.php');

/**
 * What a ratio rule changes after a label is saved with the change.
 *
 * plugins/extratio rechecks a download once the WebUI has set its label and,
 * when a rule matches, moves it to the rule's throttle channel and ratio
 * group. rTorrent keeps both in the download's session files and writes them
 * only when something saves the download, so a crash or `docker kill` before
 * the periodic session.save lost them (R4-NF-2). rRatioRulesList::checkLabels()
 * now ends its request with one d.save_resume per download it changed.
 */
class ExtratioCheckLabelsSessionSaveTest extends TestCase
{
	const A = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

	private $daemon;
	private $settingsProperty;
	private $settingsBefore;
	private $globalsBefore = array();

	public function setUp()
	{
		@mkdir($_ENV['RU_PROFILE_PATH'], 0700, true);
		$this->settingsProperty = self::makeAccessible(
			new ReflectionProperty('rTorrentSettings', 'theSettings'));
		$this->settingsBefore = $this->settingsProperty->getValue();
		$settings = (new ReflectionClass('rTorrentSettings'))->newInstanceWithoutConstructor();
		$settings->iVersion = 0x1018;
		$settings->aliases = array();
		$this->settingsProperty->setValue(null, $settings);
		foreach(array('scgi_host', 'scgi_port', 'rpcTimeOut') as $name)
			$this->globalsBefore[$name] = array_key_exists($name, $GLOBALS) ? $GLOBALS[$name] : null;
		$GLOBALS['rpcTimeOut'] = 10;
	}

	public function tearDown()
	{
		if($this->daemon !== null)
		{
			$this->daemon->stop();
			$this->daemon = null;
		}
		foreach($this->globalsBefore as $name => $value)
		{
			if($value === null) unset($GLOBALS[$name]);
			else $GLOBALS[$name] = $value;
		}
		foreach(glob($_ENV['RU_PROFILE_PATH'].'/*') ?: array() as $entry)
			@unlink($entry);
		@rmdir($_ENV['RU_PROFILE_PATH']);
		$this->settingsProperty->setValue(null, $this->settingsBefore);
	}

	/**
	 * Recheck downloads whose label, ratio group and channel are given; a
	 * FakeRtorrentPerCall label gives each download its own.
	 */
	private function check($label, $views, $throttle, $hashes = array(self::A), $rule = null,
		$tracker = 'http://tracker.example/announce')
	{
		$label = is_array($label) ? new FakeRtorrentPerCall($label) : array($label);
		$this->daemon = new FakeRtorrentDaemon(new FakeRtorrentMethodReplies(array(
			'd.get_custom1' => $label, 'd.custom1' => $label,
			'd.get_state' => array(1), 'd.state' => array(1),
			'branch' => array($views),
			'd.get_throttle_name' => array($throttle), 'd.throttle_name' => array($throttle),
			't.multicall' => array($tracker),
		)), $_ENV['RU_PROFILE_PATH'].'/calls.log');
		$GLOBALS['scgi_host'] = '127.0.0.1';
		$GLOBALS['scgi_port'] = $this->daemon->port();
		$rules = (new ReflectionClass('rRatioRulesList'))->newInstanceWithoutConstructor();
		$rules->lst = array($rule ?? new rRatioRule('films', RR_LABEL_CONTAIN, 'films', 1, 0, 'rat_1', 'thr_1'));
		$rules->checkLabels($hashes);
	}

	/** method(first argument) of each member of the request that applied the rule. */
	private function changeRequest()
	{
		foreach($this->daemon->bodies() as $body)
		{
			if(strpos($body, 'view.set_visible') === false && strpos($body, 'throttle_name.set') === false
				&& strpos($body, 'd.set_throttle_name') === false)
				continue;
			preg_match_all('|<name>methodName</name><value><string>([^<]*)</string></value></member>'
				.'\s*<member><name>params</name><value><array><data>\s*<value><string>([^<]*)</string>|',
				$body, $members, PREG_SET_ORDER);
			return array_map(function($member) { return $member[1].'('.$member[2].')'; }, $members);
		}
		return array();
	}

	public function testADownloadARuleMovesIsSavedAfterTheMove()
	{
		$this->check('films', '', '');
		$request = $this->changeRequest();
		$this->assertTrue(count($request) > 1, 'the rule changed the download: '
			.json_encode($this->daemon->calls()));
		$this->assertEquals('d.save_resume('.self::A.')', end($request),
			'and the request ends with its save: '.json_encode($request));
		$this->assertEquals(1, count(array_filter($request, function($member) {
			return strpos($member, 'd.save_resume(') === 0;
		})), 'one save for the one download');
	}

	public function testOnlyTheDownloadsARuleMovedAreSaved()
	{
		$b = str_repeat('B', 40);
		$this->check(array(array('music'), array('films')), '', '', array(self::A, $b));
		$request = $this->changeRequest();
		$this->assertEquals(array('d.save_resume('.$b.')'), array_values(array_filter($request,
			function($member) { return strpos($member, 'd.save_resume(') === 0; })),
			'the download the rule moved is saved, the other one is not: '.json_encode($request));
	}

	private function saves()
	{
		return array_values(array_filter($this->daemon->calls(), function($call) {
			return $call === 'd.save_resume';
		}));
	}

	/**
	 * AUD-ESC-17: a rule is matched against the label the daemon reported, in
	 * its own bytes -- here one another client wrote without percent-encoding.
	 */
	public function testAudLabelRuleMatchesTheLabelTheDaemonReported()
	{
		$this->check('aud "q" \b \'a\' &c', '', '', array(self::A),
			new rRatioRule('aud', RR_LABEL_CONTAIN, '"q" \b', 1, 0, 'rat_1', 'thr_1'));
		$this->assertEquals(array('d.save_resume'), $this->saves(),
			'the rule applies and the moved download is saved: '.json_encode($this->daemon->calls()));
	}

	/** AUD-ESC-17: and against the tracker URLs, in their own bytes. */
	public function testAudTrackerRuleMatchesTheUrlTheDaemonReported()
	{
		$this->check('', '', '', array(self::A),
			new rRatioRule('aud', RR_TRACKER_CONTAIN, 'u="q"\b', 1, 0, 'rat_1', 'thr_1'),
			'http://tracker.example/announce?u="q"\b');
		$this->assertEquals(array('d.save_resume'), $this->saves(),
			'the rule applies and the moved download is saved: '.json_encode($this->daemon->calls()));
	}

	public function testADownloadNoRuleChangesIsNotSaved()
	{
		$this->check('music', '', '');
		$this->assertEquals(array(), array_values(array_filter($this->daemon->calls(), function($call) {
			return $call === 'd.save_resume';
		})), 'nothing changed, nothing is saved: '.json_encode($this->daemon->calls()));
	}
}
