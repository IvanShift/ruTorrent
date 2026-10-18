<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/../../php/utility/urlhost.php');

/**
 * The one host test, held to the cases that made it necessary.
 *
 * Every plugin that decides an identity from a URL -- whose cookies go out,
 * whose verdict is written, whose announce row this is -- goes through
 * UrlHost, so this is where the traps are pinned once: a host mentioned in
 * the path or query, in the userinfo, as a leading label of a longer domain;
 * an authority whose host has colons of its own; and the two spellings of
 * the same host that whoever writes the URL picks.
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
		foreach (array(
			'http://tracker.example/ann?pk=x'                    => 'tracker.example',
			'https://tracker.example/forum/viewtopic.php?t=1'    => 'tracker.example',
			'https://evil.test/x/tracker.example/forum/dl.php'   => 'evil.test',               // the name is in the path
			'https://evil.test/?ref=tracker.example'             => 'evil.test',               // in the query
			'https://tracker.example@evil.test/forum/'           => 'evil.test',               // in the userinfo
			'https://tracker.example.evil.test/forum/'           => 'tracker.example.evil.test', // a leading label of another domain
			'not a url'                                          => null,
			''                                                   => null,
		) as $url => $expected) {
			$this->assertTrue(UrlHost::of($url) === $expected,
				$url . ' has the host ' . json_encode($expected));
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
		$this->assertTrue(UrlHost::isExactlyOneOf('tracker.example.', array('tracker.example')),
			'the root dot names the same host in an exact list');
		$this->assertTrue(UrlHost::isExactlyOneOf('Tracker.EXAMPLE', array('tracker.example')),
			'DNS names have no case in an exact list');
		$this->assertTrue(!UrlHost::isExactlyOneOf('', array('tracker.example')), 'an empty host is nobody in an exact list');
		$this->assertTrue(!UrlHost::isExactlyOneOf('tracker.example', array('')),
			'an empty candidate matches nobody in an exact list');
	}

	public function testAnExactListAdmitsNoSubdomain()
	{
		$hosts = array('bt.nnmclub.to', 'Kinozal.Cloudns.NZ.');
		$this->assertTrue(UrlHost::isExactlyOneOf('BT.nnmclub.to.', $hosts), 'case and the root dot are folded');
		$this->assertTrue(UrlHost::isExactlyOneOf('kinozal.cloudns.nz', $hosts), 'on both sides');
		$this->assertTrue(!UrlHost::isExactlyOneOf('dl.kinozal.cloudns.nz', $hosts), 'a subdomain is another host');
		$this->assertTrue(!UrlHost::isExactlyOneOf('nnmclub.to', $hosts), 'and so is the parent');
		$this->assertTrue(!UrlHost::isExactlyOneOf('tracker.example.evil.test', array('tracker.example')),
			'a leading label of a longer domain is another host');
		$this->assertTrue(!UrlHost::isExactlyOneOf('', array('')), 'an empty host is nobody');
		$this->assertTrue(!UrlHost::isExactlyOneOf(null, $hosts), 'a missing host is nobody');
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
		$this->assertTrue(!UrlHost::isExactlyOneOf('täst.example', array('xn--tst-qla.example')),
			'nor in an exact list');
		$this->assertEquals('[2001:db8::1]', UrlHost::of('https://[2001:db8::1]:8443/x'),
			'parse_url retains IPv6 brackets in the host');
		$this->assertTrue(UrlHost::isOneOf('[2001:db8::1]', array('[2001:db8::1]')),
			'a bracketed IPv6 authority can match a declared host');
		$this->assertTrue(UrlHost::isExactlyOneOf('[2001:db8::1]', array('[2001:db8::1]')),
			'and an exactly declared one');
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

	public function testAnAuthorityIsReadAsAHostAndAPort()
	{
		// What Requests reads a Host header with. The old reading,
		// strtok($host, ':'), stopped at the first colon: right for a name or
		// an IPv4 address, wrong for an IPv6 literal.
		$this->assertEquals('backend', UrlHost::of('http://backend:8080'), 'a name with a port');
		$this->assertEquals('192.0.2.7', UrlHost::of('http://192.0.2.7:8080'), 'an IPv4 address with a port');
		$this->assertEquals('[::1]', UrlHost::of('http://[::1]:8080'), 'an IPv6 literal with a port');
		$this->assertEquals('[fd00::5]', UrlHost::of('http://[fd00::5]'), 'and without one');
		$this->assertTrue(UrlHost::isExactlyOneOf(UrlHost::of('http://[FD00::5]:8080/x'), array('[fd00::5]')),
			'an IPv6 literal is compared without regard to case');
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

	/**
	 * php/util.php resolves a class by including utility/<lowercase>.php
	 * relative to php/, so a helper that ships anywhere else can be named
	 * only by a file that requires it by path. This one shipped in php/
	 * while its docblock invited plugins to take it, and a plugin that did
	 * -- naming the class and requiring nothing -- got a fatal.
	 *
	 * Read from a child PHP: this file requires urlhost.php by path for the
	 * cases above, so in this process the class is loaded whatever the
	 * autoloader could or could not have done.
	 */
	public function testUrlHostShipsWhereTheAutoloaderResolvesClasses()
	{
		$php = dirname(dirname(__DIR__)) . '/php';
		$code = 'require ' . var_export($php . '/util.php', true) . ';'
			. 'echo realpath((new ReflectionClass("UrlHost"))->getFileName()), "\n";'
			. 'echo realpath((new ReflectionClass("Math"))->getFileName()), "\n";';
		$process = proc_open(
			array(PHP_BINARY, '-c', __DIR__ . '/../php-test.ini', '-d', 'display_errors=stderr', '-r', $code),
			array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
		if (!is_resource($process)) {
			$this->assertTrue(false, 'child PHP started');
			return;
		}
		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[2]);
		proc_close($process);
		$lines = explode("\n", trim($output));

		$this->assertTrue($errors === '', 'the bootstrap writes no diagnostics: ' . $errors);
		// Nothing requires math.php, so the autoloader is the only thing that
		// can have found Math: this is the directory it looks in.
		$this->assertTrue(isset($lines[1]) && $lines[1] === realpath($php . '/utility/math.php'),
			'the autoloader resolves classes from php/utility: ' . var_export($lines, true));
		$this->assertTrue(isset($lines[0]) && $lines[0] === realpath($php . '/utility/urlhost.php'),
			'and UrlHost ships in that directory, so naming it is enough: ' . var_export($lines, true));
	}
}
