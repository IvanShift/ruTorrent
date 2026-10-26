<?php

require_once(__DIR__ . '/TestCase.php');

/**
 * utf8_encode(), utf8_decode(), strptime(), strftime(), gmstrftime(),
 * xml_set_object(), libxml_disable_entity_loader() and, from PHP 8.5,
 * SplObjectStorage::contains() and attach() are deprecated in the PHP
 * versions ruTorrent runs on, and each call writes a deprecation to the
 * log. Each code path that used one runs here in its own PHP process with
 * every error reported, so that a deprecation shows up in its output, and
 * portable outputs are compared with the deprecated function, silenced.
 * Platform-dependent date extensions use explicit contract examples instead.
 */
class DeprecatedFunctionReplacementTest extends TestCase
{
	private $root = null;

	public function setUp()
	{
		$this->root = realpath(__DIR__ . '/../..');
	}

	/**
	 * Run $code after the given sources are loaded, with every error shown,
	 * and return [what the code returned, everything PHP reported]. $options
	 * go on the php command line, before the script.
	 */
	private function run_isolated($code, $requires, $options = '')
	{
		$prelude = "<?php\nerror_reporting(E_ALL);\n"
			. "chdir(" . var_export($this->root . '/php', true) . ");\n";
		foreach($requires as $file)
			$prelude .= 'require_once(' . var_export($this->root . '/' . $file, true) . ");\n";
		$script = tempnam(sys_get_temp_dir(), 'deprecated-');
		file_put_contents($script, $prelude . "\$result = json_encode(call_user_func(function() {\n"
			. $code . "\n}));\nfwrite(STDOUT, \$result);\n");
		$command = escapeshellarg(PHP_BINARY) . ' ' . $options
			. ' -d error_reporting=-1 -d display_errors=stderr -d log_errors=0 ' . escapeshellarg($script)
			. ' > ' . escapeshellarg($script . '.out') . ' 2> ' . escapeshellarg($script . '.err');
		shell_exec($command);
		$result = json_decode((string)@file_get_contents($script . '.out'), true);
		$other = (string)@file_get_contents($script . '.err');
		foreach(array($script, $script . '.out', $script . '.err') as $file)
			@unlink($file);
		return array($result, $other);
	}

	private function brief($output)
	{
		$output = trim($output);
		return (strlen($output) > 400) ? substr($output, 0, 400) . '...' : $output;
	}

	private function assertQuiet($other, $what)
	{
		$this->assertEquals('', trim($other), $what . ' runs without any notice, warning or deprecation: '
			. $this->brief($other));
	}

	// ---- extsearch: utf8_encode() / utf8_decode() --------------------------

	public function testLatin1HelpersMatchUtf8EncodeAndDecode()
	{
		// commonEngine falls back to these when neither iconv nor mbstring is
		// loaded. They are compared directly, because whether either
		// extension can be left out depends on how PHP was built.
		list($result, $other) = $this->run_isolated('
			$samples = array("", "plain", "caf\xE9", "\xFF\x80\x00\xA0", "caf\xC3\xA9", "\xE2\x82\xAC 5",
				"\xF0\x9F\x98\x80", "\xC3", "\xE2\x82", "\xED\xA0\x80", "\xC0\xAF", "&amp; <b>", "\xC2\xA9\xC3\xBF\xC4\x80");
			mt_srand(3);
			for($i = 0; $i < 3000; $i++)
			{
				$s = "";
				for($j = mt_rand(0, 10); $j > 0; $j--)
					$s .= chr(mt_rand(0, 255));
				$samples[] = $s;
			}
			$same = 0;
			$differ = array();
			foreach($samples as $s)
			{
				$ok = (UTF::latin1ToUtf8($s) === @utf8_encode($s)) &&
					(UTF::utf8ToLatin1($s) === @utf8_decode($s));
				if($ok)
					$same++;
				else
					$differ[] = bin2hex($s);
			}
			return array("same" => $same, "differ" => array_slice($differ, 0, 5));
		', array('php/utility/utf.php'));
		$this->assertTrue(is_array($result), 'the ISO-8859-1 helpers ran: ' . $this->brief($other));
		if(!is_array($result))
			return;
		$this->assertEquals(3013, $result['same'], 'latin1ToUtf8/utf8ToLatin1 give what utf8_encode/utf8_decode gave, for '
			. $result['same'] . ' of 3013 strings; first to differ: ' . implode(', ', $result['differ']));
		$this->assertQuiet($other, 'the ISO-8859-1 helpers');
	}

	// ---- extsearch: PirateBay's strptime() -------------------------------

	public function testPirateBayUploadedTimeAcceptsCompleteListingDates()
	{
		list($result, $other) = $this->run_isolated('
			date_default_timezone_set("UTC");
			$year = date("Y");
			$samples = array(
				"05-12 12:34" => $year . "-05-12 12:34:00",
				"12-31 23:59" => $year . "-12-31 23:59:00",
				"01-01 00:00" => $year . "-01-01 00:00:00",
				"5-3 7:05" => $year . "-05-03 07:05:00",
				"05-12  12:34" => $year . "-05-12 12:34:00",
				"05-12 \t12:34" => $year . "-05-12 12:34:00",
				" 05-12 10:00 " => $year . "-05-12 10:00:00",
				"05-12 1:2" => $year . "-05-12 01:02:00",
				"05-12 2021" => "2021-05-12 00:00:00",
				"12-31 1999" => "1999-12-31 00:00:00",
				"02-29 2024" => "2024-02-29 00:00:00",
				"1-1 1" => "0001-01-01 00:00:00",
				"01-01 0069" => "0069-01-01 00:00:00",
				"01-01 0100" => "0100-01-01 00:00:00");
			$differ = array();
			foreach($samples as $input => $expected)
			{
				$stamp = PirateBayEngine::uploadedTime($input);
				$actual = ($stamp === false) ? false : date("Y-m-d H:i:s", $stamp);
				if($actual !== $expected)
					$differ[] = $input . " => " . var_export($actual, true);
			}
			return $differ;
		', array('plugins/extsearch/engines.php', 'plugins/extsearch/engines/PirateBay.php'));
		$this->assertEquals(array(), $result, 'complete listing dates preserve their calendar fields: ' . json_encode($result));
		$this->assertQuiet($other, 'PirateBay date parsing');
	}

	public function testPirateBayUploadedTimeKeepsTheLegacyOccurrenceInADstFold()
	{
		list($result, $other) = $this->run_isolated('
			$year = (int)date("Y");
			$checked = 0;
			$differ = array();
			foreach(array("America/New_York", "Australia/Lord_Howe") as $zone)
			{
				date_default_timezone_set($zone);
				$tz = new DateTimeZone($zone);
				$changes = $tz->getTransitions(gmmktime(0, 0, 0, 1, 1, $year), gmmktime(0, 0, 0, 1, 1, $year + 1));
				$previous = $changes[0]["offset"];
				foreach($changes as $change)
				{
					$gap = $previous - $change["offset"];
					$previous = $change["offset"];
					if($gap <= 0)
						continue;
					// At the backward transition, this wall time occurs at
					// both ts-gap and ts. Derive the valid UTC candidates from
					// TZDB rather than the parser or mktime(). Retain the old
					// mktime choice between them, which differs on PHP 7.4.
					$at = new DateTime("@" . $change["ts"]);
					$at->setTimezone($tz);
					$input = $at->format("m-d H:i");
					$actual = PirateBayEngine::uploadedTime($input);
					$legacy = mktime((int)$at->format("G"), (int)$at->format("i"), 0,
						(int)$at->format("n"), (int)$at->format("j"), $year);
					$valid = array($change["ts"] - $gap, $change["ts"]);
					if(($actual !== $legacy) || !in_array($actual, $valid, true))
						$differ[] = $zone . " " . $input . " => " . var_export($actual, true) . " vs " . $legacy;
					$checked++;
				}
			}
			return array("checked" => $checked, "differ" => $differ);
		', array('plugins/extsearch/engines.php', 'plugins/extsearch/engines/PirateBay.php'));
		$this->assertTrue(is_array($result) && ($result['checked'] > 0), 'the listing parser is exercised in real DST folds');
		$this->assertEquals(array(), is_array($result) ? $result['differ'] : $result,
			'a repeated wall time keeps the legacy UTC occurrence: ' . json_encode($result));
		$this->assertQuiet($other, 'PirateBay date parsing in DST folds');
	}

	public function testPirateBayUploadedTimeRejectsPartialAndInvalidDates()
	{
		list($result, $other) = $this->run_isolated('
			date_default_timezone_set("UTC");
			$accepted = array();
			foreach(array("05-12 12:34 extra", "05-12 12:345", "05-12 12:65", "05-12 24:00",
				"13-01 10:00", "00-01 10:00", "02-30 10:00", "04-31 10:00", "05-00 10:00",
				"05- 12 10:00", "051-2 10:00", "05-12 10 :00", "01-01 99999", "05-12 20211",
				"05-12", "05-12 ", "garbage", "", "02-29 2023", "10-10 2010x", "01-01 0000",
				"2024-05-12", "2 hours ago") as $input)
				if(PirateBayEngine::uploadedTime($input) !== false)
					$accepted[] = $input;
			return $accepted;
		', array('plugins/extsearch/engines.php', 'plugins/extsearch/engines/PirateBay.php'));
		$this->assertEquals(array(), $result, 'a partial or impossible date cannot become a different upload time: ' . json_encode($result));
		$this->assertQuiet($other, 'invalid PirateBay date parsing');
	}

	public function testPirateBayUploadedTimeRetainsRelativeListingDates()
	{
		list($result, $other) = $this->run_isolated('
			date_default_timezone_set("UTC");
			$before = time();
			$today = PirateBayEngine::uploadedTime("Today 12:34");
			$yesterday = PirateBayEngine::uploadedTime("Y-day 08:15");
			$ago = PirateBayEngine::uploadedTime("2 mins ago");
			$after = time();
			return array(
				in_array(date("Y-m-d H:i", $today), array(date("Y-m-d", $before) . " 12:34", date("Y-m-d", $after) . " 12:34"), true),
				in_array(date("Y-m-d H:i", $yesterday), array(date("Y-m-d", $before - 86400) . " 08:15", date("Y-m-d", $after - 86400) . " 08:15"), true),
				($ago >= $before - 120) && ($ago <= $after - 120));
		', array('plugins/extsearch/engines.php', 'plugins/extsearch/engines/PirateBay.php'));
		$this->assertEquals(array(true, true, true), $result, 'today, yesterday and minute offsets remain listing dates');
		$this->assertQuiet($other, 'relative PirateBay date parsing');
	}

	// ---- strftime() / gmstrftime() ---------------------------------------

	public function testTimeFormatMatchesPortableStrftimeConversions()
	{
		list($result, $other) = $this->run_isolated('
			// POSIX conversions and their defined E/O variants are portable.
			// GNU flags, arbitrary widths, and undefined modifier/conversion
			// pairs differ between libc implementations; literals below pin
			// the extensions supported by operator autotools label formats.
			// %Z may legally be empty in libc; the explicit zone contract is
			// checked below, including GMT for the feeds consumer.
			$specs = array();
			foreach(str_split("aAbBcCdDeFgGhHIjmMnpRrStTuUVwWxXyYz%") as $conv)
				$specs[] = "%" . $conv;
			foreach(array("Ec", "EC", "Ex", "EX", "Ey", "EY", "Od", "Oe", "OH", "OI", "Om", "OM", "OS", "Ou", "OU", "OV", "Ow", "OW", "Oy") as $conv)
				$specs[] = "%" . $conv;
			$times = array(0, 951782400, 1009843199, 1704067199, 1735689600, 1767225599, 1790000000);
			$zones = array("UTC", "Europe/Madrid", "America/New_York", "Asia/Kolkata", "Australia/Lord_Howe");
			$checked = 0;
			$differ = array();
			foreach($zones as $zone)
			{
				date_default_timezone_set($zone);
				foreach($times as $t)
					foreach($specs as $spec)
					{

						$fmt = "<" . $spec . ">";
						$pairs = array(array(@gmstrftime($fmt, $t), TimeFormat::gmstrftime($fmt, $t)),
							array(@strftime($fmt, $t), TimeFormat::strftime($fmt, $t)));
						foreach($pairs as $pair)
						{
							$checked++;
							if($pair[0] !== $pair[1])
								$differ[] = $zone . " " . $t . " " . $fmt . " " . json_encode($pair);
						}
					}
			}
			// %s is the timestamp whatever zone PHP formats in and whatever
			// zone the process runs in.
			$tz = getenv("TZ");
			foreach(array("UTC", "Europe/Moscow", "America/New_York") as $process)
			{
				putenv("TZ=" . $process);
				foreach($zones as $zone)
				{
					date_default_timezone_set($zone);
					foreach($times as $t)
						foreach(array("<%s>", "<%Es>", "<%Os>") as $fmt)
						{
							$pair = array(TimeFormat::strftime($fmt, $t), TimeFormat::gmstrftime($fmt, $t));
							$checked++;
							if($pair !== array("<" . $t . ">", "<" . $t . ">"))
								$differ[] = "TZ=" . $process . " " . $zone . " " . $t . " " . $fmt . " " . json_encode($pair);
						}
				}
			}
			putenv(($tz === false) ? "TZ" : "TZ=" . $tz);
			// Flags and widths on %s as glibc 2.43 renders them under TZ=UTC,
			// the one process zone where its %s is the timestamp (captured
			// 2026-10-17): a space is the default pad, "0" pads with zeros,
			// "-" keeps an explicit width, and E and O are ignored.
			$padded = array(
				0 => array("%6s" => "     0", "%06s" => "000000", "%-6s" => "     0", "%_6s" => "     0",
					"%^6s" => "     0", "%#6s" => "     0", "%-^6s" => "     0", "%6Es" => "     0", "%06Os" => "000000"),
				1790000000 => array("%s" => "1790000000", "%1s" => "1790000000", "%6s" => "1790000000",
					"%12s" => "  1790000000", "%012s" => "001790000000", "%-12s" => "  1790000000"));
			foreach($padded as $t => $expected)
				foreach($expected as $spec => $text)
				{
					$pair = array(TimeFormat::strftime($spec, $t), TimeFormat::gmstrftime($spec, $t));
					$checked++;
					if($pair !== array($text, $text))
						$differ[] = $t . " " . $spec . " " . json_encode($pair) . " is not " . json_encode($text);
				}
			date_default_timezone_set("Europe/Madrid");
			$now = time();
			$current = TimeFormat::strftime("%Y-%m-%d %H:%M");
			if(($current !== @strftime("%Y-%m-%d %H:%M", $now)) && ($current !== @strftime("%Y-%m-%d %H:%M")))
				$differ[] = "no timestamp means now";
			return array("checked" => $checked, "differ" => array_slice($differ, 0, 10));
		', array('php/util.php'));
		$this->assertTrue(is_array($result), 'TimeFormat ran: ' . $this->brief($other));
		if(!is_array($result))
			return;
		$this->assertEquals(array(), $result['differ'], 'TimeFormat renders ' . $result['checked']
			. ' portable formats as strftime/gmstrftime did and %s as the timestamp; differ: ' . implode('; ', $result['differ']));
		$this->assertQuiet($other, 'TimeFormat');
	}

	public function testTimeFormatExtensionsHaveTheSameMeaningOnEveryLibc()
	{
		list($result, $other) = $this->run_isolated('
			date_default_timezone_set("UTC");
			$formats = array(
				"%c" => "Thu Jan  1 00:00:00 1970", "%k|%l|%P" => " 0|12|am",
				"%-d|%_d|%0e" => "1| 1|01", "%6d|%-6d|%06e" => "000001|     1|000001",
				"%^a|%#B|%#p|%#P|%^P" => "THU|JANUARY|am|am|am",
				"%6a|%06a|%_6a" => "   Thu|000Thu|   Thu",
				"%^c|%06R|%12F" => "THU JAN  1 00:00:00 1970|000:00|  1970-01-01",
				"%Ea|%Oa|%Od|%Ec|%OY" => "%Ea|%Oa|01|Thu Jan  1 00:00:00 1970|%OY",
				"%q|%^q|%6q|%06q|%" => "%q|%^Q|   %6q|00%06q|%",
				"%n%t%%" => "\n\t%", "%s|%Es|%Os" => "0|0|0",
				"%z|%Z" => "+0000|GMT");
			$differ = array();
			foreach($formats as $format => $expected)
			{
				$actual = TimeFormat::gmstrftime($format, 0);
				if($actual !== $expected)
					$differ[] = $format . " => " . json_encode($actual) . " vs " . json_encode($expected);
			}
			return $differ;
		', array('php/util.php'));
		$this->assertEquals(array(), $result, 'operator date formats retain portable flags, widths and literal directives: ' . json_encode($result));
		$this->assertQuiet($other, 'TimeFormat extension formatting');
	}

	public function testFeedsPubDateFormatIsRfc822()
	{
		list($result, $other) = $this->run_isolated('
			date_default_timezone_set("America/New_York");
			return TimeFormat::gmstrftime("%a, %d %b %Y %T %Z", 1767225599);
		', array('php/util.php'));
		$this->assertEquals('Wed, 31 Dec 2025 23:59:59 GMT', $result, 'the feeds pubDate is RFC 822 in GMT');
		$this->assertQuiet($other, 'the feeds pubDate format');
	}

	// ---- xmpp: xml_set_object() ------------------------------------------

	public function testXmppStreamParsesWithoutXmlSetObject()
	{
		list($result, $other) = $this->run_isolated('
			$probe = new class extends XMPPHP_XMLStream
			{
				public function feed($xml)
				{
					xml_parse($this->parser, $xml, false);
					$names = array();
					foreach($this->xmlobj as $depth => $obj)
						$names[$depth] = $obj->name . "@" . $obj->ns;
					return array("depth" => $this->xml_depth, "names" => $names);
				}
			};
			return $probe->feed("<stream:stream xmlns=\"jabber:client\" xmlns:stream=\"http://etherx.jabber.org/streams\">"
				. "<message to=\"a@b\"><body>hi");
		', array('plugins/xmpp/XMPPHP/XMLStream.php'));
		$this->assertEquals(array('depth' => 3, 'names' => array(1 => 'stream@http://etherx.jabber.org/streams',
			2 => 'message@jabber:client', 3 => 'body@jabber:client')), $result, 'the stream parser still reaches its own handlers: '
			. json_encode($result) . ' ' . $this->brief($other));
		$this->assertTrue(strpos($other, 'Deprecated') === false,
			'setting up the stream parser raises no deprecation: ' . $this->brief($other));
	}

	// ---- cache: SplObjectStorage::contains() / attach() -------------------

	public function testCacheWalkOverObjectsRaisesNoDeprecation()
	{
		// rCache::get() walks every value it unserializes and remembers the
		// objects it has met, so an object met twice is the path that both
		// asks whether it was seen and records that it was. PHP 8.5
		// deprecates SplObjectStorage::contains() and attach() in favour of
		// offsetExists() and offsetSet(), which exist since PHP 5.3.
		list($result, $other) = $this->run_isolated('
			// Bound to rCache rather than a subclass of it, because
			// constructing an rCache creates the settings directory.
			$walk = Closure::bind(function($value)
			{
				return rCache::holdsRefusedClass($value, 0, $reason);
			}, null, "rCache");
			$first = new stdClass();
			$second = new stdClass();
			$first->peer = $second;
			$second->peer = $first;
			return $walk(array($first, $second));
		', array('php/cache.php'));
		$this->assertTrue($result === false, 'a cycle between two objects is walked to the end, not refused: '
			. json_encode($result) . ' ' . $this->brief($other));
		$this->assertQuiet($other, 'walking objects for a refused class');
	}

	// ---- extsearch: libxml_disable_entity_loader() -----------------------

	public function testHtmlEnginesDoNotCallTheEntityLoaderSwitch()
	{
		foreach(array('mTeam', 'IPTorrents') as $engine)
		{
			list($result, $other) = $this->run_isolated('
				$method = new ReflectionMethod("' . $engine . 'Engine", "disableEntityLoader");
				if(PHP_VERSION_ID < 80100)
					$method->setAccessible(true);
				$method->invoke(null);
				return true;
			', array('plugins/extsearch/engines.php', 'plugins/extsearch/engines/' . $engine . '.php'));
			$this->assertTrue($result === true, $engine . ' ran: ' . $this->brief($other));
			$this->assertQuiet($other, $engine . '::disableEntityLoader()');
		}
	}

	// ---- nothing else in the tree calls them -----------------------------

	public function testNoShippedCodeCallsTheDeprecatedFunctions()
	{
		$deprecated = array('utf8_encode', 'utf8_decode', 'strptime', 'strftime', 'gmstrftime', 'xml_set_object');
		$found = array();
		foreach(array('/php', '/plugins', '/conf') as $sub)
		{
			$walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
				$this->root . $sub, FilesystemIterator::SKIP_DOTS));
			foreach($walk as $file)
			{
				if(substr($file->getFilename(), -4) !== '.php')
					continue;
				$tokens = token_get_all(file_get_contents($file->getPathname()));
				foreach($tokens as $i => $token)
				{
					if(!is_array($token) || ($token[0] !== T_STRING) ||
						!in_array(strtolower($token[1]), $deprecated))
						continue;
					$prev = $i - 1;
					while(($prev >= 0) && is_array($tokens[$prev]) && ($tokens[$prev][0] === T_WHITESPACE))
						$prev--;
					$next = $i + 1;
					while(isset($tokens[$next]) && is_array($tokens[$next]) && ($tokens[$next][0] === T_WHITESPACE))
						$next++;
					// A call, not a method or a declaration of the same name.
					$method = ($prev >= 0) && is_array($tokens[$prev]) &&
						in_array($tokens[$prev][0], array(T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION));
					if(!$method && isset($tokens[$next]) && ($tokens[$next] === '('))
						$found[] = substr($file->getPathname(), strlen($this->root) + 1) . ':' . $token[2]
							. ' ' . $token[1];
				}
			}
		}
		$this->assertEquals(array(), $found, 'no shipped code calls a deprecated date, encoding or XML function: '
			. implode(', ', $found));
	}
}
