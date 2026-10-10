<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../../plugins/retrackers/retrackers.php');

class RetrackersSettingsStoreProbe extends rRetrackers
{
	public $stores = 0;
	public $stored = null;
	public $storeResult = true;

	public function store()
	{
		$this->stores++;
		if ($this->storeResult) $this->stored = serialize($this);
		return($this->storeResult);
	}
}

class RetrackersSettingsValidationTest extends TestCase
{
	private function settings()
	{
		$settings = new RetrackersSettingsStoreProbe();
		$settings->list = array(array('http://old.invalid/announce'));
		$settings->todelete = array('old-pattern');
		$settings->dontAddPrivate = 1;
		$settings->addToBegin = 1;
		return($settings);
	}

	public function testUnsupportedMixedAdditionNamesTierAndEntryWithoutStoring()
	{
		$settings = $this->settings();
		$before = serialize($settings);
		$failure = null;
		$accepted = $settings->set('dont_private=1&add_begin=0&tracker=http%3A%2F%2Fok.invalid%2Fa&tracker=&tracker=wss%3A%2F%2Fignored.invalid%2Fa', $failure);
		$this->assertTrue($accepted === false && $failure === 'tracker[2][1]: unsupported-tracker-scheme',
			'unsupported requested additions name their one-based tier and entry at save time');
		$this->assertTrue(serialize($settings) === $before && $settings->stores === 0,
			'an invalid mixed configuration leaves the previous settings and storage untouched');
	}

	public function testSettingsFlagsAndDeletionPatternsAreValidatedBeforeStorage()
	{
		$cases = array(
			'dont_private=2' => 'dont_private: invalid-boolean',
			'add_begin=true' => 'add_begin: invalid-boolean',
			'todelete=' => 'todelete[1]: empty-deletion-pattern',
			'tracker=junk' => 'tracker[1][1]: unsupported-tracker-scheme',
			'tracker=HTTP%3A%2F%2Fignored.invalid%2Fa' => 'tracker[1][1]: unsupported-tracker-scheme',
			'tracker=http%3A%2F%2Fx.invalid%2Fa%00b' => 'tracker[1][1]: invalid-tracker-characters',
		);
		foreach ($cases as $body => $expected) {
			$settings = $this->settings();
			$before = serialize($settings);
			$failure = null;
			$this->assertTrue($settings->set($body, $failure) === false && $failure === $expected &&
				serialize($settings) === $before && $settings->stores === 0,
				'typed configuration refusal leaves storage untouched: ' . $expected);
		}
	}

	public function testValidReplacementKeepsGroupsFlagsAndLiteralFormValues()
	{
		$settings = $this->settings();
		$failure = null;
		$this->assertTrue($settings->set('dont_private=1&add_begin=0&tracker=%20http%3A%2F%2Fx.invalid%2Fa%3Fkey%3D1%2B2%20&tracker=&tracker=udp%3A%2F%2Fu.invalid%3A80%2Fa&todelete=old%2Bpattern', $failure) === true && $failure === null,
			'a supported replacement is admitted before its one storage write');
		$this->assertTrue($settings->list === array(array('http://x.invalid/a?key=1+2'), array('udp://u.invalid:80/a')) &&
			$settings->todelete === array('old+pattern') && $settings->dontAddPrivate === 1 &&
			$settings->addToBegin === 0 && $settings->stores === 1,
			'ordered blank-line tiers, form literals and canonical integer flags retain the settings contract');
		$this->assertTrue($settings->set('tracker=http://x.invalid/a?key=1+2', $failure) === true &&
			$settings->list === array(array('http://x.invalid/a?key=1+2')),
			'the first equals sign separates the field while literal plus and subsequent equals stay in the URL');
		$this->assertTrue($settings->set('dont_private=1&dont_private=0&add_begin=0&add_begin=1&tracker=http%3A%2F%2Fx.invalid%2Fa', $failure) === true &&
			$settings->dontAddPrivate === 0 && $settings->addToBegin === 1,
			'ordered repeated valid flags retain their last assignment');
	}

	public function testFailedStorageIsNamedAndDoesNotPublishNewSettings()
	{
		$settings = $this->settings();
		$settings->storeResult = false;
		$before = $settings->list;
		$failure = null;
		$this->assertTrue($settings->set('tracker=http%3A%2F%2Fnew.invalid%2Fa', $failure) === false &&
			$failure === 'settings-store-failed' && $settings->list === $before,
			'a failed durable write cannot publish a new settings object or run lifecycle refresh');
	}

	public function testOmittedAddBeginRetainsThePriorSelection()
	{
		$settings = $this->settings();
		$failure = null;
		$this->assertTrue($settings->set('dont_private=1&tracker=http%3A%2F%2Fx.invalid%2Fa', $failure) === true &&
			$settings->addToBegin === 1,
			'omitting add_begin retains a previously selected insertion order');
		$this->assertTrue($settings->set('tracker=http%3A%2F%2Fx.invalid%2Fb', $failure) === true &&
			$settings->addToBegin === 1 && $settings->dontAddPrivate === 0,
			'a later replacement retains insertion order while the omitted private flag keeps its reset contract');
		$this->assertTrue($settings->set('add_begin=0&tracker=http%3A%2F%2Fx.invalid%2Fa', $failure) === true &&
			$settings->addToBegin === 0,
			'an explicit add_begin zero resets the prior selection');
	}
}
