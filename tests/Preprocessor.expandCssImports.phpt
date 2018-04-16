<?php

declare(strict_types=1);

use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$logger = new Deployment\Logger('php://temp');
$processor = new Deployment\Preprocessor($logger);

$file = __DIR__ . '/fixtures/css/combined.css';
Assert::same('/* combined JS */

body {
	background: url(subdir/image.gif);
}

body {
	background: url(image.gif);
}

body {
	background: url(subdir/image.gif);
}


', $processor->expandCssImports(file_get_contents($file), $file));
