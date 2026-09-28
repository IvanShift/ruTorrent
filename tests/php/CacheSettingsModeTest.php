<?php

require_once(__DIR__.'/TestCase.php');

define('CACHE_SETTINGS_MODE_ROOT', sys_get_temp_dir().'/cache-settings-mode-'.getmypid());
@mkdir(CACHE_SETTINGS_MODE_ROOT, 0700);
$_ENV['RU_PROFILE_PATH'] = CACHE_SETTINGS_MODE_ROOT;
require_once(__DIR__.'/../../php/cache.php');

class CacheSettingsModeTest extends TestCase
{
	public function testFirstNamedCacheCreatesProtectedDefaultSettings()
	{
		global $profilePath, $profileMask, $forbidUserSettings;
		$profilePath = CACHE_SETTINGS_MODE_ROOT;
		$profileMask = 0777;
		$forbidUserSettings = true;
		$settings = CACHE_SETTINGS_MODE_ROOT.'/settings';
		$this->assertTrue(!file_exists($settings), 'settings begins absent');
		new rCache('/accounts');
		clearstatcache(true, $settings);
		$this->assertEquals(01777, fileperms($settings) & 07777,
			'the first cache user cannot open settings before erasedata starts');
		$this->assertTrue(is_dir($settings.'/accounts'));
		@rmdir($settings.'/accounts');
		@rmdir($settings);
		@rmdir(CACHE_SETTINGS_MODE_ROOT);
	}
}
