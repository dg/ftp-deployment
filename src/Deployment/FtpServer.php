<?php

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (https://davidgrudl.com)
 */

namespace Deployment;


/**
 * FTP server.
 *
 * @author     David Grudl
 */
class FtpServer implements Server
{
	const RETRIES = 10;
	const BLOCK_SIZE = 400000;

	/** @var resource */
	private $connection;

	/** @var string */
	private $url;

	/** @var bool */
	private $passiveMode = TRUE;


	/**
	 * @param  string  URL ftp://...
	 * @param  bool
	 */
	public function __construct($url, $passiveMode = TRUE)
	{
		if (!extension_loaded('ftp')) {
			throw new \Exception('PHP extension FTP is not loaded.');
		}
		$parts = parse_url($url);
		if (!isset($parts['scheme'], $parts['user'], $parts['pass']) || ($parts['scheme'] !== 'ftp' && $parts['scheme'] !== 'ftps')) {
			throw new \InvalidArgumentException("Invalid URL or missing username or password: $url");
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
		$this->connection = $this->protect(
			$parts['scheme'] === 'ftp' ? 'ftp_connect' : 'ftp_ssl_connect',
			[$parts['host'], empty($parts['port']) ? NULL : (int) $parts['port']]
		);
		$this->ftp('login', urldecode($parts['user']), urldecode($parts['pass']));
		$this->ftp('pasv', $this->passiveMode);
		if (isset($parts['path'])) {
			$this->ftp('chdir', $parts['path']);
		}
	}


	/**
	 * Reads remote file from FTP server.
	 * @return void
	 */
	public function readFile($remote, $local)
	{
		$this->ftp('get', $local, $remote, FTP_BINARY);
	}


	/**
	 * Uploads file to FTP server.
	 * @return void
	 */
	public function writeFile($local, $remote, callable $progress = NULL)
	{
		$size = max(filesize($local), 1);
		$retry = self::RETRIES;
		upload:
		$blocks = 0;
		do {
			if ($progress) {
				$progress(min($blocks * self::BLOCK_SIZE / $size, 100));
			}
			try {
				$ret = $blocks === 0
					? $this->ftp('nb_put', $remote, $local, FTP_BINARY)
					: $this->ftp('nb_continue');

			} catch (FtpException $e) {
				@ftp_close($this->connection); // intentionally @
				$this->connect();
				if (--$retry) {
					goto upload;
				}
				throw new FtpException("Cannot upload file $local, number of retries exceeded. Error: {$e->getMessage()}");
			}
			$blocks++;
		} while ($ret === FTP_MOREDATA);

		if ($progress) {
			$progress(100);
		}
	}


	/**
	 * Removes file from FTP server if exists.
	 * @return void
	 */
	public function removeFile($file)
	{
		try {
			$this->ftp('delete', $file);
		} catch (FtpException $e) {
			if (in_array($file, (array) $this->ftp('nlist', $file . '*'))) {
				throw $e;
			}
		}
	}


	/**
	 * Renames and rewrites file on FTP server.
	 * @return void
	 */
	public function renameFile($old, $new)
	{
		$this->removeFile($new);
		$this->ftp('rename', $old, $new); // TODO: zachovat permissions
	}


	/**
	 * Creates directories on FTP server.
	 * @return void
	 */
	public function createDir($dir)
	{
		if (trim($dir, '/') === '' || $this->isDir($dir)) {
			return;
		}

		$parts = explode('/', $dir);
		$path = '';
		while (!empty($parts)) {
			$path .= array_shift($parts);
			try {
				if ($path !== '') {
					$this->ftp('mkdir', $path);
				}
			} catch (FtpException $e) {
				if (!$this->isDir($path)) {
					throw new FtpException("Cannot create directory '$path'.");
				}
			}
			$path .= '/';
		}
	}


	/**
	 * Checks if directory exists.
	 * @param  string
	 * @return bool
	 */
	private function isDir($dir)
	{
		$current = $this->getDir();
		try {
			$this->ftp('chdir', $dir);
		} catch (FtpException $e) {
		}
		$this->ftp('chdir', $current ?: '/');
		return empty($e);
	}


	/**
	 * Removes directory from FTP server if exists.
	 * @return void
	 */
	public function removeDir($dir)
	{
		try {
			$this->ftp('rmDir', $dir);
		} catch (FtpException $e) {
			if (in_array($dir, (array) $this->ftp('nlist', $dir . '*'))) {
				throw $e;
			}
		}
	}


	/**
	 * Recursive deletes content of directory or file.
	 * @param  string
	 * @return void
	 */
	public function purge($dir, callable $progress = NULL)
	{
		$dirs = [];
		foreach ((array) $this->ftp('nlist', $dir) as $entry) {
			if ($entry == NULL || $entry === $dir || preg_match('#(^|/)\\.+$#', $entry)) { // intentionally ==
				continue;
			} elseif (strpos($entry, '/') === FALSE) {
				$entry = "$dir/$entry";
			}

			if ($this->isDir($entry)) {
				$dirs[] = $tmp = "$dir/.delete" . uniqid() . count($dirs);
				$this->ftp('rename', $entry, $tmp);
			} else {
				$this->ftp('delete', $entry);
			}

			if ($progress) {
				$progress($entry);
			}
		}

		foreach ($dirs as $subdir) {
			$this->purge($subdir, $progress);
			$this->ftp('rmDir', $subdir);
		}
	}


	/**
	 * Returns current directory.
	 * @return string
	 */
	public function getDir()
	{
		return rtrim($this->ftp('pwd'), '/');
	}


	/**
	 * Executes a command on a remote server.
	 * @return string
	 */
	public function execute($command)
	{
		if (preg_match('#^(mkdir|rmdir|unlink|mv|chmod)\s+(\S+)(?:\s+(\S+))?$#', $command, $m)) {
			if ($m[1] === 'mkdir') {
				$this->createDir($m[2]);
			} elseif ($m[1] === 'rmdir') {
				$this->removeDir($m[2]);
			} elseif ($m[1] === 'unlink') {
				$this->removeFile($m[2]);
			} elseif ($m[1] === 'mv') {
				$this->renameFile($m[2], $m[3]);
			} elseif ($m[1] === 'chmod') {
				$this->ftp('chmod', octdec($m[2]), $m[3]);
			}
		} else {
			return $this->ftp('exec', $command);
		}
	}


	/**
	 * @param  string  method name
	 * @param  array   arguments
	 * @return mixed
	 */
	private function ftp($cmd)
	{
		$args = func_get_args();
		$args[0] = $this->connection;
		return $this->protect('ftp_' . $cmd, $args);
	}


	private function protect(callable $func, $args = [])
	{
		set_error_handler(function($severity, $message) {
			restore_error_handler();
			if (ini_get('html_errors')) {
				$message = html_entity_decode(strip_tags($message));
			}
			if (preg_match('#^\w+\(\):\s*(.+)#', $message, $m)) {
				$message = $m[1];
			}
			throw new FtpException($message);
		});
		$res = call_user_func_array($func, $args);
		restore_error_handler();
		return $res;
	}

}
