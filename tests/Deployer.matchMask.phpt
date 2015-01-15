<?php

use Tester\Assert,
	Deployment\Deployer;

require __DIR__ . '/bootstrap.php';


Assert::false( Deployer::matchMask('/deployment.ini', ['*.i[xy]i']) );
Assert::true( Deployer::matchMask('/deployment.ini', ['*.ini']) );
Assert::false( Deployer::matchMask('deployment.ini', ['*.ini/']) );
Assert::true( Deployer::matchMask('deployment.ini', ['/*.ini']) );
Assert::true( Deployer::matchMask('.git', ['.g*']) );
Assert::false( Deployer::matchMask('.git', ['.g*/']) );

Assert::false( Deployer::matchMask('deployment.ini', ['*.ini', '!dep*']) );
Assert::true( Deployer::matchMask('deployment.ini', ['*.ini', '!dep*', '*ment*']) );

Assert::true( Deployer::matchMask('/dir', ['dir']) );
Assert::false( Deployer::matchMask('/dir', ['dir/']) );
Assert::true( Deployer::matchMask('/dir', ['dir/'], TRUE) );
Assert::false( Deployer::matchMask('/dir', ['dir/*']) );

Assert::true( Deployer::matchMask('dir/file', ['file']) );
Assert::false( Deployer::matchMask('dir/file', ['/file']) );
Assert::true( Deployer::matchMask('dir/file', ['dir/file']) );
Assert::true( Deployer::matchMask('dir/file', ['*/file']) );
Assert::true( Deployer::matchMask('dir/file', ['**/file']) );
Assert::false( Deployer::matchMask('dir/subdir/file', ['*/file']) );
Assert::true( Deployer::matchMask('dir/subdir/file', ['*/*/file']) );
