<?php

require_once(__DIR__.'/DisplayNamesFixture.php');

class ListTitlesTest extends TestCase
{
	use HttprpcDisplayTestFixture;

	public function testListAddsVerifiedTitlesWithoutChangingExistingFields()
	{
		list($hash, $legacy) = $this->legacy();
		$plain = str_repeat('B', 40);
		$this->start(array($hash=>$legacy,
			$plain=>array('name'=>'映画', 'label'=>'Movies', 'base'=>'/data/映画', 'rate'=>'0', 'files'=>array())));
		$list = $this->post('mode=list');
		$this->assertSame(array($hash=>'Серия'), $list['n'], 'the title comes from verified metadata');
		// Fork: the legacy label and base path also get the "l" and "p" maps (TorrentTitlesTest).
		$this->assertSame(json_decode(json_encode($legacy['name'], JSON_INVALID_UTF8_SUBSTITUTE)), $list['t'][$hash][4], 'the original name cell keeps the existing JSON encoding');
		$this->assertSame('lab%FF', $list['t'][$hash][14], 'the existing label is unchanged');
		$this->assertSame('映画', $list['t'][$plain][4], 'UTF-8 name is unchanged');
		$this->assertSame('Movies', $list['t'][$plain][14], 'UTF-8 label is unchanged');
		$this->assertSame(array('d.multicall', 'get_session', 'd.multicall'), $this->daemon->calls(), 'all aliases share one source-path request');
	}

	public function testUtf8NamesAddNoMapsAndNoRequests()
	{
		$this->start(array(str_repeat('C', 40)=>array('name'=>'Plain', 'label'=>'', 'base'=>'/data/Plain', 'rate'=>'0', 'files'=>array())));
		$list = $this->post('mode=list');
		$this->assertSame(false, isset($list['n']) || isset($list['l']) || isset($list['p']), 'UTF-8 values need no side maps');
		$this->assertSame(array('d.multicall'), $this->daemon->calls(), 'the list stays one daemon request');
	}

	public function testUnreadableSourceKeepsVisibleEscapes()
	{
		list($hash, $legacy) = $this->legacy();
		unlink($this->base.'/session/'.$hash.'.torrent');
		$this->start(array($hash=>$legacy));
		$list = $this->post('mode=list');
		$this->assertSame(array($hash=>'Serie \\xF1\\xE5\\xF0\\xE8\\xFF'), $list['n'], 'without metadata the name shows its bytes');
		$this->assertSame(1, count($list['t']), 'missing metadata never drops the torrent');
	}

	public function testDeltaWithoutNameChangesReadsNoSource()
	{
		list($hash, $legacy) = $this->legacy();
		$this->start(array($hash=>$legacy));
		$first = $this->post('mode=list');
		$second = $this->post('mode=list&cid='.$first['cid']);
		$this->assertSame(false, isset($second['n']) || isset($second['p']) || isset($second['l']),
			'an unchanged torrent repeats no side map');
		$this->assertSame(array('d.multicall', 'get_session', 'd.multicall', 'd.multicall'), $this->daemon->calls(),
			'an unchanged name reads no source again');
	}



	public function testOneSourceRequestServesEveryLegacyTitle()
	{
		list($first, $one) = $this->legacy();
		list($second, $two) = $this->legacy('', "Folge \xFE", 'Folge');
		$this->start(array($first=>$one, $second=>$two));
		$list = $this->post('mode=list');
		$this->assertSame(array($first=>'Серия', $second=>'Folge'), $list['n'], 'each legacy torrent gets its own verified title');
		$this->assertSame(array('d.multicall', 'get_session', 'd.multicall'), $this->daemon->calls(),
			'all titles share one source path request');
	}

	public function testTiedFileIsTheFallbackSource()
	{
		list($hash, $legacy) = $this->legacy();
		rename($this->base.'/session/'.$hash.'.torrent', $this->base.'/tied.torrent');
		$legacy['tied'] = $this->base.'/tied.torrent';
		$this->start(array($hash=>$legacy));
		$this->assertSame(array($hash=>'Серия'), $this->post('mode=list')['n'], 'without a session copy the tied file supplies the title');
	}

	public function testMalformedSourcePathsKeepVisibleBytes()
	{
		list($hash, $legacy) = $this->legacy();
		$this->start(array($hash=>$legacy), true);
		$list = $this->post('mode=list');
		$this->assertSame(array($hash=>'Serie \\xF1\\xE5\\xF0\\xE8\\xFF'), $list['n'], 'an unpaired source path reply is not trusted');
		$this->assertSame(1, count($list['t']), 'and the list itself is unaffected');
	}

	public function testQuotesAndBackslashesInLegacyBytesKeepTheTitleAndKey()
	{
		// \\ and \" pairs: unescaping the daemon's bytes again would change the name.
		$name = "Serie \xFF \"q\" \\b \\\\ \\\"";
		list($hash, $legacy) = $this->legacy('', $name, 'Серия "q" \\b');
		foreach(array(false, true) as $raw)
		{
			$this->start(array($hash=>$legacy), false, '', $raw);
			$list = $this->post('mode=list');
			$this->assertSame(array($hash=>'Серия "q" \\b'), $list['n'],
				'a name holding quotes and backslash pairs still matches its metadata ('.($raw ? 'raw <string>' : '<base64>').')');
			$this->server->close(); $this->server = null;
			$this->daemon->stop(); $this->daemon = null;
		}
	}


}
