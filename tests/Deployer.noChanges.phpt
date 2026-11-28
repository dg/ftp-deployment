<?php declare(strict_types=1);

use Deployment\{Deployer, Helpers, MockServer};
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/MockServer.php';


// Setup local directory
$localDir = TEMP_DIR . '/nochg/local';
mkdir($localDir, recursive: true);
file_put_contents("$localDir/a.txt", 'content A');
file_put_contents("$localDir/b.txt", 'content B');

// Build .htdeployment with correct hashes for all files
$remotePaths = [
	'/a.txt' => Helpers::hashFile("$localDir/a.txt"),
	'/b.txt' => Helpers::hashFile("$localDir/b.txt"),
];

// both orders, so that one of them differs from the order in which the files are scanned
foreach ([$remotePaths, array_reverse($remotePaths, preserve_keys: true)] as $i => $paths) {
	$server = new MockServer([
		'/.htdeployment' => Deployment\buildDeploymentData($paths),
	]);
	$logger = new Deployment\Logger(TEMP_DIR . "/deployment$i.log");
	$logger->showProgress = false;

	$deployer = new Deployer($server, $localDir, $logger);
	$deployer->tempDir = TEMP_DIR;
	$deployer->deploy();

	// No writeFile or renameFile calls should happen
	$writes = $server->getOperationsOfType('writeFile');
	$renames = $server->getOperationsOfType('renameFile');
	Assert::same([], $writes, 'No files should be uploaded');
	Assert::same([], $renames, 'No files should be renamed');

	// Verify the log contains "Already synchronized"
	$logContent = file_get_contents(TEMP_DIR . "/deployment$i.log");
	Assert::contains('Already synchronized', $logContent);

	// Only connect and readFile (.htdeployment) should have been called
	$ops = $server->getOperationNames();
	Assert::same('connect', $ops[0]);
	Assert::same('readFile', $ops[1]);
	Assert::count(2, $ops);
}
