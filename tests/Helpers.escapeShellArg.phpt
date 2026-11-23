<?php declare(strict_types=1);

use Deployment\Helpers;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


Assert::same("'/www/site'", Helpers::escapeShellArg('/www/site'));
Assert::same("'/www/50% off!/a\$b'", Helpers::escapeShellArg('/www/50% off!/a$b'));
Assert::same("'it'\\''s'", Helpers::escapeShellArg("it's"));
Assert::same("''", Helpers::escapeShellArg(''));


// the remote shell gets the argument back unchanged, nothing is expanded
if (PHP_OS_FAMILY !== 'Windows') {
	foreach (['/www/50% off!/a$b', "it's", '`id` $(id) "q" \\n * ~ ;|&<>', "new\nline", ''] as $arg) {
		$out = shell_exec('/bin/sh -c ' . escapeshellarg('printf %s ' . Helpers::escapeShellArg($arg)));
		Assert::same($arg, (string) $out);
	}
}
