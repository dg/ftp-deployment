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

// a/* disallowes everything to the right, i.e. a/*/*/*
// !a/*/c allowes directories to the left, i.e. a, a/*
Assert::false( Deployer::matchMask('a', ['/a', '!/*']) );
Assert::false( Deployer::matchMask('a', ['/a', '!/*'], TRUE) );
Assert::true( Deployer::matchMask('a', ['a', '!*/b']) );
Assert::false( Deployer::matchMask('a', ['a', '!*/b'], TRUE) );
Assert::true( Deployer::matchMask('a', ['a', '!a/b', 'a/b/c']) );
Assert::false( Deployer::matchMask('a', ['a', '!a/b', 'a/b/c'], TRUE) );
Assert::false( Deployer::matchMask('a', ['a/*', '!a/b', 'a/b/c']) );
Assert::false( Deployer::matchMask('b', ['a', '!a/b', 'a/b/c']) );
Assert::false( Deployer::matchMask('a/b', ['a', '!a/b', 'a/b/c']) );
Assert::false( Deployer::matchMask('a/b', ['a/*', '!a/b', 'a/b/c']) );
Assert::true( Deployer::matchMask('a/c', ['c', '!a/b', 'a/b/c']) );
Assert::true( Deployer::matchMask('a/c', ['a', '!a/b', 'a/b/c']) );
Assert::true( Deployer::matchMask('a/c', ['a/*', '!a/b', 'a/b/c']) );
Assert::true( Deployer::matchMask('a/b/c', ['a', '!a/b', 'a/b/c']) );
Assert::true( Deployer::matchMask('a/b/c', ['a/*', '!*/b', 'a/b/c']) );
Assert::false( Deployer::matchMask('a/b/d', ['a', '!a/b', 'a/b/c']) );
Assert::true( Deployer::matchMask('a/b/c/d', ['a', '!a/b', 'a/b/c']) );
