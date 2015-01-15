<?php

use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$logger = new Deployment\Logger('php://temp');
$processor = new Deployment\Preprocessor($logger);

$file = __DIR__ . '/fixtures/js/combined.js';
Assert::same('/* combined JS */

alert();

console.log();

', $processor->expandApacheImports(file_get_contents($file), $file));
