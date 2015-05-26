<?php

use Tester\Assert,
	Deployment\Deployer;

require __DIR__ . '/bootstrap.php';


file_put_contents(TEMP_DIR . '/file', "a\r\nb");
Assert::same( md5("a\nb"), Deployer::hashFile(TEMP_DIR . '/file') );

file_put_contents(TEMP_DIR . '/file', "a\r\nb\x00");
Assert::same( md5("a\r\nb\x00"), Deployer::hashFile(TEMP_DIR . '/file') );
