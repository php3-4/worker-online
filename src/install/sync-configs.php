<?php
$config = dirname(__DIR__,1) . '/config/worker_server.php';
$to_config = dirname(__DIR__,3). '/config/worker_server.php';
copy($config, $to_config);