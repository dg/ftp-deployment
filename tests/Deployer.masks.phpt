<?php declare(strict_types=1);

use Deployment\Deployer;
use Deployment\MockServer;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/MockServer.php';


test('ignoreMasks filters out matching files', function () {
	$localDir = TEMP_DIR . '/masks1/local';
	mkdir($localDir . '/.git', 0o777, true);
	file_put_contents("$localDir/a.txt", 'text');
	file_put_contents("$localDir/b.log", 'log');
	file_put_contents("$localDir/.git/config", 'git config');

	$server = new MockServer;
	$logger = new Deployment\Logger(TEMP_DIR . '/masks1.log');
	$logger->showProgress = false;

	$deployer = new Deployer($server, $localDir, $logger);
	$deployer->tempDir = TEMP_DIR;
	$deployer->ignoreMasks = ['*.log', '.git'];
	$deployer->deploy();

	// Only a.txt should be uploaded - b.log and .git/* are ignored
	Assert::same(['/a.txt'], $server->getUploadedPaths());
});


test('includeMasks limits to matching files only', function () {
	$localDir = TEMP_DIR . '/masks2/local';
	mkdir($localDir, 0o777, true);
	file_put_contents("$localDir/a.txt", 'text');
	file_put_contents("$localDir/b.css", 'css');
	file_put_contents("$localDir/c.php", 'php');

	$server = new MockServer;
	$logger = new Deployment\Logger(TEMP_DIR . '/masks2.log');
	$logger->showProgress = false;

	$deployer = new Deployer($server, $localDir, $logger);
	$deployer->tempDir = TEMP_DIR;
	$deployer->includeMasks = ['*.txt'];
	$deployer->deploy();

	// Only a.txt matches *.txt
	Assert::same(['/a.txt'], $server->getUploadedPaths());
});
