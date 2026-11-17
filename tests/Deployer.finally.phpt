<?php declare(strict_types=1);

use Deployment\{Deployer, JobException, LockedException, MockServer, ServerException};
use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/MockServer.php';


function createDeployer(string $name, ?MockServer $server = null): Deployer
{
	$localDir = TEMP_DIR . "/$name/local";
	@mkdir($localDir, 0o777, true); // @ - directory may already exist
	file_put_contents("$localDir/index.php", '<?php');

	$logger = new Deployment\Logger(TEMP_DIR . "/$name.log");
	$logger->showProgress = false;

	$deployer = new Deployer($server ?? new MockServer, $localDir, $logger);
	$deployer->tempDir = TEMP_DIR;
	return $deployer;
}


test('finally-jobs run after a successful deployment, after after-jobs', function () {
	$log = [];
	$deployer = createDeployer('fin1');
	$deployer->runAfter = [function () use (&$log) { $log[] = 'after'; }];
	$deployer->runFinally = [function () use (&$log) { $log[] = 'finally'; }];
	$deployer->deploy();

	Assert::same(['after', 'finally'], $log);
});


test('finally-jobs run when already synchronized', function () {
	$server = new MockServer;
	createDeployer('fin2', $server)->deploy();

	$log = [];
	$deployer = createDeployer('fin2', $server);
	$deployer->runFinally = [function () use (&$log) { $log[] = 'finally'; }];
	$deployer->deploy();

	Assert::same(['finally'], $log);
});


test('finally-jobs run after a failed deployment and the original error is thrown', function () {
	$log = [];
	$deployer = createDeployer('fin3');
	$deployer->runBefore = [fn() => false];
	$deployer->runFinally = [function () use (&$log) { $log[] = 'finally'; }];

	Assert::exception(fn() => $deployer->deploy(), JobException::class, 'Job failed');
	Assert::same(['finally'], $log);
});


test('a failing finally-job does not stop the others nor mask the original error', function () {
	$log = [];
	$deployer = createDeployer('fin4');
	$deployer->runAfter = [fn() => throw new RuntimeException('after failed')];
	$deployer->runFinally = [
		fn() => throw new RuntimeException('finally failed'),
		function () use (&$log) { $log[] = 'second'; },
	];

	Assert::exception(fn() => $deployer->deploy(), RuntimeException::class, 'after failed');
	Assert::same(['second'], $log);
});


test('a failing finally-job fails a successful deployment', function () {
	$log = [];
	$deployer = createDeployer('fin5');
	$deployer->runFinally = [
		fn() => false,
		function () use (&$log) { $log[] = 'second'; },
	];

	Assert::exception(fn() => $deployer->deploy(), JobException::class, 'Job failed');
	Assert::same(['second'], $log);
});


test('finally-jobs do not run in test mode', function () {
	$log = [];
	$deployer = createDeployer('fin6');
	$deployer->testMode = true;
	$deployer->runFinally = [function () use (&$log) { $log[] = 'finally'; }];
	$deployer->deploy();

	Assert::same([], $log);
});


test('finally-jobs do not run while another deployment holds the lock', function () {
	$log = [];
	$deployer = createDeployer('fin7', new MockServer(['/.htdeployment.running' => 'in progress']));
	$deployer->runFinally = [function () use (&$log) { $log[] = 'finally'; }];

	Assert::exception(fn() => $deployer->deploy(), LockedException::class, '%A%.running exists on the server%A%');
	Assert::same([], $log);
	Assert::contains('Finally-jobs skipped', file_get_contents(TEMP_DIR . '/fin7.log'));
});


test('without a connection the jobs that need the server are skipped, the others run', function () {
	$log = [];
	$server = new class extends MockServer {
		public function connect(): void
		{
			throw new ServerException('Login failed');
		}
	};
	$deployer = createDeployer('fin8', $server);
	$deployer->runFinally = [
		'remote: unlink maintenance.php',
		'upload: index.php copy.php',
		'local: echo local-job-output',
		function () use (&$log) { $log[] = 'callable'; },
	];

	Assert::exception(fn() => $deployer->deploy(), ServerException::class, 'Login failed');
	Assert::same(['callable'], $log);
	Assert::same([], $server->getOperations());
	$output = file_get_contents(TEMP_DIR . '/fin8.log');
	Assert::contains('remote: unlink maintenance.php: skipped, not connected to the server', $output);
	Assert::contains('upload: index.php copy.php: skipped, not connected to the server', $output);
	Assert::contains('-> local-job-output', $output);
});
