<?php

$_ENV['RU_PROFILE_PATH'] = sys_get_temp_dir() . '/rutorrent-cache-rules-' . getmypid();

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/../../php/cache.php');
require_once(__DIR__ . '/../../plugins/extratio/rules.php');
require_once(__DIR__ . '/../../plugins/rssurlrewrite/rules.php');

class CacheNestedRulesTest extends TestCase
{
    public function setUp()
    {
        FileUtil::makeDirectory(FileUtil::getSettingsPath());
    }

    public function tearDown()
    {
        $settings = FileUtil::getSettingsPath();
        foreach (array('ratiorules.dat', 'urlrewriterules.dat') as $name) {
            @unlink($settings . '/' . $name);
            @unlink($settings . '/' . $name . '.lock');
        }
        @rmdir($settings);
        @rmdir($_ENV['RU_PROFILE_PATH']);
    }

    public function testRatioRulesKeepTheirNestedRuleObjects()
    {
        $stored = new rRatioRulesList();
        $stored->add(new rRatioRule('ratio rule', RR_TRACKER_CONTAIN));
        $cache = new rCache();
        $this->assertTrue($cache->set($stored), 'ratio rules are stored');
        $loaded = new rRatioRulesList();
        $this->assertTrue($cache->get($loaded), 'ratio rules load from their cache file');
        $this->assertTrue(isset($loaded->lst[0]) && $loaded->lst[0] instanceof rRatioRule,
            'the nested ratio rule retains its class');
        $this->assertSame('ratio rule', $loaded->lst[0]->name ?? null,
            'the nested ratio rule retains its name');
        $this->assertSame(RR_TRACKER_CONTAIN, $loaded->lst[0]->reason ?? null,
            'the nested ratio rule retains its matching reason');
    }

    public function testRewriteRulesKeepTheirNestedRuleObjects()
    {
        $stored = new rURLRewriteRulesList();
        $stored->add(new rURLRewriteRule('rewrite rule', '/old/'));
        $cache = new rCache();
        $this->assertTrue($cache->set($stored), 'rewrite rules are stored');
        $loaded = new rURLRewriteRulesList();
        $this->assertTrue($cache->get($loaded), 'rewrite rules load from their cache file');
        $this->assertTrue(isset($loaded->lst[0]) && $loaded->lst[0] instanceof rURLRewriteRule,
            'the nested rewrite rule retains its class');
        $this->assertSame('rewrite rule', $loaded->lst[0]->name ?? null,
            'the nested rewrite rule retains its name');
        $this->assertSame('/old/', $loaded->lst[0]->pattern ?? null,
            'the nested rewrite rule retains its pattern');
    }
}
