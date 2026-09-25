<?php

// Representative observable outcomes of XMLRPCProxy::process(): what it
// returned, what it sent, on what trust, and what it logged.
//
// The literal outcome tuple is the assertion oracle for the current policy.
// A behavior change updates both the descriptive case name and its tuple;
// the focused proxy tests separately explain why that behavior is required.

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

// Case names describe the expected behavior. The literal outcome tuple
// (returned, sends, trusted, payload, log) is the assertion oracle.
// Changes to behavior must update both the name and the tuple explicitly.
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
	"a body that is not XML is rejected" => array(
		"request" => "not xml at all",
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (invalid XML)",
		),
	),
	"a methodCall with no methodName is rejected" => array(
		"request" => "<?xml version=\"1.0\"?><methodCall><params></params></methodCall>",
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (invalid XML)",
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
			"xmlrpc-proxy: untrusted: system.foo?xmlrpc-proxy:?trusted:?forged",
		),
	),
	"load.start with an allowed command param is rebuilt and trusted" => array(
		"request" => $call("load.start", $str("").$str("http://example.test/x.torrent").$str("d.custom1.set=label")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param><param><value><string>http://example.test/x.torrent</string></value></param><param><value><string>d.custom1.set=\"label\"</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: load.start (kept 3 params)",
		),
	),
	"load.start rejects a command param that is not allowed" => array(
		"request" => $call("load.start", $str("").$str("http://example.test/x.torrent").$str("execute=evil")),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (not allowed on this connection): load.start [slot 3: execute]",
		),
	),
	"a load with multiple refused params logs one outer refusal" => array(
		"request" => $call("load.start", $str("").$str("http://example.test/x.torrent").$str("execute=evil").$str("d.peers_max.set=1")),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (not allowed on this connection): load.start [slot 3: execute]",
		),
	),
	"a param this side cannot rebuild rejects the call" => array(
		"request" => $call("load.raw_start", "<param><value><int>1</int></value></param>".$str("http://example.test/x.torrent")),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (malformed load call): load.raw_start",
		),
	),
	"a base64 data param is re-emitted without its line wrapping" => array(
		"request" => $call("load.raw_start", $str("")."<param><value><base64>Ynl0ZXMAyGJ5dGVzAMhieXRlcwDIYnl0ZXMAyGJ5dGVzAMhieXRlcwDIYnl0ZXMAyGJ5dGVzAMg=\n</base64></value></param>".$str("d.custom1.set=label")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>load.raw_start</methodName><params><param><value><string></string></value></param><param><value><base64>Ynl0ZXMAyGJ5dGVzAMhieXRlcwDIYnl0ZXMAyGJ5dGVzAMhieXRlcwDIYnl0ZXMAyGJ5dGVzAMg=</base64></value></param><param><value><string>d.custom1.set=\"label\"</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: load.raw_start (kept 3 params)",
		),
	),
	"a value with no explicit string element is read the same way" => array(
		"request" => $call("load.start", $str("").$str("http://example.test/x.torrent")."<param><value>d.custom1.set=label</value></param>"),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param><param><value><string>http://example.test/x.torrent</string></value></param><param><value><string>d.custom1.set=\"label\"</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: load.start (kept 3 params)",
		),
	),
	"a legacy load alias is locally denied" => array(
		"request" => $call("load_start", $str("http://example.test/x.torrent").$str("d.custom1.set=label")),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (not allowed on this connection): load_start",
		),
	),
	"a command taking two arguments keeps both, each trimmed" => array(
		"request" => $call("load.start", $str("").$str("http://example.test/x.torrent").$str("d.custom.set=chk-state, 7")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param><param><value><string>http://example.test/x.torrent</string></value></param><param><value><string>d.custom.set=\"chk-state\",\"7\"</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: load.start (kept 3 params)",
		),
	),
	"quotes and backslashes in a value are escaped, not dropped" => array(
		"request" => $call("load.start", $str("").$str("http://example.test/x.torrent").$str("d.custom1.set=say &quot;hi&quot; \\ bye")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param><param><value><string>http://example.test/x.torrent</string></value></param><param><value><string>d.custom1.set=\"say \\\"hi\\\" \\\\ bye\"</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: load.start (kept 3 params)",
		),
	),
	"an argument starting with \$ rejects its load rather than being quoted" => array(
		"request" => $call("load.start", $str("").$str("http://example.test/x.torrent").$str("d.custom1.set=\$execute.capture=/bin/hostname")),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (not allowed on this connection): load.start [slot 3: d.custom1.set]",
		),
	),
	"a value the client quoted itself is kept as one argument" => array(
		"request" => $call("load.start", $str("").$str("http://example.test/x.torrent").$str("d.custom1.set=&quot;Movies, Inc&quot;")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param><param><value><string>http://example.test/x.torrent</string></value></param><param><value><string>d.custom1.set=\"Movies, Inc\"</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: load.start (kept 3 params)",
		),
	),
	"a long refused value is absent from the classified log" => array(
		"request" => $call("load.start", $str("").$str("http://example.test/x.torrent").$str("execute=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA")),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (not allowed on this connection): load.start [slot 3: execute]",
		),
	),
	"a newline in a refused value cannot forge a log line" => array(
		"request" => $call("load.start", $str("").$str("http://example.test/x.torrent").$str("execute=evil\nxmlrpc-proxy: trusted: forged")),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (not allowed on this connection): load.start [slot 3: execute]",
		),
	),
	"an empty allowlist rejects a load carrying command params" => array(
		"request" => $call("load.start", $str("").$str("http://example.test/x.torrent").$str("d.custom1.set=label")),
		"safeParams" => array(),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (not allowed on this connection): load.start [slot 3: d.custom1.set]",
		),
	),
	"a load call with no params is rejected as malformed" => array(
		"request" => $call("load.start"),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (malformed load call): load.start",
		),
	),
	"logging off changes what is logged and nothing else" => array(
		"request" => $call("load.start", $str("").$str("http://example.test/x.torrent").$str("execute=evil")),
		"enableLog" => false,
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
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
	"a multicall of read commands is rebuilt and trusted" => array(
		"request" => $call("d.multicall2", $str("").$str("main").$str("d.name=")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>d.multicall2</methodName><params><param><value><string></string></value></param><param><value><string>main</string></value></param><param><value><string>d.name=\"\"</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: d.multicall2 (3 params)",
		),
	),
	"a read command that carries an argument is rebuilt with it quoted" => array(
		"request" => $call("d.multicall2", $str("").$str("main").$str("d.custom=chk-state")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>d.multicall2</methodName><params><param><value><string></string></value></param><param><value><string>main</string></value></param><param><value><string>d.custom=\"chk-state\"</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: d.multicall2 (3 params)",
		),
	),
	"a read command whose argument is an evaluator is rejected" => array(
		"request" => $call("d.multicall2", $str("").$str("main").$str("d.custom=\$execute.capture=/bin/hostname")),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (not allowed on this connection): d.multicall2 [slot 3: d.custom]",
		),
	),
	"a multicall naming a command on neither list is rejected" => array(
		"request" => $call("d.multicall2", $str("").$str("main").$str("d.wibble=")),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (not allowed on this connection): d.multicall2 [slot 3: d.wibble]",
		),
	),
	"a setter beside a command on neither list rejects the whole multicall" => array(
		"request" => $call("d.multicall2", $str("").$str("main").$str("d.custom1.set=label").$str("d.wibble=")),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (not allowed on this connection): d.multicall2 [slot 4: d.wibble]",
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
	"a setter and a read command in one multicall are both rebuilt" => array(
		"request" => $call("d.multicall2", $str("").$str("main").$str("d.custom1.set=label").$str("d.name=")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>d.multicall2</methodName><params><param><value><string></string></value></param><param><value><string>main</string></value></param><param><value><string>d.custom1.set=\"label\"</string></value></param><param><value><string>d.name=\"\"</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: d.multicall2 (4 params)",
		),
	),

	"an allowed command with a \$ argument rejects the multicall" => array(
		"request" => $call("d.multicall2", $str("").$str("main").$str("d.custom1.set=\$execute.capture=/bin/hostname")),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (not allowed on this connection): d.multicall2 [slot 3: d.custom1.set]",
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
	"a d.multicall2 view containing equals is rejected as ambiguous" => array(
		"request" => $call("d.multicall2", $str("").$str("d.custom1.set=notacommand").$str("d.custom1.set=label")),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array("xmlrpc-proxy: rejected (ambiguous multicall view): d.multicall2 [slot 2: d.custom1.set]"),
	),
	"a data param that cannot be rebuilt rejects the multicall" => array(
		"request" => $call("d.multicall2", "<param><value><int>1</int></value></param>".$str("main").$str("d.custom1.set=label")),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (not allowed on this connection): d.multicall2 [slot 1]",
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
	"a filtered multicall with a setter filter and result is rebuilt and trusted" => array(
		"request" => $call("d.multicall.filtered", $str("").$str("main").$str("d.custom1.set=filter value").$str("d.custom1.set=label")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>d.multicall.filtered</methodName><params><param><value><string></string></value></param><param><value><string>main</string></value></param><param><value><string>d.custom1.set=\"filter value\"</string></value></param><param><value><string>d.custom1.set=\"label\"</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: d.multicall.filtered (4 params)",
		),
	),
	"t.multicall of a read command is rebuilt and trusted" => array(
		"request" => $call("t.multicall", $str("0123456789ABCDEF0123456789ABCDEF01234567").$str("").$str("t.url=")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>t.multicall</methodName><params><param><value><string>0123456789ABCDEF0123456789ABCDEF01234567</string></value></param><param><value><string></string></value></param><param><value><string>t.url=\"\"</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: t.multicall (3 params)",
		),
	),
	"f.multicall of a read command is rebuilt and trusted" => array(
		"request" => $call("f.multicall", $str("0123456789ABCDEF0123456789ABCDEF01234567").$str("").$str("f.path=")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>f.multicall</methodName><params><param><value><string>0123456789ABCDEF0123456789ABCDEF01234567</string></value></param><param><value><string></string></value></param><param><value><string>f.path=\"\"</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: f.multicall (3 params)",
		),
	),
	"p.multicall of a read command is rebuilt and trusted" => array(
		"request" => $call("p.multicall", $str("0123456789ABCDEF0123456789ABCDEF01234567").$str("").$str("p.address=")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>p.multicall</methodName><params><param><value><string>0123456789ABCDEF0123456789ABCDEF01234567</string></value></param><param><value><string></string></value></param><param><value><string>p.address=\"\"</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: p.multicall (3 params)",
		),
	),
	"system.multicall is rejected before carrier rebuilding" => array(
		"request" => $call("system.multicall", $str("x")),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (not allowed on this connection): system.multicall",
		),
	),
	"load.start from a local path is rejected" => array(
		"request" => $call("load.start", $str("").$str("/srv/watch/x.torrent")),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (load from a local path): load.start",
		),
	),
	"load.normal from a local path is rejected" => array(
		"request" => $call("load.normal", $str("").$str("/srv/watch/x.torrent")),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (load from a local path): load.normal",
		),
	),
	"a legacy load alias with a local path is locally denied" => array(
		"request" => $call("load_start", $str("").$str("/srv/watch/x.torrent")),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (not allowed on this connection): load_start",
		),
	),
	"a relative path is a local path too" => array(
		"request" => $call("load.start", $str("").$str("watch/x.torrent")),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (load from a local path): load.start",
		),
	),
	"a tilde path is a local path too" => array(
		"request" => $call("load.start", $str("").$str("~/watch/x.torrent")),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (load from a local path): load.start",
		),
	),
	"an uppercase scheme is a local path to rtorrent, so it is rejected" => array(
		"request" => $call("load.start", $str("").$str("HTTP://example.test/x.torrent")),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (load from a local path): load.start",
		),
	),
	"magnet without the ? is a local path to rtorrent, so it is rejected" => array(
		"request" => $call("load.start", $str("").$str("magnet:xt=urn:btih:abc")),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (load from a local path): load.start",
		),
	),
	"a base64 parameter is read as the URI it decodes to" => array(
		"request" => $call("load.start", $str("")."<param><value><base64>L3Nydi93YXRjaC94LnRvcnJlbnQ=</base64></value></param>"),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (load from a local path): load.start",
		),
	),
	"an ftp url is accepted, as rtorrent accepts it" => array(
		"request" => $call("load.start", $str("").$str("ftp://example.test/x.torrent")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param><param><value><string>ftp://example.test/x.torrent</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: load.start (kept 2 params)",
		),
	),
	"a magnet is accepted" => array(
		"request" => $call("load.start", $str("").$str("magnet:?xt=urn:btih:abc")),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>load.start</methodName><params><param><value><string></string></value></param><param><value><string>magnet:?xt=urn:btih:abc</string></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: load.start (kept 2 params)",
		),
	),
	"load.raw_start is unaffected \xE2\x80\x94 its parameter is the torrent, not a URI" => array(
		"request" => $call("load.raw_start", $str("")."<param><value><base64>Ynl0ZXMAyGJ5dGVzAMhieXRlcwDIYnl0ZXMAyGJ5dGVzAMhieXRlcwDIYnl0ZXMAyGJ5dGVzAMg=\n</base64></value></param>"),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => true,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>load.raw_start</methodName><params><param><value><string></string></value></param><param><value><base64>Ynl0ZXMAyGJ5dGVzAMhieXRlcwDIYnl0ZXMAyGJ5dGVzAMhieXRlcwDIYnl0ZXMAyGJ5dGVzAMg=</base64></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: trusted: load.raw_start (kept 2 params)",
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
			"xmlrpc-proxy: WARNING: operator-enabled local path forwarded: load.start; trusted: load.start (kept 2 params)",
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
			"xmlrpc-proxy: rejected (not allowed on this connection): d.multicall2 [slot 3: execute.capture]",
		),
	),
	"system.multicall carrying a refused member is refused" => array(
		"request" => $call("system.multicall", "<param><value><array><data><value><struct><member><name>methodName</name><value><string>execute.capture</string></value></member><member><name>params</name><value><array><data><value><string></string></value></data></array></value></member></struct></value></data></array></value></param>"),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (not allowed on this connection): system.multicall [slot 1: execute.capture]",
			"xmlrpc-proxy: [slot 1] rejected (not allowed on this connection): execute.capture"
		),
	),
	"system.multicall of harmless members is forwarded untrusted" => array(
		"request" => $call("system.multicall", "<param><value><array><data><value><struct><member><name>methodName</name><value><string>d.name</string></value></member><member><name>params</name><value><array><data><value><string>0123456789ABCDEF0123456789ABCDEF01234567</string></value></data></array></value></member></struct></value></data></array></value></param>"),
		"returned" => "SCGI-REPLY",
		"sends" => 1,
		"trusted" => false,
		"payload" => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>system.multicall</methodName><params><param><value><array><data><value><struct><member><name>methodName</name><value><string>d.name</string></value></member><member><name>params</name><value><array><data><value><string>0123456789ABCDEF0123456789ABCDEF01234567</string></value></data></array></value></member></struct></value></data></array></value></param></params></methodCall>",
		"log" => array(
			"xmlrpc-proxy: untrusted: system.multicall (1 members) [methods: d.name]"
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
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (arguments did not match allowed shape): d.start",
		),
	),
	"an elevated method with the wrong argument count is not elevated" => array(
		"request" => $call("d.start", $str("0123456789ABCDEF0123456789ABCDEF01234567").$str("extra")),
		"returned" => null,
		"sends" => 0,
		"trusted" => null,
		"payload" => null,
		"log" => array(
			"xmlrpc-proxy: rejected (arguments did not match allowed shape): d.start",
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
			"xmlrpc-proxy: WARNING: network.xmlrpc.size_limit.set requested 999999999, sent 16777216",
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
