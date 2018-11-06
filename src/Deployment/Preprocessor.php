<?php

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Deployment;


/**
 * CSS and JS preprocessors.
 *
 * It has a dependency on the error handler that converts PHP errors to ErrorException.
 */
class Preprocessor
{
	/** @var string|null  path to UglifyJS binary */
	public $uglifyJsBinary = 'uglifyjs';

	/** @var string|null  path to clean-css binary */
	public $cleanCssBinary = 'cleancss';

	/** @var bool  compress only file when contains /**! */
	public $requireCompressMark = true;

	/** @var Logger */
	private $logger;


	public function __construct(Logger $logger)
	{
		$this->logger = $logger;
	}


	/**
	 * Compress JS file.
	 */
	public function compressJs(string $content, string $origFile): string
	{
		if (!$this->uglifyJsBinary
			|| ($this->requireCompressMark && !preg_match('#/\*+!#', $content)) // must contain /**!
		) {
			return $content;
		}
		$this->logger->log("Compressing $origFile");

		try {
			[, $output] = $this->execute(escapeshellarg($this->uglifyJsBinary) . ' --version', '', false);
		} catch (\ErrorException $e) {
			$this->logger->log("Error while executing $this->uglifyJsBinary, install Node.js and uglify-es.", 'red');
			$this->uglifyJsBinary = null;
			return $content;
		}

		$cmd = escapeshellarg($this->uglifyJsBinary) . ' --compress --mangle';
		[$ok, $output] = $this->execute($cmd, $content, false);
		if (!$ok) {
			$this->logger->log("Error while executing $cmd", 'red');
			$this->logger->log($output);
			return $content;
		}
		return $output;
	}


	/**
	 * Compress CSS file.
	 */
	public function compressCss(string $content, string $origFile): string
	{
		if (!$this->cleanCssBinary
			|| ($this->requireCompressMark && !preg_match('#/\*+!#', $content)) // must contain /**!
		) {
			return $content;
		}
		$this->logger->log("Compressing $origFile");

		if ($error = $this->checkCssClean()) {
			$this->logger->log($error, 'red');
			$this->cleanCssBinary = null;
			return $content;
		}

		$cmd = escapeshellarg($this->cleanCssBinary) . ' --compatibility ie9 -O2';
		[$ok, $output] = $this->execute($cmd, $content, false);
		if (!$ok) {
			$this->logger->log("Error while executing $cmd", 'red');
			$this->logger->log($output);
			return $content;
		}
		return $output;
	}


	private function checkCssClean(): ?string
	{
		try {
			[, $output] = $this->execute(escapeshellarg($this->cleanCssBinary) . ' --version', '', false);
		} catch (\ErrorException $e) {
			return "Error while executing $this->cleanCssBinary, install Node.js and clean-css-cli.";
		}
		if (version_compare($output, '4.2', '<')) {
			return 'Update to clean-css-cli 4.2 or newer';
		}
		return null;
	}


	/**
	 * Expands @import(file) in CSS.
	 */
	public function expandCssImports(string $content, string $origFile): string
	{
		$dir = dirname($origFile);
		return preg_replace_callback('#@import\s+(?:url)?[(\'"]+(.+)[)\'"]+;#U', function ($m) use ($dir) {
			$file = $dir . '/' . $m[1];
			if (!is_file($file)) {
				return $m[0];
			}

			$s = file_get_contents($file);
			$newDir = dirname($file);
			$s = $this->expandCssImports($s, $file);
			if ($newDir !== $dir) {
				$tmp = $dir . '/';
				if (substr($newDir, 0, strlen($tmp)) === $tmp) {
					$s = preg_replace('#\burl\(["\']?(?=[.\w])(?!\w+:)#', '$0' . substr($newDir, strlen($tmp)) . '/', $s);
				} elseif (strpos($s, 'url(') !== false) {
					return $m[0];
				}
			}
			return $s;
		}, $content);
	}


	/**
	 * Expands Apache includes <!--#include file="..." -->
	 */
	public function expandApacheImports(string $content, string $origFile): string
	{
		$dir = dirname($origFile);
		return preg_replace_callback('~<!--#include\s+file="(.+)"\s+-->~U', function ($m) use ($dir) {
			$file = $dir . '/' . $m[1];
			if (is_file($file)) {
				return $this->expandApacheImports(file_get_contents($file), dirname($file));
			}
			return $m[0];
		}, $content);
	}


	/**
	 * Executes command.
	 * @return array  [success, output]
	 * @throws \ErrorException
	 */
	private function execute(string $command, string $input, bool $bypassShell = true): array
	{
		$process = proc_open(
			$command,
			[['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
			$pipes,
			null, null, ['bypass_shell' => $bypassShell]
		);

		fwrite($pipes[0], $input);
		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]);
		if (!$output) {
			$output = stream_get_contents($pipes[2]);
		}
		$output = str_replace("\r\n", "\n", $output);
		$output = trim($output);

		return [
			proc_close($process) === 0,
			$output,
		];
	}
}
