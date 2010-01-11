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


	public function __construct($fileName)
	{
		$this->file = fopen($fileName, 'w');
	}


	public function log($s)
	{
		echo "$s        \n";
		fwrite($this->file, "$s\n");
	}

}
