<?php declare(strict_types=1);

use Deployment\Deployer;
use Deployment\MockServer;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/MockServer.php';


$localDir = TEMP_DIR . '/fresh/local';
mkdir($localDir . '/lib', 0o777, true);
file_put_contents("$localDir/index.php", '<?php echo "hello";');
file_put_contents("$localDir/style.css", 'body { color: red; }');
file_put_contents("$localDir/lib/utils.php", '<?php function foo() {}');

$server = new MockServer;
$logger = new Deployment\Logger(TEMP_DIR . '/deployment.log');
$logger->showProgress = false;

$deployer = new Deployer($server, $localDir, $logger);
$deployer->tempDir = TEMP_DIR;
$deployer->deploy();


// All files should exist on server at their final paths with correct content
Assert::same('<?php echo "hello";', $server->getFileContent('/index.php'));
Assert::same('body { color: red; }', $server->getFileContent('/style.css'));
Assert::same('<?php function foo() {}', $server->getFileContent('/lib/utils.php'));
Assert::true($server->hasFile('/.htdeployment'));

// Exactly 3 content files should be uploaded
$uploaded = $server->getUploadedPaths();
sort($uploaded);
Assert::same(['/index.php', '/lib/utils.php', '/style.css'], $uploaded);

// Transactional pattern: all writeFile calls use .deploytmp suffix
$writes = $server->getOperationsOfType('writeFile');
foreach ($writes as $write) {
	$remotePath = $write['args'][1];
	if (!str_contains($remotePath, '.htdeployment.running')) {
		Assert::true(
			str_ends_with($remotePath, '.deploytmp'),
			"writeFile should use .deploytmp suffix: $remotePath",
		);
	}
}

// Each uploaded file gets renamed from .deploytmp to final name
$renames = $server->getOperationsOfType('renameFile');
Assert::count(4, $renames); // 3 content files + .htdeployment
foreach ($renames as $rename) {
	Assert::true(str_ends_with($rename['args'][0], '.deploytmp'));
	Assert::false(str_ends_with($rename['args'][1], '.deploytmp'));
}

// Operation order: first connect, then all writes before any renames
Assert::same('connect', $server->getOperations()[0]['method']);

$ops = $server->getOperationNames();
$lastWrite = max(array_keys(array_filter($ops, fn($op) => $op === 'writeFile')));
$firstRename = min(array_keys(array_filter($ops, fn($op) => $op === 'renameFile')));
Assert::true($lastWrite < $firstRename, 'All writes should complete before renames begin');

// .running file should be cleaned up
$removes = $server->getOperationsOfType('removeFile');
$runningRemoves = array_filter($removes, fn($r) => str_contains($r['args'][0], '.htdeployment.running'));
Assert::count(1, $runningRemoves);
