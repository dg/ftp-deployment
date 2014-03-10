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

	/** @var array */
	public $colors = array();

	/** @var bool */
	public $colored = FALSE;

	public function __construct($fileName)
	{
		$this->file = fopen($fileName, 'w');
		$this->colors = $this->assocColors();
	}


	public function log($s, $color = FALSE)
	{
		if( $color === FALSE || ! array_key_exists($color, $this->colors) )
			echo "$s        \n";
		else
			echo "\033[" . $this->colors[$color] . "m" . $s . "\033[0m" . "        \n";

		fwrite($this->file, "$s\n");
	}

	protected function assocColors() {
		
		$colors = array();

		$colors['black'] = '0;30';
		$colors['dark_grey'] = '1;30';
		$colors['light_grey'] = '0;37';
		$colors['blue']	= '0;34';
		$colors['light_blue'] = '1;34';
		$colors['green'] = '0;32';
		$colors['light_green'] = '1;32';
		$colors['cyan']	= '0;36';
		$colors['light_cyan'] = '1;36';
		$colors['red'] = '0;31';
		$colors['light_red'] = '1;31';
		$colors['purple'] = '0;35';
		$colors['light_purple'] = '1;35';
		$colors['brown'] = '0;33';
		$colors['yellow'] = '1;33';

		return $colors;

	}

}
