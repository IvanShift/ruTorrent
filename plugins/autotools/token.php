<?php
require_once dirname(__DIR__, 2).'/php/cli-only.php';
rutorrent_require_cli(__FILE__);


$previous = isset($argv[1]) ? $argv[1] : '';
echo preg_match('/^[0-9a-f]{32}$/D', $previous) ? $previous : bin2hex(random_bytes(16));
