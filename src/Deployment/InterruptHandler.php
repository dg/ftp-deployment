<?php declare(strict_types=1);

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (https://davidgrudl.com)
 */

namespace Deployment;


/**
 * Handles Ctrl+C interruption with interactive prompt.
 */
class InterruptHandler
{
	public Logger $logger;
	private bool $interrupted = false;
	private bool $promptActive = false;
	private bool $enabled = true;

	/** @var string[] */
	private array $skipped = [];


	/**
	 * Registers platform-specific signal handlers.
	 */
	public function register(): void
	{
		if (extension_loaded('pcntl')) {
			pcntl_signal(SIGINT, function (): void {
				if ($this->promptActive) {
					echo "\n";
					exit(1);
				}
				$this->interrupted = true;
			});
			pcntl_async_signals(true);

		} elseif (function_exists('sapi_windows_set_ctrl_handler')) {
			sapi_windows_set_ctrl_handler(function (): void {
				if ($this->promptActive) {
					echo "\n";
					exit(1);
				}
				$this->interrupted = true;
			});
		}
	}


	/**
	 * Checks if interrupted and shows interactive prompt.
	 * @throws SkipException when user chooses to skip
	 * @throws \Exception when user chooses to terminate
	 */
	public function check(string $operation): void
	{
		if (!$this->enabled || !$this->interrupted) {
			return;
		}

		// Non-interactive mode (CI/CD) — terminate immediately
		if (!stream_isatty(STDIN)) {
			throw new \Exception('Terminated');
		}

		$this->promptActive = true; // must be set before resetting $interrupted (race condition)
		$this->interrupted = false;

		try {
			$choice = $this->ask($operation);
		} finally {
			$this->promptActive = false;
		}

		switch ($choice) {
			case 's':
				$this->logSkipped($operation);
				throw new SkipException($operation);
			case 't':
				throw new \Exception('Terminated');
			default:
				return; // continue
		}
	}


	private function ask(string $operation): string
	{
		echo "\n";
		echo "Interrupted during: $operation\n";
		echo "  [s] Skip this operation\n";
		echo "  [t] Terminate deployment\n";
		echo "  [c] Continue (resume)\n";
		echo 'Choice [s/t/c]: ';

		$line = strtolower(trim((string) fgets(STDIN)));
		return $line[0] ?? 't';
	}


	public function addSkipped(string $description): void
	{
		$this->logSkipped($description);
	}


	private function logSkipped(string $description): void
	{
		$this->skipped[] = $description;
		$this->logger->log("Skipped $description", 'yellow');
	}


	/**
	 * @return string[]
	 */
	public function getSkipped(): array
	{
		return $this->skipped;
	}


	public function hasSkipped(): bool
	{
		return (bool) $this->skipped;
	}


	public function setEnabled(bool $enabled): void
	{
		$this->enabled = $enabled;
	}
}
