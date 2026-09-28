<?php

require_once(dirname(__DIR__) . "/nyaa_family.php");

class NyaaEngine extends NyaaFamilyEngine
{
	public $categories = array(
		'All categories'=>'0_0',
		'> Anime'=>'1_0',
		'--A-- Anine Music Video'=>'1_1',
		'--A-- English-translated'=>'1_2',
		'--A-- Non-English-translated'=>'1_3',
		'--A-- Raw'=>'1_4',
		'> Audio'=>'2_0',
		'-- Lossless'=>'2_1',
		'-- Lossy'=>'2_2',
		'> Literature'=>'3_0',
		'--L-- English-translated'=>'3_1',
		'--L-- Non-English-translated'=>'3_2',
		'--L-- Raw'=>'3_3',
		'> Live Action'=>'4_0',
		'--LA-- English-translated'=>'4_1',
		'--LA-- Idol/Promotional Video'=>'4_2',
		'--LA-- Non-English-translated'=>'4_3',
		'--LA-- Raw'=>'4_4',
		'> Pictures'=>'5_0',
		'-- Graphics'=>'5_1',
		'-- Photos'=>'5_2',
		'> Software'=>'6_0',
		'-- Applications'=>'6_1',
		'-- Games'=>'6_2'
		);

	protected $baseUrl = 'https://nyaa.si';
	protected $globalCategories = array(
		'all' => '0_0',
		'anime' => '1_0',
		'music' => '2_0',
		'books' => '3_0',
		'live action' => '4_0',
		'pictures' => '5_0',
		'software' => '6_1',
		'games' => '6_2'
	);
}
