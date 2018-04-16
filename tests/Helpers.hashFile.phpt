<?php

declare(strict_types=1);

use Deployment\Helpers;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


file_put_contents(TEMP_DIR . '/file', "a\r\nb");
Assert::same(md5("a\nb"), Helpers::hashFile(TEMP_DIR . '/file'));

file_put_contents(TEMP_DIR . '/file', "a\r\nb\x00");
Assert::same(md5("a\r\nb\x00"), Helpers::hashFile(TEMP_DIR . '/file'));
