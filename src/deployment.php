<?php

namespace Deployment;

if (PHP_VERSION_ID < 50400) {
	echo 'Error: Deployment requires PHP 5.4.0 or newer.';
	exit(1);
}

require __DIR__ . '/Deployment/Server.php';
require __DIR__ . '/Deployment/FtpServer.php';
require __DIR__ . '/Deployment/SshServer.php';
require __DIR__ . '/Deployment/Logger.php';
require __DIR__ . '/Deployment/Deployer.php';
require __DIR__ . '/Deployment/Preprocessor.php';
require __DIR__ . '/Deployment/CommandLine.php';
require __DIR__ . '/Deployment/CliRunner.php';
require __DIR__ . '/Deployment/exceptions.php';


$runner = new CliRunner;
die($runner->run());
