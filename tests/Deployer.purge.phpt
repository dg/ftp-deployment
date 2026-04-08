<?php declare(strict_types=1);

use Deployment\Deployer;
use Deployment\MockServer;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/MockServer.php';


$localDir = TEMP_DIR . '/purge/local';
mkdir($localDir, 0o777, true);
file_put_contents("$localDir/index.php", '<?php');

$server = new MockServer([
	'/cache/item1.tmp' => 'cached data 1',
	'/cache/item2.tmp' => 'cached data 2',
	'/cache/sub/item3.tmp' => 'cached data 3',
]);
$logger = new Deployment\Logger(TEMP_DIR . '/purge.log');
$logger->showProgress = false;

$deployer = new Deployer($server, $localDir, $logger);
$deployer->tempDir = TEMP_DIR;
$deployer->toPurge = ['cache'];
$deployer->deploy();

// purge() should have been called for /cache
$purges = $server->getOperationsOfType('purge');
Assert::count(1, $purges);
Assert::same('/cache', $purges[0]['args'][0]);

// Cache files should be removed from the virtual filesystem
Assert::false(isset($server->getFiles()['/cache/item1.tmp']));
Assert::false(isset($server->getFiles()['/cache/item2.tmp']));
Assert::false(isset($server->getFiles()['/cache/sub/item3.tmp']));

// Purge should happen after renames (new code deployed before cache cleared)
$ops = $server->getOperationNames();
$lastRename = max(array_keys(array_filter($ops, fn($op) => $op === 'renameFile')) ?: [0]);
$purgeIndex = array_search('purge', $ops, true);
Assert::true($purgeIndex > $lastRename, 'Purge should happen after renames');
