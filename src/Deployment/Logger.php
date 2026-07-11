<?php declare(strict_types=1);

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (https://davidgrudl.com)
 */

namespace Deployment;

use Nette\CommandLine\{Ansi, Console};


/**
 * File logger.
 */
class Logger
{
	public bool $showProgress = true;

	/** @var resource */
	private $file;


	public function __construct(
		string $file,
		private readonly Console $console = new Console,
	) {
		// 'a+' so a buffering bootstrap logger (php://temp) can be read back via flushInto()
		$this->file = fopen($file, 'a+')
			?: throw new \RuntimeException("Cannot open log file '$file'");
	}


	/**
	 * Persists this logger's buffered content into another logger's file, without echoing again.
	 * Used to carry messages from the bootstrap logger into the real log once its path is known.
	 */
	public function flushInto(self $target): void
	{
		@rewind($this->file); // @ - stream may not be seekable
		$content = stream_get_contents($this->file);
		if ($content) {
			fwrite($target->file, $content);
		}
	}


	public function log(string $s, ?string $color = null, int $shorten = 1): void
	{
		fwrite($this->file, Ansi::strip($s) . "\n"); // the file is read without a terminal

		if ($shorten) {
			$lines = explode("\n", $s);
			$lines = array_filter($lines);
			$lines = $shorten > 0
				? array_slice($lines, 0, $shorten)
				: array_slice($lines, $shorten);
			$s = implode("\n", $lines);
		}
		$this->console->writeLine($s, $color);
	}


	/**
	 * Draws the message in place of the one drawn before; an empty one erases it and so does the next log().
	 */
	public function progress(string $message): void
	{
		if ($this->showProgress) {
			$this->console->setStatus($message);
		}
	}
}
