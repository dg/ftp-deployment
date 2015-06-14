<?php

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (http://davidgrudl.com)
 */

namespace Deployment;


/**
 * CSS and JS preprocessors. It requires Java, Google Closure Compiler and YUI Compressor.
 *
 * @author     David Grudl
 */
class Preprocessor
{
	/** @var string  path to java binary */
	public $javaBinary = 'java';

	/** @var bool  compress only file when contains /**! */
	public $requireCompressMark = TRUE;

	/** @var Logger */
	private $logger;



	public function __construct(Logger $logger)
	{
		$this->logger = $logger;
	}


	/**
	 * Compress JS or CSS file.
	 * @param  string  source code
	 * @param  string  original file name
	 * @return string  compressed source
	 */
	public function compress($content, $origFile)
	{
		if ($this->requireCompressMark && !preg_match('#/\*+!#', $content)) { // must contain /**!
			return $content;
		}
		$this->logger->log("Compressing $origFile");

		$dir = dirname(__DIR__) . '/vendor';
		$cmd = escapeshellarg($this->javaBinary) . ' -jar ';
		if (substr($origFile, -3) === '.js') {
			$cmd .= escapeshellarg($dir . '/Google-Closure-Compiler/compiler.jar') . ' --warning_level QUIET';
		} else {
			$cmd .= escapeshellarg($dir . '/YUI-Compressor/yuicompressor-2.4.8.jar') . ' --type css';
		}
		list($ok, $output) = $this->execute($cmd, $content);
		if (!$ok) {
			$this->logger->log("Error while executing $cmd");
			$this->logger->log($output);
			return $content;
		}
		return $output;
	}


	/**
	 * Expands @import(file) in CSS.
	 * @return string
	 */
	public function expandCssImports($content, $origFile)
	{
		$dir = dirname($origFile);
		return preg_replace_callback('#@import\s+(?:url)?[(\'"]+(.+)[)\'"]+;#U', function($m) use ($dir) {
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
				} elseif (strpos($s, 'url(') !== FALSE) {
					return $m[0];
				}
			}
			return $s;
		}, $content);
	}


	/**
	 * Expands Apache includes <!--#include file="..." -->
	 * @return string
	 */
	public function expandApacheImports($content, $origFile)
	{
		$dir = dirname($origFile);
		return preg_replace_callback('~<!--#include\s+file="(.+)"\s+-->~U', function($m) use ($dir) {
			$file = $dir . '/' . $m[1];
			if (is_file($file)) {
				return $this->expandApacheImports(file_get_contents($file), dirname($file));
			}
			return $m[0];
		}, $content);
	}


	/**
	 * Executes command.
	 * @return string
	 */
	private function execute($command, $input)
	{
		$process = proc_open(
			$command,
			[['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
			$pipes,
			NULL, NULL, ['bypass_shell' => TRUE]
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
			$output
		];
	}

}
