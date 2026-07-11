<?php declare(strict_types=1);

use Deployment\CliRunner;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


/** exposes the private expandEnvVars() for testing */
$runner = new class extends CliRunner {
	public function expand(mixed $value): mixed
	{
		return (new ReflectionMethod(CliRunner::class, 'expandEnvVars'))->invoke($this, $value);
	}
};


putenv('FTP_DEPLOY_TEST_SECRET=s3cr3t');

// scalar and nested replacement
Assert::same('s3cr3t', $runner->expand('%env(FTP_DEPLOY_TEST_SECRET)%'));
Assert::same(
	'ftp://user:s3cr3t@host/dir',
	$runner->expand('ftp://user:%env(FTP_DEPLOY_TEST_SECRET)%@host/dir'),
);
Assert::same(
	['remote' => 'x', 'before' => ['local: echo s3cr3t']],
	$runner->expand(['remote' => 'x', 'before' => ['local: echo %env( FTP_DEPLOY_TEST_SECRET )%']]),
);

// non-string values pass through untouched
Assert::same(true, $runner->expand(true));
Assert::same(42, $runner->expand(42));

// missing variable fails fast
Assert::exception(
	fn() => $runner->expand('%env(FTP_DEPLOY_TEST_MISSING)%'),
	Exception::class,
	"Environment variable 'FTP_DEPLOY_TEST_MISSING' referenced in config is not set.",
);

putenv('FTP_DEPLOY_TEST_SECRET');
