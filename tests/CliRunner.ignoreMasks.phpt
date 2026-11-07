<?php declare(strict_types=1);

use Deployment\{CliRunner, InterruptHandler, Logger};
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


/** @return list<string> */
function collectPaths(string $localDir, string $ignore): array
{
	$deployer = (function () use ($localDir, $ignore) {
		$this->logger = new Logger(TEMP_DIR . '/cli.log');
		$this->logger->showProgress = false;
		$this->interruptHandler = new InterruptHandler;
		$this->mode = null;
		return $this->createDeployer([
			'remote' => 'file:///remote',
			'local' => $localDir,
			'ignore' => $ignore,
		] + $this->defaults);
	})->call(new CliRunner);

	$paths = array_keys($deployer->collectPaths());
	sort($paths);
	return $paths;
}


function createFiles(string $dir, array $files): void
{
	foreach ($files as $file) {
		@mkdir(dirname("$dir/$file"), recursive: true); // @ directory may already exist
		file_put_contents("$dir/$file", '');
	}
}


test('forced masks hold against an exception re-including directories', function () {
	$localDir = TEMP_DIR . '/forced';
	createFiles($localDir, [
		'.git/config',
		'.idea/workspace.xml',
		'composer.json',
		'app/.git/HEAD',
		'app/index.php',
		'www/Thumbs.db',
	]);

	Assert::same(
		['/app/', '/app/index.php', '/www/'],
		collectPaths($localDir, '/* !/*/'),
	);
});


test('an exception overrides the default masks', function () {
	$localDir = TEMP_DIR . '/default';
	createFiles($localDir, [
		'.git/config',
		'.gitignore',
		'.gitlab-ci.yml',
		'index.php',
		'index.php.bak',
	]);

	Assert::same(
		['/.gitlab-ci.yml', '/index.php'],
		collectPaths($localDir, '!.gitlab-ci.yml'),
	);
	Assert::same(
		['/.gitignore', '/.gitlab-ci.yml', '/index.php', '/index.php.bak'],
		collectPaths($localDir, '!.git* !*.bak'),
	);
});
