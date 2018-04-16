<?php

declare(strict_types=1);

use Deployment\Helpers;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$output = Helpers::fetchUrl('http://example.com/', $error);
Assert::contains('Example Domain', $output);
Assert::null($error);

$output = Helpers::fetchUrl('http://www.iana.org/404', $error);
if (extension_loaded('curl')) {
	Assert::contains('This page does not exist.', $output);
} else {
	Assert::same('', $output);
}
Assert::contains('404', $error);
