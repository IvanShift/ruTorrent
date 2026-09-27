<?php
require_once( 'lookat.php' );
Requests::requirePost();

$look = new rLook();
$look->set();
CachedEcho::send($look->get(),"application/javascript");
