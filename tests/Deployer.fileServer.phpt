<?php declare(strict_types=1);

use Deployment\Deployer;
use Deployment\FileServer;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


test('fresh deploy with FileServer copies files to remote', function () {
	$localDir = TEMP_DIR . '/fs1/local';
	$remoteDir = TEMP_DIR . '/fs1/remote';
	mkdir($localDir . '/lib', 0o777, true);
	mkdir($remoteDir, 0o777, true);

	file_put_contents("$localDir/index.php", '<?php echo "hello";');
	file_put_contents("$localDir/style.css", 'body { color: red; }');
	file_put_contents("$localDir/lib/utils.php", '<?php function foo() {}');

	$server = new FileServer('file://' . $remoteDir);
	$logger = new Deployment\Logger(TEMP_DIR . '/fs1.log');
	$logger->showProgress = false;

	$deployer = new Deployer($server, $localDir, $logger);
	$deployer->tempDir = TEMP_DIR;
	$deployer->deploy();

	// All files should exist on remote with correct content
	Assert::same('<?php echo "hello";', file_get_contents("$remoteDir/index.php"));
	Assert::same('body { color: red; }', file_get_contents("$remoteDir/style.css"));
	Assert::same('<?php function foo() {}', file_get_contents("$remoteDir/lib/utils.php"));

	// .htdeployment should be valid gzipped data referencing all files
	$content = @gzinflate(file_get_contents("$remoteDir/.htdeployment"))
		?: gzdecode(file_get_contents("$remoteDir/.htdeployment"));
	Assert::type('string', $content);
	Assert::contains('/index.php', $content);
	Assert::contains('/style.css', $content);
	Assert::contains('/lib/utils.php', $content);

	// No .deploytmp or .running files should remain
	Assert::false(file_exists("$remoteDir/.htdeployment.running"));
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($remoteDir, FilesystemIterator::SKIP_DOTS));
	foreach ($iterator as $file) {
		Assert::false(
			str_ends_with($file->getPathname(), '.deploytmp'),
			".deploytmp should not remain: {$file->getPathname()}",
		);
	}
});


test('incremental deploy uploads only changed files', function () {
	// Use two separate local directories because Deployer has a static cache
	// keyed on localDir. This simulates the real scenario: same remote, changed files.
	$localDir1 = TEMP_DIR . '/fs2/local1';
	$localDir2 = TEMP_DIR . '/fs2/local2';
	$remoteDir = TEMP_DIR . '/fs2/remote';
	mkdir($localDir1, 0o777, true);
	mkdir($localDir2, 0o777, true);
	mkdir($remoteDir, 0o777, true);

	// First deploy: both files original
	file_put_contents("$localDir1/a.txt", 'original A');
	file_put_contents("$localDir1/b.txt", 'original B');

	$server = new FileServer('file://' . $remoteDir);
	$logger = new Deployment\Logger(TEMP_DIR . '/fs2.log');
	$logger->showProgress = false;

	$deployer1 = new Deployer($server, $localDir1, $logger);
	$deployer1->tempDir = TEMP_DIR;
	$deployer1->deploy();

	Assert::same('original A', file_get_contents("$remoteDir/a.txt"));
	Assert::same('original B', file_get_contents("$remoteDir/b.txt"));
	$mtimeB = filemtime("$remoteDir/b.txt");

	// Second deploy: a.txt modified, b.txt same
	file_put_contents("$localDir2/a.txt", 'modified A');
	file_put_contents("$localDir2/b.txt", 'original B');

	// Small delay so mtime difference is detectable
	sleep(1);

	$deployer2 = new Deployer($server, $localDir2, $logger);
	$deployer2->tempDir = TEMP_DIR;
	$deployer2->deploy();

	// a.txt updated, b.txt unchanged
	Assert::same('modified A', file_get_contents("$remoteDir/a.txt"));
	Assert::same('original B', file_get_contents("$remoteDir/b.txt"));

	// b.txt should not have been touched (mtime unchanged)
	clearstatcache();
	Assert::same($mtimeB, filemtime("$remoteDir/b.txt"));
});
