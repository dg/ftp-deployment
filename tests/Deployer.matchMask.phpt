<?php

use Tester\Assert,
	Deployment\Deployer;

require __DIR__ . '/bootstrap.php';


Assert::false( Deployer::matchMask('/deployment.ini', ['*.i[xy]i']) );
Assert::false( Deployer::matchMask('/deployment.ini', ['*.i[!n]i']) );
Assert::true( Deployer::matchMask('/deployment.ini', ['*.ini']) );
Assert::false( Deployer::matchMask('deployment.ini', ['*.ini/']) );
Assert::true( Deployer::matchMask('deployment.ini', ['/*.ini']) );
Assert::true( Deployer::matchMask('.git', ['.g*']) );
Assert::false( Deployer::matchMask('.git', ['.g*/']) );

Assert::false( Deployer::matchMask('deployment.ini', ['*.ini', '!dep*']) );
Assert::true( Deployer::matchMask('deployment.ini', ['*.ini', '!dep*', '*ment*']) );

// a/* disallowes everything to the right, i.e. a/*/*/*
// !a/*/c allowes directories to the left, i.e. a, a/*
Assert::true(  Deployer::matchMask('a',     ['a']      ) );
Assert::true(  Deployer::matchMask('a',     ['a'], TRUE) );
Assert::false( Deployer::matchMask('a/b',   ['a']      ) );
Assert::false( Deployer::matchMask('a/b',   ['a'], TRUE) );
Assert::true(  Deployer::matchMask('a/b',   ['b']      ) );
Assert::true(  Deployer::matchMask('a/b',   ['b'], TRUE) );
Assert::false( Deployer::matchMask('a/b',   ['c']      ) );
Assert::false( Deployer::matchMask('a/b',   ['c'], TRUE) );
Assert::false( Deployer::matchMask('a',     ['*', '!a']      ) );
Assert::false( Deployer::matchMask('a',     ['*', '!a'], TRUE) );
Assert::true(  Deployer::matchMask('a/b',   ['*', '!a']      ) );
Assert::true(  Deployer::matchMask('a/b',   ['*', '!a'], TRUE) );
Assert::false( Deployer::matchMask('a/b',   ['*', '!b']      ) );
Assert::false( Deployer::matchMask('a/b',   ['*', '!b'], TRUE) );
Assert::true(  Deployer::matchMask('a/b',   ['*', '!c']      ) );
Assert::true(  Deployer::matchMask('a/b',   ['*', '!c'], TRUE) );

Assert::true(  Deployer::matchMask('a',     ['/a']      ) );
Assert::true(  Deployer::matchMask('a',     ['/a'], TRUE) );
Assert::true(  Deployer::matchMask('a/b',   ['/a']      ) );
Assert::true(  Deployer::matchMask('a/b',   ['/a'], TRUE) );
Assert::true(  Deployer::matchMask('a/b/b', ['/a']      ) );
Assert::true(  Deployer::matchMask('a/b/b', ['/a'], TRUE) );
Assert::false( Deployer::matchMask('a',     ['*', '!/a']      ) );
Assert::false( Deployer::matchMask('a',     ['*', '!/a'], TRUE) );
Assert::false( Deployer::matchMask('a/b',   ['*', '!/a']      ) );
Assert::false( Deployer::matchMask('a/b',   ['*', '!/a'], TRUE) );
Assert::false( Deployer::matchMask('a/b/b', ['*', '!/a']      ) );
Assert::false( Deployer::matchMask('a/b/b', ['*', '!/a'], TRUE) );
Assert::false( Deployer::matchMask('a',     ['a/']      ) );
Assert::true(  Deployer::matchMask('a',     ['a/'], TRUE) );
Assert::true(  Deployer::matchMask('a/b',   ['a/']      ) );
Assert::true(  Deployer::matchMask('a/b',   ['a/'], TRUE) );
Assert::true(  Deployer::matchMask('a/b/b', ['a/']      ) );
Assert::true(  Deployer::matchMask('a/b/b', ['a/'], TRUE) );
Assert::true(  Deployer::matchMask('a',     ['*', '!a/']      ) );
Assert::false( Deployer::matchMask('a',     ['*', '!a/'], TRUE) );
Assert::false( Deployer::matchMask('a/b',   ['*', '!a/']      ) );
Assert::false( Deployer::matchMask('a/b',   ['*', '!a/'], TRUE) );
Assert::false( Deployer::matchMask('a/b/b', ['*', '!a/']      ) );
Assert::false( Deployer::matchMask('a/b/b', ['*', '!a/'], TRUE) );
Assert::false( Deployer::matchMask('a',     ['a/*']      ) );
Assert::false( Deployer::matchMask('a',     ['a/*'], TRUE) );
Assert::true(  Deployer::matchMask('a/b',   ['a/*']      ) );
Assert::true(  Deployer::matchMask('a/b',   ['a/*'], TRUE) );
Assert::true(  Deployer::matchMask('a/b/b', ['a/*']      ) );
Assert::true(  Deployer::matchMask('a/b/b', ['a/*'], TRUE) );
Assert::true(  Deployer::matchMask('a',     ['*', '!a/*']      ) );
Assert::false( Deployer::matchMask('a',     ['*', '!a/*'], TRUE) );
Assert::false( Deployer::matchMask('a/b',   ['*', '!a/*']      ) );
Assert::false( Deployer::matchMask('a/b',   ['*', '!a/*'], TRUE) );
Assert::false( Deployer::matchMask('a/b/b', ['*', '!a/*']      ) );
Assert::false( Deployer::matchMask('a/b/b', ['*', '!a/*'], TRUE) );
Assert::false( Deployer::matchMask('a',     ['a/*/']      ) );
Assert::false( Deployer::matchMask('a',     ['a/*/'], TRUE) );
Assert::false( Deployer::matchMask('a/b',   ['a/*/']      ) );
Assert::true(  Deployer::matchMask('a/b',   ['a/*/'], TRUE) );
Assert::true(  Deployer::matchMask('a/b/b', ['a/*/']      ) );
Assert::true(  Deployer::matchMask('a/b/b', ['a/*/'], TRUE) );
Assert::true(  Deployer::matchMask('a',     ['*', '!a/*/']      ) );
Assert::false( Deployer::matchMask('a',     ['*', '!a/*/'], TRUE) );
Assert::true(  Deployer::matchMask('a/b',   ['*', '!a/*/']      ) );
Assert::false( Deployer::matchMask('a/b',   ['*', '!a/*/'], TRUE) );
Assert::false( Deployer::matchMask('a/b/b', ['*', '!a/*/']      ) );
Assert::false( Deployer::matchMask('a/b/b', ['*', '!a/*/'], TRUE) );
Assert::false( Deployer::matchMask('a',     ['a/*/b']      ) );
Assert::false( Deployer::matchMask('a',     ['a/*/b'], TRUE) );
Assert::false( Deployer::matchMask('a/b',   ['a/*/b']      ) );
Assert::false( Deployer::matchMask('a/b',   ['a/*/b'], TRUE) );
Assert::true(  Deployer::matchMask('a/b/b', ['a/*/b']      ) );
Assert::true(  Deployer::matchMask('a/b/b', ['a/*/b'], TRUE) );
Assert::false( Deployer::matchMask('a/b/c', ['a/*/b']      ) );
Assert::false( Deployer::matchMask('a/b/c', ['a/*/b'], TRUE) );
Assert::true(  Deployer::matchMask('a',     ['*', '!a/*/b']      ) );
Assert::false( Deployer::matchMask('a',     ['*', '!a/*/b'], TRUE) );
Assert::true(  Deployer::matchMask('a/b',   ['*', '!a/*/b']      ) );
Assert::false( Deployer::matchMask('a/b',   ['*', '!a/*/b'], TRUE) );
Assert::false( Deployer::matchMask('a/b/b', ['*', '!a/*/b']      ) );
Assert::false( Deployer::matchMask('a/b/b', ['*', '!a/*/b'], TRUE) );
Assert::true(  Deployer::matchMask('a/b/c', ['*', '!a/*/b']      ) );
Assert::true(  Deployer::matchMask('a/b/c', ['*', '!a/*/b'], TRUE) );
Assert::false( Deployer::matchMask('a',     ['a/*/b/']      ) );
Assert::false( Deployer::matchMask('a',     ['a/*/b/'], TRUE) );
Assert::false( Deployer::matchMask('a/b',   ['a/*/b/']      ) );
Assert::false( Deployer::matchMask('a/b',   ['a/*/b/'], TRUE) );
Assert::false( Deployer::matchMask('a/b/b', ['a/*/b/']      ) );
Assert::true(  Deployer::matchMask('a/b/b', ['a/*/b/'], TRUE) );
Assert::false( Deployer::matchMask('a/b/c', ['a/*/b/']      ) );
Assert::false( Deployer::matchMask('a/b/c', ['a/*/b/'], TRUE) );
Assert::true(  Deployer::matchMask('a',     ['*', '!a/*/b/']      ) );
Assert::false( Deployer::matchMask('a',     ['*', '!a/*/b/'], TRUE) );
Assert::true(  Deployer::matchMask('a/b',   ['*', '!a/*/b/']      ) );
Assert::false( Deployer::matchMask('a/b',   ['*', '!a/*/b/'], TRUE) );
Assert::true(  Deployer::matchMask('a/b/b', ['*', '!a/*/b/']      ) );
Assert::false( Deployer::matchMask('a/b/b', ['*', '!a/*/b/'], TRUE) );
Assert::true(  Deployer::matchMask('a/b/c', ['*', '!a/*/b/']      ) );
Assert::true(  Deployer::matchMask('a/b/c', ['*', '!a/*/b/'], TRUE) );
