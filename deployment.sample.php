<?php

return array(
	'my site' => array(
		'remote' => 'ftp://user:secretpassword@ftp.example.com/directory',
		'local' => '.',
		'test' => FALSE,
		'ignore' => '
			.git*
			project.pp[jx]
			/deployment.*
			/log
			temp/*
			!temp/.htaccess
		',
		'allowdelete' => TRUE,
		'before' => array(
			function (Ftp $ftp, Logger $logger, Deployment $deployment) {
				$logger->log('Hello!');
			},
		),
		'after' => array(
			'http://example.com/deployment.php?after'
		),
		'purge' => array(
			'temp/cache',
		),
		'preprocess' => TRUE,
		'tempdir' => __DIR__ . '/temp',
	),
);
