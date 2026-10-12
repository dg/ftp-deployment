<?php declare(strict_types=1);

use Deployment\{Deployer, MockServer};
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/MockServer.php';


class Filters
{
	public function js(string $content): string
	{
		return "js:$content";
	}


	public function css(string $content): string
	{
		return "css:$content";
	}
}


function createDeployer(string $name, MockServer $server): Deployer
{
	$localDir = TEMP_DIR . "/$name/local";
	mkdir($localDir, recursive: true);
	file_put_contents("$localDir/a.js", 'same');
	file_put_contents("$localDir/a.css", 'same');

	$logger = new Deployment\Logger(TEMP_DIR . "/$name.log");
	$logger->showProgress = false;

	$deployer = new Deployer($server, $localDir, $logger);
	$deployer->tempDir = TEMP_DIR . "/$name";
	$deployer->preprocessMasks = ['*.js', '*.css'];
	return $deployer;
}


test('cached filters do not share cache for identical content', function () {
	$server = new MockServer;
	$deployer = createDeployer('filters1', $server);
	$filters = new Filters;
	$deployer->addFilter('js', $filters->js(...), cached: true);
	$deployer->addFilter('css', $filters->css(...), cached: true);
	$deployer->deploy();

	Assert::same('js:same', $server->getFileContent('/a.js'));
	Assert::same('css:same', $server->getFileContent('/a.css'));
});


test('filter can be an array callable', function () {
	$server = new MockServer;
	$deployer = createDeployer('filters2', $server);
	$deployer->addFilter('js', [new Filters, 'js'], cached: true);
	$deployer->deploy();

	Assert::same('js:same', $server->getFileContent('/a.js'));
});
