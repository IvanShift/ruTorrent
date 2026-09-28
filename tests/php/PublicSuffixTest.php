<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/../../php/publicsuffix.php');

class PublicSuffixTest extends TestCase
{
	public function testIcannAndPrivateDomains()
	{
		foreach (array(
			'co.uk' => true,
			'example.co.uk' => false,
			'github.io' => true,
			'user.github.io' => false,
			'katcr.co' => false,
			'co' => true,
			'unknown-tld-for-this-test' => true,
		) as $domain => $expected)
			$this->assertSame($expected, PublicSuffix::isPublicSuffix($domain), $domain . ' uses the full PSL');
	}

	public function testWildcardAndExceptionRules()
	{
		foreach (array(
			'a.ck' => true,
			'b.a.ck' => false,
			'www.ck' => false,
			'b.www.ck' => false,
		) as $domain => $expected)
			$this->assertSame($expected, PublicSuffix::isPublicSuffix($domain), $domain . ' obeys wildcard/exception');
	}

	public function testUnsafeDomainsAreRejectedVisibly()
	{
		foreach (array('', '.co.uk', 'co.uk.', 'bad..example', '-bad.example', 'bad-.example',
			'bad/example', '127.0.0.1', '127.000.0.1', '0x7f.0.0.1',
			'127.0.0.0x1', '0177.0.0.1', '2130706433', '0x7f000001',
			'täst.example', 'xn--tst-qla.example') as $domain)
		{
			$threw = false;
			try { PublicSuffix::isPublicSuffix($domain); }
			catch (InvalidArgumentException $e) { $threw = true; }
			$this->assertTrue($threw, $domain . ' must be explicitly rejected');
		}
	}
}
