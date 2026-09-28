<?php

if (!chdir(dirname(__FILE__))) exit(1);
if (count($argv) > 7) $_SERVER['REMOTE_USER'] = $argv[7];
require_once(dirname(__FILE__) . '/move_tx_lib.php');
AutoToolsMoveTransaction::run(array_slice($argv, 1));
