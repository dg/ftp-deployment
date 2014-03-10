<?php
// Version 1.2

if (version_compare(PHP_VERSION, '5.3.0', '<')) {
	throw new Exception('Deployment requires PHP 5.3.0 or newer.');
}

require __DIR__ . '/libs/Ftp.php';
require __DIR__ . '/libs/Logger.php';
require __DIR__ . '/libs/Deployment.php';
require __DIR__ . '/libs/Preprocessor.php';
require __DIR__ . '/libs/CommandLine.php';



$cmd = new CommandLine("
FTP deployment
--------------
Usage:
	deployment.php <config_file> [-t | --test]

Options:
	-t | --test      Run in test-mode.

", array(
	'config' => array(CommandLine::REALPATH => TRUE),
));

if ($cmd->isEmpty()) {
	$cmd->help();
	exit;
}

$options = $cmd->parse();

if (pathinfo($options['config'], PATHINFO_EXTENSION) == 'php') {
	$config = include $options['config'];
} else {
	$config = parse_ini_file($options['config'], TRUE);
}

$logger = new Logger(empty($config['log']) ? preg_replace('#\.\w+$#', '.log', $options['config']) : $config['log']);



// configure PHP
set_time_limit(0);
date_default_timezone_set('Europe/Prague');
set_error_handler(function($severity, $message, $file, $line) use ($logger) {
	if (($severity & error_reporting()) === $severity) {
		$logger->log("Error: $message in $file on $line");
		exit;
	}
	return FALSE;
});
set_exception_handler(function($e) use ($logger) {
	$logger->log("Error: {$e->getMessage()} in {$e->getFile()} on {$e->getLine()}");
});


function toArray($val)
{
	return is_array($val) ? array_filter($val) : preg_split('#\s+#', $val, -1, PREG_SPLIT_NO_EMPTY);
}


// start deploy
$time = time();
$logger->log("Started at " . date('[Y/m/d H:i]'));
$logger->log("Config file is $options[config]");

if (isset($config['remote']) && is_string($config['remote'])) {
	$config = array('' => $config);
}

foreach ($config as $section => $cfg) {
	$logger->log("\nDeploying $section");

	$cfg = array_change_key_case($cfg, CASE_LOWER) + array(
		'local' => dirname($options['config']),
		'passivemode' => TRUE,
		'colored' => TRUE,
		'ignore' => '',
		'allowdelete' => TRUE,
		'purge' => '',
		'before' => '',
		'after' => '',
		'preprocess' => TRUE,
		'tempdir' => sys_get_temp_dir() . '/deployment',
	);

	if (empty($cfg['remote'])) {
		throw new Exception("Missing 'remote' in config.");
	}

	$deployment = new Deployment($cfg['remote'], $cfg['local'], $logger);

	if ($cfg['preprocess']) {
		$deployment->preprocessMasks = $cfg['preprocess'] == 1 ? array('*.js', '*.css') : toArray($cfg['preprocess']); // intentionally ==
		$preprocessor = new Preprocessor($logger);
		$deployment->addFilter('js', array($preprocessor, 'expandApacheImports'));
		$deployment->addFilter('js', array($preprocessor, 'compress'), TRUE);
		$deployment->addFilter('css', array($preprocessor, 'expandApacheImports'));
		$deployment->addFilter('css', array($preprocessor, 'expandCssImports'));
		$deployment->addFilter('css', array($preprocessor, 'compress'), TRUE);
	}

	$deployment->ignoreMasks = array_merge(
		array('*.bak', '.svn' , '.git*', 'Thumbs.db', '.DS_Store'),
		toArray($cfg['ignore'])
	);
	$deployment->deploymentFile = empty($cfg['deploymentfile']) ? $deployment->deploymentFile : $cfg['deploymentfile'];
	$deployment->passiveMode = (bool) $cfg['passivemode'];
	$deployment->colored = (bool) $cfg['colored'];
	$deployment->testMode = !empty($cfg['test']) || $options['--test'];
	$deployment->allowDelete = $cfg['allowdelete'];
	$deployment->toPurge = toArray($cfg['purge']);
	$deployment->runBefore = toArray($cfg['before']);
	$deployment->runAfter = toArray($cfg['after']);
	$deployment->tempDir = $cfg['tempdir'];

	if ($deployment->testMode) {
		$logger->log('Test mode');
	}
	if (!$deployment->allowDelete) {
		$logger->log('Deleting disabled');
	}

	$deployment->deploy();
}

$time = time() - $time;
$logger->log("\nFinished at " . date('[Y/m/d H:i]') . " (in $time seconds)", $deployment->colored ? $deployment::COLOR_OK : FALSE);
