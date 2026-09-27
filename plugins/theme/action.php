<?php
require_once( 'theme.php' );
Requests::requirePost();

$theme = new rTheme();
$theme->set();
CachedEcho::send($theme->get(),"application/javascript");
