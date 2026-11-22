<?php declare(strict_types=1);

define('TASK', 5);
require dirname(__DIR__) . '/_app/bootstrap.php';
guard();
require dirname(__DIR__) . '/_app/cloud.php';
cloud_serve();
