<?php declare(strict_types=1);

use Deployment\Deployer;
use Deployment\Helpers;
use Deployment\InterruptHandler;
use Deployment\MockServer;
use Deployment\SkipException;
use Deployment\TerminatedException;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/MockServer.php';


/**
 * InterruptHandler subclass that throws at predetermined operations.
 * Simulates user pressing Ctrl+C and choosing skip/cancel without actual signals or STDIN.
 */
class TestInterruptHandler extends InterruptHandler
{
	/** @var array<string, 's'|'c'> operation substring => action */
	private array $actions = [];


	public function willSkip(string $pattern): self
	{
		$this->actions[$pattern] = 's';
		return $this;
	}


	public function willCancel(string $pattern): self
	{
		$this->actions[$pattern] = 'c';
		return $this;
	}


	public function check(string $operation): void
	{
		foreach ($this->actions as $pattern => $action) {
			if (str_contains($operation, $pattern)) {
				unset($this->actions[$pattern]);
				if ($action === 's') {
					$this->addSkipped($operation);
					throw new SkipException($operation);
				} else {
					throw new TerminatedException('Terminated');
				}
			}
		}
	}
}


test('skipped upload keeps old hash in deployment file', function () {
	$localDir = TEMP_DIR . '/int1/local';
	mkdir($localDir, 0o777, true);
	file_put_contents("$localDir/a.txt", 'new content A');
	file_put_contents("$localDir/b.txt", 'new content B');
	file_put_contents("$localDir/c.txt", 'unchanged');

	$newHashA = Helpers::hashFile("$localDir/a.txt");
	$newHashB = Helpers::hashFile("$localDir/b.txt");
	$hashC = Helpers::hashFile("$localDir/c.txt");
	$oldHashA = 'old_hash_a';
	$oldHashB = 'old_hash_b';

	$server = new MockServer([
		'/.htdeployment' => Deployment\buildDeploymentData([
			'/a.txt' => $oldHashA,
			'/b.txt' => $oldHashB,
			'/c.txt' => $hashC,
		]),
		'/a.txt' => 'old A',
		'/b.txt' => 'old B',
		'/c.txt' => 'unchanged',
	]);

	$handler = new TestInterruptHandler;
	$handler->willSkip('Upload /b.txt');

	$logger = new Deployment\Logger(TEMP_DIR . '/int1.log');
	$logger->showProgress = false;

	$deployer = new Deployer($server, $localDir, $logger, $handler);
	$deployer->tempDir = TEMP_DIR;
	$deployer->deploy();

	$hashes = Deployment\decodeDeploymentFile($server);

	// a.txt uploaded: new hash in deployment file
	Assert::same($newHashA, $hashes['/a.txt']);
	// b.txt skipped: old hash preserved in deployment file
	Assert::same($oldHashB, $hashes['/b.txt']);
	// c.txt unchanged
	Assert::same($hashC, $hashes['/c.txt']);
	// b.txt content on server is still old
	Assert::same('old B', $server->getFileContent('/b.txt'));
	// a.txt content on server is new
	Assert::same('new content A', $server->getFileContent('/a.txt'));
});


test('skipped rename regenerates deployment file with old hash', function () {
	$localDir = TEMP_DIR . '/int2/local';
	mkdir($localDir, 0o777, true);
	file_put_contents("$localDir/a.txt", 'new content A');
	file_put_contents("$localDir/b.txt", 'new content B');

	$newHashA = Helpers::hashFile("$localDir/a.txt");
	$oldHashA = 'old_hash_a';
	$oldHashB = 'old_hash_b';

	$server = new MockServer([
		'/.htdeployment' => Deployment\buildDeploymentData([
			'/a.txt' => $oldHashA,
			'/b.txt' => $oldHashB,
		]),
		'/a.txt' => 'old A',
		'/b.txt' => 'old B',
	]);

	$handler = new TestInterruptHandler;
	$handler->willSkip('Rename /b.txt');

	$logger = new Deployment\Logger(TEMP_DIR . '/int2.log');
	$logger->showProgress = false;

	$deployer = new Deployer($server, $localDir, $logger, $handler);
	$deployer->tempDir = TEMP_DIR;
	$deployer->deploy();

	$hashes = Deployment\decodeDeploymentFile($server);

	// a.txt renamed successfully: new hash
	Assert::same($newHashA, $hashes['/a.txt']);
	// b.txt rename skipped: old hash (temp file was cleaned up, old version remains)
	Assert::same($oldHashB, $hashes['/b.txt']);
	// No .deploytmp files left on server
	foreach ($server->getFiles() as $path => $_) {
		Assert::false(str_contains($path, '.deploytmp'), "Temp file left on server: $path");
	}
});


test('cancel during upload propagates TerminatedException and cleans up', function () {
	$localDir = TEMP_DIR . '/int3/local';
	mkdir($localDir, 0o777, true);
	file_put_contents("$localDir/a.txt", 'aaa');
	file_put_contents("$localDir/b.txt", 'bbb');
	file_put_contents("$localDir/c.txt", 'ccc');

	$server = new MockServer;

	$handler = new TestInterruptHandler;
	$handler->willCancel('Upload /b.txt');

	$logger = new Deployment\Logger(TEMP_DIR . '/int3.log');
	$logger->showProgress = false;

	$deployer = new Deployer($server, $localDir, $logger, $handler);
	$deployer->tempDir = TEMP_DIR;

	Assert::exception(
		fn() => $deployer->deploy(),
		TerminatedException::class,
	);

	// No .deploytmp files left on server after cleanup
	foreach ($server->getFiles() as $path => $_) {
		Assert::false(str_contains($path, '.deploytmp'), "Temp file left on server: $path");
	}
});


test('skipped operations are tracked and reported', function () {
	$localDir = TEMP_DIR . '/int4/local';
	mkdir($localDir, 0o777, true);
	file_put_contents("$localDir/a.txt", 'new A');
	file_put_contents("$localDir/b.txt", 'new B');
	file_put_contents("$localDir/c.txt", 'new C');

	$server = new MockServer([
		'/.htdeployment' => Deployment\buildDeploymentData([
			'/a.txt' => 'old',
			'/b.txt' => 'old',
			'/c.txt' => 'old',
		]),
	]);

	$handler = new TestInterruptHandler;
	$handler->willSkip('Upload /a.txt');
	$handler->willSkip('Upload /c.txt');

	$logger = new Deployment\Logger(TEMP_DIR . '/int4.log');
	$logger->showProgress = false;

	$deployer = new Deployer($server, $localDir, $logger, $handler);
	$deployer->tempDir = TEMP_DIR;
	$deployer->deploy();

	$skipped = $handler->getSkipped();
	Assert::count(2, $skipped);
	Assert::same('Upload /a.txt', $skipped[0]);
	Assert::same('Upload /c.txt', $skipped[1]);
});


test('skip during job execution continues deployment', function () {
	$localDir = TEMP_DIR . '/int5/local';
	mkdir($localDir, 0o777, true);
	file_put_contents("$localDir/a.txt", 'aaa');

	$server = new MockServer;

	$handler = new TestInterruptHandler;
	$handler->willSkip('Job callable');

	$logger = new Deployment\Logger(TEMP_DIR . '/int5.log');
	$logger->showProgress = false;

	$jobRan = false;
	$deployer = new Deployer($server, $localDir, $logger, $handler);
	$deployer->tempDir = TEMP_DIR;
	$deployer->runBefore = [function () use (&$jobRan) {
		$jobRan = true;
	}];
	$deployer->deploy();

	// Job was skipped (not executed)
	Assert::false($jobRan);
	// But deployment continued: file was uploaded
	Assert::true($server->hasFile('/a.txt'));
	Assert::true($handler->hasSkipped());
});


test('skipped upload of new file removes it from deployment file', function () {
	$localDir = TEMP_DIR . '/int6/local';
	mkdir($localDir, 0o777, true);
	file_put_contents("$localDir/a.txt", 'existing');
	file_put_contents("$localDir/b.txt", 'brand new');

	$hashA = Helpers::hashFile("$localDir/a.txt");

	// Server only knows about a.txt (b.txt is new)
	$server = new MockServer([
		'/.htdeployment' => Deployment\buildDeploymentData(['/a.txt' => $hashA]),
		'/a.txt' => 'existing',
	]);

	$handler = new TestInterruptHandler;
	$handler->willSkip('Upload /b.txt');

	$logger = new Deployment\Logger(TEMP_DIR . '/int6.log');
	$logger->showProgress = false;

	$deployer = new Deployer($server, $localDir, $logger, $handler);
	$deployer->tempDir = TEMP_DIR;
	$deployer->deploy();

	$hashes = Deployment\decodeDeploymentFile($server);

	// a.txt unchanged
	Assert::same($hashA, $hashes['/a.txt']);
	// b.txt was new and skipped: must NOT appear in deployment file
	Assert::false(isset($hashes['/b.txt']));
});


test('cancel during rename propagates TerminatedException and cleans up temp files', function () {
	$localDir = TEMP_DIR . '/int7/local';
	mkdir($localDir, 0o777, true);
	file_put_contents("$localDir/a.txt", 'aaa');
	file_put_contents("$localDir/b.txt", 'bbb');
	file_put_contents("$localDir/c.txt", 'ccc');

	$server = new MockServer;

	$handler = new TestInterruptHandler;
	$handler->willCancel('Rename /b.txt');

	$logger = new Deployment\Logger(TEMP_DIR . '/int7.log');
	$logger->showProgress = false;

	$deployer = new Deployer($server, $localDir, $logger, $handler);
	$deployer->tempDir = TEMP_DIR;

	Assert::exception(
		fn() => $deployer->deploy(),
		TerminatedException::class,
	);

	// No .deploytmp files should remain after finally-block cleanup
	foreach ($server->getFiles() as $path => $_) {
		Assert::false(str_contains($path, '.deploytmp'), "Temp file left on server: $path");
	}
	// .running file should be cleaned up
	Assert::false($server->hasFile('/.htdeployment.running'));
});


test('skip during delete keeps skipped file on server', function () {
	$localDir = TEMP_DIR . '/int8/local';
	mkdir($localDir, 0o777, true);
	file_put_contents("$localDir/keep.txt", 'keep');

	$hashKeep = Helpers::hashFile("$localDir/keep.txt");

	$server = new MockServer([
		'/.htdeployment' => Deployment\buildDeploymentData([
			'/keep.txt' => $hashKeep,
			'/a.txt' => 'hash_a',
			'/b.txt' => 'hash_b',
			'/c.txt' => 'hash_c',
		]),
		'/keep.txt' => 'keep',
		'/a.txt' => 'content a',
		'/b.txt' => 'content b',
		'/c.txt' => 'content c',
	]);

	$handler = new TestInterruptHandler;
	$handler->willSkip('Delete /b.txt');

	$logger = new Deployment\Logger(TEMP_DIR . '/int8.log');
	$logger->showProgress = false;

	$deployer = new Deployer($server, $localDir, $logger, $handler);
	$deployer->tempDir = TEMP_DIR;
	$deployer->allowDelete = true;
	$deployer->deploy();

	// b.txt was skipped: still exists on server
	Assert::true($server->hasFile('/b.txt'));
	Assert::same('content b', $server->getFileContent('/b.txt'));
	// a.txt and c.txt were deleted
	Assert::false($server->hasFile('/a.txt'));
	Assert::false($server->hasFile('/c.txt'));
	// Deployment file must still track b.txt so next deploy retries the deletion
	$hashes = Deployment\decodeDeploymentFile($server);
	Assert::same('hash_b', $hashes['/b.txt']);
	Assert::false(isset($hashes['/a.txt']));
	Assert::false(isset($hashes['/c.txt']));
	// skip was tracked
	Assert::true($handler->hasSkipped());
});
