<?php declare(strict_types=1);

use Deployment\Deployer;
use Deployment\JobException;
use Deployment\MockServer;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/MockServer.php';


test('callable jobs run in correct order: before -> afterUpload -> after', function () {
	$localDir = TEMP_DIR . '/jobs1/local';
	mkdir($localDir, 0o777, true);
	file_put_contents("$localDir/index.php", '<?php');

	$server = new MockServer;
	$logger = new Deployment\Logger(TEMP_DIR . '/jobs1.log');
	$logger->showProgress = false;

	$log = [];

	$deployer = new Deployer($server, $localDir, $logger);
	$deployer->tempDir = TEMP_DIR;
	$deployer->runBefore = [function () use (&$log) {
		$log[] = 'before';
	}];
	$deployer->runAfterUpload = [function () use (&$log) {
		$log[] = 'afterUpload';
	}];
	$deployer->runAfter = [function () use (&$log) {
		$log[] = 'after';
	}];
	$deployer->deploy();

	Assert::same(['before', 'afterUpload', 'after'], $log);
});


test('callable job receives correct arguments', function () {
	$localDir = TEMP_DIR . '/jobs2/local';
	mkdir($localDir, 0o777, true);
	file_put_contents("$localDir/index.php", '<?php');

	$server = new MockServer;
	$logger = new Deployment\Logger(TEMP_DIR . '/jobs2.log');
	$logger->showProgress = false;

	$receivedArgs = [];

	$deployer = new Deployer($server, $localDir, $logger);
	$deployer->tempDir = TEMP_DIR;
	$deployer->runAfter = [function ($s, $l, $d) use (&$receivedArgs) {
		$receivedArgs = ['server' => $s, 'logger' => $l, 'deployer' => $d];
	}];
	$deployer->deploy();

	Assert::type(Deployment\Server::class, $receivedArgs['server']);
	Assert::type(Deployment\Logger::class, $receivedArgs['logger']);
	Assert::type(Deployer::class, $receivedArgs['deployer']);
});


test('callable job returning false throws JobException', function () {
	$localDir = TEMP_DIR . '/jobs3/local';
	mkdir($localDir, 0o777, true);
	file_put_contents("$localDir/index.php", '<?php');

	$server = new MockServer;
	$logger = new Deployment\Logger(TEMP_DIR . '/jobs3.log');
	$logger->showProgress = false;

	$deployer = new Deployer($server, $localDir, $logger);
	$deployer->tempDir = TEMP_DIR;
	$deployer->runAfter = [fn() => false];

	Assert::exception(
		fn() => $deployer->deploy(),
		JobException::class,
	);
});
