<?php

return [
	'my site' => [
		'remote' => 'ftp://user:secretpassword@ftp.example.com/directory',
		'local' => '.',
		'test' => false,

	    'ignore' => '
			*/examples/*
        	*/docs/*
        	*/docs2/* (Doctrine)
        	*/tests/*
        	*/test/*
        	*/Tests/* (Carbon)
        	*/swiftmailer/swiftmailer/notes/*
		',

		'includes' => '
        	app
        	app/*
        	Modules
        	Modules/*
        	public
        	public/*
        	resources
        	resources/*
        	routes
        	routes/*
        	vendor
        	vendor/*
        	index.php
        ',

		'allowDelete' => true,
		'before' => [
			function (Deployment\Server $server, Deployment\Logger $logger, Deployment\Deployer $deployer) {
				$logger->log('Hello!');
			},
		],
		'afterUpload' => [
			'http://example.com/deployment.php?afterUpload'
		],
		'after' => [
			'http://example.com/deployment.php?after'
		],
		'purge' => [
			'temp/cache',
		],
		'preprocess' => false,
	],

	'tempDir' => __DIR__ . '/temp',
	'colors' => true,
];
