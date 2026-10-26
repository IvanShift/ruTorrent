<?php

class PirateBayEngine extends commonEngine
{
	public $defaults = array( "public"=>true, "page_size"=>30 );
	public $categories = array( 'all'=>'100,200,300,400,500,600', 'Audio'=>'100', 'Video'=>'200', 'Applications'=>'300', 'Games'=>'400', 'Porn'=>'500', 'Other'=>'600' );

	public function makeClient($url)
	{
		$client = parent::makeClient($url);
		return($client);
	}
	// The "Uploaded" column reads "MM-DD HH:MM" for this year, "MM-DD YYYY",
	// "Today HH:MM", "Y-day HH:MM" or "N mins ago". Numeric dates must be
	// complete and valid: libc strptime() accepted different partial fields
	// on glibc and musl, while mktime() silently normalizes impossible dates.
	public static function uploadedTime($tms)
	{
		$tms = trim($tms);
		if(preg_match('/\A([0-9]{1,2})-([0-9]{1,2})\s+([0-9]{1,2}):([0-9]{1,2})\z/', $tms, $match))
		{
			$year = (int)date('Y');
			$hour = (int)$match[3];
			$minute = (int)$match[4];
		}
		elseif(preg_match('/\A([0-9]{1,2})-([0-9]{1,2})\s+([0-9]{1,4})\z/', $tms, $match))
		{
			$year = (int)$match[3];
			$hour = $minute = 0;
		}
		else
		{
			if(preg_match('/\A([0-9]+) mins? ago\z/i', $tms, $match))
				$tms = '-' . $match[1] . ' minute';
			elseif(preg_match('/\A[0-9]/', $tms))
				return(false);
			return(strtotime(str_replace('Y-day', '-1 day', $tms)));
		}
		if(($hour > 23) || ($minute > 59) || !checkdate((int)$match[1], (int)$match[2], $year))
			return(false);
		// Retain mktime's choice of occurrence in a DST fold. Only years
		// 1..100 need setDate(), because mktime() remaps them to 19xx/20xx.
		if($year > 100)
			return(mktime($hour, $minute, 0, (int)$match[1], (int)$match[2], $year));
		$at = new DateTime();
		$at->setDate($year, (int)$match[1], (int)$match[2]);
		$at->setTime($hour, $minute, 0);
		return($at->getTimestamp());
	}

	public function action($what,$cat,&$ret,$limit,$useGlobalCats)
	{
		$added = 0;
		$url = 'https://thepiratebay.org';
		if($useGlobalCats)
			$categories = array( 'all'=>'100,200,300,400,500,600', 'movies'=>'200', 'tv'=>'205', 'music'=>'100', 'games'=>'400', 'anime'=>'0', 'software'=>'300', 'pictures'=>'603', 'books'=>'601' );
		else
			$categories = &$this->categories;
		if(!array_key_exists($cat,$categories))
			$cat = $categories['all'];
		else
			$cat = $categories[$cat];
		$maxPage = 10;
		for($pg = 0; $pg<$maxPage; $pg++)
		{
			$cli = $this->fetch( $url . '/search/' . $what . '/' . $pg . '/7/' . $cat );
			if(($cli==false) || (strpos($cli->results, "</span>&nbsp;No hits.")!==false))
				break;
			$res = preg_match_all('`<td class="vertTh">.*'.
				'<a .*>(?P<cat>.*)</a>.*<a .*>(?P<subcat>.*)</a>.*'.
				'<a href="(?P<desc>.*)" .*>(?P<name>.*)</a>.*'.
				'<a href="magnet:(?P<link>[^"]*)" .*>.*<font .*>Uploaded (?P<date>.*), Size (?P<size>.*), .*</font>.*'.
				'<td align="right">(?P<seeds>.*)</td>.*'.
				'<td align="right">(?P<leech>.*)</td>'.
				'`siU', $cli->results, $matches);
			if($res)
			{
				for($i=0; $i<count($matches["link"]); $i++)
				{
					$link = "magnet:".$matches["link"][$i];
					if(!array_key_exists($link,$ret))
					{
						$item = $this->getNewEntry();
						$item["cat"] = self::removeTags($matches["cat"][$i].' > '.$matches["subcat"][$i]);
						$item["desc"] = $url.$matches["desc"][$i];
						$item["name"] = self::removeTags($matches["name"][$i]);
						$item["size"] = self::formatSize($matches["size"][$i]);
						$item["seeds"] = intval(self::removeTags($matches["seeds"][$i]));
						$item["peers"] = intval(self::removeTags($matches["leech"][$i]));

						$tm = self::uploadedTime(self::removeTags($matches["date"][$i]));
						if($tm!==false)
							$item["time"] = $tm;
						$ret[$link] = $item;
						$added++;
						if($added>=$limit)
							return;
					}
				}
			}
			else
				break;
		}
	}
}
