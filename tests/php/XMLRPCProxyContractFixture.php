<?php

// Representative observable outcomes of XMLRPCProxy::process(): what it
// returned, what it sent, on what trust, and what it logged.
//
// Generated from the implementation, then frozen. It is not a description of
// what the proxy should do — the suite next to it covers that — it is a record
// of what it does, so that a change to how the decision is reached shows up
// here if it changes the decision.

// The four structural inputs below are what almost every case passes, so they
// are stated once here instead of on every row. They are inputs only: what a
// case expects back — returned, sends, trusted, payload, log — is written out
// on the case itself and is never defaulted, because an expectation that came
// from somewhere else is an expectation nobody wrote down. A case that needs a
// different mode, log switch, whitelist or path policy states it, and its own
// value wins.
$defaults = array(
	"mode" => "sanitize",
	"enableLog" => true,
	"safeParams" => array(
		"d.custom1.set",
		"d.custom.set",
		"d.directory.set",
	),
	"allowLocalPaths" => false,
);

// The request bytes are an input, not an expectation, and every well-formed
// case below wraps its method name and its parameters in the same envelope. The
// envelope and the one-string-parameter wrapper are written once here, so a row
// says which method it calls and which parameters it carries and nothing else.
// Three rows deliberately send something that is not a well-formed call and
// spell their bytes out instead.
//
// Nothing a case expects back is built this way: returned, sends, trusted,
// payload and log are written out literally on every row, so no expectation is
// ever computed the way the proxy computes it.
$call = function($method, $params = "") {
	return("<?xml version=\"1.0\"?><methodCall><methodName>".$method
		."</methodName><params>".$params."</params></methodCall>");
};
$str = function($value) {
	return("<param><value><string>".$value."</string></value></param>");
};

$cases = array(
	"off mode rejects and sends nothing" => array(
		"request" => $call("load.start", $str("").$str("http://example.test/x.torrent")),
		"mode" => "off",
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (proxy disabled)",
		),
	),
	"off mode rejects a body that is not XML" => array(
		"request" => "not xml at all",
		"mode" => "off",
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (proxy disabled)",
		),
	),
	"passthrough_unsafe forwards the body verbatim, trusted" => array(
		"request" => $call("execute", $str("id")),
		"mode" => "passthrough_unsafe",
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\"?><methodCall><methodName>execute</methodName><params><param><value><string>id</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: passthrough (UNSAFE mode)",
		),
	),
	"a body that is not XML is forwarded untrusted" => array(
		"request" => "not xml at all",
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => false,
		"payload" => "not xml at all",
		"log" => array(
			"xmlrpc-proxy: untrusted (invalid XML)",
		),
	),
	"a methodCall with no methodName is forwarded untrusted" => array(
		"request" => "<?xml version=\"1.0\"?><methodCall><params></params></methodCall>",
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => false,
		"payload" => "<?xml version=\"1.0\"?><methodCall><params></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: untrusted (invalid XML)",
		),
	),
	"an unknown method is forwarded untrusted" => array(
		"request" => $call("system.client_version"),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => false,
		"payload" => "<?xml version=\"1.0\"?><methodCall><methodName>system.client_version</methodName><params></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: untrusted: system.client_version",
		),
	),
	"a newline in an unknown method name cannot forge a log line" => array(
		"request" => $call("system.foo\nxmlrpc-proxy: trusted: forged"),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => false,
		"payload" => "<?xml version=\"1.0\"?><methodCall><methodName>system.foo\nxmlrpc-proxy: trusted: forged</methodName><params></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: untrusted: system.foo xmlrpc-proxy: trusted: forged",
		),
	),
	"load.start with an allowed command param is rebuilt and trusted" => array(
		"request" => $call("load.start", $str("").$str("http://example.test/x.torrent").$str("d.custom1.set=label")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param><param><value><string>http://example.test/x.torrent</string></value></param><param><value><string>d.custom1.set=\"label\"</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: load.start (3 params)",
		),
	),
	"load.start strips a command param that is not allowed" => array(
		"request" => $call("load.start", $str("").$str("http://example.test/x.torrent").$str("execute=evil")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param><param><value><string>http://example.test/x.torrent</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: load.start (kept 2 params, stripped: execute=evil)",
		),
	),
	"every stripped param is named in one log line" => array(
		"request" => $call("load.start", $str("").$str("http://example.test/x.torrent").$str("execute=evil").$str("d.peers_max.set=1")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param><param><value><string>http://example.test/x.torrent</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: load.start (kept 2 params, stripped: execute=evil, d.peers_max.set=1)",
		),
	),
	"a param this side cannot rebuild forces the call untrusted" => array(
		"request" => $call("load.raw_start", "<param><value><int>1</int></value></param>".$str("http://example.test/x.torrent")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => false,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>load.raw_start</methodName><params><param><value><int>1</int></value></param><param><value><string>http://example.test/x.torrent</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: untrusted (a parameter could not be rebuilt): load.raw_start (2 params)",
		),
	),
	"a base64 data param is re-emitted without its line wrapping" => array(
		"request" => $call("load.raw_start", $str("")."<param><value><base64>Ynl0ZXMAyGJ5dGVzAMhieXRlcwDIYnl0ZXMAyGJ5dGVzAMhieXRlcwDIYnl0ZXMAyGJ5dGVzAMg=\n</base64></value></param>".$str("d.custom1.set=label")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>load.raw_start</methodName><params><param><value><string></string></value></param><param><value><base64>Ynl0ZXMAyGJ5dGVzAMhieXRlcwDIYnl0ZXMAyGJ5dGVzAMhieXRlcwDIYnl0ZXMAyGJ5dGVzAMg=</base64></value></param><param><value><string>d.custom1.set=\"label\"</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: load.raw_start (3 params)",
		),
	),
	"a value with no explicit string element is read the same way" => array(
		"request" => $call("load.start", $str("").$str("http://example.test/x.torrent")."<param><value>d.custom1.set=label</value></param>"),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param><param><value><string>http://example.test/x.torrent</string></value></param><param><value><string>d.custom1.set=\"label\"</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: load.start (3 params)",
		),
	),
	"the 0.9.x method names take the same parameter positions" => array(
		"request" => $call("load_start", $str("http://example.test/x.torrent").$str("d.custom1.set=label")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>load_start</methodName><params><param><value><string>http://example.test/x.torrent</string></value></param><param><value><string>d.custom1.set=label</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: load_start (2 params)",
		),
	),
	"a command taking two arguments keeps both, each trimmed" => array(
		"request" => $call("load.start", $str("").$str("http://example.test/x.torrent").$str("d.custom.set=chk-state, 7")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param><param><value><string>http://example.test/x.torrent</string></value></param><param><value><string>d.custom.set=\"chk-state\",\"7\"</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: load.start (3 params)",
		),
	),
	"quotes and backslashes in a value are escaped, not dropped" => array(
		"request" => $call("load.start", $str("").$str("http://example.test/x.torrent").$str("d.custom1.set=say &quot;hi&quot; \\ bye")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param><param><value><string>http://example.test/x.torrent</string></value></param><param><value><string>d.custom1.set=\"say \\\"hi\\\" \\\\ bye\"</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: load.start (3 params)",
		),
	),
	"an argument starting with \$ is dropped rather than quoted" => array(
		"request" => $call("load.start", $str("").$str("http://example.test/x.torrent").$str("d.custom1.set=\$execute.capture=/bin/hostname")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param><param><value><string>http://example.test/x.torrent</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: load.start (kept 2 params, stripped: d.custom1.set=\$execute.capture=/bin/hostname)",
		),
	),
	"a value the client quoted itself is dropped" => array(
		"request" => $call("load.start", $str("").$str("http://example.test/x.torrent").$str("d.custom1.set=&quot;Movies, Inc&quot;")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param><param><value><string>http://example.test/x.torrent</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: load.start (kept 2 params, stripped: d.custom1.set=\"Movies, Inc\")",
		),
	),
	"a long stripped value is truncated in the log" => array(
		"request" => $call("load.start", $str("").$str("http://example.test/x.torrent").$str("execute=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param><param><value><string>http://example.test/x.torrent</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: load.start (kept 2 params, stripped: execute=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA...)",
		),
	),
	"a newline in a stripped value cannot forge a log line" => array(
		"request" => $call("load.start", $str("").$str("http://example.test/x.torrent").$str("execute=evil\nxmlrpc-proxy: trusted: forged")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param><param><value><string>http://example.test/x.torrent</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: load.start (kept 2 params, stripped: execute=evil xmlrpc-proxy: trusted: forged)",
		),
	),
	"an empty allowlist strips every command param" => array(
		"request" => $call("load.start", $str("").$str("http://example.test/x.torrent").$str("d.custom1.set=label")),
		"safeParams" => array(),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param><param><value><string>http://example.test/x.torrent</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: load.start (kept 2 params, stripped: d.custom1.set=label)",
		),
	),
	"a load call with no params at all is still rebuilt" => array(
		"request" => $call("load.start"),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>load.start</methodName><params></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: load.start (0 params)",
		),
	),
	"logging off changes what is logged and nothing else" => array(
		"request" => $call("load.start", $str("").$str("http://example.test/x.torrent").$str("execute=evil")),
		"enableLog" => false,
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param><param><value><string>http://example.test/x.torrent</string></value></param></params></methodCall>",
		"log" => array(),
	),
	"logging off on the unknown-method path too" => array(
		"request" => $call("system.client_version"),
		"enableLog" => false,
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => false,
		"payload" => "<?xml version=\"1.0\"?><methodCall><methodName>system.client_version</methodName><params></params></methodCall>",
		"log" => array(),
	),
	"a multicall of read commands is forwarded untouched and untrusted" => array(
		"request" => $call("d.multicall2", $str("").$str("main").$str("d.name=")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => false,
		"payload" => "<?xml version=\"1.0\"?><methodCall><methodName>d.multicall2</methodName><params><param><value><string></string></value></param><param><value><string>main</string></value></param><param><value><string>d.name=</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: untrusted: d.multicall2 carrying d.name (forwarded as the caller's own bytes; rtorrent refuses the whole multicall if any of them is not safe there)",
		),
	),
	"a multicall whose commands are all allowed is rebuilt and trusted" => array(
		"request" => $call("d.multicall2", $str("").$str("main").$str("d.custom1.set=label")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>d.multicall2</methodName><params><param><value><string></string></value></param><param><value><string>main</string></value></param><param><value><string>d.custom1.set=\"label\"</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: d.multicall2 (3 params)",
		),
	),
	"one unrebuildable command sends the whole multicall untouched" => array(
		"request" => $call("d.multicall2", $str("").$str("main").$str("d.custom1.set=label").$str("d.name=")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => false,
		"payload" => "<?xml version=\"1.0\"?><methodCall><methodName>d.multicall2</methodName><params><param><value><string></string></value></param><param><value><string>main</string></value></param><param><value><string>d.custom1.set=label</string></value></param><param><value><string>d.name=</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: untrusted: d.multicall2 carrying d.name (forwarded as the caller's own bytes; rtorrent refuses the whole multicall if any of them is not safe there)",
		),
	),
	"a multicall carrying execute.capture is forwarded untouched and untrusted" => array(
		"request" => $call("d.multicall2", $str("").$str("main").$str("execute.capture=/bin/sh")),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (not allowed on this connection): d.multicall2 carrying execute.capture",
		),
	),
	"an allowed command with a \$ argument makes the multicall untrusted" => array(
		"request" => $call("d.multicall2", $str("").$str("main").$str("d.custom1.set=\$execute.capture=/bin/hostname")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => false,
		"payload" => "<?xml version=\"1.0\"?><methodCall><methodName>d.multicall2</methodName><params><param><value><string></string></value></param><param><value><string>main</string></value></param><param><value><string>d.custom1.set=\$execute.capture=/bin/hostname</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: untrusted: d.multicall2 carrying d.custom1.set (forwarded as the caller's own bytes; rtorrent refuses the whole multicall if any of them is not safe there)",
		),
	),
	"a chained command stays inside the argument it was quoted into" => array(
		"request" => $call("d.multicall2", $str("").$str("main").$str("d.custom1.set=a;d.stop=")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>d.multicall2</methodName><params><param><value><string></string></value></param><param><value><string>main</string></value></param><param><value><string>d.custom1.set=\"a;d.stop=\"</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: d.multicall2 (3 params)",
		),
	),
	"a multicall view name is data, never a command" => array(
		"request" => $call("d.multicall2", $str("").$str("d.custom1.set=notacommand").$str("d.custom1.set=label")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>d.multicall2</methodName><params><param><value><string></string></value></param><param><value><string>d.custom1.set=notacommand</string></value></param><param><value><string>d.custom1.set=\"label\"</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: d.multicall2 (3 params)",
		),
	),
	"a data param that cannot be rebuilt makes the multicall untrusted" => array(
		"request" => $call("d.multicall2", "<param><value><int>1</int></value></param>".$str("main").$str("d.custom1.set=label")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => false,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>d.multicall2</methodName><params><param><value><int>1</int></value></param><param><value><string>main</string></value></param><param><value><string>d.custom1.set=\"label\"</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: untrusted (a parameter could not be rebuilt): d.multicall2 (3 params)",
		),
	),
	"d.multicall takes commands in the same position" => array(
		"request" => $call("d.multicall", $str("").$str("main").$str("d.custom1.set=label")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>d.multicall</methodName><params><param><value><string></string></value></param><param><value><string>main</string></value></param><param><value><string>d.custom1.set=\"label\"</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: d.multicall (3 params)",
		),
	),
	"d.multicall.filtered takes commands in the same position" => array(
		"request" => $call("d.multicall.filtered", $str("").$str("main").$str("d.custom1.set=label")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>d.multicall.filtered</methodName><params><param><value><string></string></value></param><param><value><string>main</string></value></param><param><value><string>d.custom1.set=\"label\"</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: d.multicall.filtered (3 params)",
		),
	),
	"t.multicall is command-carrying too" => array(
		"request" => $call("t.multicall", $str("0123456789ABCDEF0123456789ABCDEF01234567").$str("").$str("t.url=")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => false,
		"payload" => "<?xml version=\"1.0\"?><methodCall><methodName>t.multicall</methodName><params><param><value><string>0123456789ABCDEF0123456789ABCDEF01234567</string></value></param><param><value><string></string></value></param><param><value><string>t.url=</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: untrusted: t.multicall carrying t.url (forwarded as the caller's own bytes; rtorrent refuses the whole multicall if any of them is not safe there)",
		),
	),
	"f.multicall is command-carrying too" => array(
		"request" => $call("f.multicall", $str("0123456789ABCDEF0123456789ABCDEF01234567").$str("").$str("f.path=")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => false,
		"payload" => "<?xml version=\"1.0\"?><methodCall><methodName>f.multicall</methodName><params><param><value><string>0123456789ABCDEF0123456789ABCDEF01234567</string></value></param><param><value><string></string></value></param><param><value><string>f.path=</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: untrusted: f.multicall carrying f.path (forwarded as the caller's own bytes; rtorrent refuses the whole multicall if any of them is not safe there)",
		),
	),
	"p.multicall is command-carrying too" => array(
		"request" => $call("p.multicall", $str("0123456789ABCDEF0123456789ABCDEF01234567").$str("").$str("p.address=")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => false,
		"payload" => "<?xml version=\"1.0\"?><methodCall><methodName>p.multicall</methodName><params><param><value><string>0123456789ABCDEF0123456789ABCDEF01234567</string></value></param><param><value><string></string></value></param><param><value><string>p.address=</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: untrusted: p.multicall carrying p.address (forwarded as the caller's own bytes; rtorrent refuses the whole multicall if any of them is not safe there)",
		),
	),
	"system.multicall is not one of them" => array(
		"request" => $call("system.multicall", $str("x")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => false,
		"payload" => "<?xml version=\"1.0\"?><methodCall><methodName>system.multicall</methodName><params><param><value><string>x</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: untrusted: system.multicall",
		),
	),
	"load.start from a local path is rejected" => array(
		"request" => $call("load.start", $str("").$str("/srv/watch/x.torrent")),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (load from a local path): load.start /srv/watch/x.torrent",
		),
	),
	"load.normal from a local path is rejected" => array(
		"request" => $call("load.normal", $str("").$str("/srv/watch/x.torrent")),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (load from a local path): load.normal /srv/watch/x.torrent",
		),
	),
	"the 0.9.x name is forwarded trusted as an ordinary command" => array(
		"request" => $call("load_start", $str("").$str("/srv/watch/x.torrent")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>load_start</methodName><params><param><value><string></string></value></param><param><value><string>/srv/watch/x.torrent</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: load_start (2 params)",
		),
	),
	"a relative path is a local path too" => array(
		"request" => $call("load.start", $str("").$str("watch/x.torrent")),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (load from a local path): load.start watch/x.torrent",
		),
	),
	"a tilde path is a local path too" => array(
		"request" => $call("load.start", $str("").$str("~/watch/x.torrent")),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (load from a local path): load.start ~/watch/x.torrent",
		),
	),
	"an uppercase scheme is a local path to rtorrent, so it is rejected" => array(
		"request" => $call("load.start", $str("").$str("HTTP://example.test/x.torrent")),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (load from a local path): load.start HTTP://example.test/x.torrent",
		),
	),
	"magnet without the ? is a local path to rtorrent, so it is rejected" => array(
		"request" => $call("load.start", $str("").$str("magnet:xt=urn:btih:abc")),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (load from a local path): load.start magnet:xt=urn:btih:abc",
		),
	),
	"a base64 parameter is read as the URI it decodes to" => array(
		"request" => $call("load.start", $str("")."<param><value><base64>L3Nydi93YXRjaC94LnRvcnJlbnQ=</base64></value></param>"),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (load from a local path): load.start /srv/watch/x.torrent",
		),
	),
	"an ftp url is accepted, as rtorrent accepts it" => array(
		"request" => $call("load.start", $str("").$str("ftp://example.test/x.torrent")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param><param><value><string>ftp://example.test/x.torrent</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: load.start (2 params)",
		),
	),
	"a magnet is accepted" => array(
		"request" => $call("load.start", $str("").$str("magnet:?xt=urn:btih:abc")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param><param><value><string>magnet:?xt=urn:btih:abc</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: load.start (2 params)",
		),
	),
	"load.raw_start is unaffected \xE2\x80\x94 its parameter is the torrent, not a URI" => array(
		"request" => $call("load.raw_start", $str("")."<param><value><base64>Ynl0ZXMAyGJ5dGVzAMhieXRlcwDIYnl0ZXMAyGJ5dGVzAMhieXRlcwDIYnl0ZXMAyGJ5dGVzAMg=\n</base64></value></param>"),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>load.raw_start</methodName><params><param><value><string></string></value></param><param><value><base64>Ynl0ZXMAyGJ5dGVzAMhieXRlcwDIYnl0ZXMAyGJ5dGVzAMhieXRlcwDIYnl0ZXMAyGJ5dGVzAMg=</base64></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: load.raw_start (2 params)",
		),
	),
	"a local path is allowed when the operator turns it on" => array(
		"request" => $call("load.start", $str("").$str("/srv/watch/x.torrent")),
		"allowLocalPaths" => true,
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param><param><value><string>/srv/watch/x.torrent</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: WARNING: operator-enabled local path forwarded: load.start /srv/watch/x.torrent; rtorrent resolves it after proxy checks; trusted: load.start (2 params)",
		),
	),
	"execute.capture is refused" => array(
		"request" => $call("execute.capture", $str("").$str("/bin/sh")),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (not allowed on this connection): execute.capture",
		),
	),
	"method.insert is refused" => array(
		"request" => $call("method.insert", $str("").$str("evil")),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (not allowed on this connection): method.insert",
		),
	),
	"the 0.9.8 spelling execute2 is refused by the same prefix" => array(
		"request" => $call("execute2", $str("id")),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (not allowed on this connection): execute2",
		),
	),
	"schedule_remove2 is refused by the same prefix" => array(
		"request" => $call("schedule_remove2", $str("x")),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (not allowed on this connection): schedule_remove2",
		),
	),
	"import is refused" => array(
		"request" => $call("import", $str("").$str("/tmp/evil.rc")),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (not allowed on this connection): import",
		),
	),
	"a multicall carrying a refused command is refused, not forwarded" => array(
		"request" => $call("d.multicall2", $str("").$str("main").$str("execute.capture=/bin/sh")),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (not allowed on this connection): d.multicall2 carrying execute.capture",
		),
	),
	"system.multicall carrying a refused member is refused" => array(
		"request" => $call("system.multicall", "<param><value><array><data><value><struct><member><name>methodName</name><value><string>execute.capture</string></value></member><member><name>params</name><value><array><data><value><string></string></value></data></array></value></member></struct></value></data></array></value></param>"),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (not allowed on this connection): system.multicall carrying execute.capture",
		),
	),
	"system.multicall of harmless members is still forwarded untrusted" => array(
		"request" => $call("system.multicall", "<param><value><array><data><value><struct><member><name>methodName</name><value><string>d.name</string></value></member><member><name>params</name><value><array><data><value><string>0123456789ABCDEF0123456789ABCDEF01234567</string></value></data></array></value></member></struct></value></data></array></value></param>"),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => false,
		"payload" => "<?xml version=\"1.0\"?><methodCall><methodName>system.multicall</methodName><params><param><value><array><data><value><struct><member><name>methodName</name><value><string>d.name</string></value></member><member><name>params</name><value><array><data><value><string>0123456789ABCDEF0123456789ABCDEF01234567</string></value></data></array></value></member></struct></value></data></array></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: untrusted: system.multicall",
		),
	),
	"passthrough_unsafe is not subject to the refusal list" => array(
		"request" => $call("execute.capture", $str("").$str("/bin/sh")),
		"mode" => "passthrough_unsafe",
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\"?><methodCall><methodName>execute.capture</methodName><params><param><value><string></string></value></param><param><value><string>/bin/sh</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: passthrough (UNSAFE mode)",
		),
	),
	"d.start on one hash is elevated" => array(
		"request" => $call("d.start", $str("0123456789ABCDEF0123456789ABCDEF01234567")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>d.start</methodName><params><param><value><string>0123456789ABCDEF0123456789ABCDEF01234567</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: d.start (elevated)",
		),
	),
	"d.stop on one hash is elevated" => array(
		"request" => $call("d.stop", $str("0123456789ABCDEF0123456789ABCDEF01234567")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>d.stop</methodName><params><param><value><string>0123456789ABCDEF0123456789ABCDEF01234567</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: d.stop (elevated)",
		),
	),
	"d.open on one hash is elevated" => array(
		"request" => $call("d.open", $str("0123456789ABCDEF0123456789ABCDEF01234567")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>d.open</methodName><params><param><value><string>0123456789ABCDEF0123456789ABCDEF01234567</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: d.open (elevated)",
		),
	),
	"a label is elevated and the value is carried as data" => array(
		"request" => $call("d.custom1.set", $str("0123456789ABCDEF0123456789ABCDEF01234567").$str("Movies (2024)")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>d.custom1.set</methodName><params><param><value><string>0123456789ABCDEF0123456789ABCDEF01234567</string></value></param><param><value><string>Movies (2024)</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: d.custom1.set (elevated)",
		),
	),
	"a \$ value on an elevated setter is data, not a command" => array(
		"request" => $call("d.custom1.set", $str("0123456789ABCDEF0123456789ABCDEF01234567").$str("\$execute.capture=/bin/hostname")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>d.custom1.set</methodName><params><param><value><string>0123456789ABCDEF0123456789ABCDEF01234567</string></value></param><param><value><string>\$execute.capture=/bin/hostname</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: d.custom1.set (elevated)",
		),
	),
	"d.priority.set takes a hash and a number" => array(
		"request" => $call("d.priority.set", $str("0123456789ABCDEF0123456789ABCDEF01234567").$str("2")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>d.priority.set</methodName><params><param><value><string>0123456789ABCDEF0123456789ABCDEF01234567</string></value></param><param><value><i8>2</i8></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: d.priority.set (elevated)",
		),
	),
	"d.delete_tied is elevated on a hash" => array(
		"request" => $call("d.delete_tied", $str("0123456789ABCDEF0123456789ABCDEF01234567")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>d.delete_tied</methodName><params><param><value><string>0123456789ABCDEF0123456789ABCDEF01234567</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: d.delete_tied (elevated)",
		),
	),
	"a hash-shaped argument is required" => array(
		"request" => $call("d.start", $str("not-a-hash")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => false,
		"payload" => "<?xml version=\"1.0\"?><methodCall><methodName>d.start</methodName><params><param><value><string>not-a-hash</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: untrusted: d.start (arguments did not match the allowed shape)",
		),
	),
	"an elevated method with the wrong argument count is not elevated" => array(
		"request" => $call("d.start", $str("0123456789ABCDEF0123456789ABCDEF01234567").$str("extra")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => false,
		"payload" => "<?xml version=\"1.0\"?><methodCall><methodName>d.start</methodName><params><param><value><string>0123456789ABCDEF0123456789ABCDEF01234567</string></value></param><param><value><string>extra</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: untrusted: d.start (arguments did not match the allowed shape)",
		),
	),
	"the xmlrpc size limit is elevated but clamped" => array(
		"request" => $call("network.xmlrpc.size_limit.set", $str("").$str("999999999")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>network.xmlrpc.size_limit.set</methodName><params><param><value><string></string></value></param><param><value><i8>16777216</i8></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: network.xmlrpc.size_limit.set (elevated)",
		),
	),
	"a size under the ceiling is passed through" => array(
		"request" => $call("network.xmlrpc.size_limit.set", $str("").$str("2097152")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>network.xmlrpc.size_limit.set</methodName><params><param><value><string></string></value></param><param><value><i8>2097152</i8></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: network.xmlrpc.size_limit.set (elevated)",
		),
	),
);

foreach($cases as $name => $case)
	$cases[$name] = $case + $defaults;

return $cases;
