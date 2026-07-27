<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Apache/PHP's default umask (022) strips the group-write bit off every directory
// Flysystem creates during upload, even though config/filesystems.php explicitly
// requests 0775 — mkdir() always ANDs the requested mode with the process umask.
// The queue worker (different user, umask 002) then can't write the converted
// .md file into that directory. Match the worker's umask so both processes agree.
umask(0002);

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
