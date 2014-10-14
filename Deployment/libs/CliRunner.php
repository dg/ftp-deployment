<?php

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (http://davidgrudl.com)
 */

namespace Deployment;


/**
 * Run Forrest run!
 *
 * @author     David Grudl
 */
class CliRunner
{
	/** @var Logger */
	private $logger;

	/** @var string */
	private $configFile;

	/** @var bool */
	private $testMode;


	/** @return int|NULL */
	public function run()
	{
		$this->logger = new Logger('php://memory');
		$this->setupPhp();

		$config = $this->loadConfig();
		if (!$config) {
			return 1;
		}

		$config += [
			'log' => preg_replace('#\.\w+$#', '.log', $this->configFile),
			'tempdir' => sys_get_temp_dir() . '/deployment',
			'colors' => (PHP_SAPI === 'cli' && ((function_exists('posix_isatty') && posix_isatty(STDOUT))
				|| getenv('ConEmuANSI') === 'ON' || getenv('ANSICON') !== FALSE)),
		];

		$this->logger = new Logger($config['log']);
		$this->logger->useColors = (bool) $config['colors'];

		if (!is_dir($tempDir = $config['tempdir'])) {
			$this->logger->log("Creating temporary directory $tempDir");
			mkdir($tempDir,0777,true);
		}

		$time = time();
		$this->logger->log("Started at " . date('[Y/m/d H:i]'));
		$this->logger->log("Config file is $this->configFile");

		if (isset($config['remote']) && is_string($config['remote'])) {
			$config = ['' => $config];
		}

		foreach ($config as $section => $cfg) {
			if (!is_array($cfg)) {
				continue;
			}

			$this->logger->log("\nDeploying $section");

			$deployment = $this->createDeployer($cfg);
			$deployment->tempDir = $tempDir;

			if ($deployment->testMode) {
				$this->logger->log('Test mode');
			}
			if (!$deployment->allowDelete) {
				$this->logger->log('Deleting disabled');
			}
			$deployment->deploy();
		}

		$time = time() - $time;
		$this->logger->log("\nFinished at " . date('[Y/m/d H:i]') . " (in $time seconds)", 'light-green');
	}


	/** @return Deployer */
	private function createDeployer($config)
	{
		$config = array_change_key_case($config, CASE_LOWER) + [
			'local' => dirname($this->configFile),
			'passivemode' => TRUE,
			'ignore' => '',
			'allowdelete' => TRUE,
			'purge' => '',
			'before' => '',
			'after' => '',
			'preprocess' => TRUE,
		];

		if (empty($config['remote']) || !parse_url($config['remote'])) {
			throw new \Exception("Missing or invalid 'remote' URL in config.");
		}

		$server = parse_url($config['remote'], PHP_URL_SCHEME) === 'sftp'
			? new SshServer($config['remote'])
			: new FtpServer($config['remote'], (bool) $config['passivemode']);

		$deployment = new Deployer($server, $config['local'], $this->logger);

		if ($config['preprocess']) {
			$deployment->preprocessMasks = $config['preprocess'] == 1 ? ['*.js', '*.css'] : self::toArray($config['preprocess']); // intentionally ==
			$preprocessor = new Preprocessor($this->logger);
			$deployment->addFilter('js', [$preprocessor, 'expandApacheImports']);
			$deployment->addFilter('js', [$preprocessor, 'compress'], TRUE);
			$deployment->addFilter('css', [$preprocessor, 'expandApacheImports']);
			$deployment->addFilter('css', [$preprocessor, 'expandCssImports']);
			$deployment->addFilter('css', [$preprocessor, 'compress'], TRUE);
		}

		$deployment->ignoreMasks = array_merge(
			['*.bak', '.svn' , '.git*', 'Thumbs.db', '.DS_Store'],
			self::toArray($config['ignore'])
		);
		$deployment->deploymentFile = empty($config['deploymentfile']) ? $deployment->deploymentFile : $config['deploymentfile'];
		$deployment->allowDelete = $config['allowdelete'];
		$deployment->toPurge = self::toArray($config['purge'], TRUE);
		$deployment->runBefore = self::toArray($config['before'], TRUE);
		$deployment->runAfter = self::toArray($config['after'], TRUE);
		$deployment->testMode = !empty($config['test']) || $this->testMode;

		return $deployment;
	}


	/** @return void */
	private function setupPhp()
	{
		set_time_limit(0);
		date_default_timezone_set('Europe/Prague');

		set_error_handler(function($severity, $message, $file, $line) {
			if (($severity & error_reporting()) === $severity) {
				$this->logger->log("Error: $message in $file on $line", 'light-red');
				exit;
			}
			return FALSE;
		});
		set_exception_handler(function($e) {
			$this->logger->log("Error: {$e->getMessage()}\n\n$e", 'light-red');
		});
	}


	/** @return array */
	private function loadConfig()
	{
		$cmd = new CommandLine(<<<XX

FTP deployment v2.0
-------------------
Usage:
	deployment.php <config_file> [-t | --test]

Options:
	-t | --test      Run in test-mode.

XX
		, [
			'config' => [CommandLine::REALPATH => TRUE],
		]);

		if ($cmd->isEmpty()) {
			$cmd->help();
			return;
		}

		$options = $cmd->parse();
		$this->testMode = (bool) $options['--test'];
		$this->configFile = $options['config'];

		if (pathinfo($options['config'], PATHINFO_EXTENSION) == 'php') {
			return include $options['config'];
		} else {
			return parse_ini_file($options['config'], TRUE);
		}
	}


	/** @return array */
	public static function toArray($val, $lines = FALSE)
	{
		return is_array($val)
			? array_filter($val)
			: preg_split($lines ? '#\s*\n\s*#' : '#\s+#', $val, -1, PREG_SPLIT_NO_EMPTY);
	}

}
