<?php declare(strict_types=1);

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (https://davidgrudl.com)
 */

namespace Deployment;


/**
 * Handles Ctrl+C interruption with interactive prompt.
 *
 * Uses flag-based polling: the signal handler only sets a flag, and check() is called
 * at strategic points (before each upload/rename/delete, inside progress callbacks,
 * during retry sleeps) to act on it.
 */
class InterruptHandler
{
	public ?Logger $logger = null;
	private bool $interrupted = false;
	private bool $promptActive = false;

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
	 * @throws TerminatedException when user chooses to terminate
	 */
	public function check(string $operation): void
	{
		if (!$this->interrupted) {
			return;
		}

		// Non-interactive mode (CI/CD) — terminate immediately
		if (!stream_isatty(STDIN)) {
			throw new TerminatedException('Terminated');
		}

		// Set promptActive before resetting interrupted: if a second Ctrl+C arrives between
		// these two lines, the signal handler sees promptActive=true and calls exit(1).
		$this->promptActive = true;
		$this->interrupted = false;

		try {
			$choice = $this->ask($operation);
		} finally {
			$this->promptActive = false;
		}

		switch ($choice) {
			case 's':
				$this->addSkipped($operation);
				throw new SkipException($operation);
			case 'c':
				throw new TerminatedException('Terminated');
			default:
				return; // continue
		}
	}


	private function ask(string $operation): string
	{
		echo "\n";
		echo "Interrupted during: $operation\n";
		echo "  [s] Skip this operation\n";
		echo "  [r] Resume (continue)\n";
		echo "  [c] Cancel deployment\n";
		echo 'Choice [s/r/c]: ';

		$line = strtolower(trim((string) fgets(STDIN)));
		return $line !== '' ? $line[0] : 'r';
	}


	public function addSkipped(string $description): void
	{
		$this->skipped[] = $description;
		$this->logger?->log("Skipped $description", 'yellow');
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
}
