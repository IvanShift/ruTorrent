<?php
require_once( dirname(__FILE__)."/../../php/cache.php" );
require_once( dirname(__FILE__)."/../../php/urlhost.php" );

class rCookies
{
	public $hash = "cookies.dat";
	public $modified = false;
	public $list = array();

	static public function load()
	{
		$cache = new rCache();
		$rt = new rCookies();
		$cache->get($rt);
		$rt->normalizeHosts();
		return($rt);
	}
	private function normalizeHosts()
	{
		$normalized = array();
		foreach($this->list as $host => $cookies)
		{
			$key = UrlHost::normalize($host);
			if($key !== '' && (!array_key_exists($key, $normalized) || $host === $key))
				$normalized[$key] = $cookies;
		}
		$this->list = $normalized;
	}
	public function store()
	{
		$this->normalizeHosts();
		$cache = new rCache();
		return($cache->set($this));
	}
	private static function parseCookiePairs($values)
	{
		$cookies = array();
		foreach(explode(';', $values) as $item)
		{
			$pair = explode('=', trim($item), 2);
			if(count($pair) !== 2)
				continue;
			$name = trim($pair[0]);
			$value = trim($pair[1]);
			if($name !== '' && $value !== '')
				$cookies[$name] = $value;
		}
		return $cookies;
	}
	public function set($rawData = null)
	{
		if($rawData === null)
			$rawData = file_get_contents('php://input');
		if(is_string($rawData))
		{
			$this->list = array();
			foreach(explode('&', $rawData) as $var)
			{
				$parts = explode('=', $var, 2);
				if(count($parts) !== 2 || $parts[0] !== 'cookie')
					continue;
				$entry = explode('|', trim(rawurldecode($parts[1])), 2);
				if(count($entry) !== 2)
					continue;
				$host = UrlHost::normalize(trim($entry[0]));
				$cookies = self::parseCookiePairs($entry[1]);
				if($host !== '' && !empty($cookies))
					$this->list[$host] = $cookies;
			}
		}
		$this->store();
	}

	public function get()
	{
                $ret = "hostCookies = [";
		foreach( $this->list as $host=>$cookies )
		{
			$c = '';
			foreach($cookies as $name=>$value)
				$c.=($name."=".$value.";");
			$ret.="{ host: ".Utility::quoteAndDeslashEachItem($host).", cookies: '".addslashes($c)."' },";
		}
		$len = strlen($ret);
		if($ret[$len-1]==',')
			$ret = substr($ret,0,$len-1);
		return($ret."];\n");
	}

	public function getInfo()
	{
		return($this->list);
	}
	public function getCookiesForHost($host)
	{
		$host = UrlHost::normalize($host);
		if($host === '')
			return(array());
		if(array_key_exists($host,$this->list))
			return($this->list[$host]);
		// Existing caches may contain a root-dot host key. Keep them readable.
		foreach($this->list as $key => $cookies)
			if(UrlHost::normalize($key) === $host)
				return($cookies);
		return(array());
	}
	public function getCookiesForURL($url)
	{
		// Host-only persisted entries have no Secure bit. Interpret them as
		// HTTPS-only rather than sending old secrets over cleartext HTTP.
		// Snoopy::fetchComplex logs an HTTP refusal after account lookup.
		if(strtolower((string) @parse_url($url, PHP_URL_SCHEME)) !== 'https')
			return(array());
		return($this->getCookiesForHost(UrlHost::of($url)));
	}
	public function add( $host, $values )
	{
		$cookies = self::parseCookiePairs($values);
		$host = UrlHost::normalize($host);
		$this->normalizeHosts();
		if($host !== '' && !empty($cookies))
			$this->list[$host] = $cookies;
		else
			unset($this->list[$host]);
		$this->store();
	}
}
