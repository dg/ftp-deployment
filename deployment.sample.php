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
			'http://example.com/deployment.php?before',
		),
		'after' => array(
			'http://example.com/deployment.php?after'
		),
		'purge' => array(
			'temp/cache',
		),
		'preprocess' => TRUE,
	),
);
