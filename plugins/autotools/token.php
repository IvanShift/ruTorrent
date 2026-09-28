<?php

$previous = isset($argv[1]) ? $argv[1] : '';
echo preg_match('/^[0-9a-f]{32}$/D', $previous) ? $previous : bin2hex(random_bytes(16));
