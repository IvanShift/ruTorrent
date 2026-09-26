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
require_once(dirname(__FILE__).'/php/xmlrpc_path.php');
require_once(dirname(__FILE__).'/php/xmlrpc_proxy.php');

if(!require(dirname(__FILE__).'/php/xmlrpc_proxy_policy.php'))
{
	// Configuration failure must remain visible even if logging is off.
	$logging = true;
	rpc2_log('conf/xmlrpc_proxy.php exists but is not readable; proxy disabled');
	rpc2_fault('503 Service Unavailable', 'XMLRPC proxy policy is not readable.');
}
$policy = XMLRPCProxy::policySettings(get_defined_vars());
$mode = $policy['mode'];
$logging = $policy['log'];
if($policy['policyError'] !== null)
{
	// Configuration errors stay visible even when routine decision logging is off.
	$logging = true;
	rpc2_log($policy['policyError']);
	rpc2_fault('503 Service Unavailable', $policy['policyError']);
}
// An explicit empty list stays an override. A tree with no policy file at
// all gets the shipped list, so this door and httprpc decide the same request.
//
// This door reads conf/xmlrpc_proxy.php and nothing else -- not
// plugins/httprpc/conf.php, where an install older than the shared file may
// still keep its list. Such an install used to get an empty list here, that
// is every multicall refused; it now gets the shipped default, which is wider
// than a list it trimmed on purpose. Move the list into conf/xmlrpc_proxy.php
// to have the one policy at both doors.
$safeParams = $policy['safeParams'];
$allowLocalPaths = $policy['allowLocalPaths'];
$allowRootDirectory = $policy['allowRootDirectory'];

/**
 * Written here rather than through FileUtil so that this file needs nothing
 * but the proxy and the configuration — the point of the endpoint is that it
 * does one thing.
 */
function rpc2_log($message, $force = false)
{
	global $logging, $log_file;
	if(!$logging && !$force)
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
	echo XMLRPCProxy::faultXml($message);
	exit;
}

/**
 * One SCGI request to rtorrent, with the trust the policy decided on. It uses
 * the shared transport directly so this endpoint does not need ruTorrent's
 * settings bootstrap to make a call.
 */
function rpc2_send($payload, $trusted)
{
	require_once(dirname(__FILE__).'/php/scgitransport.php');
	global $scgi_host, $scgi_port, $rpcTimeOut, $rpcTransferTimeOut, $rpcMaxResponseBytes, $rpcLogCalls;
	// Explicit call tracing records exact bytes safely on one line, including
	// failures, even when routine proxy decision logging is disabled.
	if(!empty($rpcLogCalls))
		rpc2_log('rpc-call request base64='.base64_encode($payload), true);
	$failure = null;
	$result = rSCGITransport::send($scgi_host, $scgi_port, $payload, $trusted,
		isset($rpcTimeOut) ? $rpcTimeOut : 30, $failure,
		isset($rpcTransferTimeOut) ? $rpcTransferTimeOut : null,
		isset($rpcMaxResponseBytes) ? $rpcMaxResponseBytes : null,
		rSCGITransport::RESPONSE_BODY);
	if(!empty($rpcLogCalls))
		rpc2_log($result === null ? 'rpc-call response: transport-failed'
			: 'rpc-call response base64='.base64_encode($result), true);
	if($result === null)
		rpc2_log($failure);
	return $result;
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
if($raw === false)
{
	rpc2_log('could not read request body');
	rpc2_fault('400 Bad Request', 'Could not read XMLRPC request.');
}
if($raw === '')
{
	rpc2_log('empty request body');
	rpc2_fault('400 Bad Request', 'Empty XMLRPC request.');
}

$proxyOptions = array('directory' => array(
	'root'    => ($topDirectory === '') ? '/' : $topDirectory,
	'resolve' => array('XMLRPCPathResolver', 'deepestExistingAncestor'),
));
if($mode === 'sanitize')
{
	// rpc2 does not load ruTorrent settings. Pin the native command ceiling
	// from the daemon itself before any caller-selected command is classified.
	$probe = XMLRPCProxy::daemonVersionProbe();
	$reply = rpc2_send($probe, false);
	$version = XMLRPCProxy::daemonVersionFromReply($reply);
	if($version === null)
	{
		rpc2_log('refusing request: rTorrent version unavailable or unsupported', true);
		rpc2_fault('503 Service Unavailable', 'Could not verify the rTorrent version.');
	}
	$proxyOptions['rtorrentVersion'] = $version;
}
$decision = XMLRPCProxy::decide($raw, $mode, $safeParams, $allowLocalPaths, $proxyOptions);
foreach(XMLRPCProxy::decisionLogLines($decision, $policy) as $line)
	rpc2_log($line);

if($decision['action'] !== 'send')
{
	// The filter refused this call; rtorrent never saw it. Name the command so
	// the caller is told what it may not do, in the same words the httprpc door
	// renders from XMLRPCProxy::rejectionFault() for the same refusal -- one
	// refusal must not read as two different answers depending on which door
	// took it.
	$refused = isset($decision['method']) ? $decision['method'] : null;
	rpc2_fault('403 Forbidden', XMLRPCProxy::rejectionMessage($refused));
}

$result = rpc2_send($decision['payload'], $decision['trusted']);
if($result === null)
	rpc2_fault('502 Bad Gateway', 'Could not complete the rTorrent XMLRPC request.');

header('Content-Type: text/xml');
header('Content-Length: '.strlen($result));
echo $result;
