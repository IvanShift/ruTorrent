<?php

require_once( dirname(__FILE__)."/cloudflare.php" );

class _cloudflareHooks
{
	static protected $in_progress = false;

	static public function OnURLFetched( $prm )
	{
		// cloudscraper re-resolves the URL without Snoopy's private-address guard.
		if(!empty($prm['client']->block_private))
			return;
		if(!self::$in_progress &&
			($prm['method']=='GET'))
		{
			self::$in_progress = true;
			$scrape = new rCloudflare($prm['client'],$prm['uri']);
			if($scrape->process())
			{
				$prm['client']->fetch( $prm['uri'], $prm['method'], $prm['content_type'], $prm['body'] );
			}
			self::$in_progress = false;
		}
	}
}
