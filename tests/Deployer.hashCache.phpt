<?php declare(strict_types=1);

use Deployment\{Deployer, Helpers, MockServer};
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/MockServer.php';


$localDir = TEMP_DIR . '/hashcache/local';
mkdir($localDir, 0o777, true);
$file = "$localDir/a.txt";
file_put_contents($file, 'content');
$mtime = filemtime($file);

$logger = new Deployment\Logger(TEMP_DIR . '/hashcache.log');
$logger->showProgress = false;

$make = function () use ($localDir, $logger): Deployer {
	$d = new Deployer(new MockServer([]), $localDir, $logger);
	$d->tempDir = TEMP_DIR;
	$d->useHashCache = true;
	return $d;
};

// first scan computes and persists the hash
$first = $make();
$paths = $first->collectPaths();
$originalHash = Helpers::hashFile($file);
Assert::same($originalHash, $paths['/a.txt']);
$first->cleanup();
Assert::true(is_file(TEMP_DIR . '/.hashcache'));

// overwrite with different content of the SAME length and restore mtime:
// a cache hit must return the OLD hash, proving the cached value was reused
file_put_contents($file, 'CONTENT');
touch($file, $mtime);
clearstatcache();
$cached = $make()->collectPaths();
Assert::same($originalHash, $cached['/a.txt']);

// changing the mtime invalidates the entry and the new hash is computed
touch($file, $mtime + 10);
clearstatcache();
$fresh = $make()->collectPaths();
Assert::same(Helpers::hashFile($file), $fresh['/a.txt']);
Assert::notSame($originalHash, $fresh['/a.txt']);
