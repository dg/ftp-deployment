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

	/** @var string */
	private $url;


	/**
	 * @param  string  URL ftp://...
	 * @param  bool
	 */
	public function __construct($url)
	{
		if (!extension_loaded('ssh2')) {
			throw new Exception('PHP extension SSH2 is not loaded.');
		}
		$parts = parse_url($url);
		if (!isset($parts['scheme']) || $parts['scheme'] !== 'sftp') {
			throw new InvalidArgumentException('Invalid URL.');
		}
		$this->url = $url;
	}


	/**
	 * Connects to FTP server.
	 * @return void
	 */
	public function connect()
	{
		$this->protect(function() {
			$parts = parse_url($this->url);
			$this->connection = ssh2_connect($parts['host'], empty($parts['port']) ? 22 : (int) $parts['port']);
			ssh2_auth_agent($this->connection, $parts['user']);
			$this->sftp = ssh2_sftp($this->connection);
		});
	}


	/**
	 * Reads remote file from FTP server.
	 * @return void
	 */
	public function readFile($remote, $local)
	{
		$this->protect('ssh2_scp_recv', [$this->connection, $remote, $local]);
	}


	/**
	 * Uploads file to FTP server.
	 * @return void
	 */
	public function writeFile($local, $remote, callable $progress = NULL)
	{
		$this->protect(function() use ($local, $remote, $progress) {
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
		if (file_exists("ssh2.sftp://$this->sftp$file")) {
			$this->sftp('unlink', $file);
		}
	}


	/**
	 * Renames and rewrites file on FTP server.
	 * @return void
	 */
	public function rename($old, $new)
	{
		$this->removeFile($new);
		$this->sftp('rename', $old, $new); // TODO: zachovat permissions
	}


	/**
	 * Creates directories on FTP server.
	 * @return void
	 */
	public function createDir($dir)
	{
		if (!file_exists("ssh2.sftp://$this->sftp$dir")) {
			$this->sftp('mkdir', $dir, 0777, TRUE);
		}
	}


	/**
	 * Removes directory from FTP server if exists.
	 * @return void
	 */
	public function removeDir($dir)
	{
		if (file_exists("ssh2.sftp://$this->sftp$dir")) {
			$this->sftp('rmdir', $dir);
		}
	}


	/**
	 * Recursive deletes content of directory or file.
	 * @param  string
	 * @return void
	 */
	public function purge($path, callable $progress = NULL)
	{
		throw new SshException('Purge is not supported.');
	}


	/**
	 * Returns current directory.
	 * @return string
	 */
	public function getDir()
	{
		return parse_url($this->url, PHP_URL_PATH);
	}


	/**
	 * @param  string  method name
	 * @param  array   arguments
	 * @return mixed
	 */
	private function sftp($cmd)
	{
		$args = func_get_args();
		$args[0] = $this->sftp;
		return $this->protect('ssh2_sftp_' . $cmd, $args);
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
			throw new SshException($message);
		});

		$res = call_user_func_array($func, $args);

		restore_error_handler();
		if ($res === FALSE) {
			throw new SshException(is_string($func) ? "$func failures." : NULL);
		}
		return $res;
	}

}



class SshException extends ServerException
{
}
