<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/PhpProcess.php');
require_once(__DIR__ . '/../../php/PermissionsBiteFixture.php');
require_once(__DIR__ . '/../../php/RtorrentProbeFixture.php');

/**
 * plugins/create hashes what it is pointed at, and with "start seeding"
 * rtorrent then serves it, so what a creator reads has to stay inside
 * $topDirectory once symbolic links are followed, not only as written: the
 * path it is given, and every link under a folder it is given. mktorrent and
 * the other external creators follow links, so the request is refused before
 * any creator starts, with the plugin's "Incorrect directory" answer naming
 * the entry and a classified log line, rather than having links left out of
 * one creator's own walk. Seeding checks the folder again.
 *
 * Adapted from upstream a8c0c322d (xirvik): the fork's action.php reads only
 * a POST body, so the driver posts; rTask writes to stderr, so only standard
 * output is read as the answer; a folder holding a link out is refused here
 * instead of built without it.
 */
class CreateTopDirectoryTest extends TestCase
{
	private $base;
	private $top;
	private $root;

	public function setUp()
	{
		$this->root = realpath(__DIR__ . '/../../..');
		$this->base = sys_get_temp_dir() . '/rutorrent-create-top-' . uniqid('', true);
		@mkdir($this->base . '/profile/settings', 0700, true);
		foreach (array('pack', 'other', 'clean', 'deep/a/b', 'hub', 'beyond', 'dangling', 'sub/locked',
			'chain', 'odd', 'later') as $dir) {
			@mkdir($this->base . '/top/' . $dir, 0777, true);
		}
		for ($k = 1; $k <= 45; $k++) {
			@mkdir($this->base . '/top/cr/d' . $k, 0777, true);
		}
		@mkdir($this->base . '/outside', 0777, true);
		$this->base = realpath($this->base);
		$this->top = $this->base . '/top';
		$secret = $this->base . '/outside/secret.bin';
		file_put_contents($secret, 'outside');
		file_put_contents($this->top . '/pack/mine.txt', 'mine');
		file_put_contents($this->top . '/other/shared.txt', 'shared');
		file_put_contents($this->top . '/clean/mine.txt', 'mine');
		file_put_contents($this->top . '/later/mine.txt', 'mine');
		symlink($this->base . '/outside', $this->top . '/out');
		symlink($secret, $this->top . '/pack/leak.bin');
		symlink($this->top . '/other/shared.txt', $this->top . '/pack/shared.txt');
		// Links that stay inside, two of them back up the folder itself.
		symlink($this->top . '/other/shared.txt', $this->top . '/clean/shared.txt');
		symlink($this->top . '/clean', $this->top . '/clean/again');
		symlink($this->top . '/clean', $this->top . '/clean/twice');
		// A link out two folders down, behind a link back up.
		symlink($this->top . '/deep', $this->top . '/deep/a/up');
		symlink($secret, $this->top . '/deep/a/b/leak.bin');
		// A link out reached only through a folder link that stays inside.
		symlink($this->top . '/beyond', $this->top . '/hub/via');
		symlink($secret, $this->top . '/beyond/leak.bin');
		symlink($this->base . '/nowhere', $this->top . '/dangling/gone');
		// A link out behind 45 folder links, more than the kernel follows in one path.
		symlink($this->top . '/cr/d1', $this->top . '/chain/n');
		for ($k = 1; $k < 45; $k++) {
			symlink($this->top . '/cr/d' . ($k + 1), $this->top . '/cr/d' . $k . '/n');
		}
		symlink($secret, $this->top . '/cr/d45/leak.bin');
		symlink($secret, $this->top . "/odd/\xff\n.bin");
	}

	public function tearDown()
	{
		@chmod($this->top . '/sub/locked', 0777);
		$this->removeTree($this->base);
	}

	private function removeTree($path)
	{
		if (is_link($path) || !is_dir($path)) {
			@unlink($path);
			return;
		}
		foreach (glob($path . '/{,.}[!.]*', GLOB_BRACE) as $entry) {
			$this->removeTree($entry);
		}
		@rmdir($path);
	}

	private function copyTree($from, $to)
	{
		@mkdir($to, 0700, true);
		foreach (scandir($from) as $entry) {
			if ($entry === '.' || $entry === '..' || $entry === 'config.local.php' || $entry === 'conf.local.php') {
				continue;
			}
			if (is_dir($from . '/' . $entry)) {
				$this->copyTree($from . '/' . $entry, $to . '/' . $entry);
			} else {
				copy($from . '/' . $entry, $to . '/' . $entry);
			}
		}
	}

	/** Runs $body in a child PHP with the configuration loaded and returns its run. */
	private function child($root, $body, $options = array())
	{
		RtorrentProbeFixture::warmSettingsCache($this->base . '/profile');
		$driver = $this->base . '/drive-create.php';
		file_put_contents($driver, "<?php\n"
			. '$_ENV[\'RU_PROFILE_PATH\'] = ' . var_export($this->base . '/profile', true) . ";\n"
			. 'require_once(' . var_export($root . '/conf/config.php', true) . ");\n"
			. '$topDirectory = ' . var_export(isset($options['top']) ? $options['top'] : $this->top . '/', true) . ";\n"
			. '$tempDirectory = ' . var_export($this->base . '/', true) . ";\n"
			. '$log_file = ' . var_export($this->base . '/errors.log', true) . ";\n"
			. $body);
		// A walk that loops is stopped, not waited for.
		$arguments = array_merge(PhpProcess::sameIni(), array('-d', 'error_reporting=0', '-d', 'max_execution_time=30'));
		if (isset($options['disable'])) {
			$arguments = array_merge($arguments, array('-d', 'disable_functions=' . $options['disable']));
		}
		return PhpProcess::run(array_merge($arguments, array($driver)));
	}

	/** The lines the plugin logged, without their time stamps. */
	private function logged()
	{
		return is_file($this->base . '/errors.log') ?
			preg_replace('/^\[[^\]]*\] /m', '', trim(file_get_contents($this->base . '/errors.log'))) : '';
	}

	/**
	 * Posts "create" to action.php for $path and returns its answer, with the
	 * creator tasks prepared and the log. Options: 'top', the top directory;
	 * 'creator', an external creator set in a copy of the plugin's
	 * conf.local.php; 'disable', functions the child lacks; 'mayFail', to
	 * answer null errors instead of throwing when nothing is printed.
	 */
	private function create($path, $options = array())
	{
		$root = $this->root;
		if (isset($options['creator'])) {
			$root = $this->base . '/tree';
			foreach (array('php', 'conf', 'plugins/_task', 'plugins/create', 'tools') as $dir) {
				$this->copyTree($this->root . '/' . $dir, $root . '/' . $dir);
			}
			file_put_contents($root . '/plugins/create/conf.local.php', "<?php\n"
				. '$useExternal = ' . var_export($options['creator'], true) . ";\n"
				. '$pathToCreatetorrent = ' . var_export($this->base . '/bin/' . $options['creator'], true) . ";\n");
		}
		$run = $this->child($root, '$_POST = array("cmd" => "create", "path_edit" => ' . var_export($path, true)
				. ', "piece_size" => "16", "start_seeding" => "0", "private" => "0", "hybrid" => "0",'
				. ' "trackers" => "", "comment" => "", "source" => "");' . "\n"
			. '$_REQUEST = $_POST;' . "\n"
			. '$_SERVER[\'REQUEST_METHOD\'] = \'POST\';' . "\n"
			. 'chdir(' . var_export($root . '/plugins/create', true) . ");\n"
			. 'require(' . var_export($root . '/plugins/create/action.php', true) . ");\n", $options);
		$answer = json_decode($run['out'], true);
		if (!is_array($answer) || !array_key_exists('errors', $answer)) {
			if (empty($options['mayFail'])) {
				throw new Exception('action.php printed: ' . $run['out'] . ' / ' . $run['err']);
			}
			$answer = array('errors' => null);
		}
		$answer['tasks'] = array();
		foreach (glob($this->base . '/profile/settings/tasks/*/start.sh') ?: array() as $script) {
			$answer['tasks'][] = file_get_contents($script);
		}
		$answer['logged'] = $this->logged();
		return $answer;
	}

	private function refused($answer)
	{
		return is_array($answer['errors']) && count($answer['errors']) > 0
			&& strpos($answer['errors'][0], 'Incorrect directory') === 0;
	}

	/**
	 * Asserts $answer is the plugin's refusal naming $entry under the top
	 * directory, logged as $reason with that entry, and that no task was
	 * prepared.
	 */
	private function assertRefusedAs($reason, $entry, $answer, $what)
	{
		$this->assertEquals(array('Incorrect directory (' . $entry . ')'), $answer['errors'], $what);
		$this->assertEquals('create: refused reason=' . $reason . ' entry='
			. json_encode($entry, JSON_INVALID_UTF8_SUBSTITUTE), $answer['logged'], $what . ', and the log says why');
		$this->assertEquals(array(), $answer['tasks'], $what . ', before any creator task is prepared');
	}

	public function testAPathThroughALinkOutIsRefused()
	{
		$this->assertRefusedAs('outside-top-directory', '/out/secret.bin', $this->create($this->top . '/out/secret.bin'),
			'a file reached through a link out is not hashed');
	}

	public function testAFolderThatIsALinkOutIsRefused()
	{
		$this->assertRefusedAs('outside-top-directory', '/out', $this->create($this->top . '/out'),
			'nor a folder that is a link out');
	}

	public function testAPathOutsideAsWrittenIsRefusedAndLogged()
	{
		$a = $this->create($this->base . '/outside/secret.bin');
		$this->assertTrue($this->refused($a), 'a path outside the top directory as written is refused as before');
		$this->assertTrue(strpos($a['logged'], 'create: refused reason=outside-top-directory entry=') === 0,
			'and logged: ' . $a['logged']);
		$this->assertEquals(array(), $a['tasks'], 'before any creator task is prepared');
	}

	public function testAPathInsideIsAccepted()
	{
		$a = $this->create($this->top . '/pack/mine.txt');
		$this->assertTrue(!$this->refused($a), 'a file inside the top directory is hashed: ' . json_encode($a['errors']));
		$this->assertEquals(1, count($a['tasks']), 'and its creator task is prepared');
	}

	public function testAFolderHoldingALinkOutIsRefused()
	{
		$this->assertRefusedAs('link-outside-top-directory', '/pack/leak.bin', $this->create($this->top . '/pack'),
			'a folder holding a link to a file outside the top directory is not hashed');
	}

	public function testALinkOutFoldersDownIsFoundBehindALinkBackUp()
	{
		$this->assertRefusedAs('link-outside-top-directory', '/deep/a/b/leak.bin', $this->create($this->top . '/deep'),
			'nor one holding such a link two folders down');
	}

	public function testALinkOutReachedThroughAFolderLinkInsideIsFound()
	{
		$this->assertRefusedAs('link-outside-top-directory', '/hub/via/leak.bin', $this->create($this->top . '/hub'),
			'nor one whose folder link inside leads to such a link');
	}

	public function testALinkOutBehindMoreFolderLinksThanThePathLimitIsRefused()
	{
		$this->assertRefusedAs('link-outside-top-directory', '/chain' . str_repeat('/n', 45) . '/leak.bin',
			$this->create($this->top . '/chain'), 'nor one whose link out sits behind 45 folder links');
	}

	public function testALinkThatDoesNotResolveIsRefusedAsUnresolved()
	{
		$this->assertRefusedAs('link-unresolved', '/dangling/gone', $this->create($this->top . '/dangling'),
			'nor one holding a link that does not resolve');
	}

	public function testAnOddNameIsNamedInValidJson()
	{
		$a = $this->create($this->top . '/odd');
		$this->assertTrue(is_array($a['errors']) && (count($a['errors']) === 1)
			&& (preg_match('`^Incorrect directory \(/odd/.+\.bin\)$`su', $a['errors'][0]) === 1),
			'a link out whose name is not UTF-8 is named in a valid answer: ' . json_encode($a['errors']));
		$this->assertTrue((strpos($a['logged'], "\n") === false)
			&& (strpos($a['logged'], 'create: refused reason=link-outside-top-directory entry="\\/odd\\/') === 0),
			'and logged on one line: ' . json_encode($a['logged'], JSON_INVALID_UTF8_SUBSTITUTE));
	}

	public function testAFolderWhoseLinksStayInsideIsAccepted()
	{
		$a = $this->create($this->top . '/clean');
		$this->assertTrue(!$this->refused($a), 'links elsewhere inside, and back up the folder, are no reason to refuse: '
			. json_encode($a['errors']));
		$this->assertEquals('', $a['logged'], 'and nothing is logged');
	}

	public function testMktorrentIsNotStartedOnAFolderHoldingALinkOut()
	{
		$a = $this->create($this->top . '/pack', array('creator' => 'mktorrent'));
		$this->assertRefusedAs('link-outside-top-directory', '/pack/leak.bin', $a,
			'mktorrent follows links, so it is not started on a folder holding a link out');
	}

	public function testMktorrentStillStartsOnAFolderWhoseLinksStayInside()
	{
		$a = $this->create($this->top . '/clean', array('creator' => 'mktorrent'));
		$this->assertTrue(!$this->refused($a), 'mktorrent is chosen and accepted: ' . json_encode($a['errors']));
		$this->assertEquals(1, count($a['tasks']), 'one creator task is prepared');
		$this->assertTrue(isset($a['tasks'][0]) && strpos($a['tasks'][0], '/plugins/create/mktorrent.sh') !== false,
			'and it runs mktorrent.sh, so the case above did choose mktorrent');
	}

	public function testAFolderThatCannotBeWalkedIsRefused()
	{
		$this->assertRefusedAs('walk-unavailable', '/clean', $this->create($this->top . '/clean', array('disable' => 'scandir')),
			'a folder is not hashed when its links cannot be checked');
	}

	/** The path check needs these too, so it refuses before the walk would. */
	public function testAFolderIsRefusedWithoutWhatThePathCheckNeeds()
	{
		$this->assertRefusedAs('outside-top-directory', '/clean', $this->create($this->top . '/clean', array('disable' => 'is_link')),
			'a folder is not hashed without is_link()');
		// util.php needs realpath() for $rootPath: PHP 8 stops there, and on
		// 7.4 the log's place is lost with it, so only the answer is checked.
		$a = $this->create($this->top . '/clean', array('disable' => 'realpath', 'mayFail' => true));
		$this->assertEquals(array(), $a['tasks'], 'without realpath() no creator task is prepared');
		if ($a['errors'] === null) {
			echo "NOT CHECKED: the refusal answer without realpath(); PHP 8 stops in util.php\n";
			return;
		}
		$this->assertTrue($this->refused($a), 'a folder is not hashed without realpath(): ' . json_encode($a['errors']));
	}

	public function testNoFolderIsSkippedWhenIsDirIsUnavailable()
	{
		$a = $this->create($this->top . '/deep', array('disable' => 'is_dir', 'mayFail' => true));
		$this->assertEquals(array(), $a['tasks'], 'without is_dir() no creator task is prepared');
		if (PHP_MAJOR_VERSION >= 8) {
			echo "NOT CHECKED: the refusal answer without is_dir(); PHP 8 stops at action.php's own is_dir() call before the walk\n";
			return;
		}
		$this->assertRefusedAs('walk-unavailable', '/deep', $a, 'without is_dir() no folder is skipped');
	}

	/** The mbstring functions, which plugin.info only warns about when they are missing. */
	private function mbstring()
	{
		return implode(',', function_exists('get_extension_funcs') ? (get_extension_funcs('mbstring') ?: array()) : array());
	}

	public function testAnAcceptedFolderStartsWithoutMbstring()
	{
		$a = $this->create($this->top . '/clean', array('disable' => $this->mbstring()));
		$this->assertTrue(!$this->refused($a), 'without mbstring an accepted folder is still accepted: ' . json_encode($a['errors']));
		$this->assertEquals(1, count($a['tasks']), 'and its creator task is prepared');
	}

	public function testARefusalAnswersWithoutMbstring()
	{
		$this->assertRefusedAs('link-outside-top-directory', '/pack/leak.bin',
			$this->create($this->top . '/pack', array('disable' => $this->mbstring())),
			'without mbstring a refusal still answers and names the entry');
	}

	public function testAFolderThatCannotBeListedIsRefused()
	{
		if (testSkipUnlessPermissionsBite('create refusing a folder it cannot list')) {
			return;
		}
		chmod($this->top . '/sub/locked', 0100);
		$this->assertRefusedAs('unreadable-directory', '/sub/locked', $this->create($this->top . '/sub'),
			'a folder holding one whose links cannot be listed is not hashed');
	}

	public function testATopDirectoryOfRootRefusesNothing()
	{
		foreach (array('scandir', 'is_link') as $function) {
			$a = $this->create($this->top . '/pack', array('top' => '/', 'disable' => $function));
			$this->assertTrue(!$this->refused($a), 'with "/" as the top directory nothing is outside it, '
				. 'and nothing is walked, without ' . $function . '(): ' . json_encode($a['errors']));
		}
	}

	public function testTheBuiltInCreatorHashesOnlyThePathThatWasChecked()
	{
		// out/../outside reads as <top>/outside, which is inside, but the
		// kernel follows out first and the .. then leads beside its target.
		$a = $this->create($this->top . '/out/../outside');
		$this->assertTrue(!$this->refused($a), 'the path is accepted as the folder it reads as: ' . json_encode($a['errors']));
		$params = glob($this->base . '/profile/settings/tasks/*/params') ?: array();
		$this->assertEquals(1, count($params), 'one creator task is prepared');
		if (count($params) !== 1) {
			return;
		}
		$request = unserialize(file_get_contents($params[0]), array('allowed_classes' => false));
		$this->assertEquals($this->top . '/outside', $request['path_edit'], 'the task holds the path that was checked');
		$run = PhpProcess::run(array_merge(PhpProcess::sameIni(), array('-d', 'error_reporting=0', '-d', 'max_execution_time=30',
			$this->root . '/plugins/create/createtorrent.php', basename(dirname($params[0])))),
			array('env' => array('RU_PROFILE_PATH' => $this->base . '/profile')));
		$result = dirname($params[0]) . '/result.torrent';
		$this->assertTrue(!is_file($result) || (strpos(file_get_contents($result), 'secret.bin') === false),
			'and the built-in creator does not hash the file outside: ' . $run['out'] . $run['err']);
	}

	public function testALinkOutThatAppearsBeforeSeedingStopsSeeding()
	{
		$a = $this->create($this->top . '/later');
		$this->assertTrue(!$this->refused($a), 'the folder is accepted when it is requested: ' . json_encode($a['errors']));
		// The built-in creator hashes the folder, then a link out appears
		// before seeding starts.
		$run = $this->child($this->root, 'require_once(' . var_export($this->root . '/php/xmlrpc.php', true) . ");\n"
			. 'require_once(' . var_export($this->root . '/php/Torrent.php', true) . ");\n"
			. 'require_once(' . var_export($this->root . '/php/rtorrent.php', true) . ");\n"
			. 'require_once(' . var_export($this->root . '/plugins/create/seeding.php', true) . ");\n"
			. '$torrent = new Torrent(' . var_export($this->top . '/later', true) . ", array(), 16);\n"
			. 'symlink(' . var_export($this->base . '/outside/secret.bin', true) . ', '
				. var_export($this->top . "/later/leak\n.bin", true) . ");\n"
			. 'CreateSeeding::publish($torrent, array("path_edit" => ' . var_export($this->top . '/later', true)
				. '), 0666, "create");' . "\n");
		$this->assertEquals("Seeding refused (link-outside-top-directory): \"\\/later\\/leak\\n.bin\"\n", $run['out'],
			'the task log says on one line that seeding was refused and why: ' . $run['err']);
		$this->assertEquals('create: seeding refused reason=link-outside-top-directory entry="\/later\/leak\n.bin"',
			$this->logged(), 'so does errors.log');
		$this->assertEquals(array(), glob($this->base . '/profile/torrents/*') ?: array(),
			'and no torrent is handed to rtorrent');
	}
}
