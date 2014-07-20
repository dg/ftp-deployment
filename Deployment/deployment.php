<?php

require __DIR__ . '/libs/Server.php';
require __DIR__ . '/libs/FtpServer.php';
require __DIR__ . '/libs/SshServer.php';
require __DIR__ . '/libs/Logger.php';
require __DIR__ . '/libs/Deployment.php';
require __DIR__ . '/libs/Preprocessor.php';
require __DIR__ . '/libs/CommandLine.php';



$cmd = new CommandLine("
FTP deployment v1.5.1
---------------------
Usage:
	deployment.php <config_file> [-t | --test]

Options:
	-t | --test      Run in test-mode.

", [
	'config' => [CommandLine::REALPATH => TRUE],
]);

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

$config += [
	'log' => preg_replace('#\.\w+$#', '.log', $options['config']),
];

$logger = new Logger($config['log']);



// configure PHP
set_time_limit(0);
date_default_timezone_set('Europe/Prague');
set_error_handler(function($severity, $message, $file, $line) use ($logger) {
	if (($severity & error_reporting()) === $severity) {
		$logger->log("Error: $message in $file on $line", 'light-red');
		exit;
	}
	return FALSE;
});
set_exception_handler(function($e) use ($logger) {
	$logger->log("Error: {$e->getMessage()}\n\n$e", 'light-red');
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
	$config = ['' => $config];
}

foreach ($config as $section => $cfg) {
	if (!is_array($cfg)) {
		continue;
	}

	$logger->log("\nDeploying $section");

	$cfg = array_change_key_case($cfg, CASE_LOWER) + [
		'local' => dirname($options['config']),
		'passivemode' => TRUE,
		'ignore' => '',
		'allowdelete' => TRUE,
		'purge' => '',
		'before' => '',
		'after' => '',
		'preprocess' => TRUE,
		'tempdir' => sys_get_temp_dir() . '/deployment',
		'colors' => (PHP_SAPI === 'cli' && ((function_exists('posix_isatty') && posix_isatty(STDOUT))
			|| getenv('ConEmuANSI') === 'ON' || getenv('ANSICON') !== FALSE)),
	];

	if (empty($cfg['remote']) || !parse_url($cfg['remote'])) {
		throw new Exception("Missing or invalid 'remote' URL in config.");
	}

	$logger->useColors = (bool) $cfg['colors'];

	$server = parse_url($cfg['remote'], PHP_URL_SCHEME) === 'sftp'
		? new SshServer($cfg['remote'])
		: new FtpServer($cfg['remote'], (bool) $cfg['passivemode']);

	$deployment = new Deployment($server, $cfg['local'], $logger);

	if ($cfg['preprocess']) {
		$deployment->preprocessMasks = $cfg['preprocess'] == 1 ? ['*.js', '*.css'] : toArray($cfg['preprocess']); // intentionally ==
		$preprocessor = new Preprocessor($logger);
		$deployment->addFilter('js', [$preprocessor, 'expandApacheImports']);
		$deployment->addFilter('js', [$preprocessor, 'compress'], TRUE);
		$deployment->addFilter('css', [$preprocessor, 'expandApacheImports']);
		$deployment->addFilter('css', [$preprocessor, 'expandCssImports']);
		$deployment->addFilter('css', [$preprocessor, 'compress'], TRUE);
	}

	$deployment->ignoreMasks = array_merge(
		['*.bak', '.svn' , '.git*', 'Thumbs.db', '.DS_Store'],
		toArray($cfg['ignore'])
	);
	$deployment->deploymentFile = empty($cfg['deploymentfile']) ? $deployment->deploymentFile : $cfg['deploymentfile'];
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
$logger->log("\nFinished at " . date('[Y/m/d H:i]') . " (in $time seconds)", 'light-green');
