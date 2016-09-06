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
	/** @var bool  compress only file when contains /**! */
	public $requireCompressMark = TRUE;

	/** @var Logger */
	private $logger;



	public function __construct(Logger $logger)
	{
		$this->logger = $logger;
	}


	/**
	 * Compress JS file.
	 * @param  string  source code
	 * @param  string  original file name
	 * @return string  compressed source
	 */
	public function compressJs($content, $origFile)
	{
		if ($this->requireCompressMark && !preg_match('#/\*+!#', $content)) { // must contain /**!
			return $content;
		}
		$this->logger->log("Compressing $origFile");

		$data = ['output_info' => 'compiled_code', 'js_code' => $content];
		$output = Helpers::fetchUrl('https://closure-compiler.appspot.com/compile', $error, $data);
		if ($error) {
			$this->logger->log("Unable to minfy: $error\n");
			return $content;
		}
		return $output;
	}


	/**
	 * Compress CSS file.
	 * @param  string  source code
	 * @param  string  original file name
	 * @return string  compressed source
	 */
	public function compressCss($content, $origFile)
	{
		if ($this->requireCompressMark && !preg_match('#/\*+!#', $content)) { // must contain /**!
			return $content;
		}
		$this->logger->log("Compressing $origFile");

		$data = [
			'code' => $content,
			'type' => 'css',
			'options' => [
				'advanced' => TRUE,
				'aggressiveMerging' => TRUE,
				'rebase' => FALSE,
				'processImport' => FALSE,
				'compatibility' => 'ie8',
				'keepSpecialComments' => '1',
			],
		];
		$output = Helpers::fetchUrl('https://refresh-sf.herokuapp.com/css/', $error, $data);
		if ($error) {
			$this->logger->log("Unable to minfy: $error\n");
			return $content;
		}
		$json = @json_decode($output, TRUE);
		if (!isset($json['code'])) {
			$this->logger->log("Unable to minfy. Server response: $output\n");
			return $content;
		}
		return $json['code'];
	}


	/**
	 * Expands @import(file) in CSS.
	 * @return string
	 */
	public function expandCssImports($content, $origFile)
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
		return preg_replace_callback('~<!--#include\s+file="(.+)"\s+-->~U', function ($m) use ($dir) {
			$file = $dir . '/' . $m[1];
			if (is_file($file)) {
				return $this->expandApacheImports(file_get_contents($file), dirname($file));
			}
			return $m[0];
		}, $content);
	}

}
