<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/../../php/urlhost.php');

/**
 * The one host test, held to the cases that made it necessary.
 *
 * Every plugin that decides an identity from a URL -- whose cookies go out,
 * whose verdict is written, whose announce row this is -- goes through
 * UrlHost, so this is where the traps are pinned once: a host mentioned in
 * the path or query, in the userinfo, as a leading label of a longer domain;
 * and the two spellings of the same host that whoever writes the URL picks.
 */
class UrlHostTest extends TestCase
{
	public function testAHostIsTakenOutOfTheUrlNeverSearchedForInIt()
	{
		$hosts = array('t-ru.org', 'rutracker.org');
		foreach (array(
			'http://bt.t-ru.org/ann?pk=x'                    => true,
			'https://rutracker.org/forum/viewtopic.php?t=1'  => true,
			'https://evil.test/x/rutracker.org/forum/dl.php' => false,   // the name is in the path
			'https://evil.test/?ref=rutracker.org'           => false,   // in the query
			'https://rutracker.org@evil.test/forum/'         => false,   // in the userinfo
			'https://rutracker.org.evil.test/forum/'         => false,   // a leading label of another domain
			'https://evil-rutracker.org/'                    => false,   // a longer label
			'https://myrutracker.org/'                       => false,   // merely ends in the name
			'not a url'                                      => false,
			''                                               => false,
		) as $url => $expected) {
			$this->assertEquals($expected, UrlHost::urlIsOneOf($url, $hosts),
				$url . ($expected ? ' is one of ours' : ' is not one of ours'));
		}
	}

	public function testTwoSpellingsOfTheSameHostAreOne()
	{
		$this->assertEquals('bt.t-ru.org', UrlHost::normalize('BT.T-RU.ORG.'), 'case and the root dot are folded');
		$this->assertEquals('bt.t-ru.org', UrlHost::of('http://BT.T-RU.ORG./ann'), 'and folded when read out of a URL');
		$this->assertTrue(UrlHost::isOneOf('bt3.t-ru.org.', array('t-ru.org')), 'the root dot names the same host');
		$this->assertTrue(UrlHost::isOneOf('Rutracker.ORG', array('rutracker.org')), 'DNS names have no case');
		$this->assertTrue(UrlHost::urlIsOneOf('https://nnm-club.me./forum/', array('nnm-club.me')),
			'a URL written with the root dot reaches the same account');
		$this->assertTrue(UrlHost::of('/forum/viewtopic.php?t=1') === null, 'a URL without a host has none');
		$this->assertTrue(UrlHost::of('http://.../x') === null, 'a normalized empty host is null');
		$this->assertTrue(UrlHost::normalize(array('not', 'a', 'string')) === '', 'a non-string folds to nothing');
		$this->assertTrue(!UrlHost::isOneOf('', array('t-ru.org')), 'an empty host is nobody');
		$this->assertTrue(!UrlHost::isOneOf('t-ru.org', array('')), 'an empty candidate matches nobody');
	}

	public function testTheSchemeMayNotBeWeakenedAndThePathMayBeRequired()
	{
		$hosts = array('tracker.example');
		$this->assertTrue(UrlHost::urlIsOneOf('https://tracker.example/x', $hosts, 'https'), 'https site, https URL');
		$this->assertTrue(!UrlHost::urlIsOneOf('http://tracker.example/x', $hosts, 'https'),
			'an https site is not matched over http: the cookies would go out in clear');
		$this->assertTrue(UrlHost::urlIsOneOf('https://tracker.example/x', $hosts, 'http'),
			'an http site is matched over https: nothing is weakened');
		$this->assertTrue(UrlHost::urlIsOneOf('http://tracker.example/x', $hosts, 'HTTP'), 'the scheme has no case');
		$this->assertTrue(UrlHost::urlIsOneOf('https://tracker.example/forum/dl.php', $hosts, null, '/forum/'),
			'the path prefix is honoured');
		$this->assertTrue(!UrlHost::urlIsOneOf('https://tracker.example/other/dl.php', $hosts, null, '/forum/'),
			'and a URL outside it is refused');
		$this->assertTrue(UrlHost::urlIsOneOf('https://tracker.example', $hosts, null, '/'),
			'a URL with no path is at /');
	}
	public function testParsedAuthorityEdgesAreStableAcrossPhpVersions()
	{
		// UrlHost compares the parsed host literally; it does not convert IDNs.
		$this->assertEquals('xn--tst-qla.example', UrlHost::of('https://xn--tst-qla.example/x'),
			'an IDN A-label remains an A-label');
		$this->assertEquals('täst.example', UrlHost::of('https://täst.example/x'),
			'a Unicode host is not silently converted to punycode');
		$this->assertTrue(!UrlHost::isOneOf('täst.example', array('xn--tst-qla.example')),
			'different IDN spellings do not gain identity without an explicit converter');
		$this->assertEquals('[2001:db8::1]', UrlHost::of('https://[2001:db8::1]:8443/x'),
			'parse_url retains IPv6 brackets in the host');
		$this->assertTrue(UrlHost::isOneOf('[2001:db8::1]', array('[2001:db8::1]')),
			'a bracketed IPv6 authority can match a declared host');
		$this->assertTrue(UrlHost::of('tracker.example/x') === null,
			'a bare name and path are not a URL authority');
		$this->assertEquals('tracker.example', UrlHost::of('//tracker.example/x'),
			'parse_url exposes the host in a scheme-relative reference');
		$this->assertTrue(!UrlHost::urlIsOneOf('//tracker.example/x', array('tracker.example'), 'https'),
			'an account requiring HTTPS does not accept a scheme-relative reference');
		$this->assertEquals('tracker.example', UrlHost::of('https://user@evil@tracker.example:443/x'),
			'the last at-sign separates userinfo from the actual host');
		$this->assertTrue(!UrlHost::urlIsOneOf('https://tracker.example@evil.test/x',
			array('tracker.example'), 'https'), 'a trusted name in userinfo is not a trusted host');
	}

	public function testHostPredicatesIgnorePortWhileOriginPredicatesCompareIt()
	{
		$url = 'https://tracker.example:8443/download';
		$this->assertEquals('tracker.example', UrlHost::of($url), 'of returns the host without its port');
		$this->assertTrue(UrlHost::isOneOf(UrlHost::of($url), array('tracker.example')),
			'isOneOf compares host identity only');
		$this->assertTrue(UrlHost::urlIsOneOf($url, array('tracker.example'), 'https'),
			'urlIsOneOf does not impose a port policy');
		$this->assertTrue(!UrlHost::sameOrigin($url, 'https://tracker.example/download'),
			'sameOrigin does compare the effective port');
	}

	public function testSameOriginRequiresTheSameSchemeHostAndEffectivePort()
	{
		foreach(array(
			array('https://tracker.example/a', 'https://TRACKER.EXAMPLE.:443/b', true),
			array('https://tracker.example/a', 'https://evil.test/b', false),
			array('https://tracker.example/a', 'https://tracker.example:8443/b', false),
			array('http://tracker.example/a', 'https://tracker.example/b', false),
			array('https://tracker.example/a', 'https://tracker.example@evil.test/b', false),
			array('https://tracker.example/a', 'https://evil.test/path/tracker.example', false),
		) as $case)
			$this->assertEquals($case[2], UrlHost::sameOrigin($case[0], $case[1]),
				$case[0] . ' versus ' . $case[1]);
	}

	public function testHttpsUpgradeRequiresDefaultPortsAndTheSameHost()
	{
		foreach(array(
			array('http://tracker.example/a', 'https://TRACKER.EXAMPLE.:443/b', true),
			array('http://tracker.example:80/a', 'https://tracker.example/b', true),
			array('http://tracker.example/a', 'https://evil.test/b', false),
			array('http://tracker.example/a', 'https://tracker.example@evil.test/b', false),
			array('http://tracker.example:8080/a', 'https://tracker.example/b', false),
			array('http://tracker.example/a', 'https://tracker.example:8443/b', false),
			array('https://tracker.example/a', 'http://tracker.example/b', false),
		) as $case)
			$this->assertEquals($case[2], UrlHost::isHttpsUpgrade($case[0], $case[1]),
				$case[0] . ' to ' . $case[1]);
	}

}
