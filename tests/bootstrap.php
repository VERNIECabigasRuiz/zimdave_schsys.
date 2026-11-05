<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

// Use the school's timezone before any test clock is created, as the kernel does on boot
date_default_timezone_set('Asia/Manila');

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
