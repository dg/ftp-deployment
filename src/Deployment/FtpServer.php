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
 */
class FtpServer implements Server
{
	private const RETRIES = 10;

	private const BLOCK_SIZE = 400000;

	public ?int $filePermissions = null;

	public ?int $dirPermissions = null;

	/** @var resource */
	private $connection;

	/** see parse_url() */
	private array $url;

	private bool $passiveMode = true;


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
		if (
			!isset($url['scheme'], $url['user'], $url['pass'], $url['host'])
			|| ($url['scheme'] !== 'ftp' && $url['scheme'] !== 'ftps')
		) {
			throw new \InvalidArgumentException('Invalid URL or missing username, password or host');
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
		if ($this->connection) { // reconnect?
			@ftp_close($this->connection); // @ may fail
		}
		$this->connection = $this->url['scheme'] === 'ftp'
			? Safe::ftp_connect($this->url['host'], $this->url['port'] ?? 21)
			: Safe::ftp_ssl_connect($this->url['host'], $this->url['port'] ?? 21);

		Safe::ftp_login($this->connection, urldecode($this->url['user']), urldecode($this->url['pass']));

		if ($this->passiveMode) {
			Safe::ftp_set_option($this->connection, FTP_USEPASVADDRESS, false);
			Safe::ftp_pasv($this->connection, $this->passiveMode);
		}

		if (isset($this->url['path'])) {
			Safe::ftp_chdir($this->connection, $this->url['path']);
		}
	}


	/**
	 * Reads remote file from FTP server.
	 * @throws ServerException
	 */
	public function readFile(string $remote, string $local): void
	{
		Safe::ftp_get($this->connection, $local, $remote, FTP_BINARY);
	}


	/**
	 * Uploads file to FTP server.
	 * @throws ServerException
	 */
	public function writeFile(string $local, string $remote, callable $progress = null): void
	{
		$size = max(filesize($local), 1);
		$blocks = 0;
		do {
			if ($progress) {
				$progress(min($blocks * self::BLOCK_SIZE / $size, 100));
			}
			$ret = $blocks === 0
				? Safe::ftp_nb_put($this->connection, $remote, $local, FTP_BINARY)
				: Safe::ftp_nb_continue($this->connection);

			$blocks++;
			usleep(10000);
		} while ($ret === FTP_MOREDATA);

		if ($this->filePermissions) {
			$this->chmod($remote, $this->filePermissions);
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
			Safe::ftp_delete($this->connection, $file);
		} catch (ServerException $e) {
			if (in_array($file, (array) @ftp_nlist($this->connection, $file . '*'), true)) { // @ may return false when file not exists
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
		Safe::ftp_rename($this->connection, $old, $new); // TODO: zachovat permissions
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
					Safe::ftp_mkdir($this->connection, $path);
					if ($this->dirPermissions) {
						$this->chmod($path, $this->dirPermissions);
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
		Safe::ftp_chdir($this->connection, $current ?: '/');
		return $res;
	}


	/**
	 * Removes directory from FTP server if exists.
	 * @throws ServerException
	 */
	public function removeDir(string $dir): void
	{
		try {
			Safe::ftp_rmdir($this->connection, $dir);
		} catch (ServerException $e) {
			if ($this->isDir($dir)) {
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
		if (!$this->isDir($dir)) {
			return;
		}

		$dirs = [];
		foreach ((array) Safe::ftp_nlist($this->connection, $dir) as $entry) {
			if ($entry == null || $entry === $dir || preg_match('#(^|/)\\.+$#', $entry)) { // intentionally ==
				continue;
			} elseif (strpos($entry, '/') === false) {
				$entry = "$dir/$entry";
			}

			if ($this->isDir($entry)) {
				$dirs[] = $tmp = "$dir/.delete" . uniqid() . count($dirs);
				Safe::ftp_rename($this->connection, $entry, $tmp);
			} else {
				Safe::ftp_delete($this->connection, $entry);
			}

			if ($progress) {
				$progress($entry);
			}
		}

		foreach ($dirs as $subdir) {
			$this->purge($subdir, $progress);
			Safe::ftp_rmdir($this->connection, $subdir);
		}
	}


	/**
	 * Returns current directory.
	 * @throws ServerException
	 */
	public function getDir(): string
	{
		return rtrim(Safe::ftp_pwd($this->connection), '/');
	}


	/**
	 * Executes a command on a remote server.
	 * @throws ServerException
	 */
	public function execute(string $command): string
	{
		Safe::ftp_exec($this->connection, $command);
		return '';
	}


	/**
	 * @throws ServerException
	 */
	public function chmod(string $file, int $perms): void
	{
		try {
			Safe::ftp_chmod($this->connection, $perms, $file);
		} catch (ServerException $e) {
			$perms = str_pad(decoct($perms), 4, '0', STR_PAD_LEFT);
			Safe::ftp_site($this->connection, "CHMOD $perms $file");
		}
	}
}
