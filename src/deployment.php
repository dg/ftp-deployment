<?php

declare(strict_types=1);

namespace Deployment;

if (PHP_VERSION_ID < 80000) {
	echo 'Error: Deployment requires PHP 8.0 or newer.';
	exit(1);
}

if (is_file(__DIR__ . '/../vendor/autoload.php')) {
	require __DIR__ . '/../vendor/autoload.php';

} elseif (is_file(__DIR__ . '/../../../autoload.php')) {
	require __DIR__ . '/../../../autoload.php';
}


$runner = new CliRunner;
die($runner->run());
