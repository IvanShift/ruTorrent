<?php
/**
 * A filtered XMLRPC endpoint for rtorrent.
 *
 * Point your web server's XMLRPC location at this file instead of passing the
 * request straight to rtorrent's SCGI socket. The usual recipe —
 *
 *     location /RPC2 { include scgi_params; scgi_pass 127.0.0.1:5000; }
 *
 * — publishes rtorrent's whole command surface, execute.capture included, to
 * anyone who gets past the web server's authentication. rtorrent runs commands
 * as its own user, so that is a shell.
 *
 * This applies the same filtering ruTorrent's httprpc proxy applies, from the
 * same policy in conf/xmlrpc_proxy.php, and forwards what survives.
 *
 *     location = /RPC2 {
 *         # authenticate the caller however you already do
 *         include fastcgi_params;
 *         fastcgi_param SCRIPT_FILENAME /path/to/rutorrent/rpc2.php;
 *         fastcgi_param RUTORRENT_XMLRPC_ENDPOINT on;
 *         fastcgi_pass unix:/run/php/php-fpm.sock;
 *     }
 *
 * RUTORRENT_XMLRPC_ENDPOINT is required, and without it this file does
 * nothing. It is what stops the endpoint also being reachable at its own URL
 * under the ruTorrent docroot, through whatever authentication the rest of
 * ruTorrent uses — which would make tightening the XMLRPC credential
 * pointless. Set it in the one location block you meant to expose, and the
 * endpoint has exactly one door.
 *
 * It does not authenticate. That is the web server's job here, exactly as it
 * is for ruTorrent itself.
 */

// Not reachable except from the location block the operator wrote for it.
if(!isset($_SERVER['RUTORRENT_XMLRPC_ENDPOINT']) ||
	($_SERVER['RUTORRENT_XMLRPC_ENDPOINT'] !== 'on'))
{
	header('HTTP/1.1 404 Not Found');
	exit;
}

require_once(dirname(__FILE__).'/conf/config.php');
require_once(dirname(__FILE__).'/php/scgitransport.php');
require_once(dirname(__FILE__).'/php/xmlrpc_path.php');
require_once(dirname(__FILE__).'/php/xmlrpc_proxy.php');

$policyFile = dirname(__FILE__).'/conf/xmlrpc_proxy.php';
if(is_file($policyFile) && is_readable($policyFile))
	require_once($policyFile);

$mode = isset($XMLRPCProxy) ? $XMLRPCProxy : 'sanitize';
$logging = isset($XMLRPCProxyLog) ? $XMLRPCProxyLog : true;
$safeParams = isset($XMLRPCProxySafeParams) ? $XMLRPCProxySafeParams : array();
$allowLocalPaths = isset($XMLRPCProxyAllowLocalPaths) ? $XMLRPCProxyAllowLocalPaths : false;
$allowRootDirectory = isset($XMLRPCProxyAllowRootDirectory) ? $XMLRPCProxyAllowRootDirectory : false;

/**
 * Written here rather than through FileUtil so that this file needs nothing
 * but the proxy and the configuration — the point of the endpoint is that it
 * does one thing.
 */
function rpc2_log($message)
{
	global $logging, $log_file;
	if(!$logging)
		return;
	$line = date('d.m.Y H:i:s').' rpc2: '.str_replace(array("\r", "\n"), ' ', $message)."\n";
	if(!empty($log_file) && (@file_put_contents($log_file, $line, FILE_APPEND | LOCK_EX) !== false))
		return;
	error_log('rpc2: '.$line);
}

function rpc2_fault($status, $message)
{
	header('HTTP/1.1 '.$status);
	header('Content-Type: text/xml');
	echo '<?xml version="1.0" encoding="UTF-8"?>'."\n"
		.'<methodResponse><fault><value><struct>'
		.'<member><name>faultCode</name><value><i4>-501</i4></value></member>'
		.'<member><name>faultString</name><value><string>'
		.htmlspecialchars($message, ENT_NOQUOTES, 'UTF-8')
		.'</string></value></member>'
		.'</struct></value></fault></methodResponse>';
	exit;
}

/**
 * One SCGI request to rtorrent, with the trust the policy decided on. Same
 * wire format ruTorrent's rXMLRPCRequest::send() uses; kept here so the
 * endpoint does not need ruTorrent's settings bootstrap to make a call.
 */
function rpc2_send($payload, $trusted)
{
	global $scgi_host, $scgi_port, $rpcTimeOut;
	$err = null;
	$res = rSCGITransport::send($scgi_host, $scgi_port, $payload, $trusted, $rpcTimeOut, $err);
	if($res === null)
	{
		if($err !== null)
			rpc2_log($err);
		return null;
	}
	return $res['body'];
}

if(!isset($_SERVER['REQUEST_METHOD']) || ($_SERVER['REQUEST_METHOD'] !== 'POST'))
{
	header('HTTP/1.1 405 Method Not Allowed');
	header('Allow: POST');
	exit;
}

// A caller may name the directory a download is written into, so the endpoint
// has to know what is out of bounds before it answers anything. $topDirectory
// is ruTorrent's own answer and correctDirectory() already holds the panel to
// it; stock ruTorrent ships it as "/", which is not a boundary. Rather than
// apply a check that confines nothing, refuse to serve until somebody has said
// which it is.
$topDirectory = isset($topDirectory) ? trim($topDirectory) : '';
if((($topDirectory === '') || ($topDirectory === '/')) && !$allowRootDirectory)
{
	rpc2_log('refusing to serve: $topDirectory is "'.$topDirectory.'"'
		.' and $XMLRPCProxyAllowRootDirectory is false');
	rpc2_fault('503 Service Unavailable',
		'This XMLRPC endpoint is not configured: set $topDirectory in conf/config.php '
		.'to the directory downloads may be written under, or set '
		.'$XMLRPCProxyAllowRootDirectory = true in conf/xmlrpc_proxy.php to allow any path.');
}

$raw = file_get_contents('php://input');
if($raw === false || ($raw === ''))
{
	// An empty body is what arrives when post_max_size is smaller than the
	// request, which is easy to hit when a client adds a torrent by file.
	rpc2_log('empty request body (check post_max_size against the largest torrent you add)');
	rpc2_fault('400 Bad Request', 'Empty XMLRPC request.');
}

$decision = XMLRPCProxy::decide($raw, $mode, $safeParams, $allowLocalPaths, array(
	'directory' => array(
		'root'    => ($topDirectory === '') ? '/' : $topDirectory,
		'resolve' => array('XMLRPCPathResolver', 'deepestExistingAncestor'),
	),
));
foreach($decision['log'] as $line)
	rpc2_log($line);

if($decision['action'] !== 'send')
	rpc2_fault('403 Forbidden', 'This XMLRPC call is not allowed on this endpoint.');

$result = rpc2_send($decision['payload'], $decision['trusted']);
if($result === null)
	rpc2_fault('502 Bad Gateway', 'Could not reach rTorrent over XMLRPC. Is rTorrent running?');

header('Content-Type: text/xml');
header('Content-Length: '.strlen($result));
echo $result;
