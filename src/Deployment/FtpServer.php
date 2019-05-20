<?php

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Deployment;


/**
 * FTP server.
 *
 * It has a dependency on the error handler that converts PHP errors to ServerException.
 */
class FtpServer implements Server
{
	private const RETRIES = 10;

	private const BLOCK_SIZE = 400000;

	/** @var int */
	public $filePermissions;

	/** @var int */
	public $dirPermissions;

	/** @var resource */
	private $connection;

	/** @var array  see parse_url() */
	private $url;

	/** @var bool */
	private $passiveMode = true;


	/**
	 * @param  string  $url  ftp://... or ftps://...
	 * @throws \Exception
	 */
	public function __construct(string $url, bool $passiveMode = true)
	{
		if (!extension_loaded('ftp')) {
			throw new \Exception('PHP extension FTP is not loaded.');
		}
		$this->url = $url = parse_url($url);
		if (!isset($url['scheme'], $url['user'], $url['pass']) || ($url['scheme'] !== 'ftp' && $url['scheme'] !== 'ftps')) {
			throw new \InvalidArgumentException('Invalid URL or missing username or password');
		} elseif ($url['scheme'] === 'ftps' && !function_exists('ftp_ssl_connect')) {
			throw new \Exception('PHP extension OpenSSL is not built statically in PHP.');
		}
		$this->passiveMode = $passiveMode;
	}


	/**
	 * Connects to FTP server.
	 * @throws ServerException
	 */
	public function connect(): void
	{
		$this->connection = $this->url['scheme'] === 'ftp'
			? ftp_connect($this->url['host'], $this->url['port'] ?? 21)
			: ftp_ssl_connect($this->url['host'], $this->url['port'] ?? 21);

		ftp_login($this->connection, urldecode($this->url['user']), urldecode($this->url['pass']));

		if ($this->passiveMode) {
			ftp_set_option($this->connection, FTP_USEPASVADDRESS, false);
			ftp_pasv($this->connection, $this->passiveMode);
		}

		if (isset($this->url['path'])) {
			ftp_chdir($this->connection, $this->url['path']);
		}
	}


	/**
	 * Reads remote file from FTP server.
	 * @throws ServerException
	 */
	public function readFile(string $remote, string $local): void
	{
		ftp_get($this->connection, $local, $remote, FTP_BINARY);
	}


	/**
	 * Uploads file to FTP server.
	 * @throws ServerException
	 */
	public function writeFile(string $local, string $remote, callable $progress = null): void
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
					? ftp_nb_put($this->connection, $remote, $local, FTP_BINARY)
					: ftp_nb_continue($this->connection);

			} catch (ServerException $e) {
				@ftp_close($this->connection); // intentionally @
				$this->connect();
				if (--$retry) {
					goto upload;
				}
				throw new ServerException("Cannot upload file $local, number of retries exceeded. Error: {$e->getMessage()}");
			}
			$blocks++;
		} while ($ret === FTP_MOREDATA);

		if ($this->filePermissions) {
			ftp_chmod($this->connection, $this->filePermissions, $remote);
		}
		if ($progress) {
			$progress(100);
		}
	}


	/**
	 * Removes file from FTP server if exists.
	 * @throws ServerException
	 */
	public function removeFile(string $file): void
	{
		try {
			ftp_delete($this->connection, $file);
		} catch (ServerException $e) {
			if (in_array($file, (array) ftp_nlist($this->connection, $file . '*'), true)) {
				throw $e;
			}
		}
	}


	/**
	 * Renames and rewrites file on FTP server.
	 * @throws ServerException
	 */
	public function renameFile(string $old, string $new): void
	{
		$this->removeFile($new);
		ftp_rename($this->connection, $old, $new); // TODO: zachovat permissions
	}


	/**
	 * Creates directories on FTP server.
	 * @throws ServerException
	 */
	public function createDir(string $dir): void
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
					ftp_mkdir($this->connection, $path);
					if ($this->dirPermissions) {
						ftp_chmod($this->connection, $this->dirPermissions, $path);
					}
				}
			} catch (ServerException $e) {
				if (!$this->isDir($path)) {
					throw new ServerException("Cannot create directory '$path'.");
				}
			}
			$path .= '/';
		}
	}


	/**
	 * Checks if directory exists.
	 * @throws ServerException
	 */
	private function isDir(string $dir): bool
	{
		$current = $this->getDir();
		$res = @ftp_chdir($this->connection, $dir); // intentionally @
		ftp_chdir($this->connection, $current ?: '/');
		return $res;
	}


	/**
	 * Removes directory from FTP server if exists.
	 * @throws ServerException
	 */
	public function removeDir(string $dir): void
	{
		try {
			ftp_rmdir($this->connection, $dir);
		} catch (ServerException $e) {
			if (in_array($dir, (array) ftp_nlist($this->connection, $dir . '*'), true)) {
				throw $e;
			}
		}
	}


	/**
	 * Recursive deletes content of directory or file.
	 * @throws ServerException
	 */
	public function purge(string $dir, callable $progress = null): void
	{
		$dirs = [];
		foreach ((array) ftp_nlist($this->connection, $dir) as $entry) {
			if ($entry == null || $entry === $dir || preg_match('#(^|/)\\.+$#', $entry)) { // intentionally ==
				continue;
			} elseif (strpos($entry, '/') === false) {
				$entry = "$dir/$entry";
			}

			if ($this->isDir($entry)) {
				$dirs[] = $tmp = "$dir/.delete" . uniqid() . count($dirs);
				ftp_rename($this->connection, $entry, $tmp);
			} else {
				ftp_delete($this->connection, $entry);
			}

			if ($progress) {
				$progress($entry);
			}
		}

		foreach ($dirs as $subdir) {
			$this->purge($subdir, $progress);
			ftp_rmdir($this->connection, $subdir);
		}
	}


	/**
	 * Returns current directory.
	 * @throws ServerException
	 */
	public function getDir(): string
	{
		return rtrim(ftp_pwd($this->connection), '/');
	}


	/**
	 * Executes a command on a remote server.
	 * @throws ServerException
	 */
	public function execute(string $command): string
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
				ftp_chmod($this->connection, octdec($m[2]), $m[3]);
			}
		} else {
			ftp_exec($this->connection, $command);
		}
		return '';
	}
}
