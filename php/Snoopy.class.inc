<?php

/*************************************************

Snoopy - the PHP net client
Author: Monte Ohrt <monte@ispi.net>
Copyright (c): 1999-2000 ispi, all rights reserved
Version: 1.0

 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

You may contact the author of Snoopy by e-mail at:
monte@ispi.net

Or, write to:
Monte Ohrt
CTO, ispi
237 S. 70th suite 220
Lincoln, NE 68510

The latest version of Snoopy can be obtained from:
http://snoopy.sourceforge.com

*************************************************/

require_once( 'util.php' );
require_once( 'settings.php' );
require_once(__DIR__ . '/utility/urlhost.php');
require_once(__DIR__ . '/publicsuffix.php');

class Snoopy
{
	// $status when the client has no response to report: a request it refused
	// to send, or a reply it could not take. Below 100 like the -100 of a read
	// timeout, so callers that already treat a status under 100 as "nothing
	// arrived" need no new case; the reason is in $error.
	const RESPONSE_BODY_FAILED = -101;
	const SOCKET_CONNECT_FAILED = -102;
	// Provisional (1xx) blocks one answer may carry before its final response.
	// A server sends one or two; each block is discarded when it ends, so only
	// this count stops a peer that streams them without end.
	const MAX_INTERIM_RESPONSES = 5;
	const CREDENTIAL_REDIRECT_REFUSED = 'credential-redirect-refused';
	const REQUEST_THROTTLED = 'request-throttled';

	/**** Public variables ****/

	/* user definable vars */

	var $host		=	"www.php.net";		// host name we are connecting to
	var $port		=	80;			// port we are connecting to
        var $proxy_proto	=       "http";			// http or https
	var $proxy_host		=	"";			// proxy host to use
	var $proxy_port		=	"";			// proxy port to use
	var $agent		=	"Snoopy v1.0";		// agent we masquerade as
	var $referer		=	"";			// referer info to pass
	var $cookies		=	array();		// array of cookies to pass
								// $cookies["username"]="joe";
	var $rawheaders		=	array();		// array of raw headers to send
								// $rawheaders["Content-type"]="text/html";

	var $maxredirs		=	5;			// http redirection depth maximum. 0 = disallow
	var $lastredirectaddr	=	"";			// contains address of last redirected address
	var $lastResponseURL	=	"";			// URL that produced the latest HTTP response
	// Scoped by loginmgr; both HTTPS URLs must belong to the selected account.
	public $redirectTrust = null;
	var $passcookies	=	true;			// pass set cookies back through redirects

	// Hosts behind one rate limiter, registered by whoever knows them. Every
	// wire request to such a host, from any client, login and redirect hops
	// included, waits the group's interval after the previous turn started
	// ('next') and after the last request that had ended when it took its turn
	// ('ended'); a turn handed back unused rolls back 'next' only. An HTTP 429
	// pauses the group, doubling up to a cap while the limiter keeps refusing.
	// The state lives in a flock-guarded file in the shared settings path, so
	// independent callers share one clock; without that file the group is still
	// throttled in this process. Kinozal's group is registered at the transport
	// boundary, including for anonymous clients and redirect targets.
	private static $throttleGroups = array();
	private static $throttleStates = array();
	private static $throttleLogged = array();
	private $throttleReservation = null;
	private $throttleResponse = null;
	private $kinozalAttempt = null;
	private static $kinozalAttemptNumber = 0;

	var $user		=	"";			// user for http authentication
	var $pass		=	"";			// password for http authentication

	// http accept types
	var $accept		=	"image/gif, image/x-xbitmap, image/jpeg, image/pjpeg, */*";

	var $results		=	"";			// where the content is put

	var $error		=	"";			// error messages sent here
	var $response_code	=	"";			// response code returned from server
	var $headers		=	array();		// headers returned from server sent here
	var $maxlength		=	4194304;		// max return data length (body), see $httpMaxResponseLength
	var $read_timeout	=	0;			// timeout on read operations, in seconds

	var $timed_out		=	false;			// if a read operation timed out
	var $status		=	0;			// http request status

	// send Accept-encoding: gzip?
	var $use_gzip		=	true;
	var $IP			=	null;
	var $useIpv4		=	false;

	// refuse targets that resolve to a private or otherwise non-routable address
	var $block_private	=	false;
	// hosts exempt from the check above, matched against the URL host verbatim
	var $private_allowlist	=	array();

	// check the certificate an HTTPS server presents
	var $verify_certificates =	true;

	/**** Private variables ****/

	var $_maxlinelen	=	4096;			// max line length (headers)

	var $_httpversion	=	"HTTP/1.0";		// default http request version
	var $_mime_boundary	=	"";			// MIME boundary for multipart/form-data submit type
	var $_redirectaddr	=	false;			// will be set if page fetched is a redirect
	var $_redirectdepth	=	0;			// increments on an http redirect
	protected $requestCookieHost = null;
	protected $requestCookieScheme = '';
	protected $requestScopedCookies = array();
	protected $requestOriginalCookies = array();
	protected $requestAccountCookies = array();
	private $secureResponseCookies = array();
	private $loadedAccountCookies = array();
	private $responseCookieJar = array();
	private $flattenedResponseCookies = array();
	private $responseCookieSequence = 0;
	private $selectedCookieAccount = null;
	private $requestChainSourceURL = '';
	private $requestDeadline = null;
	const MAX_RESPONSE_COOKIES = 300;
	const MAX_DOMAIN_COOKIES = 50;
	const MAX_COOKIE_BYTES = 4096;
	const MAX_COOKIE_HEADERS = 100;
	const MAX_COOKIE_HEADER_BYTES = 8192;
	private $lastResponseAt = null;
	private $responseCookiesCaptured = false;
	private $responseCookieActions = array();

	var $_isproxy		=	false;			// set if using a proxy server
	var $_fp_timeout	=	30;			// timeout for socket connection
	var $_pinnedaddr	=	false;			// address the guard validated, connected to verbatim

	public function __construct()
	{
		global $httpIP;
		global $httpProxy;
		global $httpUserAgent;
		global $httpTimeOut;
		global $httpUseGzip;
		global $httpMaxResponseLength;
		global $httpBlockPrivateNetworks;
		global $httpPrivateNetworkAllowlist;
		global $httpVerifyCertificates;

		if(isset($httpUserAgent))
			$this->agent = $httpUserAgent;
		if(isset($httpTimeOut))
		{
			$this->_fp_timeout = $httpTimeOut;
			$this->read_timeout = $httpTimeOut;
		}
		if(is_array($httpProxy) && $httpProxy['use'])
		{
			$this->proxy_proto = $httpProxy['proto'];
			$this->proxy_host = $httpProxy['host'];
			$this->proxy_port = $httpProxy['port'];
		}
		if (isset($httpUseGzip))
			$this->use_gzip = $httpUseGzip;
		// Anything that is not a positive byte count would refuse every fetch,
		// which is not what an operator who wrote it meant; the bound the class
		// ships with stands instead.
		if (isset($httpMaxResponseLength) && (int)$httpMaxResponseLength > 0)
			$this->maxlength = (int)$httpMaxResponseLength;
		if(isset($httpBlockPrivateNetworks))
			$this->block_private = $httpBlockPrivateNetworks;
		if(isset($httpPrivateNetworkAllowlist) && is_array($httpPrivateNetworkAllowlist))
			$this->private_allowlist = $httpPrivateNetworkAllowlist;
		if(isset($httpVerifyCertificates))
			$this->verify_certificates = (bool)$httpVerifyCertificates;
		if(isset($httpIP))
			$this->IP = $httpIP;
	}

	/** One monotonic budget across fetches and redirects; HTTP uses curl too.
	 * Synchronous target resolution is charged after it returns, not interrupted.
	 */
	public function setRequestDeadline($seconds)
	{
		$this->requestDeadline = self::requestClock() + max(0.0, (float)$seconds);
	}

	private static function requestClock()
	{
		$time = hrtime();
		return $time[0] + $time[1] / 1000000000.0;
	}

	private function beginKinozalAttempt($url, $method)
	{
		require_once(__DIR__ . '/kinozal.php');
		$target = KinozalResponse::httpLogTarget($url, $method);
		$this->kinozalAttempt = $target === null ? null : $target + array(
			'pid' => getmypid(), 'attempt' => ++self::$kinozalAttemptNumber,
			'caller' => KinozalResponse::httpLogCaller(),
			// The limiter uses the installation settings path, not an account.
			// This getter explicitly avoids creating a profile just for logging.
			'profile' => substr(hash('sha256', FileUtil::getProfilePathEx('', false) . '/settings'), 0, 12),
			'transport' => null, 'transport_attempted' => false, 'configured_proxy' => null, 'clock' => self::requestClock(),
			'wall' => microtime(true), 'http' => 0, 'retry_after_s' => null, 'strikes' => null, 'until' => null);
	}

	private function beginKinozalTransport($transport)
	{
		if($this->kinozalAttempt === null)
			return;
		$this->kinozalAttempt['transport'] = $transport;
		$this->kinozalAttempt['transport_attempted'] = true;
		$this->kinozalAttempt['configured_proxy'] = (bool)$this->_isproxy;
		$this->kinozalAttempt['clock'] = self::requestClock();
		$this->kinozalAttempt['wall'] = microtime(true);
	}

	private function rememberKinozalThrottleState($state)
	{
		if($this->kinozalAttempt !== null)
		{
			$this->kinozalAttempt['strikes'] = $state['strikes'];
			$this->kinozalAttempt['until'] = $state['until'];
		}
	}

	private function finishKinozalAttempt($completed)
	{
		if($this->kinozalAttempt === null)
			return;
		$record = $this->kinozalAttempt;
		$this->kinozalAttempt = null;
		$record['started_at'] = gmdate('Y-m-d\TH:i:s', (int)$record['wall'])
			. sprintf('.%03dZ', (int)(($record['wall'] - floor($record['wall'])) * 1000));
		$record['duration_ms'] = round(max(0.0, self::requestClock() - $record['clock']) * 1000, 3);
		$record['status'] = (int)$this->status;
		$record['result'] = !$record['transport_attempted'] ? 'local-refused' : ($completed ? 'response' : 'transfer-failed');
		unset($record['clock'], $record['wall'], $record['curl_origin']);
		// Best-effort diagnostics must not turn a working fetch into a refusal.
		try { @FileUtil::toLog('kinozal-http: ' . json_encode($record, JSON_UNESCAPED_SLASHES)); }
		catch(Throwable $error) { }
	}

	private function requestTimeLeft()
	{
		return $this->requestDeadline === null ? null : $this->requestDeadline - self::requestClock();
	}

	private function requestDeadlineExpired()
	{
		// curl stores this timeout in milliseconds; a smaller positive value
		// rounds to its unlimited zero. Refuse the final millisecond instead.
		if($this->requestDeadline === null || $this->requestTimeLeft() > 0.001)
			return false;
		$this->refuseResponseBody('request-deadline');
		return true;
	}

	/**
	 * Throttle every request to $hosts and their subdomains as one group.
	 * $margin ends each pause after a 429 that many seconds early, after the
	 * doubling and never shortening a Retry-After: a periodic caller's run at
	 * the end of a pause then is admitted whatever second it starts at.
	 */
	public static function throttleHosts($group, $hosts, $interval, $pause, $maxPause, $margin = 0)
	{
		$interval = is_numeric($interval) ? (float)$interval : 0.0;
		$pause = is_numeric($pause) ? (float)$pause : 0.0;
		$margin = is_numeric($margin) && is_finite((float)$margin) ? (float)$margin : 0.0;
		if(!is_string($group) || !preg_match('/^[a-z0-9_-]{1,32}$/D', $group))
			return;
		if(!is_finite($interval) || !is_finite($pause) || ($interval <= 0 && $pause <= 0))
		{
			unset(self::$throttleGroups[$group]);
			return;
		}
		self::$throttleGroups[$group] = array('hosts' => array_values((array)$hosts),
			'interval' => max(0.0, $interval), 'pause' => max(0.0, $pause),
			'maxPause' => max(0.0, $pause, is_numeric($maxPause) ? (float)$maxPause : 0.0),
			// At most half the first pause, so no pause shrinks to nothing.
			'margin' => min(max(0.0, $margin), max(0.0, $pause) / 2));
	}

	private static function throttleGroupFor($scheme, $host)
	{
		if($scheme !== 'http' && $scheme !== 'https')
			return(null);
		// A shared pause must also cover anonymous RSS, add-by-URL and redirects
		// that never select an account. The leaf declares policy without loading
		// either optional plugin or its configuration.
		require_once(__DIR__ . '/kinozal.php');
		KinozalResponse::throttle();
		foreach(self::$throttleGroups as $group => $throttle)
			if(UrlHost::isOneOf($host, $throttle['hosts']))
				return($group);
		return(null);
	}

	// Reserves this request's turn in its group and waits for it. False refuses
	// the request without sending it: the group is paused after HTTP 429, or
	// the wait would outlast the request deadline.
	private function enterThrottle($group)
	{
		$throttle = self::$throttleGroups[$group];
		$budget = $this->requestTimeLeft();
		$until = $slot = null;
		self::changeThrottleState($group, function(&$state) use($throttle, $budget, &$until, &$slot)
		{
			$this->rememberKinozalThrottleState($state);
			$now = microtime(true);
			$start = max($now, $state['next'], $state['ended']);
			if($state['until'] > $now)
				$until = $state['until'];
			// requestDeadlineExpired() refuses the final millisecond.
			else if($budget === null || $start - $now < $budget - 0.001)
			{
				$slot = array($start, $state['next']);
				$state['next'] = $start + $throttle['interval'];
			}
		});
		if($until !== null)
			return($this->refuseThrottled($group, $until));
		if($slot === null)
			return($this->refuseResponseBody('request-deadline'));
		// A wait spends the same deadline as DNS, transport and every redirect.
		while(($left = $slot[0] - microtime(true)) > 0)
		{
			if($this->requestDeadlineExpired())
			{
				self::returnTurn($group, $slot);
				return(false);
			}
			$budget = $this->requestTimeLeft();
			usleep((int)ceil(min(1.0, $left, $budget === null ? 1.0 : max(0.0, $budget)) * 1000000));
		}
		if($this->requestDeadlineExpired())
		{
			self::returnTurn($group, $slot);
			return(false);
		}
		// Another process may have met a 429 while this one waited its turn.
		$until = self::changeThrottleState($group, function(&$state)
		{
			$this->rememberKinozalThrottleState($state);
			return($state['until'] > microtime(true) ? $state['until'] : null);
		});
		if($until !== null)
		{
			self::returnTurn($group, $slot);
			return($this->refuseThrottled($group, $until));
		}
		$this->throttleReservation = array($group, $slot[0], $slot[1]);
		return(true);
	}

	private function refuseThrottled($group, $until)
	{
		self::logThrottle(self::REQUEST_THROTTLED, $group, $until);
		return($this->refuseResponseBody(self::REQUEST_THROTTLED));
	}

	// Gives an unused turn back, unless somebody has reserved after it. Only
	// 'next' rolls back: the interval after the last request ended still holds.
	private static function returnTurn($group, $slot)
	{
		$interval = self::$throttleGroups[$group]['interval'];
		self::changeThrottleState($group, function(&$state) use($slot, $interval)
		{
			if($state['next'] === $slot[0] + $interval)
				$state['next'] = $slot[1];
		});
	}

	// The group's next request waits a whole interval after this one ended,
	// and an HTTP 429 pauses the group. A transfer that never reached the host
	// (curl could not resolve or connect to it) hands its turn back.
	private function leaveThrottle($sent)
	{
		list($group, $start, $previousNext) = $this->throttleReservation;
		$this->throttleReservation = null;
		$throttle = self::$throttleGroups[$group];
		// The response line, not $status: an answer whose body was refused
		// (gzip, size, curl's size limit) still came from the host, and curl's
		// exit code is no HTTP status.
		$http = preg_match('~^HTTP/\S+\s+(\d{3})~', (string)$this->response_code, $match) ? (int)$match[1] : 0;
		if(!$sent && $http === 0 && in_array((int)$this->status, array(6, 7), true))
		{
			self::returnTurn($group, array($start, $previousNext));
			return;
		}
		$retryAfter = $http === 429 ? $this->retryAfterSeconds() : null;
		if($http === 0 && $this->throttleResponse !== null)
			list($http, $retryAfter) = $this->throttleResponse;
		$this->throttleResponse = null;
		$paused = self::changeThrottleState($group, function(&$state) use($throttle, $http, $retryAfter)
		{
			$now = microtime(true);
			$state['ended'] = max($state['ended'], $now + $throttle['interval']);
			if($http === 429 && $throttle['pause'] > 0)
			{
				// A 429 while the pause is on answers a request sent before it
				// began: the same limiter event, so it may lengthen the pause
				// but adds no strike.
				if($state['until'] <= $now)
					$state['strikes'] = min($state['strikes'] + 1, 16);
				$own = min($throttle['pause'] * pow(2, max($state['strikes'], 1) - 1), $throttle['maxPause'])
					- $throttle['margin'];
				$state['until'] = max($state['until'], $now + min(max($own, (float)$retryAfter),
					$throttle['maxPause']));
				$this->rememberKinozalThrottleState($state);
				return($state['until']);
			}
			if($http >= 100)
				$state['strikes'] = 0;
			$this->rememberKinozalThrottleState($state);
			return(null);
		});
		if($paused !== null)
			self::logThrottle('throttle-paused', $group, $paused);
	}

	// A 429's Retry-After in seconds: delta-seconds or an exact IMF-fixdate.
	// RFC 9110 section 5.6.7 asks for the obsolete RFC 850 and asctime dates
	// too; they are ignored on purpose, as that only leaves the doubling backoff.
	private function retryAfterSeconds()
	{
		foreach($this->headers as $line)
			if(preg_match('/^Retry-After:\s*(.+?)\s*$/i', (string)$line, $match))
			{
				if(ctype_digit($match[1]))
					return((int)$match[1]);
				$at = DateTime::createFromFormat('D, d M Y H:i:s \G\M\T', $match[1], new DateTimeZone('UTC'));
				if($at === false || $at->format('D, d M Y H:i:s \G\M\T') !== $match[1])
					return(null);
				return(max(0, $at->getTimestamp() - time()));
			}
		return(null);
	}

	// Runs $change on the group's state under the state file's lock and returns
	// its result. Without a usable file the in-process copy is the state.
	private static function changeThrottleState($group, callable $change)
	{
		$state = isset(self::$throttleStates[$group]) ? self::$throttleStates[$group]
			: array('next' => 0.0, 'ended' => 0.0, 'until' => 0.0, 'strikes' => 0);
		$fp = self::openThrottleFile($group);
		if($fp !== false && !@flock($fp, LOCK_EX))
		{
			fclose($fp);
			$fp = false;
			self::logThrottle('throttle-state-unavailable', $group, null);
		}
		if($fp !== false)
			$state = self::saneThrottleState(@json_decode((string)stream_get_contents($fp, -1, 0), true),
				$state, $group);
		$result = $change($state);
		if($fp !== false)
		{
			$json = json_encode($state);
			if(!ftruncate($fp, 0) || fseek($fp, 0) !== 0 || fwrite($fp, $json) !== strlen($json) || !fflush($fp))
				self::logThrottle('throttle-state-unwritable', $group, null);
			flock($fp, LOCK_UN);
			fclose($fp);
		}
		self::$throttleStates[$group] = $state;
		return($result);
	}

	// A value another process stored is trusted only within the bounds a
	// writer can produce: a clock step or a damaged file must not stall the
	// group for days. Times already known in-process only add caution.
	private static function saneThrottleState($stored, $local, $group)
	{
		if(!is_array($stored))
			return($local);
		$now = microtime(true);
		$throttle = self::$throttleGroups[$group];
		$value = function($key, $limit) use($stored)
		{
			$number = isset($stored[$key]) && is_numeric($stored[$key]) ? (float)$stored[$key] : 0.0;
			return(is_finite($number) && $number >= 0 && $number <= $limit ? $number : 0.0);
		};
		$turns = $now + max(600.0, 32 * $throttle['interval']);
		return(array(
			'next' => max($local['next'], $value('next', $turns)),
			'ended' => max($local['ended'], $value('ended', $turns)),
			'until' => max($local['until'], $value('until', $now + $throttle['maxPause'])),
			'strikes' => (int)$value('strikes', 16),
		));
	}

	private static function openThrottleFile($group)
	{
		global $profileMask;
		if(FileUtil::settingsReadOnlyContext())
		{
			self::logThrottle('throttle-state-unavailable', $group, null);
			return(false);
		}
		$mask = isset($profileMask) ? $profileMask : 0777;
		$dir = FileUtil::getSettingsPathEx('');
		if(!is_dir($dir))
			FileUtil::makeDirectory($dir, $mask | 01000, true);
		$path = $dir . '/throttle-' . $group . '.json';
		// Narrow the umask for the create itself, as RuTrackerState does: the
		// scheduler's and the web server's OS users must both open this file.
		$previous = @umask(~($mask & 0666) & 0777);
		$fp = @fopen($path, 'c+');
		@umask($previous);
		if($fp === false)
		{
			self::logThrottle('throttle-state-unavailable', $group, null);
			return(false);
		}
		@chmod($path, $mask & 0666);
		return($fp);
	}

	private static function logThrottle($event, $group, $until)
	{
		// A pause is logged each time it starts; the rest once per process.
		$key = $event . ' ' . $group;
		if($event !== 'throttle-paused' && isset(self::$throttleLogged[$key]))
			return;
		self::$throttleLogged[$key] = true;
		FileUtil::toLog('Snoopy: ' . $event . ' group=' . $group
			. ($until === null ? '; pacing stays within this process'
				: ' until=' . gmdate('Y-m-d\TH:i:s\Z', (int)ceil($until))));
	}

	/** Whether a response cookie scoped to $domain is sent to $host. */
	public static function cookieDomainMatches($host, $domain, $hostOnly)
	{
		return $hostOnly ? $host === $domain : UrlHost::isOneOf($host, array($domain));
	}

	static public function linkencode($p_url)
	{
		if(preg_match("/\%([0-9,A-F]{2})/i",$p_url)==1)
			return($p_url);
		$p_url = str_replace("\t","%09",$p_url);
		$uparts = @parse_url($p_url);
		if(!is_array($uparts))
			return($p_url);
		$scheme = array_key_exists('scheme',$uparts) ? $uparts['scheme'] : "";
		$pass = array_key_exists('pass',$uparts) ? $uparts['pass']  : "";
		$user = array_key_exists('user',$uparts) ? $uparts['user']  : "";
		$port = array_key_exists('port',$uparts) ? $uparts['port']  : "";
		$host = array_key_exists('host',$uparts) ? $uparts['host']  : "";
		$path = array_key_exists('path',$uparts) ? $uparts['path']  : "";
		$query = array_key_exists('query',$uparts) ? $uparts['query']  : "";
		$fragment = array_key_exists('fragment',$uparts) ? $uparts['fragment']  : "";

		if(!empty($scheme))
			$scheme .= '://';

		if(!empty($pass) && !empty($user))
		{
			$user = rawurlencode($user).':';
			$pass = rawurlencode($pass).'@';
		}
		elseif(!empty($user))
			$user .= '@';

		if(!empty($port) && !empty($host))
			$host = ''.$host.':';

		if(!empty($path))
		{
			$arr = preg_split("/([\/;=])/", $path, -1, PREG_SPLIT_DELIM_CAPTURE);
			$path = "";
			foreach($arr as $var)
			{
				switch($var)
				{
					case "/":
					case ";":
					case "=":
						$path .= $var;
						break;
					default:
						$path .= rawurlencode($var);
				}
			}
			// legacy patch for servers that need a literal /~username
			$path = str_replace("/%7E","/~",$path);
		}
		else
		    $path="/";

		if(!empty($query))
		{
			$arr = preg_split("/([&=;])/", $query, -1, PREG_SPLIT_DELIM_CAPTURE);
			$query = "?";
			foreach($arr as $var)
			{
				switch($var)
				{
					case "&":
					case "=":
					case ";":
						$query .= $var;
						break;
					default:
						$query .= urlencode(str_replace("+", " ", $var));
				}
			}
		}
		if(!empty($fragment))
			$fragment = '#'.urlencode($fragment);
		return implode('', array($scheme, $user, $pass, $host, $port, $path, $query, $fragment));
	}

	static public function getURLCookies(&$url)
	{
		$cookies = array();
		$pos = strpos($url,':COOKIE:');
		if($pos!==false)
		{
			foreach(explode(';',substr($url,$pos+8)) as $item)
			{
				$pair = explode('=',trim($item),2);
				if(count($pair) === 2 && $pair[0] !== '')
					$cookies[$pair[0]] = $pair[1];
			}
			$url = substr($url,0,$pos);
		}
		return($cookies);
	}

	static public function isSuccessfulResponse($client)
	{
		return is_object($client) && isset($client->status)
			&& $client->status >= 200 && $client->status < 300
			&& (!isset($client->error) || $client->error === '');
	}

	static public function isTorrentResponse($client)
	{
		if(!self::isSuccessfulResponse($client) || !isset($client->results))
			return false;
		$body = $client->results;
		// Metainfo is a bencoded dictionary. The parser's raw mode avoids
		// interpreting a remote response as a local filename.
		if(!is_string($body) || $body === '' || $body[0] !== 'd')
			return false;
		require_once(dirname(__FILE__) . '/Torrent.php');
		$torrent = Torrent::fromRawBytes($body);
		return !$torrent->errors() && is_array($torrent->info) && count($torrent->info) > 0;
	}

	public function fetchComplex($URI,$method="GET",$content_type="",$body="")
	{
		// The cookie suffix is not part of the URL authority. Parse the host
		// only after removing it, including for pathless source URLs.
		$urlCookies = self::getURLCookies($URI);
		$parts = @parse_url($URI);
		if(!is_array($parts) || !isset($parts["scheme"]) || !isset($parts["host"]))
			return($this->fetch($URI,$method,$content_type,$body));
		$this->host = $parts["host"];
		$originalCookies = $this->cookies;
		$originalSecureCookies = $this->secureResponseCookies;
		$originalFlattenedCookies = $this->flattenedResponseCookies;
		$previousCookieAccount = $this->selectedCookieAccount;
		$this->selectedCookieAccount = null;
		$previousScope = array($this->requestCookieHost, $this->requestCookieScheme,
			$this->requestScopedCookies, $this->requestOriginalCookies, $this->requestAccountCookies,
			$this->loadedAccountCookies, $this->requestChainSourceURL);
		$this->requestCookieHost = UrlHost::of($URI);
		$this->requestCookieScheme = is_array($parts) && isset($parts['scheme'])
			? strtolower($parts['scheme']) : '';
		$this->requestScopedCookies = array();
		$this->requestOriginalCookies = $originalCookies;
		$this->requestAccountCookies = array();
		try
		{
			$pluginCookies = array();
			$pluginCookieRefused = false;
			if(rTorrentSettings::get()->isPluginRegistered('cookies') && $this->requestCookieHost !== null)
			{
				// Go back to ruTorrent root folder and include cookies.php plugin file
				require_once(dirname(__FILE__)."/../plugins/cookies/cookies.php");
				$store = rCookies::load();
				$pluginCookies = $store->getCookiesForURL($URI);
				$pluginCookieRefused = $this->requestCookieScheme === 'http'
					&& !empty($store->getCookiesForHost($this->requestCookieHost));
			}
			$this->requestScopedCookies = array_merge($pluginCookies, $urlCookies);
			$this->cookies = array_merge($this->cookies, $this->requestScopedCookies);
			$acc = false;
			$httpsAccount = null;
			if(rTorrentSettings::get()->isPluginRegistered('loginmgr'))
			{
				// Go back to ruTorrent root folder and include accounts.php plugin file
				require_once(dirname(__FILE__)."/../plugins/loginmgr/accounts.php");
				$am = accountManager::load();
				if($am===false)
				{
					$am = new accountManager();
					$am->obtain();
				}
				$acc = $am->getAccount($URI, $httpsAccount);
			}
			if($pluginCookieRefused)
				self::logPluginCookieRefusal($this->requestCookieHost, $acc ? $acc : $httpsAccount);
			$this->selectedCookieAccount = $acc ?: null;
			if($acc)
			{
				$accountClass = $am->accounts[$acc]['object'];
				$account = new $accountClass();
				$configuredPort = self::effectivePort($account->url);
				if($configuredPort !== null && self::effectivePort($URI) !== $configuredPort)
				{
					$this->error = self::CREDENTIAL_REDIRECT_REFUSED;
					self::logAccountPortRefusal($this->requestCookieHost,
						self::effectivePort($URI), $configuredPort);
					return($this->refuseRequest());
				}
				return($am->fetch( $acc, $this, $URI, $method, $content_type, $body ));
			}
			return($this->fetch($URI,$method,$content_type,$body));
		}
		finally
		{
			// Source cookies and account sessions belong to this call.
			$this->cookies = $originalCookies;
			$this->secureResponseCookies = $originalSecureCookies;
			$this->flattenedResponseCookies = $originalFlattenedCookies;
			$this->selectedCookieAccount = $previousCookieAccount;
			list($this->requestCookieHost, $this->requestCookieScheme,
				$this->requestScopedCookies, $this->requestOriginalCookies,
				$this->requestAccountCookies, $this->loadedAccountCookies,
				$this->requestChainSourceURL) = $previousScope;
		}
	}

	/** Log a suppressed plugin cookie without exposing cookie data or URL userinfo. */
	private static function logPluginCookieRefusal($host, $account)
	{
		static $logged = array();
		$key = $host;
		if(isset($logged[$key]))
			return;
		$logged[$key] = true;
		$safeHost = self::safeLogHost($host);
		$details = 'source=cookies host=' . $safeHost;
		if(is_string($account) && $account !== '')
			$details .= ' account=' . preg_replace('/[^a-z0-9_.-]/i', '?', $account);
		FileUtil::toLog('cookies: http-refused: ' . $details . '; use HTTPS URL');
	}

	/** Keep caller cookies and account sessions on allowed redirects. */
	private function withoutSourceCookies($cookies)
	{
		foreach($this->requestScopedCookies as $name => $value)
		{
			if(!array_key_exists($name, $cookies) || $cookies[$name] !== $value
				|| isset($this->requestAccountCookies[$name]))
				continue;
			if(array_key_exists($name, $this->requestOriginalCookies))
				$cookies[$name] = $this->requestOriginalCookies[$name];
			else
				unset($cookies[$name]);
		}
		return $cookies;
	}

	protected function cookiesForRequest($url)
	{
		$host = UrlHost::of($url);
		$scheme = strtolower((string) @parse_url($url, PHP_URL_SCHEME));
		$cookies = $this->requestCookieHost !== null && $host === $this->requestCookieHost
			&& ($this->requestCookieScheme !== 'https' || $scheme === 'https')
			? $this->cookies : $this->withoutSourceCookies($this->cookies);
		if($scheme !== 'https')
		{
			foreach(array('secure-response' => $this->secureResponseCookies,
				'loginmgr-session' => $this->loadedAccountCookies) as $source => $restricted)
			{
				$refused = false;
				foreach($restricted as $name => $value)
					if(array_key_exists($name, $cookies) && $cookies[$name] === $value)
					{
						unset($cookies[$name]);
						$refused = true;
					}
				if($refused)
					self::logHttpsCookieRefusal($host, $source);
			}
		}
		return $cookies;
	}

	/** Public caller cookies stay flat; response cookies retain their wire scope. */
	private function cookiePairsForRequest($url)
	{
		$flat = $this->cookiesForRequest($url);
		if($this->_redirectdepth > 0
			&& !UrlHost::sameOrigin($this->requestChainSourceURL, $url)
			&& !UrlHost::isHttpsUpgrade($this->requestChainSourceURL, $url))
			foreach($flat as $name => $value)
				if($this->redirectTrust === null || !isset($this->loadedAccountCookies[$name])
					|| $this->loadedAccountCookies[$name] !== $value)
					unset($flat[$name]);
		foreach($flat as $name => $value)
			if($this->isFlattenedResponseCookie($name, $value))
				unset($flat[$name]);
		$pairs = array();
		foreach($flat as $name => $value)
			$pairs[] = array('name' => $name, 'value' => $value);
		$host = UrlHost::of($url);
		$scheme = strtolower((string) @parse_url($url, PHP_URL_SCHEME));
		$path = (string) @parse_url($url, PHP_URL_PATH);
		if($path === '' || $path[0] !== '/')
			$path = '/';
		$this->purgeResponseCookies();
		$matching = array();
		foreach($this->responseCookieJar as $key => $entry)
		{
			if($entry['account'] !== $this->cookiePartition()
				|| array_key_exists($entry['name'], $flat)
				|| !self::cookieDomainMatches($host, $entry['domain'], $entry['hostOnly'])
				|| !self::cookiePathMatches($path, $entry['path']))
				continue;
			if($entry['secure'] && $scheme !== 'https')
			{
				self::logHttpsCookieRefusal($host, 'secure-response');
				continue;
			}
			$this->responseCookieJar[$key]['lastUsed'] = ++$this->responseCookieSequence;
			$matching[] = $entry;
		}
		usort($matching, function($a, $b) {
			$length = strlen($b['path']) - strlen($a['path']);
			return $length ?: $a['created'] - $b['created'];
		});
		foreach($matching as $entry)
			$pairs[] = array('name' => $entry['name'], 'value' => $entry['value']);
		return $pairs;
	}

	/** Bind parser actions and stored tuples to the same account and wire scope. */
	private function responseCookieKey($domain, $hostOnly, $path, $name)
	{
		return $this->cookiePartition() . "\0" . $domain . "\0" . (int) $hostOnly . "\0" . $path . "\0" . $name;
	}

	private static function cookiePathMatches($requestPath, $cookiePath)
	{
		if(strncmp($requestPath, $cookiePath, strlen($cookiePath)) !== 0)
			return false;
		return $requestPath === $cookiePath || substr($cookiePath, -1) === '/'
			|| substr($requestPath, strlen($cookiePath), 1) === '/';
	}

	private function cookieHeaderForRequest($url)
	{
		foreach($this->rawheaders as $name => $value)
			if(strcasecmp((string) $name, 'Cookie') === 0)
				return $name . ': ' . $value;
		$pairs = $this->cookiePairsForRequest($url);
		$values = array();
		foreach($pairs as $pair)
			$values[] = $pair['name'] . '=' . $pair['value'];
		return $values ? 'Cookie: ' . implode('; ', $values) : '';
	}

	private function cookiePartition()
	{
		return $this->selectedCookieAccount === null ? 'anonymous'
			: 'account:' . $this->selectedCookieAccount;
	}

	/** Direct loginmgr refreshes also need an account cookie partition. */
	public function withAccountCookieScope($account, callable $body)
	{
		if($this->selectedCookieAccount !== null)
			return $body();
		$this->selectedCookieAccount = $account;
		try
		{
			return $body();
		}
		finally
		{
			$this->selectedCookieAccount = null;
		}
	}

	private function isFlattenedResponseCookie($name, $value)
	{
		return isset($this->flattenedResponseCookies[$name])
			&& !$this->flattenedResponseCookies[$name]['manual']
			&& $this->flattenedResponseCookies[$name]['value'] === $value
			&& (!array_key_exists($name, $this->requestOriginalCookies)
				|| $this->requestOriginalCookies[$name] !== $value);
	}

	/** Capture against the URL that answered, before another redirect changes headers. */
	private function captureResponseCookies($url)
	{
		$this->responseCookieActions = array();
		$host = UrlHost::of($url);
		if($host === null)
			return;
		$sourceScheme = strtolower((string) @parse_url($url, PHP_URL_SCHEME));
		$path = (string) @parse_url($url, PHP_URL_PATH);
		$lastSlash = strrpos($path, '/');
		$defaultPath = $lastSlash === false || $lastSlash === 0 ? '/'
			: substr($path, 0, $lastSlash);
		// Explicit setcookies() can run later than receipt, including when
		// automatic capture is off. It must not restart a Max-Age lifetime.
		$receivedAt = $this->lastResponseAt === null ? time() : $this->lastResponseAt;
		$seen = 0;
		foreach($this->headers as $header)
		{
			if(!preg_match('/^Set-Cookie:[ \t]*(.*)$/i', trim($header), $match))
				continue;
			if(++$seen > self::MAX_COOKIE_HEADERS)
			{
				self::logCookieRefusal($host, 'header-limit');
				break;
			}
			if(strlen($header) > self::MAX_COOKIE_HEADER_BYTES
				|| (strlen($header) >= $this->_maxlinelen - 1 && substr($header, -1) !== "\n"))
			{
				self::logCookieRefusal($host, 'oversized-or-truncated-header');
				continue;
			}
			$parts = explode(';', $match[1]);
			$nameValue = trim(array_shift($parts));
			$equal = strpos($nameValue, '=');
			if($equal === false)
			{
				self::logCookieRefusal($host, 'invalid-name-or-value');
				continue;
			}
			$name = trim(substr($nameValue, 0, $equal));
			$value = trim(substr($nameValue, $equal + 1));
			if(!preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D', $name)
				|| preg_match('/[\x00-\x1f\x7f]/', $value)
				|| strlen($name) + strlen($value) > self::MAX_COOKIE_BYTES)
			{
				self::logCookieRefusal($host, 'invalid-name-or-value');
				continue;
			}
			$attributes = array();
			foreach($parts as $part)
			{
				$equal = strpos($part, '=');
				$key = strtolower(trim($equal === false ? $part : substr($part, 0, $equal)));
				if($key !== '')
					$attributes[$key] = $equal === false ? '' : trim(substr($part, $equal + 1));
			}
			if(isset($attributes['secure']) && $sourceScheme !== 'https')
			{
				self::logCookieRefusal($host, 'secure-from-http-refused');
				continue;
			}
			$domain = $host;
			$hostOnly = true;
			if(isset($attributes['domain']))
			{
				$domain = strtolower($attributes['domain']);
				if(substr($domain, 0, 1) === '.')
					$domain = substr($domain, 1);
				try
				{
					if(!function_exists('filter_var'))
						throw new RuntimeException('Cookie IP validator is unavailable');
					$valid = filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) === false
						&& !PublicSuffix::isPublicSuffix($host)
						&& !PublicSuffix::isPublicSuffix($domain)
						&& UrlHost::isOneOf($host, array($domain));
				}
				catch(InvalidArgumentException $e)
				{
					$valid = false;
				}
				catch(Throwable $e)
				{
					self::logCookieRefusal($host, 'domain-validation-unavailable; check php/public_suffix_list.dat; Domain cookies dropped');
					continue;
				}
				if(!$valid)
				{
					self::logCookieRefusal($host, 'domain-refused');
					continue;
				}
				$hostOnly = false;
			}
			$cookiePath = isset($attributes['path']) && substr($attributes['path'], 0, 1) === '/'
				? $attributes['path'] : $defaultPath;
			if((stripos($name, '__Secure-') === 0 && !isset($attributes['secure']))
				|| (stripos($name, '__Host-') === 0
					&& (!isset($attributes['secure']) || !$hostOnly
						|| !isset($attributes['path']) || $cookiePath !== '/')))
			{
				self::logCookieRefusal($host, 'invalid-prefix');
				continue;
			}
			$expires = null;
			$maxAge = isset($attributes['max-age'])
				&& preg_match('/^-?[0-9]+$/D', $attributes['max-age']);
			if(isset($attributes['expires']) && !$maxAge)
			{
				try
				{
					if(!function_exists('strtotime'))
						throw new RuntimeException('Cookie expiry parser is unavailable');
					$parsed = strtotime($attributes['expires']);
				}
				catch(Throwable $e)
				{
					self::logCookieRefusal($host, 'expiry-unavailable');
					continue;
				}
				if(is_int($parsed))
					$expires = $parsed;
			}
			if($maxAge)
			{
				$age = (float) $attributes['max-age'];
				$expires = $age <= 0 ? $receivedAt : ($age >= PHP_INT_MAX - $receivedAt
					? PHP_INT_MAX : $receivedAt + (int) $age);
			}
			$key = $this->responseCookieKey($domain, $hostOnly, $cookiePath, $name);
			$this->purgeResponseCookies();
			if($sourceScheme !== 'https'
				&& $this->overwritesSecureCookie($name, $domain, $cookiePath))
			{
				self::logCookieRefusal($host, 'secure-overwrite-refused');
				continue;
			}
			if(($expires !== null && $expires <= time()) || $value === 'deleted')
			{
				unset($this->responseCookieJar[$key]);
				$this->responseCookieActions[] = array('name' => $name, 'key' => $key, 'delete' => true);
				continue;
			}
			$this->rememberResponseCookie($name, $value, $domain, $hostOnly,
				$cookiePath, isset($attributes['secure']), $expires);
			$this->responseCookieActions[] = array('name' => $name, 'value' => $value, 'key' => $key, 'delete' => false);
		}
	}

	private function rememberResponseCookie($name, $value, $domain, $hostOnly, $path, $secure, $expires)
	{
		$key = $this->responseCookieKey($domain, $hostOnly, $path, $name);
		$created = isset($this->responseCookieJar[$key])
			? $this->responseCookieJar[$key]['created'] : ++$this->responseCookieSequence;
		$this->responseCookieJar[$key] = array(
			'account' => $this->cookiePartition(), 'name' => $name, 'value' => $value,
			'domain' => $domain, 'hostOnly' => $hostOnly, 'path' => $path,
			'secure' => $secure, 'expires' => $expires,
			'created' => $created, 'lastUsed' => ++$this->responseCookieSequence,
		);
		$this->enforceCookieLimits($domain);
	}

	private function overwritesSecureCookie($name, $domain, $path)
	{
		foreach($this->responseCookieJar as $entry)
			if($entry['account'] === $this->cookiePartition()
				&& $entry['name'] === $name && $entry['secure']
				&& (UrlHost::isOneOf($domain, array($entry['domain']))
					|| UrlHost::isOneOf($entry['domain'], array($domain)))
				&& self::cookiePathMatches($path, $entry['path']))
				return true;
		return false;
	}

	private static function logCookieRefusal($host, $reason)
	{
		static $logged = array();
		$key = $reason . ' ' . $host;
		if(isset($logged[$key]))
			return;
		$logged[$key] = true;
		// Diagnostics must not turn rejected cookies into a failed HTTP response.
		try { @FileUtil::toLog('Snoopy: response-cookie-' . $reason . ' host=' . self::safeLogHost($host)); }
		catch(Throwable $e) {}
	}

	private function purgeResponseCookies()
	{
		$now = time();
		foreach($this->responseCookieJar as $key => $entry)
			if($entry['expires'] !== null && $entry['expires'] <= $now)
				unset($this->responseCookieJar[$key]);
	}

	private function enforceCookieLimits($domain)
	{
		$partition = $this->cookiePartition();
		while(count($this->responseCookieJar) > self::MAX_RESPONSE_COOKIES
			|| $this->countDomainCookies($domain, $partition) > self::MAX_DOMAIN_COOKIES)
		{
			$domainOver = $this->countDomainCookies($domain, $partition) > self::MAX_DOMAIN_COOKIES;
			$oldestKey = null;
			$oldestUse = PHP_INT_MAX;
			$oldestPriority = PHP_INT_MAX;
			foreach($this->responseCookieJar as $key => $entry)
			{
				if($domainOver && ($entry['domain'] !== $domain || $entry['account'] !== $partition))
					continue;
				$priority = $entry['secure'] ? 1 : 0;
				if($priority < $oldestPriority
					|| ($priority === $oldestPriority && $entry['lastUsed'] < $oldestUse))
				{
					$oldestKey = $key;
					$oldestUse = $entry['lastUsed'];
					$oldestPriority = $priority;
				}
			}
			if($oldestKey === null)
				break;
			unset($this->responseCookieJar[$oldestKey]);
			self::logCookieRefusal($domain, 'capacity-evicted');
		}
	}

	private function countDomainCookies($domain, $partition)
	{
		$count = 0;
		foreach($this->responseCookieJar as $entry)
			if($entry['domain'] === $domain && $entry['account'] === $partition)
				$count++;
		return $count;
	}

	private static function logAccountPortRefusal($host, $requested, $configured)
	{
		FileUtil::toLog('Snoopy: account-port-refused host=' . self::safeLogHost($host)
			. ' requested=' . (int) $requested . ' configured=' . (int) $configured);
	}

	private static function logHttpsCookieRefusal($host, $source)
	{
		static $logged = array();
		$key = $source . ' ' . $host;
		if(isset($logged[$key]))
			return;
		$logged[$key] = true;
		FileUtil::toLog('Snoopy: ' . $source . '-cookie-http-refused host=' . self::safeLogHost($host));
	}

	private static function safeLogHost($host)
	{
		return preg_replace('/[^a-z0-9.\[\]:-]/i', '?', (string) $host);
	}

	/** Keep caller cookies flat; response cookies retain their wire scope. */
	public function cookiesForAccountStorage()
	{
		$cookies = $this->withoutSourceCookies($this->cookies);
		foreach($cookies as $name => $value)
			if($this->isFlattenedResponseCookie($name, $value))
				unset($cookies[$name]);
		return $cookies;
	}

	public function responseCookiesForAccountStorage()
	{
		$this->purgeResponseCookies();
		$entries = array();
		if($this->selectedCookieAccount === null)
			return $entries;
		foreach($this->responseCookieJar as $entry)
			if($entry['account'] === $this->cookiePartition())
				$entries[] = array_intersect_key($entry, array_flip(array(
					'name', 'value', 'domain', 'hostOnly', 'path', 'secure', 'expires')));
		return $entries;
	}

	public function loadAccountResponseCookies($entries)
	{
		if($this->selectedCookieAccount === null || !is_array($entries))
			return;
		$now = time();
		foreach(array_slice($entries, 0, self::MAX_RESPONSE_COOKIES) as $entry)
		{
			if(!is_array($entry) || !isset($entry['name'], $entry['value'],
				$entry['domain'], $entry['path'], $entry['hostOnly'], $entry['secure'])
				|| !is_string($entry['name']) || !is_string($entry['value'])
				|| !is_string($entry['domain']) || !is_string($entry['path'])
				|| !is_bool($entry['hostOnly']) || !is_bool($entry['secure'])
				|| !preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D', $entry['name'])
				|| preg_match('/[\x00-\x1f\x7f]/', $entry['value'] . $entry['path'])
				|| strlen($entry['name']) + strlen($entry['value']) > self::MAX_COOKIE_BYTES
				|| strlen($entry['path']) > 2048 || substr($entry['path'], 0, 1) !== '/'
				|| !preg_match('/^[a-z0-9.\[\]:-]+$/iD', $entry['domain']))
				continue;
			$expires = isset($entry['expires']) ? $entry['expires'] : null;
			if($expires !== null && (!is_int($expires) || $expires <= $now))
				continue;
			$domain = UrlHost::normalize($entry['domain']);
			try
			{
				$validDomain = $domain !== ''
					&& ($entry['hostOnly'] || !PublicSuffix::isPublicSuffix($domain));
			}
			catch(Throwable $e)
			{
				self::logCookieRefusal($domain, 'domain-validation-unavailable; check php/public_suffix_list.dat; Domain cookies dropped');
				$validDomain = false;
			}
			if(!$validDomain)
				continue;
			$this->rememberResponseCookie($entry['name'], $entry['value'], $domain,
				$entry['hostOnly'], $entry['path'], $entry['secure'], $expires);
		}
	}

	/** A cached account session may legitimately reuse a source cookie name. */
	public function markAccountCookies($cookies)
	{
		foreach((array) $cookies as $name => $value)
		{
			$this->requestAccountCookies[$name] = true;
			$this->loadedAccountCookies[$name] = $value;
		}
	}

	public function fetch($URI,$method="GET",$content_type="",$body="")
	{
		$previousAttempt = $this->kinozalAttempt;
		$this->beginKinozalAttempt($URI, $method);
		$parts = @parse_url((string) $URI);
		$scopedCredentials = is_array($parts) && isset($parts['user']);
		if($scopedCredentials)
		{
			$previousUser = $this->user;
			$previousPass = $this->pass;
		}
		try
		{
			return $this->fetchRequest($URI,$method,$content_type,$body);
		}
		finally
		{
			// Normal hops finish before redirect recursion. Early local refusals
			// finish here, and a nested HostConnect fetch restores its parent's context.
			$this->finishKinozalAttempt(false);
			$this->kinozalAttempt = $previousAttempt;
			// URL userinfo belongs to this request chain, not to a reused client.
			if($scopedCredentials)
			{
				$this->user = $previousUser;
				$this->pass = $previousPass;
			}
		}
	}

	private function fetchRequest($URI,$method,$content_type,$body)
	{
		$this->error = "";
		$this->timed_out = false;
		$this->discardResponse();
		$this->pruneResponseCookieFacade();
		$this->lastResponseAt = null;
		$this->responseCookiesCaptured = false;
		$this->responseCookieActions = array();
		if($this->_redirectdepth === 0)
		{
			$this->lastredirectaddr = "";
			$this->_redirectaddr = false;
		}
		$this->lastResponseURL = "";
		if($this->requestDeadlineExpired())
			return false;
		$parts = parse_url($URI);
		if( !is_array($parts) || !isset($parts["scheme"]) || !isset($parts["host"]) )
		{
			$this->headers = array();
			$this->response_code = '';
			$this->_redirectaddr = false;
			return($this->refuseResponseBody('invalid-url'));
		}
		rTorrentSettings::get()->pushEvent( "HostConnect", array
		(
			"client" => &$this,
			"uri" => &$URI,
			"method" => &$method,
			"content_type" => &$content_type,
			"body" => &$body
		) );
		// HostConnect may rewrite the method. Categorize the effective request,
		// while keeping paths, query values and arbitrary method text out of logs.
		$target = KinozalResponse::httpLogTarget($URI, $method);
		if($target === null)
			$this->kinozalAttempt = null;
		else if($this->kinozalAttempt === null)
			$this->beginKinozalAttempt($URI, $method);
		else
			$this->kinozalAttempt = array_merge($this->kinozalAttempt, $target);
		if($this->_redirectdepth === 0)
			$this->requestChainSourceURL = $URI;
		$this->port = empty($parts["port"]) ? (($parts["scheme"]=="https") ? 443 : 80) : $parts["port"];
		// Keep the URI override after HostConnect, including empty userinfo.
		if(isset($parts['user']))
		{
			$this->user = rawurldecode($parts['user']);
			$this->pass = isset($parts['pass']) ? rawurldecode($parts['pass']) : '';
		}
		$this->host = $parts["host"];
		$this->_isproxy = (!empty($this->proxy_host) && !empty($this->proxy_port));
		$this->_pinnedaddr = false;
		// checkTarget() names in $error the rule that turned the host down.
		if($this->block_private && ($parts["scheme"]=="http" || $parts["scheme"]=="https") && !$this->checkTarget($this->host))
			return($this->refuseRequest());
		if($this->requestDeadlineExpired())
			return false;
		$throttle = self::throttleGroupFor($parts["scheme"], $this->host);
		if($throttle !== null && !$this->enterThrottle($throttle))
			return false;
		if($throttle !== null)
			// leaveThrottle() judges this request by its own status line; a
			// refused connect or deadline must not leave a previous one there.
			$this->response_code = '';
		$ret = true;
		switch($parts["scheme"])
		{
			case "dummy":
			{
				break;
			}
			case "http":
			{
				if($this->proxy_proto!='https' && $this->requestDeadline === null)
				{
					$this->beginKinozalTransport('socket');
					if(($fp = $this->connect())!==false)
					{
						$path = (isset($parts["path"]) ? $parts["path"] : "/").(isset($parts["query"]) ? "?".$parts["query"] : "");
						$requestTarget = $path;
						if($this->_isproxy)
						{
							$requestTarget = $URI;
							if($this->_pinnedaddr!==false)
							{
								$pinned = filter_var($this->_pinnedaddr,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)!==false
									? '['.$this->_pinnedaddr.']' : $this->_pinnedaddr;
								// A proxy must receive the checked IP in the absolute URI;
								// Host and cookie scope still refer to the original URL.
								$requestTarget = 'http://'.$pinned.($this->port == 80 ? '' : ':'.$this->port).$path;
							}
						}
						$ret = $this->_httprequest($requestTarget,$fp,$URI,$method,$content_type,$body);
						fclose($fp);
					}
					else
						$ret = false;
					break;
				}
			}
			case "https":
			{
				$ret = $this->_httpsrequest($URI,$content_type,$body,$method);
				break;
			}
			default:
			{
				$ret = $this->refuseRequest('invalid-protocol "'.$parts["scheme"].'"');
				break;
			}
		}
		if($throttle !== null)
			$this->leaveThrottle($ret);
		$this->finishKinozalAttempt($ret);
		if($ret)
		{
			$this->lastResponseURL = $URI;
			$this->lastResponseAt = time();
			if($this->passcookies)
			{
				$this->captureResponseCookies($URI);
				$this->responseCookiesCaptured = true;
			}
			rTorrentSettings::get()->pushEvent( "URLFetched", array
			(
				"client" => $this,
				"uri" => $URI,
				"method" => $method,
				"content_type" => $content_type,
				"body" => $body
			) );
			if($this->_redirectaddr)
			{
				// Callers classify login redirects even when credentials prevent following them.
				$this->lastredirectaddr = $this->_redirectaddr;
				$redirect = $this->_redirectaddr;
				if($this->maxredirs > $this->_redirectdepth)
				{
					$trusted = $this->trustsRedirect($URI, $redirect);
					if(!$trusted)
					{
						// Do not import this response's cookies on a different origin,
						// including a later explicit fetch on the same client.
						$this->_redirectaddr = false;
						// Account operations must not import cookies from a foreign
						// landing page into Snoopy's flat jar, even before login.
						if($this->redirectTrust !== null || $this->carriesOwnCredentials() || $this->cookiesForRequest($redirect))
						{
							$this->error = self::CREDENTIAL_REDIRECT_REFUSED;
							$this->logRedirectRefusal($URI, $redirect);
							// The origin answered. A login caller may explicitly retain
							// its Set-Cookie without following the refused redirect.
							return($ret);
						}
					}
					$this->_redirectdepth++;
					$previousHeaders = $this->rawheaders;
					if(!UrlHost::sameOrigin($URI, $redirect))
						foreach($this->rawheaders as $name => $value)
							if(in_array(strtolower((string) $name), array('if-none-match', 'if-modified-since'), true))
								unset($this->rawheaders[$name]);
					try
					{
						$ret = $this->fetch($redirect);
					}
					finally
					{
						$this->rawheaders = $previousHeaders;
						// A failed nested target may return before its transport clears this
						// pending import. Never carry the source response into a later fetch.
						$this->_redirectaddr = false;
						$this->_redirectdepth--;
					}
				}
				else
					$this->_redirectaddr = false;
			}
		}
		else
			$this->_redirectaddr = false;
		return($ret);
	}

	protected static function redirectOriginForLog($url)
	{
		$parts = @parse_url((string) $url);
		if(!is_array($parts) || !isset($parts['scheme']))
			return 'unknown';
		$scheme = strtolower($parts['scheme']);
		if($scheme !== 'http' && $scheme !== 'https')
			return 'unknown';
		$host = UrlHost::of($url);
		if($host === null)
			return 'unknown';
		// A Location header is remote input: never log its path, query or userinfo.
		$host = self::safeLogHost($host);
		$port = self::effectivePort($url);
		return $scheme . '://' . $host . ':' . $port;
	}

	protected function logRedirectRefusal($source, $target)
	{
		static $logged = array();
		$key = self::redirectOriginForLog($source) . ' -> ' . self::redirectOriginForLog($target);
		if(isset($logged[$key]))
			return;
		$logged[$key] = true;
		FileUtil::toLog('Snoopy: ' . self::CREDENTIAL_REDIRECT_REFUSED . ': ' . $key . '; redirect target not requested, original response retained');
	}

	protected function trustsRedirect($source, $target)
	{
		$targetParts = @parse_url((string) $target);
		if(!is_array($targetParts) || isset($targetParts['user']))
			return false;
		if($this->redirectTrust === null)
			return UrlHost::sameOrigin($source, $target) || UrlHost::isHttpsUpgrade($source, $target);
		if(!is_callable($this->redirectTrust)
			|| !call_user_func($this->redirectTrust, $source)
			|| !call_user_func($this->redirectTrust, $target))
			return false;
		// The selected account's configured port is an authorization boundary.
		// RFC cookie Domain matching above deliberately does not use ports.
		$configuredPort = $this->configuredAccountPort();
		$sourcePort = self::effectivePort($source);
		$targetPort = self::effectivePort($target);
		if($configuredPort !== null
			&& ($sourcePort !== $configuredPort || $targetPort !== $configuredPort))
			return false;
		if(UrlHost::sameOrigin($source, $target))
			return strtolower((string) @parse_url($source, PHP_URL_SCHEME)) === 'https';
		if($this->carriesOwnCredentials())
			return false;
		// A standard-port same-host HTTP to HTTPS upgrade keeps the existing
		// anonymous path. Account cookies themselves remain HTTPS-only.
		if($configuredPort === null && UrlHost::isHttpsUpgrade($source, $target))
			return true;
		return strtolower((string) @parse_url($source, PHP_URL_SCHEME)) === 'https'
			&& strtolower((string) @parse_url($target, PHP_URL_SCHEME)) === 'https'
			&& $sourcePort !== null && $sourcePort === $targetPort;
	}

	private static function effectivePort($url)
	{
		$parts = @parse_url((string) $url);
		if(!is_array($parts) || !isset($parts['scheme']))
			return null;
		$scheme = strtolower($parts['scheme']);
		if($scheme !== 'http' && $scheme !== 'https')
			return null;
		return isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
	}

	private function configuredAccountPort()
	{
		$trust = $this->redirectTrust;
		return is_array($trust) && isset($trust[0]) && is_object($trust[0])
			&& isset($trust[0]->url) ? self::effectivePort($trust[0]->url) : null;
	}

	protected function carriesOwnCredentials()
	{
		if($this->user !== '' || $this->pass !== '')
			return(true);
		// Conditional validators may follow anonymous redirects; these headers may not.
		foreach($this->rawheaders as $name => $value)
			if(in_array(strtolower($name), array('cookie', 'authorization', 'proxy-authorization'), true))
				return(true);
		return(false);
	}

	protected function checkTarget($host)
	{
		if(in_array($host,$this->private_allowlist,true))
			return(true);
		$literal = filter_var(trim($host,"[]"),FILTER_VALIDATE_IP)!==false;
		$addresses = static::resolveHost($host);
		if(empty($addresses))
		{
			$this->error = 'Refusing to fetch: cannot resolve host "'.$host.'".';
			return(false);
		}
		foreach($addresses as $address)
			if(!self::isPublicAddress($address))
			{
				$this->error = 'Refusing to fetch: host "'.$host.'" resolves to the non-public address '.$address.'.';
				return(false);
			}
		// A second lookup at connect time could answer differently, so the
		// address that passed the check is the one the request goes to.
		if(!$literal)
			$this->_pinnedaddr = $addresses[0];
		return(true);
	}

	static public function resolveHost($host)
	{
		$host = trim($host,"[]");
		if(filter_var($host,FILTER_VALIDATE_IP)!==false)
			return(array($host));
		$addresses = array();
		$records = @dns_get_record($host,DNS_A|DNS_AAAA);
		if(is_array($records))
			foreach($records as $record)
			{
				if(isset($record["ip"]))
					$addresses[] = $record["ip"];
				if(isset($record["ipv6"]))
					$addresses[] = $record["ipv6"];
			}
		if(empty($addresses))
		{
			$byname = @gethostbynamel($host);
			if(is_array($byname))
				$addresses = $byname;
		}
		return(array_values(array_unique($addresses)));
	}

	static public function isPublicAddress($address)
	{
		if(filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false)
			return(false);
		// Ranges filter_var accepts but that are not reachable on the public internet
		foreach(array("100.64.0.0/10","192.0.0.0/24","192.88.99.0/24","198.18.0.0/15") as $cidr)
			if(self::addressInCIDR($address,$cidr))
				return(false);
		return(true);
	}

	static protected function addressInCIDR($address,$cidr)
	{
		$ip = ip2long($address);
		list($network,$bits) = explode("/",$cidr);
		$network = ip2long($network);
		if($ip===false || $network===false)
			return(false);
		$mask = -1 << (32-intval($bits));
		return(($ip & $mask)===($network & $mask));
	}

	function get_header( $name )
	{
		foreach( $this->headers as $i=>$header )
			if(preg_match_all("/^$name:\s*(.*)/i",$header,$matches))
				return( $matches[1][0] );
		return(false);
	}

	function get_filename()
	{
		foreach( $this->headers as $i=>$header )
			if(preg_match_all("/^Content-Disposition:.*(\s|;)filename=\"(.*)\.torrent\"/i",$header,$matches) && (count($matches)>2))
				return( str_replace( '/', '_', rawurldecode($matches[2][0]).".torrent" ) );
		return(false);
	}

	function get_modified()
	{
		$value = $this->get_header( 'Last-Modified' );
		return( $value ? strtotime($value) : false );
	}

	protected function redirectFromHeader($header, $scheme)
	{
		$header = rtrim($header, "\r\n");
		if(!preg_match('/^(Location:|URI:)[ \t]*(.+)/i', $header, $matches)
			&& !preg_match('/(^Refresh:\s+\d+\s*;\s*url\s*=\s*)(.+)/i', $header, $matches))
			return false;
		$target = trim($matches[2]);
		if($target === '' || preg_match('/[[:space:][:cntrl:]]/', $target))
			return false;
		// A network-path reference names its own authority and inherits only
		// the current scheme; it is not a path on the requested host.
		if(strpos($target, '//') === 0)
			return $scheme . ':' . $target;
		if(strpos($target, '://') !== false)
			return $target;
		$host = isset($this->rawheaders['Host']) ? $this->rawheaders['Host'] : $this->host;
		return $scheme . '://' . $host . ':' . $this->port
			. ($target[0] === '/' ? $target : '/' . $target);
	}

	protected function refuseRequest($reason = null)
	{
		$this->discardResponse();
		$this->status = self::RESPONSE_BODY_FAILED;
		if($reason !== null)
			$this->error = $reason;
		return(false);
	}

	private function rememberThrottleResponse()
	{
		// Complete final headers own the limiter verdict even if the body fails.
		// Keep only these facts; discarded headers must not feed setcookies().
		if(($this->throttleReservation !== null || $this->kinozalAttempt !== null)
			&& preg_match('~^HTTP/\S+\s+(\d{3})~', (string)$this->response_code, $match)
			&& (int)$match[1] >= 200)
		{
			$http = (int)$match[1];
			$retryAfter = $http === 429 ? $this->retryAfterSeconds() : null;
			if($this->throttleReservation !== null)
				$this->throttleResponse = array($http, $retryAfter);
			if($this->kinozalAttempt !== null && ($this->kinozalAttempt['transport'] !== 'curl'
				|| ($this->kinozalAttempt['curl_origin'] ?? 0) === $http))
			{
				$this->kinozalAttempt['http'] = $http;
				$this->kinozalAttempt['retry_after_s'] = $retryAfter;
			}
		}
	}

	protected function refuseResponseBody($reason)
	{
		return($this->refuseRequest($reason));
	}

	// Reused clients and explicit setcookies() must never see a refused response.
	protected function discardResponse()
	{
		$this->headers = array();
		$this->response_code = '';
		$this->results = '';
		$this->_redirectaddr = false;
	}

	protected function refuseGzipBody()
	{
		if(!$this->hasZlibGzip())
			return($this->refuseResponseBody('gzip-decoder-unavailable'));
		return($this->refuseResponseBody('invalid-or-oversized-gzip'));
	}

	protected static function isGzipContentEncoding($header)
	{
		return(preg_match('/^Content-Encoding[ \t]*:[ \t]*gzip[ \t]*$/i',
			rtrim($header, "\r\n")) === 1);
	}

	protected function readFixedBytes($fp, $length)
	{
		$body = '';
		while(strlen($body) < $length)
		{
			$part = fread($fp, min(4096, $length - strlen($body)));
			if($this->_check_timeout($fp) || $part === false || $part === '')
				return(false);
			$body .= $part;
		}
		return($body);
	}

	protected function readChunkedBody($fp)
	{
		$body = '';
		$limit = (int)$this->maxlength;
		if($limit < 0)
			return(false);
		while(true)
		{
			$line = fgets($fp, $this->_maxlinelen);
			if($this->_check_timeout($fp) || $line === false || !preg_match('/^([0-9a-f]+)(?:;[^\r\n]*)?\r?\n$/i',
				$line, $match))
				return(false);
			$size = hexdec($match[1]);
			if($size > $limit - strlen($body))
				return(false);
			if($size == 0)
				break;
			$part = $this->readFixedBytes($fp, (int)$size);
			if($part === false || $this->readFixedBytes($fp, 2) !== "\r\n")
				return(false);
			$body .= $part;
		}
		$trailerBytes = 0;
		while(($line = fgets($fp, $this->_maxlinelen)) !== false)
		{
			if($this->_check_timeout($fp))
				return(false);
			$trailerBytes += strlen($line);
			if($trailerBytes > 8192)
				return(false);
			if($line === "\r\n" || $line === "\n")
				return($body);
			if(!preg_match('/^[^\r\n]+\r?\n$/', $line))
				return(false);
		}
		return(false);
	}

	protected function hasZlibGzip()
	{
		return(function_exists('inflate_init') && function_exists('inflate_add')
			&& function_exists('inflate_get_status') && function_exists('inflate_get_read_len'));
	}

	// Decode bounded chunks so a small compressed answer cannot expand without
	// limit. The old external fallback trusted gzip's exit status, which can ignore trailing bytes.
	protected function decodeGzipBody($encoded)
	{
		// A 304 or 204 may carry Content-Encoding: gzip with no body behind it
		// (RFC 9110 only says a 304 SHOULD NOT), and the RSS plugin reads a
		// 304 as "unchanged". An empty body is an empty body under its own
		// status, never a refusal (upstream #3370; curl writes no file then).
		if($encoded === '' || $encoded === false)
			return('');
		$limit = (int)$this->maxlength;
		if(!is_string($encoded) || strlen($encoded) < 10 || $limit < 0
			|| strlen($encoded) > $limit || substr($encoded, 0, 2) !== "\x1f\x8b")
			return(false);
		if($this->hasZlibGzip())
		{
			$decoded = '';
			$length = strlen($encoded);
			$consumed = 0;
			while($consumed < $length)
			{
				// gzip accepts zero padding after the final member.
				if(strspn($encoded, "\0", $consumed) === $length - $consumed)
					break;
				$inflater = @inflate_init(ZLIB_ENCODING_GZIP);
				if($inflater === false)
					return(false);
				for($offset = $consumed; $offset < $length; $offset += 1024)
				{
					$chunk = substr($encoded, $offset, 1024);
					$mode = $offset + strlen($chunk) === $length ? ZLIB_FINISH : ZLIB_SYNC_FLUSH;
					$part = @inflate_add($inflater, $chunk, $mode);
					if($part === false || strlen($part) > $limit - strlen($decoded))
						return(false);
					$decoded .= $part;
					if(inflate_get_status($inflater) === ZLIB_STREAM_END)
						break;
				}
				if(inflate_get_status($inflater) !== ZLIB_STREAM_END)
					return(false);
				$read = inflate_get_read_len($inflater);
				if($read <= 0)
					return(false);
				$consumed += $read;
			}
			return($decoded);
		}
		return(false);
	}

	function _httprequest($url,$fp,$URI,$http_method,$content_type="",$body="")
	{
		$this->discardResponse();
		if(empty($url))
			$url = "/";
		$headers = $http_method." ".$url." ".$this->_httpversion."\r\n";
		if(!empty($this->agent))
			$headers .= "User-Agent: ".$this->agent."\r\n";
		if(!empty($this->host) && !isset($this->rawheaders['Host']))
			$headers .= "Host: ".$this->host.($this->port == 80 ? "" : ":".$this->port)."\r\n";
		if(!empty($this->accept))
			$headers .= "Accept: ".$this->accept."\r\n";
		if($this->use_gzip && $this->hasZlibGzip())
			$headers .= "Accept-encoding: gzip\r\n";
		if(!empty($this->referer))
			$headers .= "Referer: ".$this->referer."\r\n";
		$cookieHeader = $this->cookieHeaderForRequest($URI);
		if($cookieHeader !== '')
			$headers .= $cookieHeader . "\r\n";
		foreach( $this->rawheaders as $headerKey => $headerVal )
			if(strcasecmp((string) $headerKey, 'Cookie') !== 0)
				$headers .= $headerKey.": ".$headerVal."\r\n";
		if(!empty($content_type))
		{
			$headers .= "Content-type: $content_type";
			if ($content_type == "multipart/form-data")
				$headers .= "; boundary=".$this->_mime_boundary;
			$headers .= "\r\n";
		}
		if(strlen((string)$body) > 0)
			$headers .= "Content-length: ".strlen($body)."\r\n";
		if(!empty($this->user) || !empty($this->pass))
			$headers .= "Authorization: Basic ".base64_encode($this->user.":".$this->pass)."\r\n";

		$headers .= "\r\n";

		// set the read timeout if needed
		if ($this->read_timeout > 0)
			// socket_set_timeout() is the same function under a name PHP 8.5
			// deprecates; stream_set_timeout() has existed since PHP 4.3.
			stream_set_timeout($fp, $this->read_timeout);
		$this->timed_out = false;
		fwrite($fp,$headers.$body,strlen($headers.$body));
		$this->_redirectaddr = false;
		$this->headers = array();
		$this->status = 0;
		$this->response_code = '';
		$is_gzipped = false;
		$transferCodings = array();
		$interimResponses = 0;
		$completeHeaders = false;

		while(($currentHeader = fgets($fp,$this->_maxlinelen)) !== false)
		{
			if ($this->read_timeout > 0 && $this->_check_timeout($fp))
			{
				$this->discardResponse();
				$this->status=-100;
				return false;
			}

			if(preg_match("/^\r?\n$/", $currentHeader))
			{
				$status = (int) $this->status;
				if($status >= 100 && $status < 200 && $status !== 101)
				{
					// A provisional block owns neither the final metadata nor
					// its body. 101 switches protocols rather than preceding it.
					if(++$interimResponses > self::MAX_INTERIM_RESPONSES)
						return $this->refuseResponseBody('too-many-interim-responses');
					$this->discardResponse();
					$this->status = 0;
					$is_gzipped = false;
					$transferCodings = array();
					continue;
				}
				$completeHeaders = true;
				break;
			}

			if(preg_match('/^(Location:|URI:|Refresh:)/i', $currentHeader))
			{
				$redirect = $this->redirectFromHeader($currentHeader, 'http');
				if($redirect !== false)
					$this->_redirectaddr = $redirect;
			}
			if(preg_match("|^HTTP/|",$currentHeader))
			{
		                if(preg_match("|^HTTP/[^\s]*\s(.*?)\s|",$currentHeader, $status))
				{
					$this->status= $status[1];
				}
				$this->response_code = $currentHeader;
			}

			if (self::isGzipContentEncoding($currentHeader))
				$is_gzipped = true;
			if(preg_match('/^Transfer-Encoding[ \t]*:/i', $currentHeader))
			{
				if(preg_match('/^Transfer-Encoding[ \t]*:[ \t]*(.*)\r?\n$/i',
					$currentHeader, $match))
					foreach(explode(',', $match[1]) as $coding)
						$transferCodings[] = strtolower(trim($coding));
				else
					$transferCodings[] = '';
			}

			$this->headers[] = $currentHeader;
		}
		// A timed-out fgets() returns false without entering the loop body.
		// Check before another read can clear the stream's timeout flag.
		if ($this->read_timeout > 0 && $this->_check_timeout($fp))
		{
			$this->discardResponse();
			$this->status=-100;
			return false;
		}

		if($this->response_code === ''
			|| ((int)$this->status >= 100 && (int)$this->status < 200 && (int)$this->status !== 101))
			return $this->refuseResponseBody('missing-final-response');
		if($completeHeaders)
			$this->rememberThrottleResponse();

		$is_chunked = $transferCodings === array('chunked')
			|| $transferCodings === array('gzip', 'chunked');
		$transferGzip = $transferCodings === array('gzip', 'chunked');
		if($transferCodings && !$is_chunked)
		{
			return($this->refuseResponseBody('unsupported-transfer-encoding'));
		}
		$results = $is_chunked ? $this->readChunkedBody($fp) : '';
		if($is_chunked && $results === false)
		{
			if($this->_check_timeout($fp))
			{
				$this->discardResponse();
				$this->status = -100;
				return(false);
			}
			return($this->refuseResponseBody('invalid-or-oversized-chunked'));
		}
		if(!$is_chunked)
		{
			while(($data = fread($fp, 4096)) !== false && $data !== '')
			{
				// Partial fread() bytes can already carry a timeout on PHP 7.4/8.1.
				if($this->_check_timeout($fp))
					break;
				$results .= $data;
				if(strlen($results) > $this->maxlength)
					break;
			}
			// fread() ends the loop on a timeout as it does at EOF; check before
			// the gzip decode refuses the truncated body as invalid gzip.
			if ($this->read_timeout > 0 && $this->_check_timeout($fp))
			{
				$this->discardResponse();
				$this->status=-100;
				return false;
			}
			if(strlen($results) > $this->maxlength)
			{
				return($this->refuseResponseBody('oversized-response'));
			}
		}
		foreach(array($transferGzip, $is_gzipped) as $encodedGzip)
		{
			if(!$encodedGzip)
				continue;
			$results = $this->decodeGzipBody($results);
			if($results === false)
			{
				return($this->refuseGzipBody());
			}
		}

		if ($this->read_timeout > 0 && $this->_check_timeout($fp))
		{
			$this->discardResponse();
			$this->status=-100;
			return false;
		}

		$this->results = $results;
		return true;
	}

	function _httpsrequest($url,$content_type="",$body="",$http_method="GET")
	{
		$this->discardResponse();
		$scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
		$defaultPort = $scheme === 'http' ? 80 : 443;
		$headers = array();
		if(!empty($this->agent))
			$headers[] = "User-Agent: ".$this->agent;
		if(!empty($this->host) && !isset($this->rawheaders['Host']))
			$headers[] = "Host: ".$this->host.($this->port == $defaultPort ? "" : ":".$this->port);
		if(!empty($this->accept))
			$headers[] = "Accept: ".$this->accept;
		if($this->use_gzip && $this->hasZlibGzip())
			$headers[] = "Accept-Encoding: gzip";
		if(!empty($this->referer))
			$headers[] = "Referer: ".$this->referer;
		$cookieHeader = $this->cookieHeaderForRequest($url);
		if($cookieHeader !== '')
			$headers[] = $cookieHeader;
		foreach( $this->rawheaders as $headerKey => $headerVal )
			if(strcasecmp((string) $headerKey, 'Cookie') !== 0)
				$headers[] = $headerKey.": ".$headerVal;
		if(!empty($content_type))
		{
			if ($content_type == "multipart/form-data")
				$headers[] = "Content-type: $content_type; boundary=".$this->_mime_boundary;
			else
				$headers[] = "Content-type: $content_type";
		}
		if(strlen((string)$body) > 0)
			$headers[] = "Content-length: ".strlen($body);
		if(!empty($this->user) || !empty($this->pass))
			$headers[] = "Authorization: Basic ".base64_encode($this->user.":".$this->pass);

		$cmdline_params = '';
		if($scheme === 'http' && $this->requestDeadline !== null && $this->_httpversion === 'HTTP/1.0')
			$cmdline_params .= ' --http1.0';
		// Legacy 3-arg callers keep curl's own method choice; explicit callers
		// (fetch with any method) get the method forwarded verbatim.
		if(func_num_args() >= 4)
			$cmdline_params .= " -X ".escapeshellarg(strtoupper($http_method));
		foreach( $headers as $header )
			$cmdline_params .= " -H ".escapeshellarg($header);

		$bodyfile = null;
		if(strlen((string)$body) > 0)
		{
			// A body is bytes, and the command line is not a way to carry
			// bytes: escapeshellarg() keeps only what is a valid character in
			// the process locale and drops the rest, a single argument is
			// capped well below the size of a request body, and every argument
			// is readable in the process table by anyone on the host, where a
			// body may be carrying credentials. A file has none of those
			// limits, and the name is one this code generated, so the leading
			// @ that makes curl read it cannot be mistaken for part of a body.
			// --data-binary and not -d, which strips line breaks out of what
			// it reads from a file.
			$bodyfile = FileUtil::getTempFilename('https-request-body');
			$mask = umask(0177);
			$written = @file_put_contents($bodyfile, (string)$body);
			umask($mask);
			if($written !== strlen((string)$body))
			{
				@unlink($bodyfile);
				return $this->refuseRequest('request-body-not-written');
			}
			$cmdline_params .= " --data-binary ".escapeshellarg("@".$bodyfile);
		}

		if(!is_null($this->IP))
			$cmdline_params .= " --interface ".$this->IP;

		if($this->useIpv4)
			$cmdline_params .= " --ipv4";

		$curlURL = $url;
		if($this->_pinnedaddr!==false)
		{
			$pinned = (filter_var($this->_pinnedaddr,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)!==false)
				? "[".$this->_pinnedaddr."]" : $this->_pinnedaddr;
			// With a proxy, --resolve only changes local DNS; the proxy would
			// still resolve a hostname in CONNECT or an absolute HTTP target.
			if($this->_isproxy && parse_url($url,PHP_URL_SCHEME)==='http')
			{
				$path = parse_url($url,PHP_URL_PATH) ?: '/';
				$query = parse_url($url,PHP_URL_QUERY);
				$target = 'http://'.$pinned.($this->port == 80 ? '' : ':'.$this->port)
					.$path.($query === null ? '' : '?'.$query);
				// curl 7.74 ignores --request-target in absolute proxy requests.
				// The transfer URL itself must name the checked address; headers,
				// cookies and response/redirect metadata keep the original URL.
				$curlURL = $target;
			}
			else
			{
				$pinOption = $this->_isproxy ? '--connect-to' : '--resolve';
				$pinValue = $this->_isproxy
					? $this->host.':'.$this->port.':'.$pinned.':'.$this->port
					: $this->host.':'.$this->port.':'.$pinned;
				$cmdline_params .= ' '.$pinOption.' '.escapeshellarg($pinValue);
			}
		}

		if($this->block_private)
			// Environment NO_PROXY/HTTPS_PROXY must not change which pinning path curl uses.
			$cmdline_params .= ' --noproxy '.escapeshellarg($this->_isproxy ? '' : '*');

		if($this->_isproxy)
		{
			if(!$this->verify_certificates)
				$cmdline_params .= " --proxy-insecure";
			$cmdline_params .= " --proxy ".(empty($this->proxy_proto) ? '' : $this->proxy_proto.'://').$this->proxy_host.":".$this->proxy_port;
		}

		// Recompute immediately before starting curl. Redirects and later fetches
		// on this client spend the remaining budget instead of restarting it.
		if($this->requestDeadlineExpired())
		{
			if($bodyfile !== null)
				@unlink($bodyfile);
			return false;
		}
		$timeout = $this->requestTimeLeft();
		if($this->read_timeout > 0)
			$timeout = $timeout === null ? $this->read_timeout : min($timeout, $this->read_timeout);
		if($this->requestDeadline === null && $this->read_timeout > 0)
			$cmdline_params .= ' -m ' . $this->read_timeout;
		else if($timeout !== null)
			// curl 8.14 misreads six fractional digits (0.999999 becomes
			// 99ms). Its timeout is millisecond-based, so truncate to that unit.
			$cmdline_params .= ' -m ' . sprintf('%.3F', max(0.001, floor($timeout * 1000) / 1000));

		$headerfile = FileUtil::getTempFilename('https-header');
		$contfile = FileUtil::getTempFilename('https-body');

		if(!$this->verify_certificates)
			$cmdline_params .= " -k";
		$cmdline_params .= " -s ";
		// escapeshellarg() keeps only the bytes that form a character in the
		// process locale and drops the rest, so a url is not something the
		// command line carries as it stands: under C every byte above ASCII
		// goes, and a six-byte path arrives as four -- a request for a
		// resource nobody asked for, with nothing to say it happened.
		// Percent-encoding those bytes first leaves the locale nothing to
		// decide, and in a path it is what curl does with them in any case.
		// The authority keeps its own bytes: a non-ASCII host is curl's to
		// resolve, and it has an IDN library for that.
		$authority = strpos($curlURL, '://');
		$authority = ($authority === false) ? 0 : $authority + 3;
		$target = $authority + strcspn($curlURL, '/?#', $authority);
		$encoded = '';
		for($at = $target, $end = strlen($curlURL); $at < $end; $at++)
			$encoded .= (ord($curlURL[$at]) < 0x80) ? $curlURL[$at] : sprintf('%%%02X', ord($curlURL[$at]));
		$curlURL = substr($curlURL, 0, $target).$encoded;

		// The shipped curl (8.22) stops even an unknown-length response during
		// transfer, before the temporary body file can grow without bound.
		$maxDownload = max(1, (int)$this->maxlength);
		try
		{
			$this->beginKinozalTransport('curl');
			exec(escapeshellarg(Utility::getExternal('curl'))." -g --max-filesize ".$maxDownload
				." -D ".escapeshellarg($headerfile)." -o ".escapeshellarg($contfile)
				." --write-out ".escapeshellarg('%{http_code}')." ".$cmdline_params." ".escapeshellarg($curlURL),$results,$return);
		}
		finally
		{
			if($bodyfile !== null)
				@unlink($bodyfile);
		}
		$originStatus = count($results) === 1 && preg_match('/^[0-9]{3}$/D', $results[0])
			? (int)$results[0] : 0;
		if($this->kinozalAttempt !== null)
			$this->kinozalAttempt['curl_origin'] = $originStatus;
		$this->_redirectaddr = false;
		$this->headers = array();
		$this->status = $return;
		$this->response_code = "";
		$this->results = "";
		if($return)
		{
			if($return === 28)
			{
				// curl's transfer code excludes CONNECT replies: a tunnel can
				// succeed before TLS times out without any origin response.
				$result_headers = @file($headerfile);
				if($originStatus >= 200 && is_array($result_headers))
					$this->readCurlHeaders($result_headers, $scheme, $originStatus);
				$this->discardResponse();
				$this->status = $return;
			}
			if($return === 63)
			{
				$this->error = 'oversized-response';
				// curl stopped the body after it wrote the headers. Keep the
				// answer's status line and headers (a throttled 429 reads its
				// Retry-After), but follow nothing from a refused answer.
				$result_headers = @file($headerfile);
				if(is_array($result_headers))
					$this->readCurlHeaders($result_headers, $scheme);
				$this->status = $return;
				$this->_redirectaddr = false;
			}
			else if($this->verify_certificates && in_array($return,array(51,60,77),true))
			{
				$whose = $this->_isproxy
					? "\"".$this->host."\", or of the proxy it was reached through,"
					: "\"".$this->host."\"";
				$this->error = "Error: cURL could not verify the certificate of ".$whose." (error $return). "
					."Install the certificate authority that signed it, or set \$httpVerifyCertificates to false in conf/config.php to stop checking.";
			}
			else
				$this->error = "Error: cURL could not retrieve the document, error $return.";
		}
		else
		{
			$result_headers = @file($headerfile);
			if(!is_array($result_headers))
			{
				@unlink($headerfile);
				@unlink($contfile);
				return $this->refuseResponseBody('missing-final-response');
			}
			$is_gzipped = $this->readCurlHeaders($result_headers, $scheme);
			if($this->response_code === ''
				|| ((int)$this->status >= 100 && (int)$this->status < 200 && (int)$this->status !== 101))
			{
				@unlink($headerfile);
				@unlink($contfile);
				return $this->refuseResponseBody('missing-final-response');
			}
			// Captured HTTPS 304 replies do not make curl create the -o file.
			// Parse the final origin status first; gzip on HEAD/304 describes
			// the representation, not a compressed body in this response.
			$bodyless = strtoupper($http_method) === 'HEAD'
				|| in_array((int)$this->status, array(204, 205, 304), true);
			$limit = max(0, (int)$this->maxlength);
			$results = @file_get_contents($contfile, false, null, 0, $limit + 1);
			if($results === false && $bodyless && !file_exists($contfile))
				$results = '';
			if($results === false || strlen($results) > $limit)
			{
				@unlink($headerfile);
				@unlink($contfile);
				return($this->refuseResponseBody('unreadable-or-oversized-response'));
			}
			if($bodyless)
				$results = '';
			else if($is_gzipped)
			{
				$results = $this->decodeGzipBody($results);
				if($results === false)
				{
					@unlink($headerfile);
					@unlink($contfile);
					return($this->refuseGzipBody());
				}
			}
			$this->results = $results;
		}
		@unlink($headerfile);
		@unlink($contfile);
		return(!$return);
	}

	// Reads the header lines curl wrote with -D into the status, response line,
	// headers and redirect target. Returns whether the answer is gzip-encoded.
	private function readCurlHeaders($result_headers, $scheme, $originStatus = null)
	{
		$is_gzipped = false;
		foreach($result_headers as $header)
		{
			if(preg_match('/^(Location:|URI:|Refresh:)/i', $header))
			{
				$redirect = $this->redirectFromHeader($header, $scheme);
				if($redirect === false)
					continue;
				$this->_redirectaddr = $redirect;
			}
			if(preg_match("|^HTTP/|",$header))
			{
				// curl -D includes CONNECT and provisional responses before
				// the origin's final block. None of their metadata survives it.
				$this->headers = array();
				$this->_redirectaddr = false;
				$this->status = 0;
				$is_gzipped = false;
				$this->response_code = $header;
				if(preg_match("|^HTTP/[^\s]*\s(.*?)\s|",$this->response_code, $match))
					$this->status= $match[1];
			}
			if (self::isGzipContentEncoding($header))
				$is_gzipped = true;
			$this->headers[] = $header;
		}
		$lastHeader = end($this->headers);
		if(($lastHeader === "\r\n" || $lastHeader === "\n")
			&& ($originStatus === null || (int)$this->status === $originStatus))
			$this->rememberThrottleResponse();
		return($is_gzipped);
	}

	private function pruneResponseCookieFacade()
	{
		$this->purgeResponseCookies();
		foreach($this->flattenedResponseCookies as $name => $source)
		{
			if(!array_key_exists($name, $this->cookies) || $this->cookies[$name] !== $source['value'])
			{
				unset($this->flattenedResponseCookies[$name]);
				continue;
			}
			if(!isset($this->responseCookieJar[$source['key']])
				|| $this->responseCookieJar[$source['key']]['value'] !== $source['value'])
			{
				if(!$source['manual'])
					unset($this->cookies[$name]);
				unset($this->flattenedResponseCookies[$name]);
			}
		}
	}

	function setcookies()
	{
		$this->pruneResponseCookieFacade();
		if($this->lastResponseURL === "")
		{
			foreach($this->headers as $header)
			{
				// Neither a cookie name nor its value carries a control
				// character. A value bounded only by ";" takes the line
				// terminator of an attributeless Set-Cookie with it, and what is
				// stored here is written into an outgoing request header by hand.
				if(preg_match("/^set-cookie:[\s]+([^;=[:cntrl:]]+)=([^;[:cntrl:]]+)/i", $header,$match))
				{
					unset($this->flattenedResponseCookies[$match[1]]);
					if($match[2]=="deleted")
					{
						unset($this->cookies[$match[1]], $this->requestAccountCookies[$match[1]],
							$this->secureResponseCookies[$match[1]], $this->loadedAccountCookies[$match[1]],
							$this->flattenedResponseCookies[$match[1]]);
					}
					else
					{
						$captured = false;
						foreach($this->responseCookieJar as $entry)
							if($entry['account'] === $this->cookiePartition()
								&& $entry['name'] === $match[1] && $entry['value'] === $match[2])
							{
								$captured = true;
								break;
							}
						// Login responses already passed Domain, Path and expiry validation
						// in captureResponseCookies(). Never reintroduce rejected values
						// through this legacy flat-cookie facade.
						if($this->selectedCookieAccount !== null && !$captured)
							continue;
						$this->cookies[$match[1]] = $match[2];
						$this->requestAccountCookies[$match[1]] = true;
						if(isset($this->loadedAccountCookies[$match[1]]))
							$this->loadedAccountCookies[$match[1]] = $match[2];
						$attributes = substr($header, strlen($match[0]));
						if(preg_match('/(?:^|;)\s*secure\s*(?:=|;|$)/i', $attributes))
							$this->secureResponseCookies[$match[1]] = $match[2];
						else
							unset($this->secureResponseCookies[$match[1]]);
					}
				}
			}
			return;
		}
		if(!$this->responseCookiesCaptured)
		{
			$this->captureResponseCookies($this->lastResponseURL);
			$this->responseCookiesCaptured = true;
		}
		$this->pruneResponseCookieFacade();
		// Keep the public flat facade, but export only the accepted actions of
		// the same parser that owns the jar. Refused/expired values cannot be
		// resurrected by saving that facade and loading it into a fresh client.
		foreach($this->responseCookieActions as $action)
		{
			$name = $action['name'];
			if($action['delete'])
			{
				// A flat facade can name only one tuple. Deleting another Path,
				// host-only flag or Domain must not erase its still-live source.
				if(!isset($this->flattenedResponseCookies[$name])
					|| $this->flattenedResponseCookies[$name]['key'] === $action['key'])
					unset($this->cookies[$name], $this->flattenedResponseCookies[$name],
						$this->requestAccountCookies[$name], $this->secureResponseCookies[$name],
						$this->loadedAccountCookies[$name]);
				continue;
			}
			if(!isset($this->responseCookieJar[$action['key']])
				|| $this->responseCookieJar[$action['key']]['value'] !== $action['value'])
				continue;
			$value = $action['value'];
			$manualSame = array_key_exists($name, $this->cookies) && $this->cookies[$name] === $value
				&& (!isset($this->flattenedResponseCookies[$name]) || $this->flattenedResponseCookies[$name]['manual']);
			$this->cookies[$name] = $value;
			$this->flattenedResponseCookies[$name] = array('value' => $value, 'key' => $action['key'], 'manual' => $manualSame);
			$this->requestAccountCookies[$name] = true;
			if(isset($this->loadedAccountCookies[$name]))
				$this->loadedAccountCookies[$name] = $value;
			if($this->responseCookieJar[$action['key']]['secure'])
				$this->secureResponseCookies[$name] = $value;
			else
				unset($this->secureResponseCookies[$name]);
		}
	}

	function _check_timeout($fp)
	{
		if ($this->read_timeout > 0)
		{
			$fp_status = socket_get_status($fp);
			if ($fp_status["timed_out"])
			{
				$this->timed_out = true;
				return true;
			}
		}
		return false;
	}

	function connect()
	{
		$fp = false;
		if($this->_isproxy)
		{
			$host = $this->proxy_host;
			$port = $this->proxy_port;
		}
		else
		{
			$host = ($this->_pinnedaddr===false) ? $this->host : $this->_pinnedaddr;
			$port = $this->port;
		}
		if(filter_var(trim($host,"[]"),FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)!==false)
			$host = "[".trim($host,"[]")."]";
		$this->status = 0;
		if(function_exists("stream_socket_client") && !is_null($this->IP))
		{
			$opts = array('socket' => array('bindto' => $this->IP.':0'));
			$context = stream_context_create($opts);
			$fp = stream_socket_client("tcp://".$host.":".$port, $errno, $errstr, $this->_fp_timeout, STREAM_CLIENT_CONNECT, $context);
		}
		else
			$fp = fsockopen($host,$port,$errno,$errstr,$this->_fp_timeout);
		if(!$fp)
		{
			// A request whose socket never opened has no response of its own,
			// and the one still in place describes whatever the client last
			// fetched. Callers reuse a client for a sequence of fetches and
			// read $results and $headers rather than the return value.
			$this->discardResponse();
			// Positive OS errno values overlap HTTP statuses. Keep the errno in
			// the message and use a distinct transport status, not -100 or -101.
			$this->status = $errno > 0 ? self::SOCKET_CONNECT_FAILED : $errno;
			// PHP's resolver can fail with errno 0. Keep its known category,
			// without copying a hostname or arbitrary system text into logs.
			if($errno === 0 && strpos((string)$errstr, 'php_network_getaddresses:') === 0)
			{
				$this->error = 'dns lookup failure (0)';
				return(false);
			}
			switch($errno)
			{
				case -3:
					$this->error="socket creation failed (-3)";
					break;
				case -4:
					$this->error="dns lookup failure (-4)";
					break;
				case -5:
					$this->error="connection refused or timed out (-5)";
					break;
				default:
					$this->error="connection failed (".$errno.")";
			}
		}
		return($fp);
	}
}
