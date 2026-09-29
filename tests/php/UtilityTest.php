<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/../../php/utility/utility.php');

class UtilityTest extends TestCase
{
	public function testOrderedFormPairsCanPreserveRawValuesAndBareFields()
	{
		$raw = 'rss=raw=A&rss=plus+tag&rss=encoded%2Btag&rss&rss=&url=x%3Dy&url=last';
		$this->assertEquals(array(
			array('rss', 'raw=A'), array('rss', 'plus+tag'),
			array('rss', 'encoded%2Btag'), array('rss'), array('rss', ''),
			array('url', 'x%3Dy'), array('url', 'last'),
		), iterator_to_array(Utility::legacyOrderedFormPairs($raw, false)),
			'raw pairs keep order, duplicates, a first-equals split and absent values');
		$this->assertEquals(array(array('rss', 'plus tag'), array('url', 'x=y')),
			iterator_to_array(Utility::legacyOrderedFormPairs('rss=plus+tag&url=x%3Dy')),
			'the default decoded parser keeps its existing contract');
	}

	public function testAcceptsDottedHostNames()
	{
		foreach (array('tracker.example.com', 'a.bc', 'my-tracker.example.co.uk', 'XYZ.Example.COM') as $name) {
			$this->assertEquals(true, Utility::isHostname($name), 'Accepted: '.json_encode($name));
		}
	}

	public function testRefusesWhatIsNotAHostName()
	{
		foreach (array(
			'', 'localhost', 'example.com/../x', 'example.com:8080', 'http://example.com',
			'exa mple.com', '-example.com', 'example-.com', 'example..com', '.example.com',
			'example.com.', "example.com\nHost: x", str_repeat('a.', 200).'com', null, 42,
		) as $name) {
			$this->assertEquals(false, Utility::isHostname($name), 'Refused: '.json_encode($name));
		}
	}
}
