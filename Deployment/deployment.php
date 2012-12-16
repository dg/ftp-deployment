<?php

echo '
FTP deployment
--------------
';

if (version_compare(PHP_VERSION, '5.3.0', '<')) {
	throw new Exception('Deployment requires PHP 5.3.0 or newer.');
}

require __DIR__ . '/libs/Ftp.php';
require __DIR__ . '/libs/Logger.php';
require __DIR__ . '/libs/Deployment.php';
require __DIR__ . '/libs/Preprocessor.php';



// load config file
if (!isset($_SERVER['argv'][1])) {
	die("Usage: {$_SERVER['argv'][0]} <config_file> [-t | --test]");
}

$configFile = realpath($_SERVER['argv'][1]);
if (!$configFile) {
	die("Missing config file {$_SERVER['argv'][1]}");
}

$options = getopt('t', array('test'));
$config = parse_ini_file($configFile, TRUE);

if (isset($config['remote']) && is_string($config['remote'])) {
	$config = array('' => $config);
}

$logger = new Logger(preg_replace('#\.\w+$#', '.log', $configFile));



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



// start deploy
$logger->log("Started at " . date('[Y/m/d H:i]'));
$logger->log("Config file is $configFile");

foreach ($config as $section => $cfg) {
	$logger->log("\nDeploying $section");

	$cfg = array_change_key_case($cfg, CASE_LOWER) + array(
		'local' => dirname($configFile),
		'ignore' => '',
		'allowdelete' => TRUE,
		'purge' => '',
		'before' => '',
		'after' => '',
		'preprocess' => TRUE,
	);

	if (empty($cfg['remote'])) {
		throw new Exception("Missing 'remote' in config.");
	}

	$deployment = new Deployment($cfg['remote'], $cfg['local'], $logger);

	if ($cfg['preprocess']) {
		$preprocessor = new Preprocessor($logger);
		$deployment->addFilter('js', array($preprocessor, 'expandApacheImports'));
		$deployment->addFilter('js', array($preprocessor, 'compress'));
		$deployment->addFilter('css', array($preprocessor, 'expandApacheImports'));
		$deployment->addFilter('css', array($preprocessor, 'expandCssImports'));
		$deployment->addFilter('css', array($preprocessor, 'compress'));
	}

	$deployment->ignoreMasks = array_merge(
		array('*.bak', '.svn' , '.git*'),
		is_array($cfg['ignore']) ? $cfg['ignore'] : preg_split('#\s+#', $cfg['ignore'], -1, PREG_SPLIT_NO_EMPTY)
	);
	$deployment->deploymentFile = empty($cfg['deploymentfile']) ? $deployment->deploymentFile : $cfg['deploymentfile'];
	$deployment->testMode = !empty($cfg['test']) || isset($options['t']) || isset($options['test']);
	$deployment->allowDelete = $cfg['allowdelete'];
	$deployment->toPurge = is_array($cfg['purge']) ? $cfg['purge'] : preg_split('#\s+#', $cfg['purge'], -1, PREG_SPLIT_NO_EMPTY);
	$deployment->runBefore = is_array($cfg['before']) ? $cfg['before'] : preg_split('#\s+#', $cfg['before'], -1, PREG_SPLIT_NO_EMPTY);
	$deployment->runAfter = is_array($cfg['after']) ? $cfg['after'] : preg_split('#\s+#', $cfg['after'], -1, PREG_SPLIT_NO_EMPTY);

	if ($deployment->testMode) {
		$logger->log('Test mode');
	}
	if (!$deployment->allowDelete) {
		$logger->log('Deleting disabled');
	}

	$deployment->deploy();
}

$logger->log("\nFinished at " . date('[Y/m/d H:i]'));
