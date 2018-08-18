<?php

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Deployment;


/**
 * File logger.
 */
class Logger
{
	/** @var bool */
	public $useColors;

	/** @var bool */
	public $showProgress = true;

	/** @var resource */
	private $file;

	/** @var array */
	public $fullCliLog = [];

	/** @var array */
	private $colors = [
		'black' => '0;30',
		'gray' => '1;30',
		'silver' => '0;37',
		'navy' => '0;34',
		'blue' => '1;34',
		'green' => '0;32',
		'lime' => '1;32',
		'teal' => '0;36',
		'aqua' => '1;36',
		'maroon' => '0;31',
		'red' => '1;31',
		'purple' => '0;35',
		'fuchsia' => '1;35',
		'olive' => '0;33',
		'yellow' => '1;33',
		'white' => '1;37',
	];


	public function __construct(string $file)
	{
		$this->file = fopen($file, 'w');
	}


	public function log(string $s, string $color = null, bool $shorten = null): void
	{
		fwrite($this->file, $s . "\n");

		if (($shorten ?? $this->shortenFor("*")) && preg_match('#^\n?.*#', $s, $m)) {
			$s = $m[0];
		}
		$s .= "        \n";
		if ($this->useColors && $color) {
			$c = explode('/', $color);
			$s = "\033[" . ($c[0] ? $this->colors[$c[0]] : '')
				. (empty($c[1]) ? '' : ';4' . substr($this->colors[$c[1]], -1)) . "m$s\033[0m";
		}
		echo $s;
	}


	/**
	 * Echos a progress message.
	 */
	public function progress(string $message): void
	{
		if ($this->showProgress) {
			echo $message, "\x0D";
		}
	}

	/**
	 * Check if given action should be shortened
	 *
	 * @param  string $action
	 * @return bool
	 */
	public function shortenFor(string $action)
	{
		$aliases = [
			"https" => "http"
		];
		if (in_array("*", $this->fullCliLog)) {
			return false;
		}
		if (isset($aliases[$action])) {
			$action = $aliases[$action];
		}
		// If action is present in list, return false to disable shortening
		return in_array($action, $this->fullCliLog) ? false : true;
	}
}
