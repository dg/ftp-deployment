<?php declare(strict_types=1);

use Deployment\{Deployer, MockServer, ServerException};
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/MockServer.php';


function makeLocal(string $name): string
{
	$dir = TEMP_DIR . "/$name/local";
	mkdir($dir, 0o777, true);
	file_put_contents("$dir/index.php", '<?php');
	return $dir;
}


test('refuses to deploy when a .running lock file is present', function () {
	$localDir = makeLocal('lock1');
	$server = new MockServer(['/.htdeployment.running' => 'in progress']);
	$logger = new Deployment\Logger(TEMP_DIR . '/lock1.log');
	$logger->showProgress = false;

	$deployer = new Deployer($server, $localDir, $logger);
	$deployer->tempDir = TEMP_DIR;

	Assert::exception(
		fn() => $deployer->deploy(),
		ServerException::class,
		'%A%.running exists on the server%A%',
	);
	// nothing was uploaded
	Assert::same([], $server->getUploadedPaths());
});


test('--force overrides the .running lock', function () {
	$localDir = makeLocal('lock2');
	$server = new MockServer(['/.htdeployment.running' => 'in progress']);
	$logger = new Deployment\Logger(TEMP_DIR . '/lock2.log');
	$logger->showProgress = false;

	$deployer = new Deployer($server, $localDir, $logger);
	$deployer->tempDir = TEMP_DIR;
	$deployer->force = true;
	$deployer->deploy();

	Assert::true($server->hasFile('/index.php'));
	// the lock file is cleaned up afterwards
	Assert::false($server->hasFile('/.htdeployment.running'));
});
