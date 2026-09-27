<?php
require_once( 'retrackers.php' );
Requests::requirePost();

$trks = new rRetrackers();
$trks->set();
CachedEcho::send($trks->get(),"application/javascript");
