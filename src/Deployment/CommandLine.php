<?php

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (https://davidgrudl.com)
 */

namespace Deployment;


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
	private $options = [];

	/** @var string[] */
	private $aliases = [];

	/** @var bool[] */
	private $positional = [];

	/** @var string */
	private $help;


	public function __construct($help, array $defaults = [])
	{
		$this->help = $help;
		$this->options = $defaults;

		preg_match_all('#^[ \t]+(--?\w.*?)(?:  .*\(default: (.*)\)|  |\r|$)#m', $help, $lines, PREG_SET_ORDER);
		foreach ($lines as $line) {
			preg_match_all('#(--?\w[\w-]*)(?:[= ](<.*?>|\[.*?]|\w+)(\.{0,3}))?[ ,|]*#A', $line[1], $m);
			if (!count($m[0]) || count($m[0]) > 2 || implode('', $m[0]) !== $line[1]) {
				throw new \InvalidArgumentException("Unable to parse '$line[1]'.");
			}

			$name = end($m[1]);
			$opts = isset($this->options[$name]) ? $this->options[$name] : [];
			$this->options[$name] = $opts + [
				self::ARGUMENT => (bool) end($m[2]),
				self::OPTIONAL => isset($line[2]) || (substr(end($m[2]), 0, 1) === '[') || isset($opts[self::VALUE]),
				self::REPEATABLE => (bool) end($m[3]),
				self::VALUE => isset($line[2]) ? $line[2] : NULL,
			];
			if ($name !== $m[1][0]) {
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
			$args = isset($_SERVER['argv']) ? array_slice($_SERVER['argv'], 1) : [];
		}
		$params = [];
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
			}

			list($name, $arg) = strpos($arg, '=') ? explode('=', $arg, 2) : [$arg, TRUE];

			if (isset($this->aliases[$name])) {
				$name = $this->aliases[$name];

			} elseif (!isset($this->options[$name])) {
				throw new \Exception("Unknown option $name.");
			}

			$opt = $this->options[$name];

			if ($arg !== TRUE && empty($opt[self::ARGUMENT])) {
				throw new \Exception("Option $name has not argument.");

			} elseif ($arg === TRUE && !empty($opt[self::ARGUMENT])) {
				if (isset($args[$i]) && $args[$i][0] !== '-') {
					$arg = $args[$i++];
				} elseif (empty($opt[self::OPTIONAL])) {
					throw new \Exception("Option $name requires argument.");
				}
			}
			$this->checkArg($opt, $arg);

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
		return !isset($_SERVER['argv']) || count($_SERVER['argv']) < 2;
	}

}
