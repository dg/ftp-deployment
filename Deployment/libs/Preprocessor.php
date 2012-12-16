<?php

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (http://davidgrudl.com)
 *
 * For the full copyright and license information, please view
 * the file license.txt that was distributed with this source code.
 */



/**
 * CSS and JS preprocessors. It requires Java, Google Closure Compiler and YUI Compressor.
 *
 * @author     David Grudl
 */
class Preprocessor
{
	/** @var string  path to java binary */
	public $javaBinary = 'java';

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
		if (!preg_match('#/\*+!#', $content)) { // must contain /**!
			return $content;
		}
		$dir = dirname(__DIR__) . '/vendor';;
		$this->logger->log("Compressing $origFile");
		if (substr($origFile, -3) === '.js') {
			$cmd = "$this->javaBinary -jar \"{$dir}/Google-Closure-Compiler/compiler.jar\" --warning_level QUIET";
		} else {
			$cmd = "$this->javaBinary -jar \"{$dir}/YUI-Compressor/yuicompressor-2.4.7.jar\" --type css";
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
		$preprocessor = $this;
		return preg_replace_callback('#@import\s+(?:url)?[(\'"]+(.+)[)\'"]+;#U', function($m) use ($dir, $preprocessor) {
			$file = $dir . '/' . $m[1];
			if (!is_file($file)) {
				return $m[0];
			}

			$s = file_get_contents($file);
			$newDir = dirname($file);
			$s = $preprocessor->expandCssImports($s, $file);
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
		$preprocessor = $this;
		return preg_replace_callback('~<!--#include\s+file="(.+)"\s+-->~U', function($m) use ($dir, $preprocessor) {
			$file = $dir . '/' . $m[1];
			if (is_file($file)) {
				return $preprocessor->expandApacheImports(file_get_contents($file), dirname($file));
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
		$process = proc_open($command, array(array('pipe', 'r'), array('pipe', 'w'), array('pipe', 'w')), $pipes);
		if (!is_resource($process)) {
			throw new Exception("Unable start process $command.");
		}

		fwrite($pipes[0], $input);
		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]);
		if (!$output) {
			$output = stream_get_contents($pipes[2]);
		}
		return array(proc_close($process) === 0, $output);
	}

}
