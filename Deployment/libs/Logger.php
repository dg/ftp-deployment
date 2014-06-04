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
	public $colors = [
		'black' => '0;30',
		'dark-grey' => '1;30',
		'light-grey' => '0;37',
		'blue' => '0;34',
		'light-blue' => '1;34',
		'green' => '0;32',
		'light-green' => '1;32',
		'cyan' => '0;36',
		'light-cyan' => '1;36',
		'red' => '0;31',
		'light-red' => '1;31',
		'purple' => '0;35',
		'light-purple' => '1;35',
		'brown' => '0;33',
		'yellow' => '1;33',
	];


	public function __construct($fileName)
	{
		$this->file = fopen($fileName, 'w');
	}


	public function log($s, $color = NULL)
	{
		echo $this->useColors && isset($this->colors[$color])
			? "\033[{$this->colors[$color]}m$s\033[0m        \n"
			: "$s        \n";
		fwrite($this->file, "$s\n");
	}

}
