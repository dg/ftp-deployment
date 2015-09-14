<?php

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (http://davidgrudl.com)
 */

namespace Deployment;


/**
 * File logger.
 *
 * @author     David Grudl
 */
class Logger
{
	/** @var resource */
	private $file;

	/** @var bool */
	public $useColors;

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


	public function __construct($fileName)
	{
		$this->file = fopen($fileName, 'w');
	}


	public function log($s, $color = NULL, $shorten = TRUE)
	{
		fwrite($this->file, $s . "\n");

		if ($shorten && preg_match('#^\n?.*#', $s, $m)) {
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

}
