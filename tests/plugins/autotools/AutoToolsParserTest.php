<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/FakeRtorrentDaemon.php');

/**
 * AutoTools through the real php/xmlrpc.php parser against a fake daemon.
 *
 * The parser hands out the daemon's own bytes. Until 2026-10 it escaped each
 * backslash and double quote of an answer for a JavaScript literal, and every
 * AutoTools comparison of a daemon path with a path its finished hook was
 * given failed for a download whose path carried either character: a claimed
 * Move stopped after its payload had moved (AUD-ESC-04, L4b-NEW-1, probe9b),
 * the recovery exits never finished (AUD-ESC-11), and the {DIR} label was
 * stored escaped (AUD-ESC-07, probe10). The paths here carry both characters
 * and the sequences \" and \\ themselves, so neither that escaping nor a
 * second unescaping of an answer can pass. setHandlers() and init.php are
 * driven the same way to show what they ask the daemon to run and what they
 * serve the WebUI.
 */
class AutoToolsParserTest extends TestCase
{
	const HASH = 'ABCDEF0123456789ABCDEF0123456789ABCDEF01';
	const JOB = '0123456789abcdef0123456789abcdef';
	// The claim token of the receipts the daemon answers.
	const TOKEN = 'dddddddddddddddddddddddddddddddd:0000000000000001';
	// A download directory, a destination directory and a payload name with a
	// double quote, a backslash, the sequences \" and \\, an apostrophe and an
	// ampersand, which the daemon writes as an entity.
	const SOURCE_DIR = 'aud "q" \\b \'a\' &c';
	const DEST_DIR = 'to \\"x\\"\\\\y';
	const PAYLOAD = 'pay \\"load\\" \\\\ x.bin';
	// A label folder: d.custom1 is 'Rock%20%22n%22%20Roll%5Cx' for it.
	const LABEL_DIR = ' Rock "n" Roll\\x ';

	private $base;
	private $daemon;

	public function setUp()
	{
		$this->base = sys_get_temp_dir() . '/rutorrent-autotools-parser-' . getmypid() . '-' . bin2hex(random_bytes(4));
		@mkdir($this->base, 0700, true);
	}

	public function tearDown()
	{
		if ($this->daemon !== null)
			$this->daemon->stop();
		$this->daemon = null;
		$this->remove($this->base);
	}

	private function remove($path)
	{
		if (is_dir($path) && !is_link($path)) {
			@chmod($path, 0700);
			foreach (array_diff(scandir($path), array('.', '..')) as $entry)
				$this->remove($path . '/' . $entry);
			@rmdir($path);
		} else @unlink($path);
	}

	private function repoRoot()
	{
		return realpath(__DIR__ . '/../../..');
	}

	// Runs $body in a child PHP with the real core parser against a fake
	// daemon answering $replies (in order, or by method name), from the
	// AutoTools plugin directory as its CLI entry points run, and returns what
	// the body printed as JSON. $settings overrides the default and session
	// directories of the daemon.
	private function drive($replies, $body, array $settings = array())
	{
		$settings = array_merge(array('directory' => $this->base . '/dl', 'session' => $this->base . '/session'),
			$settings);
		if ($this->daemon !== null)
			$this->daemon->stop();
		$this->daemon = new FakeRtorrentDaemon($replies, $this->base . '/calls-' . uniqid() . '.log');
		$driver = $this->base . '/drive-' . uniqid() . '.php';
		file_put_contents($driver, "<?php\n"
			. '$_SERVER[\'REMOTE_USER\'] = "tester"; $_ENV[\'RU_PROFILE_PATH\'] = '
				. var_export($this->base . '/profile', true) . '; $_ENV[\'RU_LOG_FILE\'] = '
				. var_export($this->base . '/errors.log', true) . ";\n"
			. 'chdir(' . var_export($this->repoRoot() . '/plugins/autotools', true) . ");\n"
			. 'require_once(' . var_export($this->repoRoot() . '/conf/config.php', true) . ");\n"
			. 'require_once(' . var_export($this->repoRoot() . '/php/settings.php', true) . ");\n"
			. '$r = new ReflectionClass("rTorrentSettings"); $s = $r->newInstanceWithoutConstructor();'
			. ' $s->iVersion = 0x1019; $s->aliases = array(); $s->linkExist = true;'
			. ' $s->directory = ' . var_export($settings['directory'], true) . ';'
			. ' $s->session = ' . var_export($settings['session'], true) . ';'
			. ' $p = $r->getProperty("theSettings"); $p->setAccessible(true); $p->setValue(null, $s);' . "\n"
			. '$scgi_host = "127.0.0.1"; $scgi_port = ' . $this->daemon->port() . ';'
				. ' $rpcTimeOut = 10; $rpcLogCalls = false;' . "\n"
			. 'require_once(' . var_export($this->repoRoot() . '/php/xmlrpc.php', true) . ");\n"
			. $body);
		$out = array();
		exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=0 ' . escapeshellarg($driver) . ' 2>&1', $out);
		$printed = implode("\n", $out);
		$answer = json_decode($printed, true);
		if (!is_array($answer))
			throw new Exception('the driver printed: ' . $printed);
		return $answer;
	}

	private function logLines()
	{
		$log = @file($this->base . '/errors.log', FILE_IGNORE_NEW_LINES);
		return $log === false ? array() : array_map(function ($line) {
			return preg_replace('/^\[[^\]]*\] /', '', $line);
		}, $log);
	}

	// What the noty() call naming $property shows, evaluated as JavaScript
	// would: its text argument must be a '+' chain of string literals and
	// $property, which stands in as '<name>'. Null when it is not such a
	// chain, as when a quote inside a literal ends it early.
	private static function notyText($script, $property)
	{
		$at = strpos($script, $property);
		$start = $at === false ? false : strrpos(substr($script, 0, $at), 'noty(');
		if ($start === false) return null;
		$length = strlen($script);
		$i = $start + 5;
		$text = '';
		while (true) {
			while ($i < $length && $script[$i] === ' ') $i++;
			if ($i >= $length) return null;
			$quote = $script[$i];
			if ($quote === '"' || $quote === "'") {
				$end = $i + 1;
				while ($end < $length && $script[$end] !== $quote && $script[$end] !== "\n")
					$end += $script[$end] === '\\' ? 2 : 1;
				if ($end >= $length || $script[$end] !== $quote) return null;
				$body = substr($script, $i + 1, $end - $i - 1);
				if ($quote === '"') {
					// A JSON string is a JavaScript string literal.
					$value = json_decode('"' . $body . '"');
					if (!is_string($value)) return null;
				} else {
					$value = '';
					for ($k = 0; $k < strlen($body); $k++) {
						if ($body[$k] !== '\\') {
							$value .= $body[$k];
							continue;
						}
						$k++;
						if ($k >= strlen($body) || strpos('\\\'"', $body[$k]) === false) return null;
						$value .= $body[$k];
					}
				}
				$text .= $value;
				$i = $end + 1;
			} elseif (substr($script, $i, strlen($property)) === $property) {
				$text .= '<' . substr($property, strrpos($property, '.') + 1) . '>';
				$i += strlen($property);
			} else return null;
			while ($i < $length && $script[$i] === ' ') $i++;
			if ($i >= $length) return null;
			if ($script[$i] === ',') return $text;
			if ($script[$i] !== '+') return null;
			$i++;
		}
	}

	// init.php names an unusable session directory in a notice. The path is
	// the daemon's bytes (session.path), and pasted into a single-quoted
	// literal an apostrophe ended the literal and a trailing backslash escaped
	// its closing quote: the whole getplugins script, every plugin's init,
	// failed to parse (AUD-ESC section 4). It is a JSON string literal now,
	// which keeps every byte; the path here holds an apostrophe, double
	// quotes and a trailing backslash.
	public function testInitNamesAnUnusableSessionPathInAStringLiteral()
	{
		$session = $this->base . '/no "such" it\'s\\';
		@mkdir($this->base . '/fin', 0700);
		$answer = $this->drive(new FakeRtorrentMethodReplies(array()),
			'require_once("./autotools.php");' . "\n"
			. '$at = new rAutoTools(); $at->enable_move = 1; $at->fileop_type = "Copy";'
			. ' $at->path_to_finished = ' . var_export($this->base . '/fin', true) . '; $at->store();' . "\n"
			. '$jResult = ""; $plugin = array("name" => "autotools"); $pInfo = array("perms" => 0);'
			. ' $do_diagnostic = true; $theSettings = rTorrentSettings::get();' . "\n"
			. 'require("./init.php");' . "\n"
			. 'echo json_encode(array("script" => $jResult));',
			array('session' => $session));
		$script = isset($answer['script']) ? $answer['script'] : '';
		$this->assertTrue(strpos($script, 'plugin.disable();') !== false, 'the plugin is disabled: ' . $script);
		$this->assertSame('autotools: <webBadSessionWarning> (' . $session . ').',
			self::notyText($script, 'theUILang.webBadSessionWarning'),
			'the notice is a well-formed string expression that shows the session path byte for byte: ' . $script);
		$this->assertSame(false, strpos($script, "it's"), 'no part of the path is pasted into a quoted literal');
	}

	// Runs plugins/autotools/label.php as the insert hook does once it is in
	// the background (`php label.php --daemon <hash> <user>`), with AutoLabel
	// enabled for $template in the user's profile, against a daemon that
	// answers by method name: rTorrent 0.16.25 whose default directory is
	// <base>/dl, and a multi-file download in <base>/dl/Rock "n" Roll\x/Album,
	// plus $replies. Returns the label it set, as the d.set_custom1 value sent.
	public function testAutoLabelPreservesUnknownDirectoryBytesWithoutCharsetGuessing()
	{
		$directory = $this->base.'/dl/raw'."\xFF".'/Album';
		$label = $this->autoLabel('{DIR}', array('d.get_directory'=>array($directory), 'd.directory'=>array($directory)));
		$this->assertSame('raw%FF', $label, 'the insert hook stores the original directory bytes as custom1');
	}

	private function autoLabel($template, array $replies)
	{
		$root = $this->base . '/dl';
		$directory = $root . '/' . self::LABEL_DIR . '/Album';
		$answers = array();
		foreach (FakeRtorrentAddTorrentReplies::STARTUP as $method => $value)
			$answers[$method] = array($value);
		$answers = array_merge($answers, array('system.client_version' => array('0.16.25'),
			'system.api_version' => array(11), 'get_directory' => array($root), 'directory.default' => array($root),
			'd.get_directory' => array($directory), 'd.directory' => array($directory),
			'd.get_custom3' => array(''), 'd.custom3' => array(''), 'd.is_multi_file' => array(1)), $replies);
		$this->daemon = new FakeRtorrentDaemon(new FakeRtorrentMethodReplies($answers), $this->base . '/calls.log');
		$plugin = $this->repoRoot() . '/plugins/autotools';
		$setup = $this->base . '/label-settings.php';
		file_put_contents($setup, "<?php\n" . '$_SERVER[\'REMOTE_USER\'] = "tester";' . "\n"
			. 'require_once("./autotools.php");' . "\n"
			. '$at = new rAutoTools(); $at->enable_label = 1; $at->label_template = '
				. var_export($template, true) . ";\n"
			. 'rTorrentSettings::get()->store(); exit($at->store() ? 0 : 1);' . "\n");
		$this->assertSame(0, $this->runPhp(array($setup), $plugin), 'AutoLabel is enabled in the profile');
		// No SysV semaphore is left behind by the test: label.php locks only
		// when sem_get() exists.
		$status = $this->runPhp(array('-d', 'disable_functions=sem_get', 'label.php', '--daemon', self::HASH, 'tester'),
			$plugin);
		$this->assertSame(0, $status, 'label.php runs to its end: ' . @file_get_contents($this->base . '/child.out'));
		$labels = array();
		foreach ($this->daemon->bodies() as $body)
			if (preg_match('~<string>d\.(?:custom1\.set|set_custom1)</string>.*?<value><string>' . self::HASH
				. '</string></value>\s*<value><string>([^<]*)</string></value>~s', $body, $match))
				$labels[] = $match[1];
		$this->assertSame(1, count($labels), 'one label is set: ' . json_encode($this->daemon->calls()));
		return count($labels) === 1 ? $labels[0] : null;
	}

	// One PHP child with the environment conf/config.php reads, from $cwd,
	// without a shell; its output is kept for a failure message.
	private function runPhp(array $arguments, $cwd)
	{
		$environment = array('PATH' => (string) getenv('PATH'), 'TMPDIR' => sys_get_temp_dir(),
			'RU_SCGI_HOST' => '127.0.0.1', 'RU_SCGI_PORT' => (string) $this->daemon->port(),
			'RU_PROFILE_PATH' => $this->base . '/profile', 'RU_LOG_FILE' => $this->base . '/errors.log');
		$output = array('file', $this->base . '/child.out', 'a');
		$process = proc_open(array_merge(array(PHP_BINARY), $arguments),
			array(0 => array('file', '/dev/null', 'r'), 1 => $output, 2 => $output), $pipes, $cwd, $environment);
		if (!is_resource($process))
			throw new Exception('cannot start ' . implode(' ', $arguments));
		return proc_close($process);
	}

	// AUD-ESC-07: the automatic label {DIR}, also the template an empty one
	// stands for, is the download's folder relative to the default directory,
	// read with d.get_directory. The parser hands out the daemon's bytes, so
	// the label is the folder's name. While it escaped them the label here
	// became 'Rock \"n\" Roll\\x', custom1 'Rock%20%5C%22n%5C%22%20Roll%5C%5Cx'
	// (probe10 measured that shape on 0.16.25): "add label" then sent a Copy
	// or Link to another folder and automove_filter was matched against that
	// spelling.
	public function testAutoLabelIsTheFolderAsTheDaemonHoldsIt()
	{
		$label = $this->autoLabel('', array());
		$this->assertSame(rawurlencode(self::LABEL_DIR), $label,
			'the label is the folder name, URL-encoded as every label is: ' . var_export($label, true));
	}

	// The same folder with the domain of the first enabled HTTP tracker in
	// group 0, read with t.multicall by types (strings and integers apart).
	public function testAutoLabelJoinsTheFolderAndTheFirstTracker()
	{
		$label = $this->autoLabel('{DIR}/{TRACKER}', array('t.multicall' => array(1, 1, 0,
			'http://bt.tracker.example.org:2710/announce')));
		$this->assertSame(rawurlencode(self::LABEL_DIR . '/tracker.example.org'), $label,
			'the label is the folder and the tracker domain: ' . var_export($label, true));
	}

	// R4 fresh-journal gap: a fresh instance has no .autotools-file-jobs until
	// the first recovery pass, and a finished hook in a session directory that
	// turned unwritable could not even record its deferral. While the journal
	// is missing, setHandlers() has the daemon run one pass at once, as the
	// daemon's user.
}
