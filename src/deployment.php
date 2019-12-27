<?php

declare(strict_types=1);

namespace Deployment;

if (PHP_VERSION_ID < 70100) {
	echo 'Error: Deployment requires PHP 7.1 or newer.';
	exit(1);
}

require __DIR__ . '/Deployment/Server.php';
require __DIR__ . '/Deployment/FtpServer.php';
require __DIR__ . '/Deployment/SshServer.php';
require __DIR__ . '/Deployment/FileServer.php';
require __DIR__ . '/Deployment/RetryServer.php';
require __DIR__ . '/Deployment/Helpers.php';
require __DIR__ . '/Deployment/Safe.php';
require __DIR__ . '/Deployment/Logger.php';
require __DIR__ . '/Deployment/Deployer.php';
require __DIR__ . '/Deployment/Preprocessor.php';
require __DIR__ . '/Deployment/CommandLine.php';
require __DIR__ . '/Deployment/CliRunner.php';
require __DIR__ . '/Deployment/ServerException.php';
require __DIR__ . '/Deployment/JobException.php';


$runner = new CliRunner;
die($runner->run());
