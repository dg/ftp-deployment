<?php

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (https://davidgrudl.com)
 */

namespace Deployment;


/**
 * CSS and JS preprocessors.
 */
class Preprocessor
{
	/** @var string  path to java binary */
	public $javaBinary = 'java';

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
		if ($this->requireCompressMark && !preg_match('#/\*+!#', $content)) { // must contain /**!
			return $content;
		}
		$this->logger->log("Compressing $origFile");

		$dir = dirname(__DIR__) . '/vendor';
		$cmd = escapeshellarg($this->javaBinary) . ' -jar ' . escapeshellarg($dir . '/Google-Closure-Compiler/compiler.jar') . ' --warning_level QUIET';
		[$ok, $output] = $this->execute($cmd, $content);
		if (!$ok) {
			$this->logger->log("Error while executing $cmd");
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
		if ($this->requireCompressMark && !preg_match('#/\*+!#', $content)) { // must contain /**!
			return $content;
		}
		$this->logger->log("Compressing $origFile");

		$data = [
			'code' => $content,
			'type' => 'css',
			'options' => [
				'advanced' => true,
				'aggressiveMerging' => true,
				'rebase' => false,
				'processImport' => false,
				'compatibility' => 'ie8',
				'keepSpecialComments' => '1',
			],
		];
		$output = Helpers::fetchUrl('https://refresh-sf.herokuapp.com/css/', $error, $data);
		if ($error) {
			$this->logger->log("Unable to minfy: $error\n");
			return $content;
		}
		$json = @json_decode($output, true);
		if (!isset($json['code'])) {
			$this->logger->log("Unable to minfy. Server response: $output\n");
			return $content;
		}
		return $json['code'];
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
	 * @throws \Exception
	 */
	private function execute(string $command, string $input): array
	{
		$process = proc_open(
			$command,
			[['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
			$pipes,
			null, null, ['bypass_shell' => true]
		);
		if (!is_resource($process)) {
			throw new \Exception("Unable start process $command.");
		}

		fwrite($pipes[0], $input);
		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]);
		if (!$output) {
			$output = stream_get_contents($pipes[2]);
		}

		return [
			proc_close($process) === 0,
			$output,
		];
	}
}
