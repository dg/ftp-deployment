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
 * Stupid command line arguments parser.
 *
 * @author     David Grudl
 */
class CommandLine
{
	const
		ARGUMENT = 'argument',
		OPTIONAL = 'optional',
		REPEATABLE = 'repeatable',
		REALPATH = 'realpath',
		VALUE = 'default';

	/** @var array[] */
	private $options = array();

	/** @var string[] */
	private $aliases = array();

	/** @var bool[] */
	private $positional = array();

	/** @var string */
	private $help;


	public function __construct($help, array $defaults = array())
	{
		$this->help = $help;
		$this->options = $defaults;

		preg_match_all('#^[ \t]+(--?\w.*?)(?:  .*\(default: (.*)\)|  |\r|$)#m', $help, $lines, PREG_SET_ORDER);
		foreach ($lines as $line) {
			preg_match_all('#(--?\w+)(?:[= ](<.*?>|\[.*?]|\w+)(\.{0,3}))?[ ,|]*#A', $line[1], $m);
			if (!count($m[0]) || count($m[0]) > 2 || implode('', $m[0]) !== $line[1]) {
				throw new \InvalidArgumentException("Unable to parse '$line[1]'.");
			}

			$name = isset($m[1][1]) ? $m[1][1] : $m[1][0];
			$this->options[$name] = (isset($this->options[$name]) ? $this->options[$name] : array()) + array(
				self::ARGUMENT => $m[2][0] ? trim($m[2][0], '<>[]') : NULL,
				self::OPTIONAL => isset($line[2]) ? TRUE : ($m[2][0] ? $m[2][0][0] === '[' : NULL),
				self::REPEATABLE => (bool) $m[3][0],
				self::VALUE => isset($line[2]) ? $line[2] : NULL,
			);
			if (isset($m[1][1])) {
				$this->aliases[$m[1][0]] = $name;
			}
		}

		foreach ($this->options as $name => $foo) {
			if ($name[0] !== '-') {
				$this->positional[] = $name;
			}
		}
	}


	public function parse(array $args = NULL)
	{
		if ($args === NULL) {
			$args = isset($_SERVER['argv']) ? array_slice($_SERVER['argv'], 1) : array();
		}
		$params = array();
		reset($this->positional);
		$i = 0;
		while ($i < count($args)) {
			$arg = $args[$i++];
			if ($arg[0] !== '-') {
				if (!current($this->positional)) {
					throw new \Exception("Unexpected parameter $arg.");
				}
				$name = current($this->positional);
				$this->checkArg($this->options[$name], $arg);
				if (empty($this->options[$name][self::REPEATABLE])) {
					$params[$name] = $arg;
					next($this->positional);
				} else {
					$params[$name][] = $arg;
				}
				continue;

			} elseif (isset($this->aliases[$arg])) {
				$name = $this->aliases[$arg];

			} elseif (isset($this->options[$arg])) {
				$name = $arg;

			} else {
				throw new \Exception("Unknown option $arg.");
			}

			$opt = $this->options[$name];

			if (isset($args[$i]) && $args[$i][0] !== '-' && !empty($opt[self::ARGUMENT])) {
				$arg = $args[$i++];
				$this->checkArg($opt, $arg);

			} elseif (empty($opt[self::OPTIONAL]) && !empty($opt[self::ARGUMENT])) {
				throw new \Exception("Option $arg requires argument.");

			} else {
				$arg = TRUE;
			}

			if (empty($opt[self::REPEATABLE])) {
				$params[$name] = $arg;
			} else {
				$params[$name][] = $arg;
			}
		}

		foreach ($this->options as $name => $opt) {
			if (isset($params[$name])) {
				continue;
			} elseif (isset($opt[self::VALUE])) {
				$params[$name] = $opt[self::VALUE];
			} elseif ($name[0] !== '-' && empty($opt[self::OPTIONAL])) {
				throw new \Exception("Missing required argument <$name>.");
			} else {
				$params[$name] = NULL;
			}
			if (!empty($opt[self::REPEATABLE])) {
				$params[$name] = (array) $params[$name];
			}
		}
		return $params;
	}


	public function help()
	{
		echo $this->help;
	}


	public function checkArg(array $opt, & $arg)
	{
		if (!empty($opt[self::REALPATH])) {
			$path = realpath($arg);
			if ($path === FALSE) {
				throw new \Exception("File path '$arg' not found.");
			}
			$arg = $path;
		}
	}


	public function isEmpty()
	{
		return !isset($_SERVER['argc']) || $_SERVER['argc'] < 2;
	}

}
