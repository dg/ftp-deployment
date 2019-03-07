<?php

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Deployment;


/**
 * SSH server.
 *
 * It has a dependency on the error handler that converts PHP errors to ServerException.
 */
class SshServer implements Server
{
	/** @var int */
	public $filePermissions;

	/** @var int */
	public $dirPermissions;

	/** @var resource */
	private $connection;

	/** @var resource */
	private $sftp;

	/** @var array  see parse_url() */
	private $url;

	/** @var string|null */
	private $publicKey;

	/** @var string|null */
	private $privateKey;

	/** @var string */
	private $passPhrase;


	/**
	 * @param  string  $url  sftp://...
	 * @throws \Exception
	 */
	public function __construct(string $url, string $publicKey = null, string $privateKey = null, string $passPhrase = null)
	{
		if (!extension_loaded('ssh2')) {
			throw new \Exception('PHP extension SSH2 is not loaded.');
		}
		$this->url = parse_url($url);
		if (!isset($this->url['scheme'], $this->url['user']) || $this->url['scheme'] !== 'sftp') {
			throw new \InvalidArgumentException('Invalid URL or missing username');
		}
		$this->publicKey = $publicKey;
		$this->privateKey = $privateKey;
		$this->passPhrase = $passPhrase;
	}


	/**
	 * Connects to FTP server.
	 * @throws ServerException
	 */
	public function connect(): void
	{
		$this->connection = ssh2_connect($this->url['host'], $this->url['port'] ?? 22);
		if (isset($this->url['pass'])) {
			ssh2_auth_password($this->connection, urldecode($this->url['user']), urldecode($this->url['pass']));
		} elseif ($this->publicKey && $this->privateKey) {
			ssh2_auth_pubkey_file($this->connection, urldecode($this->url['user']), $this->publicKey, $this->privateKey, (string) $this->passPhrase);
		} else {
			ssh2_auth_agent($this->connection, urldecode($this->url['user']));
		}
		$this->sftp = ssh2_sftp($this->connection);
	}


	/**
	 * Reads remote file from FTP server.
	 * @throws ServerException
	 */
	public function readFile(string $remote, string $local): void
	{
		copy('ssh2.sftp://' . (int) $this->sftp . $remote, $local);
	}


	/**
	 * Uploads file to FTP server.
	 * @throws ServerException
	 */
	public function writeFile(string $local, string $remote, callable $progress = null): void
	{
		$size = max(filesize($local), 1);
		$len = 0;
		$i = fopen($local, 'rb');
		$o = fopen('ssh2.sftp://' . (int) $this->sftp . $remote, 'wb');
		while (!feof($i)) {
			$s = fread($i, 10000);
			fwrite($o, $s, strlen($s));
			$len += strlen($s);
			if ($progress) {
				$progress($len * 100 / $size);
			}
		}
		if ($this->filePermissions) {
			ssh2_sftp_chmod($this->sftp, $remote, $this->filePermissions);
		}
	}


	/**
	 * Removes file from FTP server if exists.
	 * @throws ServerException
	 */
	public function removeFile(string $file): void
	{
		if (file_exists($path = 'ssh2.sftp://' . (int) $this->sftp . $file)) {
			unlink($path);
		}
	}


	/**
	 * Renames and rewrites file on FTP server.
	 * @throws ServerException
	 */
	public function renameFile(string $old, string $new): void
	{
		if (file_exists($path = 'ssh2.sftp://' . (int) $this->sftp . $new)) {
			$perms = fileperms($path);
			$this->removeFile($new);
		}
		ssh2_sftp_rename($this->sftp, $old, $new);
		if (!empty($perms)) {
			ssh2_sftp_chmod($this->sftp, $new, $perms);
		}
	}


	/**
	 * Creates directories on FTP server.
	 * @throws ServerException
	 */
	public function createDir(string $dir): void
	{
		if (trim($dir, '/') !== '' && !file_exists('ssh2.sftp://' . (int) $this->sftp . $dir)) {
			ssh2_sftp_mkdir($this->sftp, $dir, $this->dirPermissions ?: 0777, true);
		}
	}


	/**
	 * Removes directory from FTP server if exists.
	 * @throws ServerException
	 */
	public function removeDir(string $dir): void
	{
		if (file_exists($path = 'ssh2.sftp://' . (int) $this->sftp . $dir)) {
			rmdir($path);
		}
	}


	/**
	 * Recursive deletes content of directory or file.
	 * @throws ServerException
	 */
	public function purge(string $dir, callable $progress = null): void
	{
		$dirs = $entries = [];

		$iterator = dir($path = 'ssh2.sftp://' . (int) $this->sftp . $dir);
		while (($entry = $iterator->read()) !== false) {
			if ($entry !== '.' && $entry !== '..') {
				$entries[] = $entry;
			}
		}

		foreach ($entries as $entry) {
			if (is_dir("$path/$entry")) {
				$dirs[] = $tmp = '.delete' . uniqid() . count($dirs);
				rename("$path/$entry", "$path/$tmp");
			} else {
				unlink("$path/$entry");
			}

			if ($progress) {
				$progress($entry);
			}
		}

		foreach ($dirs as $subdir) {
			$this->purge("$dir/$subdir", $progress);
			rmdir("$path/$subdir");
		}
	}


	/**
	 * Returns current directory.
	 */
	public function getDir(): string
	{
		return isset($this->url['path']) ? rtrim($this->url['path'], '/') : '';
	}


	/**
	 * Executes a command on a remote server.
	 * @throws ServerException
	 */
	public function execute(string $command): string
	{
		$stream = ssh2_exec($this->connection, $command);
		stream_set_blocking($stream, true);
		$out = stream_get_contents($stream);
		fclose($stream);
		return $out;
	}
}
