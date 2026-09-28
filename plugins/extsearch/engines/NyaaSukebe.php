<?php

require_once(dirname(__DIR__) . "/nyaa_family.php");

class NyaaSukebeEngine extends NyaaFamilyEngine
{
	public $categories = array(
		'All categories'=>'0_0',
		'> Art'=>'1_0',
		'-- Anime'=>'1_1',
		'-- Doujinshi'=>'1_2',
		'-- Games'=>'1_3',
		'-- Manga'=>'1_4',
		'-- Pictures'=>'1_5',
		'> Reallife'=>'2_0',
		'-- Photobooks & Pictures'=>'2_1',
		'-- Videos'=>'2_2'
		);

	protected $baseUrl = 'https://sukebei.nyaa.si';
	protected $globalCategories = array(
		'all' => '0_0',
		'art' => '1_0',
		'reallife' => '2_0'
	);
}
