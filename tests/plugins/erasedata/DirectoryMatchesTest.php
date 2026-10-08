<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/CollectorFixture.php');
require_once(__DIR__ . '/../../../plugins/erasedata/removewithdata.php');
require_once(__DIR__ . '/../../../plugins/erasedata/collector.php');

/**
 * R5-REV-05. The file-operation plugins list directories under the session
 * path, which is the daemon's own bytes, and under the profile path. glob()
 * reads a '\', '[...]', '*' or '?' in such a directory as pattern syntax, so
 * a directory named with one matched nothing -- a deferred AutoTools request
 * was written and never found -- or matched a sibling. They list with
 * erasedataDirectoryMatches() instead, which compares every byte literally
 * and otherwise answers what glob() answered.
 */
class ErasedataDirectoryMatchesTest extends TestCase
{
	const HASH = 'ABCDEF0123456789ABCDEF0123456789ABCDEF01';

	private $base;

	public function setUp()
	{
		$this->base = realpath(sys_get_temp_dir()) . '/erasedata-matches-' . getmypid() . '-' . bin2hex(random_bytes(4));
		mkdir($this->base, 0700);
		FileUtil::$log = array();
	}

	public function tearDown()
	{
		$this->remove($this->base);
	}

	private function remove($path)
	{
		if (is_dir($path) && !is_link($path))
		{
			foreach (array_diff(scandir($path), array('.', '..')) as $entry)
				$this->remove($path . '/' . $entry);
			@rmdir($path);
		}
		else
			@unlink($path);
	}

	// Every entry of $directory except '.' and '..'.
	private static function entries($directory)
	{
		return array_values(array_diff(scandir($directory), array('.', '..')));
	}

	// A refactor guard: in a directory without pattern bytes the listing is
	// exactly glob()'s answer, order, hidden names and directories included.
	public function testAPlainDirectoryListsExactlyWhatGlobListed()
	{
		$directory = $this->base . '/plain';
		mkdir($directory, 0700);
		foreach (array('b.nonmove-request.json', 'a.nonmove-request.json', 'Z.nonmove-request.json',
			"\xC3\xA9.nonmove-request.json", '.hidden.nonmove-request.json', '.nonmove-request.json',
			'nonmove-request.json', 'a.nonmove.json', 'a.nonmove.json.tmp', 'x.notice',
			'.erasedata-unsafe-abc-20260101.notice', '.erasedata-unsafe-abc-intent-20260101.notice',
			'.erasedata-unsafe-abc-.notice', '.erasedata-unsafe-abd-20260101.notice') as $name)
			file_put_contents($directory . '/' . $name, '1');
		mkdir($directory . '/d.nonmove-request.json', 0700);
		$cases = array(array('', '.nonmove-request.json'), array('', '.nonmove.json'), array('', '.notice'),
			array('.erasedata-unsafe-abc-', '.notice'), array('a', ''), array('', ''), array('none-', '.json'));
		foreach ($cases as $case)
		{
			list($prefix, $suffix) = $case;
			$this->assertSame(glob($directory . '/' . $prefix . '*' . $suffix),
				erasedataDirectoryMatches($directory, $prefix, $suffix),
				'the same answer as glob() for ' . json_encode($case));
		}
		$this->assertSame(false, erasedataDirectoryMatches($this->base . '/missing', '', '.notice'),
			'a directory that cannot be listed answers false');
	}

	public function testABytePatternSyntaxWouldReadIsMatchedLiterally()
	{
		$siblings = array('session', 'sesxsion', 'sesXsion', 'sesQsion');
		foreach ($siblings as $sibling)
		{
			mkdir($this->base . '/' . $sibling, 0700);
			file_put_contents($this->base . '/' . $sibling . '/foreign.nonmove-request.json', '1');
		}
		foreach (array('ses\\sion', 'ses[x]sion', 'ses*sion', 'ses?sion') as $name)
		{
			$directory = $this->base . '/' . $name;
			mkdir($directory, 0700);
			file_put_contents($directory . '/own.nonmove-request.json', '1');
			$expected = array($directory . '/own.nonmove-request.json');
			$this->assertTrue(glob($directory . '/*.nonmove-request.json') !== $expected,
				'glob() misreads ' . $name . ', so this case is one it got wrong');
			$this->assertSame($expected, erasedataDirectoryMatches($directory, '', '.nonmove-request.json'),
				'only the directory\'s own entry, for ' . $name);
			$this->remove($directory);
		}
		$prefix = '[own]*?\\';
		file_put_contents($this->base . '/session/' . $prefix . '1.notice', '1');
		file_put_contents($this->base . '/session/o1.notice', '1');
		$this->assertSame(array($this->base . '/session/' . $prefix . '1.notice'),
			erasedataDirectoryMatches($this->base . '/session', $prefix, '.notice'),
			'pattern bytes in the prefix are literal too');
	}

	// The collector's daily report markers for a retained manifest live beside
	// the queue directory. Completion and every new report clear the older
	// ones; under a directory glob() misread they stayed, and a stale marker
	// of today hides the next refusal of the same manifest until tomorrow.
	public function testTheCollectorClearsItsReportsUnderADirectoryGlobWouldReadAsAPattern()
	{
		$siblings = array('session', 'sesxsion', 'sesXsion');
		foreach (array('ses\\sion', 'ses[x]sion', 'ses*sion') as $name)
		{
			$dir = $this->base . '/' . $name;
			$queue = $dir . '/erasedata';
			foreach (array($dir, $queue, $dir . '/root', $dir . '/outside') as $path)
				mkdir($path, 0700);
			file_put_contents($dir . '/outside/secret.txt', 'outside');
			symlink($dir . '/outside', $dir . '/root/away');
			$listed = $dir . '/root/away/secret.txt';
			$item = $queue . '/' . self::HASH . '.list';
			file_put_contents($item, ErasedataManifestCodec::encode(self::HASH,
				array('files' => array($listed), 'base' => $listed, 'multi' => false), 1));
			$marker = '.erasedata-unsafe-' . hash('sha256', $item) . '-';
			// A report of an earlier day, and siblings the pattern would also match.
			file_put_contents($dir . '/' . $marker . '20000101.notice', '1');
			foreach ($siblings as $sibling)
			{
				@mkdir($this->base . '/' . $sibling, 0700);
				file_put_contents($this->base . '/' . $sibling . '/' . $marker . '20000101.notice', '1');
			}

			$this->collect($queue);
			$this->assertSame('outside', file_get_contents($dir . '/outside/secret.txt'),
				'nothing is deleted through the link: ' . $name);
			$this->assertSame(array($marker . gmdate('Ymd') . '.notice'),
				array_values(preg_grep('/^\\.erasedata-unsafe-/', self::entries($dir))),
				'today\'s report replaces the earlier one: ' . $name);
			$this->assertSame(1, count(FileUtil::$log), 'the refusal is reported once: ' . $name);

			unlink($dir . '/root/away');
			mkdir($dir . '/root/away', 0700);
			file_put_contents($listed, 'owned');
			$this->collect($queue);
			$this->assertSame(false, file_exists($listed) || file_exists($item),
				'the repaired manifest completes: ' . $name);
			$this->assertSame(array(), array_values(preg_grep('/^\\.erasedata-unsafe-/', self::entries($dir))),
				'completion clears its reports: ' . $name);
			foreach ($siblings as $sibling)
				$this->assertSame(true, is_file($this->base . '/' . $sibling . '/' . $marker . '20000101.notice'),
					'a sibling directory\'s file is left alone: ' . $name . ' ' . $sibling);
			FileUtil::$log = array();
		}
	}

	private function collect($queue)
	{
		$collector = new ErasedataCollector(new ErasedataCollectorFixture(array()),
			function($hash) { return ERASEDATA_TORRENT_ABSENT; },
			function($hash, $physicalOnly = false) { return false; },
			function($line) {}, false);
		$collector->run($queue);
	}

	// recover.php has a listing only a missing class reaches; a call there is
	// as wrong as one on a path every pass takes.
	public function testNoFileOperationPluginCallsGlob()
	{
		$root = realpath(__DIR__ . '/../../..');
		// PHP 8 reads '\glob' as one name token; PHP 7.4 as a separator and 'glob'.
		$names = defined('T_NAME_FULLY_QUALIFIED') ? array(T_STRING, T_NAME_FULLY_QUALIFIED) : array(T_STRING);
		$calls = array();
		foreach (array('autotools', 'datadir', 'erasedata') as $plugin)
		{
			$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/plugins/' . $plugin,
				FilesystemIterator::SKIP_DOTS));
			foreach ($files as $file)
			{
				if (substr($file->getFilename(), -4) !== '.php')
					continue;
				$tokens = array_values(array_filter(token_get_all(file_get_contents($file->getPathname())),
					function($token) {
						return !is_array($token) || !in_array($token[0], array(T_WHITESPACE, T_COMMENT,
							T_DOC_COMMENT), true);
					}));
				foreach ($tokens as $index => $token)
					if (is_array($token) && in_array($token[0], $names, true)
						&& ltrim(strtolower($token[1]), '\\') === 'glob'
						&& isset($tokens[$index + 1]) && $tokens[$index + 1] === '('
						&& !(isset($tokens[$index - 1]) && is_array($tokens[$index - 1])
							&& in_array($tokens[$index - 1][0], array(T_FUNCTION, T_OBJECT_OPERATOR,
								T_DOUBLE_COLON), true)))
						$calls[] = substr($file->getPathname(), strlen($root) + 1) . ':' . $token[2];
			}
		}
		sort($calls, SORT_STRING);
		$this->assertSame(array(), $calls, 'no glob() call: ' . json_encode($calls));
	}
}
