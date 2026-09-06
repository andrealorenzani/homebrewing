<?php

declare(strict_types=1);

// PHPUnit bootstrap: wires the Composer autoloader (App\ and Tests\ namespaces)
// and sets sane defaults for the test run. Deliberately does not touch any
// database or environment file — config/DB loading is handled by later tasks.

error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('UTC');

require __DIR__ . '/../vendor/autoload.php';
