<?php

// Shared result format of nyaa.si and sukebei.nyaa.si, captured on 2026-09-28.
// Keep this outside engines/: engineManager treats every file there as a site.
abstract class NyaaFamilyEngine extends commonEngine
{
	public $defaults = array( "public"=>true, "page_size"=>75 );
	protected $baseUrl = '';
	protected $globalCategories = array();

	public function action($what,$cat,&$ret,$limit,$useGlobalCats)
	{
		$added = 0;
		$url = $this->baseUrl;
		$categories = $useGlobalCats ? $this->globalCategories : $this->categories;
		$cat = array_key_exists($cat,$categories) ? $categories[$cat] : reset($categories);

		for($pg = 1; $pg<=10; $pg++)
		{
			$search = $url . '/?c=' . $cat . '&q=' . $what . '&s=seeders&o=desc&p=' . $pg;
			$cli = $this->fetch($search);
			if(($cli == false) || (strpos($cli->results, ">No results found<") !== false))
				break;

			$res = preg_match_all('`<tr class.*>.*'.
				'<td.*>.*<a.*title="(?P<cat>.*)">.*'.
				'<td.*>.*<a href="/view/(?P<id>\d+)".*>(?P<name>.*)</a>.*'.
				'<td.*>.*<a href="(?P<link>magnet.*)">.*'.
				'<td.*>(?P<size>.*)</td>.*'.
				'<td(?P<dateAttrs>[^>]*)>(?P<date>.*)</td>.*'.
				'<td.*>(?P<seeds>.*)</td>.*'.
				'<td.*>(?P<peers>.*)</td>'.
				'`siU',$cli->results,$matches);
			if(!$res)
				break;

			for($i = 0; $i < $res; $i++)
			{
				$link = self::removeTags($matches['link'][$i]);
				if(array_key_exists($link,$ret))
					continue;
				$item = $this->getNewEntry();
				$item["desc"] = $url."/view/".$matches["id"][$i];
				$time = false;
				if(preg_match('/(?:^|\s)data-timestamp="(0|[1-9][0-9]*)"(?=\s|$)/',
					$matches["dateAttrs"][$i], $timestamp))
					$time = filter_var($timestamp[1], FILTER_VALIDATE_INT,
						array("options"=>array("min_range"=>0)));
				$item["time"] = $time === false
					? strtotime(self::removeTags($matches["date"][$i]).' UTC') : $time;
				$item["name"] = self::toUTF(self::removeTags($matches["name"][$i]),"utf-8");
				$item["size"] = self::formatSize($matches["size"][$i]);
				$item["cat"] = self::removeTags($matches["cat"][$i]);
				$item["seeds"] = intval(self::removeTags($matches["seeds"][$i]));
				$item["peers"] = intval(self::removeTags($matches["peers"][$i]));
				$ret[$link] = $item;
				if(++$added >= $limit)
					return;
			}
		}
	}
}
