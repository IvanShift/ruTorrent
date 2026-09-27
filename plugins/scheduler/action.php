<?php
require_once( 'scheduler.php' );
Requests::requirePost();

$sch = rScheduler::load();
$sch->set();
CachedEcho::send($sch->get(),"application/javascript");
