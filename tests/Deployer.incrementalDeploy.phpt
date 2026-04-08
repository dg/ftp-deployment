<?php declare(strict_types=1);

use Deployment\Deployer;
use Deployment\Helpers;
use Deployment\MockServer;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/MockServer.php';


$localDir = TEMP_DIR . '/incr/local';
mkdir($localDir, 0o777, true);
file_put_contents("$localDir/a.txt", 'changed content A');
file_put_contents("$localDir/b.txt", 'changed content B');
file_put_contents("$localDir/c.txt", 'unchanged content');

// Build .htdeployment with old hashes for a.txt and b.txt, correct hash for c.txt
$remotePaths = [
	'/a.txt' => 'old_hash_a',
	'/b.txt' => 'old_hash_b',
	'/c.txt' => Helpers::hashFile("$localDir/c.txt"),
];

$server = new MockServer([
	'/.htdeployment' => Deployment\buildDeploymentData($remotePaths),
]);
$logger = new Deployment\Logger(TEMP_DIR . '/deployment.log');
$logger->showProgress = false;

$deployer = new Deployer($server, $localDir, $logger);
$deployer->tempDir = TEMP_DIR;
$deployer->deploy();

// Exactly a.txt and b.txt should be uploaded, NOT c.txt
$uploaded = $server->getUploadedPaths();
sort($uploaded);
Assert::same(['/a.txt', '/b.txt'], $uploaded);

// Uploaded content should be correct
Assert::same('changed content A', $server->getFileContent('/a.txt'));
Assert::same('changed content B', $server->getFileContent('/b.txt'));
