<?php declare(strict_types=1);

use Deployment\{CurlServer, FileServer, FtpServer, InterruptHandler, Logger, RetryServer, Server, ServerFactory};
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


function createServer(array $config): Server
{
	$factory = new ServerFactory(new Logger(TEMP_DIR . '/factory.log'), new InterruptHandler);
	$server = $factory->create($config + ['passivemode' => true]);
	Assert::type(RetryServer::class, $server);
	return (new ReflectionProperty($server, 'server'))->getValue($server);
}


test('ftps:// goes through cURL when the extension is loaded', function () {
	if (!extension_loaded('curl') || !extension_loaded('ftp')) {
		Tester\Environment::skip('Requires the cURL and FTP extensions.');
	}

	Assert::type(CurlServer::class, createServer(['remote' => 'ftps://user:secret@example.com/dir']));
	Assert::type(FtpServer::class, createServer(['remote' => 'ftps://user:secret@example.com/dir', 'curl' => false]));
});


test('ftp:// stays with the FTP extension', function () {
	if (!extension_loaded('ftp')) {
		Tester\Environment::skip('Requires the FTP extension.');
	}

	Assert::type(FtpServer::class, createServer(['remote' => 'ftp://user:secret@example.com/dir']));
});


test('other protocols', function () {
	Assert::type(FileServer::class, createServer(['remote' => 'file:///srv/www']));
});


test('invalid remote', function () {
	Assert::exception(
		fn() => createServer(['remote' => '']),
		Exception::class,
		"Missing or invalid 'remote' URL in config.",
	);
});
