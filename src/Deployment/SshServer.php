<?php

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (https://davidgrudl.com)
 */

namespace Deployment;


/**
 * SSH server.
 *
 * @author     David Grudl
 */
class SshServer implements Server
{
	/** @var resource */
	private $connection;

	/** @var resource */
	private $sftp;

	/** @var array  see parse_url() */
	private $url;


	/**
	 * @param  string|array  URL ftp://...
	 * @param  bool
	 */
	public function __construct($url)
	{
		if (!extension_loaded('ssh2')) {
			throw new \Exception('PHP extension SSH2 is not loaded.');
		}
		$this->url = is_array($url) ? $url : parse_url($url);
		if (!isset($this->url['scheme'], $this->url['user']) || $this->url['scheme'] !== 'sftp') {
			throw new \InvalidArgumentException("Invalid URL or missing username");
		}
	}


	/**
	 * Connects to FTP server.
	 * @return void
	 */
	public function connect()
	{
		$this->connection = $this->protect('ssh2_connect', [$this->url['host'], empty($this->url['port']) ? 22 : (int) $this->url['port']]);
		if (isset($this->url['pass'])) {
			$this->protect('ssh2_auth_password', [$this->connection, urldecode($this->url['user']), urldecode($this->url['pass'])]);
		} else {
			$this->protect('ssh2_auth_agent', [$this->connection, urldecode($this->url['user'])]);
		}
		$this->sftp = $this->protect('ssh2_sftp', [$this->connection]);
	}


	/**
	 * Reads remote file from FTP server.
	 * @return void
	 */
	public function readFile($remote, $local)
	{
		$this->protect('copy', ["ssh2.sftp://$this->sftp$remote", $local]);
	}


	/**
	 * Uploads file to FTP server.
	 * @return void
	 */
	public function writeFile($local, $remote, callable $progress = NULL)
	{
		$this->protect(function () use ($local, $remote, $progress) {
			$size = max(filesize($local), 1);
			$len = 0;
			$i = fopen($local, 'rb');
			$o = fopen("ssh2.sftp://$this->sftp$remote", 'wb');
			while (!feof($i)) {
				$s = fread($i, 10000);
				fwrite($o, $s, strlen($s));
				$len += strlen($s);
				if ($progress) {
					$progress($len * 100 / $size);
				}
			}
		});
	}


	/**
	 * Removes file from FTP server if exists.
	 * @return void
	 */
	public function removeFile($file)
	{
		if (file_exists($path = "ssh2.sftp://$this->sftp$file")) {
			$this->protect('unlink', [$path]);
		}
	}


	/**
	 * Renames and rewrites file on FTP server.
	 * @return void
	 */
	public function renameFile($old, $new)
	{
		if (file_exists($path = "ssh2.sftp://$this->sftp$new")) {
			$perms = fileperms($path);
			$this->removeFile($new);
		}
		$this->protect('ssh2_sftp_rename', [$this->sftp, $old, $new]);
		if (!empty($perms)) {
			$this->protect('ssh2_sftp_chmod', [$this->sftp, $new, $perms]);
		}
	}


	/**
	 * Creates directories on FTP server.
	 * @return void
	 */
	public function createDir($dir)
	{
		if (trim($dir, '/') !== '' && !file_exists("ssh2.sftp://$this->sftp$dir")) {
			$this->protect('ssh2_sftp_mkdir', [$this->sftp, $dir, 0777, TRUE]);
		}
	}


	/**
	 * Removes directory from FTP server if exists.
	 * @return void
	 */
	public function removeDir($dir)
	{
		if (file_exists($path = "ssh2.sftp://$this->sftp$dir")) {
			$this->protect('rmdir', [$path]);
		}
	}


	/**
	 * Recursive deletes content of directory or file.
	 * @param  string
	 * @return void
	 */
	public function purge($dir, callable $progress = NULL)
	{
		$dirs = $entries = [];

		$iterator = dir($path = "ssh2.sftp://$this->sftp$dir");
		while (FALSE !== ($entry = $iterator->read())) {
			if ($entry !== '.' && $entry !== '..') {
				$entries[] = $entry;
			}
		}

		foreach ($entries as $entry) {
			if (is_dir("$path/$entry")) {
				$dirs[] = $tmp = '.delete' . uniqid() . count($dirs);
				$this->protect('rename', ["$path/$entry", "$path/$tmp"]);
			} else {
				$this->protect('unlink', ["$path/$entry"]);
			}

			if ($progress) {
				$progress($entry);
			}
		}

		foreach ($dirs as $subdir) {
			$this->purge("$dir/$subdir", $progress);
			$this->protect('rmdir', ["$path/$subdir"]);
		}
	}


	/**
	 * Returns current directory.
	 * @return string
	 */
	public function getDir()
	{
		return isset($this->url['path']) ? rtrim($this->url['path'], '/') : '';
	}


	/**
	 * Executes a command on a remote server.
	 * @return string
	 */
	public function execute($command)
	{
		$stream = $this->protect('ssh2_exec', [$this->connection, $command]);
		stream_set_blocking($stream, TRUE);
		$out = stream_get_contents($stream);
		fclose($stream);
		return $out;
	}


	private function protect(callable $func, $args = [])
	{
		set_error_handler(function ($severity, $message) {
			if (ini_get('html_errors')) {
				$message = html_entity_decode(strip_tags($message));
			}
			if (preg_match('#^\w+\(\):\s*(.+)#', $message, $m)) {
				$message = $m[1];
			}
			throw new SshException($message);
		});
		try {
			$res = call_user_func_array($func, $args);
			restore_error_handler();
		} catch (\Exception $e) {
			restore_error_handler();
			throw $e;
		}
		if ($res === FALSE) {
			throw new SshException(is_string($func) ? "$func failures." : NULL);
		}
		return $res;
	}

}
