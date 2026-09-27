<?php
require_once( 'xmpp.php' );
Requests::requirePost();

$at = new rXmpp();
$at->set();
CachedEcho::send($at->get(),"application/javascript");
