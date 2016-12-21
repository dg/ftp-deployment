<?php

use Tester\Assert;
use Deployment\Helpers;

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
