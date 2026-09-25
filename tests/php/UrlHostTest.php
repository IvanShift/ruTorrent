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
