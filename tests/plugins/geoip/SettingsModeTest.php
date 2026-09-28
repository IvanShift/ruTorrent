<?php

require_once(__DIR__.'/../../php/TestCase.php');

// util.php caches the profile path while it loads, so select the isolated root first.
define('GEOIP_SETTINGS_TEST_ROOT', sys_get_temp_dir().'/geoip-settings-mode-'.getmypid());
@mkdir(GEOIP_SETTINGS_TEST_ROOT, 0700);
$_ENV['RU_PROFILE_PATH'] = GEOIP_SETTINGS_TEST_ROOT;

// The shipped Docker image replaces geoip, and some host PHP builds lack SQLite3.
// The directory mode is observed before database I/O; this stub keeps the test local.
if(!class_exists('SQLite3'))
{
	class SQLite3
	{
		public function __construct($path) {}
		public function exec($sql) { return(true); }
		public function close() {}
	}
}
require_once(__DIR__.'/../../../plugins/geoip/ip_db.php');

class GeoIpSettingsModeTest extends TestCase
{
	private $root;

	public function setUp()
	{
		global $profilePath, $profileMask, $forbidUserSettings;
		$this->root = GEOIP_SETTINGS_TEST_ROOT;
		@mkdir($this->root, 0700);
		$profilePath = $this->root;
		$profileMask = 0777;
		$forbidUserSettings = true;
	}

	public function tearDown()
	{
		@unlink($this->root.'/settings/peers3.dat');
		@rmdir($this->root.'/settings');
		@rmdir($this->root);
	}

	public function testExistingSettingsKeepsStickyAfterGeoIpDatabaseOpen()
	{
		$settings = $this->root.'/settings';
		mkdir($settings, 01777);
		chmod($settings, 01777);
		$db = new ipDB();
		clearstatcache(true, $settings);
		$this->assertEquals(01777, fileperms($settings) & 07777,
			'geoip must not reopen a protected settings directory');
		unset($db);
	}

	public function testMissingSettingsIsStillCreatedForGeoIpDatabase()
	{
		$settings = $this->root.'/settings';
		$db = new ipDB();
		$this->assertTrue(is_dir($settings),
			'geoip must still create a missing settings directory');
		unset($db);
	}
}
