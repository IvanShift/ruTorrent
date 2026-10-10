<?php

require_once(__DIR__ . '/../../php/TestCase.php');
define('RETRACKERS_IMPORT_ONLY', true);
require_once(__DIR__ . '/../../../plugins/retrackers/update.php');
require_once(__DIR__ . '/../../../php/Torrent.php');

class RetrackersTrackerDeduplicationTest extends TestCase
{
	private function source($announce, $list = null)
	{
		$b = function ($s) { return(strlen($s) . ':' . $s); };
		$raw = 'd8:announce' . $b($announce);
		if ($list !== null) {
			$raw .= '13:announce-listl';
			foreach ($list as $tier) {
				$raw .= 'l';
				foreach ($tier as $url) $raw .= $b($url);
				$raw .= 'e';
			}
			$raw .= 'e';
		}
		return($raw . '7:comment7:foreign4:infod4:name1:xee');
	}

	public function testCapturedLoaderWhitespaceDeduplicatesOnlyRequestedAdditions()
	{
		$url = 'http://retracker.local.invalid/announce';
		// REV2 c17 capture: the source contains a trailing space; runtime trims it.
		foreach (array('', ' ', "\t", "\r", "\n", "\v", "\f") as $padding) {
			foreach (array(false, true) as $announceOnly) {
			foreach (array(false, true) as $begin) {
				$padded = $padding . $url . $padding;
				$list = $announceOnly ? null : array(array('wss://foreign.invalid/a'), array($padded, $padded));
				$source = $this->source($padded, $list);
				$result = (new RetrackersTorrentProjector())->project($source, array(array($url)), array(), $begin);
				$this->assertTrue($result['ok'] === true && $result['changed'] === false &&
					$result['announce'] === $padded && $result['announce_list'] === $list,
					'loader-equivalent existing URLs suppress additions without rewriting original URLs, tiers or duplicates');
			}
			}
		}
	}

	public function testDedupeKeepsIndependentAdditionsAndExistingDeletionOrder()
	{
		$url = 'http://retracker.local.invalid/announce';
		$padded = $url . ' ';
		$other = 'udp://new.invalid:80/a';
		foreach (array(false, true) as $begin) {
			$result = (new RetrackersTorrentProjector())->project($this->source($padded),
				array(array($url, $other)), array(), $begin);
			$this->assertTrue($result['ok'] && $result['changed'] && $result['announce'] === $padded &&
				$result['announce_list'] === ($begin ? array(array($other), array($padded)) : array(array($padded), array($other))),
				'announce-only dedupe removes only the matching addition and preserves insertion order');
		}
		$result = (new RetrackersTorrentProjector())->project($this->source($padded, array(array($padded), array('http://delete.invalid/a'))),
			array(array($url)), array('delete.invalid'), false);
		$this->assertTrue($result['ok'] && $result['changed'] && $result['announce_list'] === array(array($padded)),
			'deletions still operate after additions while the preserved source URL remains raw');
	}

	public function testLiteralAndEmbeddedWhitespaceRemainDistinctAndForeignBytesSurvive()
	{
		$url = 'http://retracker.local.invalid/announce';
		$foreign = array(array($url . '%20'), array('http://retracker.local.invalid/an nounce'), array('wss://foreign.invalid/a'));
		$source = $this->source('http://pub.invalid/a', $foreign);
		foreach (array(false, true) as $begin) {
			$result = (new RetrackersTorrentProjector())->project($source, array(array($url)), array(), $begin);
			$this->assertTrue($result['ok'] === true && $result['changed'] === true &&
				$result['announce_list'] === ($begin ? array_merge(array(array($url)), $foreign) : array_merge($foreign, array(array($url)))),
				'percent-encoded and embedded whitespace stay literal, preserving addToBegin and foreign tiers');
		}
	}
}
