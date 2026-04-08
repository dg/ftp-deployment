<?php declare(strict_types=1);

use Deployment\Deployer;
use Deployment\Helpers;
use Deployment\MockServer;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/MockServer.php';


test('allowDelete=true removes files missing from local', function () {
	$localDir = TEMP_DIR . '/del1/local';
	mkdir($localDir, 0o777, true);
	file_put_contents("$localDir/a.txt", 'content A');

	$remotePaths = [
		'/a.txt' => Helpers::hashFile("$localDir/a.txt"),
		'/b.txt' => 'some_hash',
		'/old-dir/' => true,
	];

	$server = new MockServer([
		'/.htdeployment' => Deployment\buildDeploymentData($remotePaths),
	]);
	$logger = new Deployment\Logger(TEMP_DIR . '/del1.log');
	$logger->showProgress = false;

	$deployer = new Deployer($server, $localDir, $logger);
	$deployer->tempDir = TEMP_DIR;
	$deployer->allowDelete = true;
	$deployer->deploy();

	// b.txt should be removed via removeFile
	$removedFiles = array_map(fn($r) => $r['args'][0], $server->getOperationsOfType('removeFile'));
	Assert::contains('/b.txt', $removedFiles);

	// old-dir/ should be removed via removeDir
	$removedDirs = array_map(fn($r) => $r['args'][0], $server->getOperationsOfType('removeDir'));
	Assert::contains('/old-dir/', $removedDirs);

	// .htdeployment should be updated on server (reflecting only a.txt)
	$hashes = Deployment\decodeDeploymentFile($server);
	Assert::true(isset($hashes['/a.txt']));
	Assert::false(isset($hashes['/b.txt']));
	Assert::false(isset($hashes['/old-dir/']));
});


test('allowDelete=false keeps remote-only files but updates .htdeployment', function () {
	$localDir = TEMP_DIR . '/del2/local';
	mkdir($localDir, 0o777, true);
	file_put_contents("$localDir/a.txt", 'content A');

	$remotePaths = [
		'/a.txt' => Helpers::hashFile("$localDir/a.txt"),
		'/b.txt' => 'some_hash',
		'/old-dir/' => true,
	];

	$server = new MockServer([
		'/.htdeployment' => Deployment\buildDeploymentData($remotePaths),
	]);
	$logger = new Deployment\Logger(TEMP_DIR . '/del2.log');
	$logger->showProgress = false;

	$deployer = new Deployer($server, $localDir, $logger);
	$deployer->tempDir = TEMP_DIR;
	$deployer->allowDelete = false;
	$deployer->deploy();

	// No content files should be removed (only .running cleanup is OK)
	$removedFiles = array_map(fn($r) => $r['args'][0], $server->getOperationsOfType('removeFile'));
	$contentRemoves = array_filter($removedFiles, fn($p) => !str_contains($p, '.htdeployment'));
	Assert::same([], $contentRemoves);

	// No directories should be removed
	Assert::same([], $server->getOperationsOfType('removeDir'));

	// .htdeployment should still be updated (reflects only local files)
	$hashes = Deployment\decodeDeploymentFile($server);
	Assert::true(isset($hashes['/a.txt']));
	Assert::false(isset($hashes['/b.txt']));
	Assert::false(isset($hashes['/old-dir/']));
});
