<?php declare(strict_types=1);

use Deployment\Deployer;
use Deployment\MockServer;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/MockServer.php';


$localDir = TEMP_DIR . '/testmode/local';
mkdir($localDir, 0o777, true);
file_put_contents("$localDir/index.php", '<?php echo "hello";');
file_put_contents("$localDir/style.css", 'body {}');

$server = new MockServer;
$logger = new Deployment\Logger(TEMP_DIR . '/deployment.log');
$logger->showProgress = false;

$deployer = new Deployer($server, $localDir, $logger);
$deployer->tempDir = TEMP_DIR;
$deployer->testMode = true;
$deployer->deploy();

// Connect and readFile should happen (server is contacted)
$ops = $server->getOperationNames();
Assert::same('connect', $ops[0]);
Assert::same('readFile', $ops[1]);

// No writeFile, renameFile, or removeFile should happen
Assert::same([], $server->getOperationsOfType('writeFile'));
Assert::same([], $server->getOperationsOfType('renameFile'));
Assert::count(2, $ops);
