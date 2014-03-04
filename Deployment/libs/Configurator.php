<?php

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (http://davidgrudl.com)
 *
 * For the full copyright and license information, please view
 * the file license.txt that was distributed with this source code.
 */



class Configurator
{
	/** @var array  default section config */
	public $defaultConfig;

	/** @var array */
	private $sections;

	/** @var array */
	private $globalConfig;

	/** @var bool */
	private $firstFile = TRUE;

	/** @var string|NULL */
	private $logFile;

	/** @var array */
	private static $arrayOptions = array('before', 'after', 'purge');


	/**
	 * Adds new configuration
	 * @param  string
	 */
	public function addConfig(array $config)
	{
		$globalConfig = NULL;
		$sections = NULL;

		foreach ($config as $section => $cfg) {
			if (is_array($cfg) && !in_array($section, self::$arrayOptions, TRUE)) {
				$sections[$section] = array_change_key_case($cfg, CASE_LOWER);
			} else {
				$section = strtolower($section);
				$globalConfig[$section] = $cfg;
			}
		}

		$this->sections = self::merge($sections, $this->sections);
		$this->globalConfig = self::merge($globalConfig, $this->globalConfig);
	}


	/**
	 * Adds new config file
	 * @param  string
	 */
	public function addFile($file)
	{
		if (pathinfo($file, PATHINFO_EXTENSION) == 'php') {
			$config = include $file;
		} else {
			$config = @parse_ini_file($file, TRUE); // intentionally @
		}

		if ($config === FALSE) {
			if (!is_file($file)) {
				throw new RuntimeException("Config file $file not found");
			}
			throw new RuntimeException("Problem with file $file (not found or syntax error)");
		}

		if ($this->firstFile) {
			$this->firstFile = FALSE;

			if (isset($config['log'])) {
				$this->logFile = $config['log'];
			}

			if (isset($config['includes'])) {
				$basePath = pathinfo($file, PATHINFO_DIRNAME);

				foreach ((array) $config['includes'] as $configFile) {
					$this->addFile("$basePath/$configFile");
				}
			}
		}

		unset($config['includes']);
		$this->addConfig($config);
	}


	/**
	 * Returns configuration
	 * @return array  [section => config]
	 */
	public function getConfig()
	{
		static $remoteKeys = array(
			'remote.user' => 'user',
			'remote.password' => 'pass',
			'remote.host' => 'host',
			'remote.port' => 'port',
			'remote.path' => 'path',
		);

		$sections = $this->sections;

		if (empty($sections)) {
			$sections[''] = NULL;
		}

		$config = NULL;
		$globalConfig = self::merge($this->globalConfig, $this->defaultConfig);

		foreach ($sections as $name => $cfg) {
			$cfg = self::merge($cfg, $globalConfig);

			// build 'remote' key
			$remoteParts = NULL;

			if (isset($cfg['remote'])) {
				$remoteParts = parse_url($cfg['remote']);
			}

			if (isset($cfg['remote.secured'])) {
				$remoteParts['scheme'] = $cfg['remote.secured'] ? 'sftp' : 'ftp';
				unset($cfg['remote.secured']);
			}

			foreach ($remoteKeys as $remoteKey => $urlKey) {
				if (isset($cfg[$remoteKey])) {
					$remoteParts[$urlKey] = $cfg[$remoteKey];
					unset($cfg[$remoteKey]);
				}
			}

			if (isset($remoteParts['host'])) {
				// generate new URL
				$cfg['remote'] = (isset($remoteParts['scheme']) ? $remoteParts['scheme'] : 'ftp') . "://"
					. (isset($remoteParts['user']) ? rawurlencode($remoteParts['user']) : '')
					. (isset($remoteParts['pass']) ? (':' . rawurlencode($remoteParts['pass'])) : '')
					. (isset($remoteParts['user']) || isset($remoteParts['pass']) ? '@' : '')
					. $remoteParts['host']
					. (isset($remoteParts['port']) ? ":{$remoteParts['port']}" : '')
					. '/' . (isset($remoteParts['path']) ? ltrim($remoteParts['path'], '/') : '');
			}

			$config[$name] = $cfg;
		}

		return $config;
	}


	/**
	 * Returns name of log file
	 * @return string|NULL
	 */
	public function getLogFile()
	{
		return $this->logFile;
	}


	/**
	 * Merges configurations. Left has higher priority than right one.
	 */
	public static function merge($left, $right)
	{
		if (is_array($left) && is_array($right)) {
			foreach ($left as $key => $val) {
				if (is_int($key)) {
					$right[] = $val;
				} else {
					if (isset($right[$key])) {
						$val = self::merge($val, $right[$key]);
					}
					$right[$key] = $val;
				}
			}
			return $right;

		} elseif ($left === NULL && is_array($right)) {
				return $right;

		} else {
				return $left;
		}
	}

}
