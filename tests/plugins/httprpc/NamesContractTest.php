<?php

require_once(__DIR__.'/DisplayNamesFixture.php');

/**
 * Answers captured on 2026-10-30 from rutorrent-native 12420fec (rtorrent
 * 0.16.26, the image ivanshift/rutorrent:latest) to the requests the real
 * httprpc sent for three torrents, then edited for the later list states.
 * The session directory and the tied files move into the case's directory.
 */
class NamesContractReplies
{
	const A = '117C4C3C9EC4FA840B8C6B9E4DD35E4BC0FE45F3';
	const B = '1AD8A6F063FF8DF6C928CA8DF939334D76F54662';

	private $root;
	private $lists = 0;

	public function __construct($root)
	{
		$this->root = $root;
	}

	public static function captured($name)
	{
		return file_get_contents(__DIR__.'/../../php/fixtures/names-contract-rtorrent-0.16.26-native-12420fec-'.$name.'.xml');
	}

	public function __invoke($request, $methods)
	{
		$session = count(array_intersect(array('get_session', 'session.path'), $methods)) > 0;
		if($methods === array('system.client_version'))
			$xml = self::captured('version');
		else if($methods === array('d.multicall'))
			$xml = self::listPhase(self::captured('list'), $this->lists++);
		else if($session && in_array('d.multicall', $methods, true))
			$xml = self::captured('sourcepaths');
		else if($session)
			$xml = self::captured('source');
		else if($methods === array('f.multicall'))
			$xml = self::captured('fls');
		else
			return FakeRtorrentDaemon::fault('unexpected request: '.implode(',', $methods));
		return HttprpcListFixture::raw(str_replace(array('/data/.session/', '/tmp/contract-'),
			array($this->root.'/session/', $this->root.'/missing/contract-'), $xml));
	}

	/**
	 * The captured list, then each later state on top of the one before:
	 * A downloads at 42 B/s, then A is renamed "Renamed", then B is gone.
	 */
	public static function listPhase($xml, $phase)
	{
		if($phase >= 1)
			$xml = self::editRow($xml, self::A, function($cells) { $cells[13] = '<value><i8>42</i8></value>'; return $cells; });
		if($phase >= 2)
			$xml = self::editRow($xml, self::A, function($cells) { $cells[5] = '<value><string>Renamed</string></value>'; return $cells; });
		if($phase >= 3)
			$xml = self::editRow($xml, self::B, function($cells) { return null; });
		return $xml;
	}

	// $edit gets the row's cells, hash first, and returns them, or null to drop the row.
	private static function editRow($xml, $hash, $edit)
	{
		$xml = preg_replace_callback('#<value><array><data>(<value><string>'.$hash.'</string></value>.*?)</data></array></value>#s',
			function($row) use($edit) {
				preg_match_all('#<value>(?:<(i8|string|base64)>[^<]*</\1>|<string/>)</value>#', $row[1], $cells);
				$cells = $edit($cells[0]);
				return ($cells === null) ? '' : '<value><array><data>'.implode('', $cells).'</data></array></value>';
			}, $xml, 1, $count);
		if($count !== 1)
			throw new RuntimeException('the captured list has no row '.$hash);
		return $xml;
	}
}

/**
 * The answers the real plugins/httprpc/action.php gives for captured daemon
 * replies, pinned as the golden JSON tests/js/names-contract.spec.js feeds
 * to the WebUI: a full list, a delta, a rename and a deletion, then a file
 * list with names=1 whose two cp1251 directories have names of equal length.
 * NAMES_CONTRACT_WRITE=1 rewrites tests/js/fixtures/names-contract/ from
 * this run instead of comparing with it.
 */
class NamesContractTest extends TestCase
{
	use HttprpcDisplayTestFixture;

	const C = '52512685729C47241B550B1E583DF5F854AEF34F';
	const PRIMER = "\xCF\xF0\xE8\xEC\xE5\xF0";
	const PRIVET = "\xCF\xF0\xE8\xE2\xE5\xF2";

	// The three torrents' metainfo, as the session copies the daemon keeps.
	private function sessionCopies()
	{
		$infos = array(
			NamesContractReplies::A => array('name'=>self::PRIMER, 'name.utf-8'=>'Пример', 'piece length'=>16384,
				'pieces'=>str_repeat("\x0A", 20), 'private'=>1, 'files'=>array(
					array('length'=>1, 'path'=>array(self::PRIMER, 'a.mkv'), 'path.utf-8'=>array('Пример', 'a.mkv')),
					array('length'=>2, 'path'=>array(self::PRIVET, 'a.mkv'), 'path.utf-8'=>array('Привет', 'a.mkv')),
					array('length'=>3, 'path'=>array('plain', 'b.mkv')))),
			NamesContractReplies::B => array('name'=>self::PRIVET, 'name.utf-8'=>'Привет', 'length'=>4, 'piece length'=>16384,
				'pieces'=>str_repeat("\x0B", 20), 'private'=>1),
			self::C => array('name'=>'Plain', 'length'=>5, 'piece length'=>16384, 'pieces'=>str_repeat("\x0C", 20), 'private'=>1),
		);
		foreach($infos as $hash=>$info)
		{
			$this->assertSame($hash, strtoupper(sha1(displayNamesBencode($info))), 'the session copy is the captured torrent');
			file_put_contents($this->base.'/session/'.$hash.'.torrent', displayNamesBencode(array('info'=>$info)));
		}
	}

	public function testGoldenAnswersForTheWebUiComeFromCapturedReplies()
	{
		$a = NamesContractReplies::A;
		$b = NamesContractReplies::B;
		$this->sessionCopies();
		$this->serve(new NamesContractReplies($this->base), array(__FILE__));
		$wire = str_repeat("\u{FFFD}", 6);

		$requests = array('list-full' => 'mode=list');
		$bodies = array('list-full' => $this->postRaw($requests['list-full']));
		$full = json_decode($bodies['list-full'], true);
		$this->assertSame(array($a, $b, self::C), array_keys($full['t']), 'the full list has every captured torrent');
		$this->assertSame(array($wire, $wire, 'Plain'), array_column($full['t'], 4), 'column 4 is the daemon name as JSON carries it');
		$this->assertSame(array($a=>'Пример', $b=>'Привет'), $full['n'], 'the legacy titles get their labels beside it');
		$this->assertSame(1, $full['full'], 'and it is a full answer');

		foreach(array('list-delta', 'list-rename', 'list-delete') as $step)
		{
			$requests[$step] = 'mode=list&cid='.json_decode(end($bodies), true)['cid'];
			$bodies[$step] = $this->postRaw($requests[$step]);
		}
		$delta = json_decode($bodies['list-delta'], true);
		$this->assertSame(array($a=>array(12=>'42')), $delta['t'], 'a rate change sends that cell alone');
		$this->assertSame(false, isset($delta['n']), 'and no label: the name did not change');
		$rename = json_decode($bodies['list-rename'], true);
		$this->assertSame(array($a=>array(4=>'Renamed')), $rename['t'], 'a new UTF-8 name is sent');
		$this->assertSame(false, isset($rename['n']), 'with no label');
		$delete = json_decode($bodies['list-delete'], true);
		$this->assertSame(array($b), $delete['d'], 'a removed torrent is a deletion');

		$requests['fls-names'] = 'mode=fls&hash='.$a.'&cmd=f.prioritize_first%3D&cmd=f.prioritize_last%3D&names=1';
		$bodies['fls-names'] = $this->postRaw($requests['fls-names']);
		$files = json_decode($bodies['fls-names'], true);
		$this->assertSame(array($wire.'/a.mkv', $wire.'/a.mkv', 'plain/b.mkv'), array_column($files, 0), 'the paths are the daemon paths');
		$this->assertSame(array(8, 8, 7), array_map('count', $files), 'seven requested columns, then a label cell on a legacy row only');
		$this->assertSame(array('displayName'=>'Пример/a.mkv', 'pathKey'=>'%CF%F0%E8%EC%E5%F0/a.mkv'), $files[0][7], 'the first directory\'s label and key');
		$this->assertSame(array('displayName'=>'Привет/a.mkv', 'pathKey'=>'%CF%F0%E8%E2%E5%F2/a.mkv'), $files[1][7], 'the second directory keeps its own key');
		$this->assertSame(array('d.multicall', 'get_session', 'd.multicall', 'd.multicall', 'd.multicall', 'd.multicall',
			'system.client_version', 'f.multicall', 'get_session', 'd.get_tied_to_file'), $this->daemon->calls(),
			'only the full list reads source paths; the file list reads its own: '.json_encode($this->daemon->calls()));

		$directory = __DIR__.'/../../js/fixtures/names-contract';
		// .editorconfig indents JSON by two spaces.
		$golden = array('requests.json' => preg_replace('/^    /m', '  ', json_encode($requests, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))."\n");
		foreach($bodies as $step=>$body)
			$golden[$step.'.json'] = $body."\n";
		if(getenv('NAMES_CONTRACT_WRITE') === '1')
		{
			@mkdir($directory, 0755, true);
			foreach($golden as $file=>$text)
				file_put_contents($directory.'/'.$file, $text);
		}
		foreach($golden as $file=>$text)
			$this->assertSame($text, @file_get_contents($directory.'/'.$file),
				$file.' is what action.php answered; NAMES_CONTRACT_WRITE=1 rewrites it');
	}
}
