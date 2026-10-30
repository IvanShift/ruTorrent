<?php

require_once(__DIR__.'/../../php/TestCase.php');
require_once(__DIR__.'/../../php/FakeRtorrentDaemon.php');
require_once(__DIR__.'/../../php/HttprpcListFixture.php');
require_once(__DIR__.'/../../php/TestHttpServer.php');

function displayNamesBencode($value)
{
	if(is_int($value))
		return 'i'.$value.'e';
	if(is_string($value))
		return strlen($value).':'.$value;
	if(array_keys($value) === range(0, count($value) - 1))
		return 'l'.implode('', array_map('displayNamesBencode', $value)).'e';
	ksort($value, SORT_STRING);
	$out = 'd';
	foreach($value as $key=>$item)
		$out .= displayNamesBencode((string)$key).displayNamesBencode($item);
	return $out.'e';
}

/** Answers like rTorrent; HttprpcListFixture writes a name that is not UTF-8 as <base64>, as the fork's daemon does. */
class HttprpcDisplayReplies
{
	private $session;
	private $phases;
	private $torrents;
	private $lists = 0;
	private $brokenPaths;
	private $raw;
	/**
	 * Each list request answers with the next phase; the last one repeats.
	 * $raw writes text that is not UTF-8 inside <string>, as rakshasa's rtorrent does.
	 */
	public function __construct($session, $phases, $brokenPaths = false, $raw = false)
	{
		$this->session = $session;
		$this->phases = $phases;
		$this->torrents = $phases[0];
		$this->brokenPaths = $brokenPaths;
		$this->raw = $raw;
	}
	public function __invoke($request, $methods)
	{
		$session = count(array_intersect(array('get_session', 'session.path'), $methods)) > 0;
		// Fork: httprpc checks the daemon version before it runs a cmd= column.
		if($methods === array('system.client_version'))
			return HttprpcListFixture::raw('<?xml version="1.0"?><methodResponse><params><param><value><string>0.16.27</string></value></param></params></methodResponse>');
		if($methods === array('d.multicall'))
		{
			$this->torrents = $this->phases[min($this->lists++, count($this->phases) - 1)];
			$rows = array();
			foreach($this->torrents as $hash=>$torrent)
			{
				$row = array_fill(0, 36, '0');
				$row[0] = $hash;
				$row[5] = $torrent['name'];
				$row[15] = $torrent['label'];
				$row[26] = $torrent['base'];
				$row[12] = $torrent['rate'];
				$rows[] = $row;
			}
			return HttprpcListFixture::raw(HttprpcListFixture::body(HttprpcListFixture::typed($rows, HttprpcListFixture::LIST_INTEGERS), $this->raw));
		}
		if($session && in_array('d.multicall', $methods, true))
		{
			$paths = array();
			foreach($this->torrents as $hash=>$torrent)
				$paths[] = array($hash, $torrent['tied'] ?? '');
			if($this->brokenPaths)
				$paths[] = array('unpaired');
			return HttprpcListFixture::raw(HttprpcListFixture::body(array(array($this->session), array($paths)), $this->raw));
		}
		if($session)
		{
			$torrent = reset($this->torrents);
			return HttprpcListFixture::raw(HttprpcListFixture::body(array(array($this->session), array($torrent['tied'] ?? '')), $this->raw));
		}
		if($methods === array('f.multicall'))
		{
			$torrent = reset($this->torrents);
			$rows = array();
			foreach($torrent['files'] as $file)
				$rows[] = array_merge(array($file[0], '1', '1', (string)$file[1], '1'), $torrent['extra'] ?? array());
			return HttprpcListFixture::raw(HttprpcListFixture::body(HttprpcListFixture::typed($rows, HttprpcListFixture::FILE_INTEGERS), $this->raw));
		}
		if(in_array('d.open', $methods, true))
		{
			$torrent = reset($this->torrents);
			return HttprpcListFixture::raw(HttprpcListFixture::body(array(array('0'), array($torrent['base']),
				array('0'), array($torrent['name']))));
		}
		return FakeRtorrentDaemon::fault('unexpected request: '.implode(',', $methods));
	}
}

trait HttprpcDisplayTestFixture
{
	private $base;
	private $daemon;
	private $server;

	public function setUp()
	{
		$this->base = sys_get_temp_dir().'/rt-legacy-names-'.getmypid().'-'.bin2hex(random_bytes(4));
		mkdir($this->base, 0700);
		chmod($this->base, 0700);
		mkdir($this->base.'/session', 0700);
		chmod($this->base.'/session', 0700);
	}
	public function tearDown()
	{
		if($this->server !== null) $this->server->close();
		if($this->daemon !== null) $this->daemon->stop();
		TestTree::remove($this->base);
	}

	/** A metainfo file in the session directory; returns its info hash. */
	private function torrent($info)
	{
		$info['piece length'] = 16384;
		$info['pieces'] = str_repeat("\0", 20);
		$hash = strtoupper(sha1(displayNamesBencode($info)));
		file_put_contents($this->base.'/session/'.$hash.'.torrent', displayNamesBencode(array('info'=>$info)));
		return $hash;
	}

	private function start($torrents, $brokenPaths = false, $routerCode = '', $raw = false)
	{
		$this->serve(new HttprpcDisplayReplies($this->base.'/session/',
			isset($torrents[0]) ? $torrents : array($torrents), $brokenPaths, $raw), array(__DIR__.'/DisplayNamesFixture.php'), $routerCode);
	}

	// The real plugins/httprpc/action.php behind a test HTTP server, with
	// $replies answering for the daemon; $classFiles define its class.
	private function serve($replies, $classFiles, $routerCode = '')
	{
		$root = realpath(__DIR__.'/../../..');
		$this->daemon = new FakeRtorrentDaemon($replies, $this->base.'/calls.log', false, $classFiles);
		$router = $this->base.'/router.php';
		file_put_contents($router, '<?php '.$routerCode.' $_ENV["RU_PROFILE_PATH"]='.var_export($this->base.'/profile', true).';'
			.'$_ENV["RU_LOG_FILE"]='.var_export($this->base.'/app.log', true).';'
			.'require '.var_export($root.'/php/settings.php', true).';'
			.'$r = new ReflectionClass("rTorrentSettings"); $s = $r->newInstanceWithoutConstructor();'
			.'$s->iVersion=0x101a; $s->aliases=array(); $s->directory="/"; $s->linkExist=true;'
			.'$p=$r->getProperty("theSettings"); if(PHP_VERSION_ID<80100) $p->setAccessible(true); $p->setValue(null,$s);'
			.'$topDirectory="/"; $rpcLogCalls=false; $scgi_host="127.0.0.1"; $scgi_port='.$this->daemon->port().';'
			.'chdir('.var_export($root.'/plugins/httprpc', true).'); require "action.php";');
		$this->server = TestHttpServer::start($this->base,
			array('RU_PROFILE_PATH'=>$this->base.'/profile', 'RU_LOG_FILE'=>$this->base.'/app.log'), array(), $router);
	}

	private function post($body)
	{
		$value = json_decode($this->postRaw($body), true);
		$this->assertTrue(is_array($value), 'httprpc answers a JSON value');
		return $value;
	}

	// The exact body httprpc answered.
	private function postRaw($body)
	{
		$response = TestHttpServer::rawPost($this->server->port(), '/', $body, 'application/x-www-form-urlencoded');
		$this->assertTrue(strpos($response['status'], '200') !== false, 'httprpc answers 200: '.$response['body']);
		return $response['body'];
	}

	private function legacy($label = 'lab%FF', $name = "Serie \xF1\xE5\xF0\xE8\xFF", $alias = 'Серия')
	{
		$hash = $this->torrent(array('name'=>$name, 'name.utf-8'=>$alias, 'length'=>100));
		return array($hash, array('name'=>$name, 'label'=>$label, 'base'=>'/data/'.$name, 'rate'=>'0',
			'files'=>array(array($name, 100))));
	}

}
