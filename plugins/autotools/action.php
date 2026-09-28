<?php
require_once( 'autotools.php' );
Requests::requirePost();

$at = new rAutoTools();
$at->set();
$response = $at->get();
if( $at->moveAdmission === 'unsupported' )
    $response .= "noty('AutoTools Move refused: this rTorrent lacks required claim commands.', 'error');";
else if( $at->moveAdmission === 'unconfirmed' )
    $response .= "noty('AutoTools Move paused: rTorrent claim support could not be verified.', 'error');";
CachedEcho::send($response,"application/javascript");
