<?php
require_once( 'ratio.php' );
Requests::requirePost();

$rat = rRatio::load();
$rat->set();
CachedEcho::send($rat->get(),"application/javascript");
