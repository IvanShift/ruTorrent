<?php
require_once( 'autotools.php' );
Requests::requirePost();

$at = new rAutoTools();
$at->set();
$response = $at->get();
$response .= $at->modeNotice();
if ($at->finishedPathRefused) $response .= "noty(theUILang.autotoolsNoPathToFinished, 'error');";
CachedEcho::send($response,"application/javascript");
