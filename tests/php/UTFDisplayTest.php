<?php

require_once(__DIR__.'/TestCase.php');
require_once(__DIR__.'/PhpProcess.php');
require_once(__DIR__.'/../../php/utility/utf.php');

class UTFDisplayTest extends TestCase
{
	public function testValidUnicodeRemainsByteIdentical()
	{
		foreach(array('', '0', '123', "ASCII \\xFF\t\r\n", "серия 中文 🙂", "\0\x7F",
			"\xC2\x80\xDF\xBF", "\xE0\xA0\x80\xED\x9F\xBF\xEF\xBF\xBF", "\xF0\x90\x80\x80\xF4\x8F\xBF\xBF") as $text)
		{
			$this->assertSame(true, UTF::isValid($text), 'strict UTF-8 is accepted');
			$this->assertSame($text, UTF::display($text), 'valid text, controls and literal backslashes remain unchanged');
		}
	}

	public function testUnknownBytesKeepValidFragmentsAndLogLines()
	{
		$raw = "中文/серия\t\xF1\xE5\xF0\xE8\xFF\r\n\\xFF\0\x7F🙂";
		$expected = "中文/серия\t\\xF1\\xE5\\xF0\\xE8\\xFF\r\n\\\\xFF\\x00\\x7F🙂";
		$this->assertSame($expected, UTF::display($raw), 'only invalid bytes and ambiguous controls are escaped in mixed text');
		$this->assertSame(1, preg_match('//u', UTF::display($raw)), 'the display projection is strict UTF-8');
	}

	public function testInvalidSequencesAndNonStringsAreRejected()
	{
		foreach(array("\x80", "\xC0\x80", "\xC1\xBF", "\xE0\x9F\xBF", "\xED\xA0\x80",
			"\xF0\x8F\xBF\xBF", "\xF4\x90\x80\x80", "\xF5\x80\x80\x80", "\xFF", "\xE2\x82", "\xC2A") as $text)
		{
			$this->assertSame(false, UTF::isValid($text), 'malformed, overlong, surrogate and out-of-range sequences are rejected');
			$this->assertSame(true, UTF::isInvalidUTF8($text), 'the existing validity API uses the same strict grammar');
			$this->assertSame(1, preg_match('//u', UTF::display($text)), 'each malformed input has a valid display projection');
		}
		foreach(array(null, false, 12, array(), new stdClass()) as $value)
			$this->assertSame(false, UTF::isValid($value), 'only strings can supply a Unicode label');
	}

	public function testEveryOneAndTwoByteSequenceAgreesWithPcre()
	{
		$mismatches = 0;
		for($first = 0; $first < 256; $first++)
		{
			$text = chr($first);
			if(UTF::isValid($text) !== (preg_match('//u', $text) === 1)) $mismatches++;
			for($second = 0; $second < 256; $second++)
			{
				$text = chr($first).chr($second);
				if(UTF::isValid($text) !== (preg_match('//u', $text) === 1)) $mismatches++;
			}
		}
		$this->assertSame(0, $mismatches, 'all 65792 one/two-byte strings match the independent UTF-8 decoder');
	}

	public function testLongSequenceBoundariesAgreeWithPcre()
	{
		$mismatches = 0;
		foreach(array(0xDF, 0xE0, 0xE1, 0xEC, 0xED, 0xEE, 0xEF, 0xF0, 0xF1, 0xF3, 0xF4, 0xF5) as $first)
			foreach(array(0x7F, 0x80, 0x8F, 0x90, 0x9F, 0xA0, 0xBF, 0xC0) as $second)
				foreach(array(0x7F, 0x80, 0xBF, 0xC0) as $last)
					foreach(array('', "\x80") as $fourth)
					{
						$text = chr($first).chr($second).chr($last).$fourth;
						if(UTF::isValid($text) !== (preg_match('//u', $text) === 1)) $mismatches++;
					}
		$this->assertSame(0, $mismatches, 'three/four-byte lower, upper, surrogate and truncation boundaries agree');
	}

	public function testDisplayDoesNotDependOnOptionalStringFunctions()
	{
		$code = 'require '.var_export(__DIR__.'/../../php/utility/utf.php', true).';'
			.'$text = "中文\\xFF\\n\\\\xFF";'
			.'if (UTF::display($text) !== "中文\\\\xFF\\n\\\\\\\\xFF") exit(1);'
			.'if (UTF::isValid("中文") !== true || UTF::isValid("\\x80") !== false) exit(2);';
		$disabled = 'preg_match,preg_replace_callback,ord,sprintf,chr,strlen,substr,bin2hex,iconv,mb_convert_encoding,function_exists';
		$result = PhpProcess::run(array('-n', '-d', 'disable_functions='.$disabled, '-r', $code));
		$this->assertSame(0, $result['code'], 'the byte display path works with conversion/formatting primitives disabled');
		$this->assertSame('', $result['out'].$result['err'], 'disabled primitives produce no hidden warnings');
	}

	public function testUnavailableTypePredicateUsesStrictFallbackWithoutBreakingDisplay()
	{
		foreach(array('is_string', 'gettype', 'is_string,gettype') as $disabled)
		{
			$expected = $disabled === 'is_string,gettype' ? 'false' : 'true';
			$code = 'require '.var_export(__DIR__.'/../../php/utility/utf.php', true).';'
				.'if (UTF::isString("中文") !== '.$expected.' || UTF::isValid("中文") !== '.$expected.') exit(1);'
				.'foreach ([null,false,12,1.5,[],new stdClass()] as $value)'
				.' if (UTF::isString($value) !== false || UTF::isValid($value) !== false) exit(2);'
				.'if (UTF::isValid("\\x80") !== false) exit(3);'
				.'if (UTF::display("中文\\xFF") !== "中文\\\\xFF") exit(4);';
			$result = PhpProcess::run(array('-n', '-d', 'disable_functions='.$disabled, '-r', $code));
			$this->assertSame(0, $result['code'], 'one working type predicate preserves labels; neither may accept nonstrings');
			$this->assertSame('', $result['out'].$result['err'], 'PHP 7 NULL and PHP 8 Error paths remain quiet');
		}
	}

	public function testMixedByteCorpusRetainsEachValidFragment()
	{
		$parts = array('я', '界', '🙂', 'A', '\\', "\n", "\t", "\xFF", "\xC0", "\xF4");
		$shown = array('я', '界', '🙂', 'A', '\\\\', "\n", "\t", '\\xFF', '\\xC0', '\\xF4');
		$seed = 1847;
		$mismatches = 0;
		for($case = 0; $case < 1000; $case++)
		{
			$raw = "\xFF";
			$expected = '\\xFF';
			for($part = 0; $part < 40; $part++)
			{
				$seed = ($seed * 16807) % 2147483647;
				$index = $seed % count($parts);
				$raw .= $parts[$index];
				$expected .= $shown[$index];
			}
			if(UTF::display($raw) !== $expected) $mismatches++;
		}
		$this->assertSame(0, $mismatches, 'one thousand deterministic mixed strings preserve Unicode, whitespace and escaped-byte identity');
	}
}
