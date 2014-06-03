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
 * FTP server wrapper.
 *
 * @author     David Grudl
 */
class Ftp
{
	const ASCII = FTP_ASCII;
	const TEXT = FTP_TEXT;
	const BINARY = FTP_BINARY;
	const IMAGE = FTP_IMAGE;
	const TIMEOUT_SEC = FTP_TIMEOUT_SEC;
	const AUTOSEEK = FTP_AUTOSEEK;
	const AUTORESUME = FTP_AUTORESUME;
	const FAILED = FTP_FAILED;
	const FINISHED = FTP_FINISHED;
	const MOREDATA = FTP_MOREDATA;

	private static $aliases = [
		'sslconnect' => 'ssl_connect',
		'getoption' => 'get_option',
		'setoption' => 'set_option',
		'nbcontinue' => 'nb_continue',
		'nbfget' => 'nb_fget',
		'nbfput' => 'nb_fput',
		'nbget' => 'nb_get',
		'nbput' => 'nb_put',
	];

	/** @var resource */
	private $resource;

	/** @var string */
	private $dir;

	/** @var string */
	private $url;

	/** @var bool */
	private $passiveMode = TRUE;


	/**
	 * @param  string  URL ftp://...
	 * @param  bool
	 */
	public function __construct($url = NULL, $passiveMode = TRUE)
	{
		if (!extension_loaded('ftp')) {
			throw new Exception('PHP extension FTP is not loaded.');
		}
		$parts = parse_url($url);
		if (!isset($parts['scheme']) || ($parts['scheme'] !== 'ftp' && $parts['scheme'] !== 'ftps')) {
			throw new InvalidArgumentException('Invalid URL.');
		}
		$this->url = $url;
		$this->passiveMode = (bool) $passiveMode;
	}


	/**
	 * Connects to FTP server.
	 * @return void
	 */
	public function connect()
	{
		$parts = parse_url($this->url);
		$this->__call(
			$parts['scheme'] === 'ftp' ? 'connect' : 'ssl_connect',
			[$parts['host'], empty($parts['port']) ? NULL : (int) $parts['port']]
		);
		$this->login(urldecode($parts['user']), urldecode($parts['pass']));
		$this->pasv($this->passiveMode);
		if (isset($parts['path'])) {
			$this->chdir($parts['path']);
		} else {
			$this->dir = ftp_pwd($this->resource);
		}
	}


	/**
	 * @param  string  method name
	 * @param  array   arguments
	 * @return mixed
	 * @throws Exception
	 * @throws FtpException
	 */
	public function __call($name, $args)
	{
		$name = strtolower($name);
		$silent = strncmp($name, 'try', 3) === 0;
		$func = $silent ? substr($name, 3) : $name;
		$func = 'ftp_' . (isset(self::$aliases[$func]) ? self::$aliases[$func] : $func);

		if (!function_exists($func)) {
			throw new Exception("Call to undefined method Ftp::$name().");
		}

		set_error_handler(function($severity, $message) use (& $errorMsg) {
			$errorMsg = $message;
		});

		if ($func === 'ftp_connect' || $func === 'ftp_ssl_connect') {
			$this->resource = call_user_func_array($func, $args);
			$res = NULL;

		} elseif (!is_resource($this->resource)) {
			restore_error_handler();
			throw new FtpException("Not connected to FTP server. Call connect() or ssl_connect() first.");

		} else {
			array_unshift($args, $this->resource);
			$res = call_user_func_array($func, $args);

			if ($func === 'ftp_chdir' || $func === 'ftp_cdup') {
				$this->dir = ftp_pwd($this->resource);
			}
		}

		restore_error_handler();
		if (!$silent && $errorMsg !== NULL) {
			if (ini_get('html_errors')) {
				$errorMsg = html_entity_decode(strip_tags($errorMsg));
			}

			if (($a = strpos($errorMsg, ': ')) !== FALSE) {
				$errorMsg = substr($errorMsg, $a + 2);
			}

			throw new FtpException($errorMsg);
		}

		return $res;
	}


	/**
	 * Reconnects to FTP server.
	 * @return void
	 */
	public function reconnect()
	{
		@ftp_close($this->resource); // intentionally @
		$this->connect();
		$this->chdir($this->dir);
	}


	/**
	 * Checks if file or directory exists.
	 * @param  string
	 * @return bool
	 */
	public function fileExists($file)
	{
		return is_array($this->nlist($file));
	}


	/**
	 * Checks if directory exists.
	 * @param  string
	 * @return bool
	 */
	public function isDir($dir)
	{
		$current = $this->pwd();
		try {
			$this->chdir($dir);
		} catch (FtpException $e) {
		}
		$this->chdir($current);
		return empty($e);
	}


	/**
	 * Recursive creates directories.
	 * @param  string
	 * @return void
	 */
	public function mkDirRecursive($dir)
	{
		$parts = explode('/', $dir);
		$path = '';
		while (!empty($parts)) {
			$path .= array_shift($parts);
			try {
				if ($path !== '') $this->mkdir($path);
			} catch (FtpException $e) {
				if (!$this->isDir($path)) {
					throw new FtpException("Cannot create directory '$path'.");
				}
			}
			$path .= '/';
		}
	}

}



class FtpException extends Exception
{
}
