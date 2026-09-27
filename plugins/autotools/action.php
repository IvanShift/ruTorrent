<?php
require_once( 'autotools.php' );
Requests::requirePost();

$at = new rAutoTools();
$at->set();
CachedEcho::send($at->get(),"application/javascript");
