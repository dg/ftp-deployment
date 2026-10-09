<?php declare(strict_types=1);

use Deployment\{JobRunner, MockServer};
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/MockServer.php';


function runRemote(string $remoteDir, string $command): array
{
	$server = new MockServer([], $remoteDir);
	$runner = new JobRunner($server, TEMP_DIR, $remoteDir);
	$runner->remote($command);
	return array_map(fn($op) => [$op['method'], ...$op['args']], $server->getOperations());
}


test('relative paths are resolved against the remote directory', function () {
	Assert::same([['createDir', '/www/temp']], runRemote('/www', 'mkdir temp'));
	Assert::same([['removeDir', '/www/temp']], runRemote('/www', 'rmdir temp'));
	Assert::same([['removeFile', '/www/temp/a.txt']], runRemote('/www', 'unlink temp/a.txt'));
	Assert::same([['renameFile', '/www/a.txt', '/www/b.txt']], runRemote('/www', 'mv a.txt b.txt'));
	Assert::same([['chmod', '/www/temp/cache', 0o777]], runRemote('/www', 'chmod 0777 temp/cache'));
});


test('absolute paths are kept', function () {
	Assert::same([['createDir', '/other/temp']], runRemote('/www', 'mkdir /other/temp'));
	Assert::same([['renameFile', '/www/a.txt', '/b.txt']], runRemote('/www', 'mv a.txt /b.txt'));
	Assert::same([['chmod', '/other', 0o755]], runRemote('/www', 'chmod 0755 /other'));
});


test('remote directory in the root', function () {
	Assert::same([['createDir', '/temp']], runRemote('', 'mkdir temp'));
	Assert::same([['chmod', '/temp', 0o777]], runRemote('', 'chmod 0777 temp'));
});


test('other commands are executed', function () {
	Assert::same([['execute', 'unzip api.zip']], runRemote('/www', 'unzip api.zip'));
});
