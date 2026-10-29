<?php declare(strict_types=1);

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (https://davidgrudl.com)
 */

namespace Deployment;

use Nette\CommandLine\{Attributes as Cli, ColorDepth, Command, Console, HelpRenderer, Normalizers, Parser, UsageException};


/**
 * Run Forrest run!
 */
class CliRunner
{
	public const Version = '3.8.0';

	/** @var array<string, string|bool> */
	public array $defaults = [
		'local' => '',
		'passivemode' => true,
		'include' => '',
		'ignore' => '',
		'allowdelete' => true,
		'purge' => '',
		'before' => '',
		'afterupload' => '',
		'after' => '',
		'preprocess' => false,
	];

	/** @var string[] */
	public array $ignoreMasks = ['*.bak', '.svn', '.git*', 'Thumbs.db', '.DS_Store', '.idea', '.claude'];
	private Console $console;
	private Logger $logger;
	private string $configFile;
	private InterruptHandler $interruptHandler;

	/** @var 'test'|'generate'|null */
	private ?string $mode;
	private bool $force = false;

	/** @var array<string, array<string, mixed>> */
	private array $batches = [];

	/** @var resource|null holds file lock for process lifetime */
	private $lock;


	public function run(): ?int
	{
		$this->console = new Console;
		$this->logger = new Logger('php://memory', $this->console);
		$this->interruptHandler = new InterruptHandler($this->console);
		$this->setupPhp();

		try {
			return (new Parser)->parse(Command::from($this->deploy(...)))->run();
		} catch (UsageException $e) {
			(new HelpRenderer)->renderError($e);
			return 1;
		}
	}


	#[Cli\Command('FTP deployment v' . self::Version, usage: 'deployment <config_file> [-t | --test]')]
	private function deploy(
		Command $program,
		#[Cli\Flag('Run in test-mode.', alias: '-t')]
		?bool $test = null,
		#[Cli\Option('Only deploys the named section.', valueName: 'name')]
		?string $section = null,
		#[Cli\Flag('Only generates deployment file.')]
		?bool $generate = null,
		#[Cli\Flag('Hide the progress indicators.')]
		?bool $noProgress = null,
		#[Cli\Flag('Deploy even if a leftover .running lock file is present.')]
		?bool $force = null,
		#[Cli\Argument(normalizer: [Normalizers::class, 'realPath'], hidden: true, name: 'config')]
		?string $configFile = null,
	): int
	{
		if ($configFile === null) {
			(new HelpRenderer($this->console))->render($program);
			return 1;
		}

		$this->mode = $generate ? 'generate' : ($test ? 'test' : null);
		$this->force = (bool) $force;
		$this->configFile = $configFile;
		$config = $this->loadConfig($section, (bool) $noProgress);

		$this->logger = new Logger($config['log'], $this->console);
		if (!$config['colors']) {
			$this->console->setColorDepth(ColorDepth::None);
		} elseif (!$this->console->hasColors()) { // `colors = yes` turns them on even for a pipe, as FORCE_COLOR does
			$this->console->setColorDepth(ColorDepth::Ansi16);
		}
		$this->logger->showProgress = (bool) $config['progress'];
		$this->interruptHandler->logger = $this->logger;

		if (!is_dir($tempDir = $config['tempdir'])) {
			$this->logger->log("Creating temporary directory $tempDir");
			mkdir($tempDir, recursive: true);
		}

		$time = time();
		$this->logger->log('Started at ' . date('[Y/m/d H:i]'));
		$this->logger->log("Config file is $this->configFile");
		$res = 0;

		foreach ($this->batches as $name => $batch) {
			$this->logger->log("\nDeploying $name");

			$deployment = $this->createDeployer($batch);
			$deployment->tempDir = $tempDir;

			if ($this->mode === 'generate') {
				$this->logger->log('Scanning files');
				$localPaths = $deployment->collectPaths();
				$this->logger->log('Saved ' . $deployment->writeDeploymentFile($localPaths));
				$deployment->cleanup();
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

			try {
				$deployment->deploy();
			} catch (JobException|ServerException $e) {
				$this->logger->log("Error: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}\n\n$e", 'red');
				$res = 1;
			} catch (TerminatedException $e) {
				$this->logger->log($e->getMessage(), 'red');
				$res = 1;
				break;
			}
			$this->logger->log("\n\n");
		}

		if ($this->interruptHandler->hasSkipped()) {
			$this->logger->log("\nSkipped operations:", 'yellow');
			foreach ($this->interruptHandler->getSkipped() as $skipped) {
				$this->logger->log("  - $skipped", 'yellow');
			}
			$res = 1;
		}

		$time = time() - $time;
		$this->logger->log(
			'Finished at ' . date('[Y/m/d H:i]') . " (in $time seconds)\n----------------------------------------------\n\n",
			'lime',
		);
		return $res;
	}


	/** @param array<string, mixed> $config */
	private function createDeployer(array $config): Deployer
	{
		$server = (new ServerFactory($this->logger, $this->interruptHandler))->create($config);

		if (!preg_match('#/|\\\|[a-z]:#iA', $config['local'])) {
			$config['local'] = dirname($this->configFile) . '/' . $config['local'];
		}

		$deployment = new Deployer($server, $config['local'], $this->logger, $this->interruptHandler);

		if ($config['preprocess']) {
			$deployment->preprocessMasks = $config['preprocess'] == 1 // intentionally ==, the option may be true or a string
				? ['*.js', '*.css']
				: self::toArray($config['preprocess']);
			$preprocessor = new Preprocessor($this->logger);
			$deployment->addFilter('js', $preprocessor->expandApacheImports(...));
			$deployment->addFilter('js', $preprocessor->compressJs(...), cached: true);
			$deployment->addFilter('css', $preprocessor->expandApacheImports(...));
			$deployment->addFilter('css', $preprocessor->expandCssImports(...));
			$deployment->addFilter('css', $preprocessor->compressCss(...), cached: true);
		}

		$deployment->includeMasks = self::toArray($config['include'], lines: true);
		$deployment->ignoreMasks = [...$this->ignoreMasks, ...self::toArray($config['ignore'])];
		$deployment->deploymentFile = empty($config['deploymentfile'])
			? $deployment->deploymentFile
			: $config['deploymentfile'];
		$deployment->allowDelete = (bool) $config['allowdelete'];
		$deployment->force = $this->force;
		$deployment->toPurge = self::toArray($config['purge'], lines: true);
		$deployment->runBefore = self::toArray($config['before'], lines: true);
		$deployment->runAfterUpload = self::toArray($config['afterupload'], lines: true);
		$deployment->runAfter = self::toArray($config['after'], lines: true);
		$deployment->testMode = !empty($config['test']) || $this->mode === 'test';

		return $deployment;
	}


	private function setupPhp(): void
	{
		set_time_limit(0);

		set_error_handler(function ($severity, $message, $file, $line) {
			if (($severity & error_reporting()) !== $severity) {
				return false;
			}

			if (ini_get('html_errors')) {
				$message = html_entity_decode(strip_tags($message));
			}

			throw new \ErrorException($message, 0, $severity, $file, $line);
		});

		set_exception_handler(function (\Throwable $e): void {
			$this->logger->log("Error: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}\n\n$e", 'red');
			exit(1);
		});

		$this->interruptHandler->register();
	}


	/** @return array<string, mixed> */
	private function loadConfig(?string $section, bool $noProgress): array
	{
		$config = $this->loadConfigFile($this->configFile);
		if (!$config) {
			throw new \Exception('Missing config.');
		}

		$lock = fopen($this->configFile, 'r');
		if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
			throw new \Exception('It seems that you are in the middle of another deployment.');
		}
		$this->lock = $lock;

		$this->batches = isset($config['remote']) && is_string($config['remote'])
			? ['' => $config]
			: array_filter($config, 'is_array');

		if ($section !== null) {
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
			'progress' => $this->console->isTerminal(),
			'colors' => $this->console->hasColors(),
		];
		$config['progress'] = $noProgress ? false : $config['progress'];
		return $config;
	}


	/** @return array<string, mixed> */
	protected function loadConfigFile(string $file): array
	{
		return pathinfo($file, PATHINFO_EXTENSION) === 'php' ? (include $file) : (parse_ini_file($file, process_sections: true)
			?: throw new \Exception("Unable to parse config file '$file'."));
	}


	/**
	 * @param  string|list<string>  $val
	 * @return list<string>
	 */
	public static function toArray(mixed $val, bool $lines = false): array
	{
		return is_array($val)
			? array_values(array_filter($val))
			: preg_split($lines ? '#\s*\n\s*#' : '#\s+#', $val, -1, PREG_SPLIT_NO_EMPTY);
	}
}
