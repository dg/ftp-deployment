<?php

return [
	'my site' => [
		'remote' => 'ftp://user:secretpassword@ftp.example.com/directory',
		'local' => '.',
		'test' => false,
		'ignore' => '
			.git*
			project.pp[jx]
			/deployment.*
			/log
			temp/*
			!temp/.htaccess
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
