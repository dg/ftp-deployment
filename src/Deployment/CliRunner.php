<?php

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (https://davidgrudl.com)
 */

namespace Deployment;


/**
 * Run Forrest run!
 */
class CliRunner
{
	/** @var array */
	public $defaults = [
		'local' => '',
		'passivemode' => true,
	    'includes' => '',
		'ignore' => '',
		'allowdelete' => true,
		'purge' => '',
		'before' => '',
		'afterupload' => '',
		'after' => '',
		'preprocess' => true,
	];

	/** @var string[] */
	public $ignoreMasks = ['*.bak', '.svn', '.git*', 'Thumbs.db', '.DS_Store', '.idea'];

	/** @var Logger */
	private $logger;

	/** @var string */
	private $configFile;

	/** @var string  test|generate|null */
	private $mode;

	/** @var array[] */
	private $batches = [];

	/** @var resource */
	private $lock;


	/** @return int|null */
	public function run()
	{
		$this->logger = new Logger('php://memory');
		$this->setupPhp();

		$config = $this->loadConfig();
		if (!$config) {
			return 1;
		}

		$this->logger = new Logger($config['log']);
		$this->logger->useColors = (bool) $config['colors'];
		$this->logger->showProgress = (bool) $config['progress'];

		if (!is_dir($tempDir = $config['tempdir'])) {
			$this->logger->log("Creating temporary directory $tempDir");
			mkdir($tempDir, 0777, true);
		}

		$time = time();
		$this->logger->log('Started at ' . date('[Y/m/d H:i]'));
		$this->logger->log("Config file is $this->configFile");

		foreach ($this->batches as $name => $batch) {
			$this->logger->log("\nDeploying $name");

			$deployment = $this->createDeployer($batch);
			$deployment->tempDir = $tempDir;

			if ($this->mode === 'generate') {
				$this->logger->log('Scanning files');
				$localPaths = $deployment->collectPaths();
				$this->logger->log('Saved ' . $deployment->writeDeploymentFile($localPaths));
				continue;
			}

			if ($deployment->testMode) {
				$this->logger->log('Test mode', 'lime');
			} else {
				$this->logger->log('Live mode', 'aqua');
			}
			if (!$deployment->allowDelete) {
				$this->logger->log('Deleting disabled');
			}
			$deployment->deploy();
		}

		$time = time() - $time;
		$this->logger->log("\nFinished at " . date('[Y/m/d H:i]') . " (in $time seconds)", 'lime');
	}


	/** @return Deployer */
	private function createDeployer($config)
	{
		if (empty($config['remote']) || !($urlParts = parse_url($config['remote'])) || !isset($urlParts['scheme'], $urlParts['host'])) {
			throw new \Exception("Missing or invalid 'remote' URL in config.");
		}
		if (isset($config['user'])) {
			$urlParts['user'] = urlencode($config['user']);
		}
		if (isset($config['password'])) {
			$urlParts['pass'] = urlencode($config['password']);
		}

		if ($urlParts['scheme'] === 'ftp') {
			$this->logger->log('Note: connection is not encrypted', 'maroon');
		}

		$server = $urlParts['scheme'] === 'sftp'
			? new SshServer($urlParts)
			: new FtpServer($urlParts, (bool) $config['passivemode']);

		if (!preg_match('#/|\\\\|[a-z]:#iA', $config['local'])) {
			$config['local'] = dirname($this->configFile) . '/' . $config['local'];
		}

		$deployment = new Deployer($server, $config['local'], $this->logger);

		if ($config['preprocess']) {
			$deployment->preprocessMasks = $config['preprocess'] == 1 ? ['*.js', '*.css'] : self::toArray($config['preprocess']); // intentionally ==
			$preprocessor = new Preprocessor($this->logger);
			$deployment->addFilter('js', [$preprocessor, 'expandApacheImports']);
			$deployment->addFilter('js', [$preprocessor, 'compressJs'], true);
			$deployment->addFilter('css', [$preprocessor, 'expandApacheImports']);
			$deployment->addFilter('css', [$preprocessor, 'expandCssImports']);
			$deployment->addFilter('css', [$preprocessor, 'compressCss'], true);
		}

		$deployment->includeMasks = !empty($config['includes']) ? self::toArray($config['includes']) : null;
		$deployment->ignoreMasks = array_merge($this->ignoreMasks, self::toArray($config['ignore']));
		$deployment->deploymentFile = empty($config['deploymentfile']) ? $deployment->deploymentFile : $config['deploymentfile'];
		$deployment->allowDelete = $config['allowdelete'];
		$deployment->toPurge = self::toArray($config['purge'], true);
		$deployment->runBefore = self::toArray($config['before'], true);
		$deployment->runAfterUpload = self::toArray($config['afterupload'], true);
		$deployment->runAfter = self::toArray($config['after'], true);
		$deployment->testMode = !empty($config['test']) || $this->mode === 'test';

		$server->filePermissions = empty($config['filepermissions']) ? null : octdec($config['filepermissions']);
		$server->dirPermissions = empty($config['dirpermissions']) ? null : octdec($config['dirpermissions']);

		return $deployment;
	}


	/** @return void */
	private function setupPhp()
	{
		set_time_limit(0);
		date_default_timezone_set('Europe/Prague');

		set_error_handler(function ($severity, $message, $file, $line) {
			if (($severity & error_reporting()) === $severity) {
				$this->logger->log("Error: $message in $file on $line", 'red');
				exit(1);
			}
			return false;
		});
		set_exception_handler(function ($e) {
			$this->logger->log("Error: {$e->getMessage()}\n\n$e", 'red');
			exit(1);
		});
	}


	/** @return array */
	private function loadConfig()
	{
		$cmd = new CommandLine(<<<'XX'

FTP deployment v2.8
-------------------
Usage:
	deployment <config_file> [-t | --test]

Options:
	-t | --test       Run in test-mode.
	--section <name>  Only deploys the named section.
	--generate        Only generates deployment file.
	--no-progress     Hide the progress indicators.

XX
		, [
			'config' => [CommandLine::REALPATH => true],
		]);

		if ($cmd->isEmpty()) {
			$cmd->help();
			return;
		}

		$options = $cmd->parse();
		$this->mode = $options['--generate'] ? 'generate' : ($options['--test'] ? 'test' : null);
		$this->configFile = $options['config'];

		if (!flock($this->lock = fopen($options['config'], 'r'), LOCK_EX | LOCK_NB)) {
			throw new \Exception('It seems that you are in the middle of another deployment.');
		}

		$config = $this->loadConfigFile($options['config']);
		if (!$config) {
			throw new \Exception('Missing config.');
		}

		$this->batches = isset($config['remote']) && is_string($config['remote'])
			? ['' => $config]
			: array_filter($config, 'is_array');

		if (isset($options['--section'])) {
			$section = $options['--section'];
			if ($section === '') {
				throw new \Exception('Missing section name.');
			} elseif (!isset($this->batches[$section])) {
				throw new \Exception("Unknown section '$section'.");
			}
			$this->batches = [$section => $this->batches[$section]];
		}

		foreach ($this->batches as &$batch) {
			$batch = array_change_key_case($batch, CASE_LOWER) + $this->defaults;
		}

		$config = array_change_key_case($config, CASE_LOWER) + [
			'log' => preg_replace('#\.\w+$#', '.log', $this->configFile),
			'tempdir' => sys_get_temp_dir() . '/deployment',
			'progress' => true,
			'colors' => (PHP_SAPI === 'cli' && ((function_exists('posix_isatty') && posix_isatty(STDOUT))
				|| getenv('ConEmuANSI') === 'ON' || getenv('ANSICON') !== false)),
		];
		$config['progress'] = $options['--no-progress'] ? false : $config['progress'];
		return $config;
	}


	protected function loadConfigFile($file)
	{
		if (pathinfo($file, PATHINFO_EXTENSION) == 'php') {
			return include $file;
		} else {
			return parse_ini_file($file, true);
		}
	}


	/** @return array */
	public static function toArray($val, $lines = false)
	{
		return is_array($val)
			? array_filter($val)
			: preg_split($lines ? '#\s*\n\s*#' : '#\s+#', $val, -1, PREG_SPLIT_NO_EMPTY);
	}
}
