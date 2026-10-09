<?php

require_once(__DIR__.'/TestCase.php');

/** The context policy belongs to profile/cache creation, not each loader. */
class SettingsReadOnlyBootstrapTest extends TestCase
{
	private function child($user, $readOnly, $cache, $explicitCreate = true)
	{
		$directory = sys_get_temp_dir().'/readonly-profile-'.getmypid().'-'.uniqid();
		mkdir($directory, 0700);
		$profile = $directory.'/absent-profile';
		$driver = $directory.'/bootstrap.php';
		$root = realpath(__DIR__.'/../..');
		file_put_contents($driver, '<?php '.
			'putenv("RU_HTTP_AUTH");putenv("RU_FORBID_USER_SETTINGS");'.
			'$_ENV["RU_PROFILE_PATH"]='.var_export($profile, true).';'.
			'$_SERVER["REMOTE_USER"]='.var_export($user, true).';'.
			($readOnly ? 'define("RUTORRENT_SETTINGS_READ_ONLY", true);' : '').
			'require '.var_export($root.'/php/cache.php', true).';'.
			'$before=is_dir('.var_export($profile, true).');'.
			($cache ? ($explicitCreate ? 'new rCache("/plugin-loader");' : 'new rCache("/plugin-loader", false);') : '').
			'echo json_encode(array($before,is_dir('.var_export($profile, true).'),'.
			'is_dir(FileUtil::getSettingsPath()."/plugin-loader")));');
		$out = array();
		exec(escapeshellarg(PHP_BINARY).' -d display_errors=stderr '.escapeshellarg($driver).' 2>&1', $out, $code);
		$text = implode("\n", $out);
		$result = json_decode($text, true);
		$this->assertSame(0, $code, 'the actual CLI bootstrap exits normally: '.$text);
		$this->removeTree($directory);
		return $result;
	}

	private function removeTree($path)
	{
		if(!is_dir($path) || is_link($path)) { @unlink($path); return; }
		foreach(array_diff(scandir($path), array('.', '..')) as $entry) $this->removeTree($path.'/'.$entry);
		rmdir($path);
	}

	public function testReadOnlyBootstrapAndDefaultPluginCacheCreateNoColdProfileDirectories()
	{
		foreach(array('', 'Cold Inspector') as $user)
		{
			$this->assertSame(array(false, false, false), $this->child($user, true, false),
				'util bootstrap never creates a missing shared or per-user profile');
			$this->assertSame(array(false, false, false), $this->child($user, true, true),
				'a default named cache loader cannot override the read-only creation policy');
		}
	}

	public function testNormalApplicationBootstrapAndDefaultCacheStillCreateTheirDirectories()
	{
		$this->assertSame(array(false, true, true), $this->child('', false, true),
			'ordinary shared cache initialization keeps its default directory creation');
		$this->assertSame(array(true, true, true), $this->child('ordinary-user', false, true),
			'ordinary per-user bootstrap and cache initialization keep their directories');
	}

	public function testExplicitNonCreatingCacheStillWorksWithoutTheReadOnlyFlag()
	{
		$this->assertSame(array(false, false, false), $this->child('', false, true, false),
			'an explicitly noncreating cache remains noncreating');
	}
}
