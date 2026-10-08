<?php

require_once(__DIR__ . '/../../php/TestCase.php');

/**
 * A plugin that cannot install its daemon handlers disables itself with a
 * notice "<name>: <pluginCantStart>". extratio's notice said "retrackers",
 * so a user looked for the fault in another plugin (AUD-ESC side item S6).
 */
class CantStartNoticeTest extends TestCase
{
	public function testEveryLiteralCantStartNoticeNamesItsOwnPlugin()
	{
		$root = realpath(__DIR__ . '/../../../plugins');
		$seen = 0;
		foreach (scandir($root) as $plugin) {
			$init = $root . '/' . $plugin . '/init.php';
			if ($plugin[0] === '.' || !is_file($init))
				continue;
			preg_match_all("/noty\\('([^':]+): '\\s*\\+\\s*theUILang\\.pluginCantStart/",
				file_get_contents($init), $names);
			foreach ($names[1] as $name) {
				$seen++;
				$this->assertSame($plugin, $name, 'plugins/' . $plugin . '/init.php names itself');
			}
		}
		$this->assertTrue($seen >= 10, 'the notices were found, not skipped: ' . $seen);
	}
}
