<?php declare(strict_types=1);

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (https://davidgrudl.com)
 */

namespace Deployment;


/**
 * FTP server.
 */
class FtpServer implements Server
{
	private const BlockSize = 400000;

	public ?int $filePermissions = null;
	public ?int $dirPermissions = null;

	/** @var resource|null */
	private $connection;

	/** @var array{scheme: string, host: string, user: string, pass: string, port?: int, path?: string} */
	private array $url;
	private bool $passiveMode = true;


	/**
	 * @param  string  $url  ftp://... or ftps://...
	 * @throws \Exception
	 */
	public function __construct(
		#[\SensitiveParameter]
		string $url,
		bool $passiveMode = true,
	) {
		if (!extension_loaded('ftp')) {
			throw new \Exception('PHP extension FTP is not loaded.');
		}
		$url = parse_url($url);
		if (
			!$url
			|| !isset($url['scheme'], $url['user'], $url['pass'], $url['host'])
			|| ($url['scheme'] !== 'ftp' && $url['scheme'] !== 'ftps')
		) {
			throw new \InvalidArgumentException('Invalid URL or missing username, password or host');
		} elseif ($url['scheme'] === 'ftps' && !function_exists('ftp_ssl_connect')) {
			throw new \Exception('PHP extension OpenSSL is not built statically in PHP.');
		}
		$this->url = $url;
		$this->passiveMode = $passiveMode;
	}


	/**
	 * Suppresses error on closing.
	 */
	public function __destruct()
	{
		if ($this->connection) {
			try {
				Safe::ftp_close($this->connection);
			} catch (\Throwable) {
			}
		}
	}


	/**
	 * Connects to FTP server.
	 * @throws ServerException
	 */
	public function connect(): void
	{
		if ($this->connection) { // reconnect?
			try {
				Safe::ftp_close($this->connection);
			} catch (\Throwable) {
			}
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
		Safe::ftp_get($this->getConnection(), $local, $remote, FTP_BINARY);
	}


	/**
	 * Uploads file to FTP server.
	 * @throws ServerException
	 */
	public function writeFile(string $local, string $remote, ?callable $progress = null): void
	{
		$conn = $this->getConnection();
		$size = max(filesize($local), 1);
		$blocks = 0;
		do {
			if ($progress) {
				$progress(min($blocks * self::BlockSize / $size, 100));
			}
			$ret = $blocks === 0
				? Safe::ftp_nb_put($conn, $remote, $local, FTP_BINARY)
				: Safe::ftp_nb_continue($conn);

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
		$conn = $this->getConnection();
		try {
			Safe::ftp_delete($conn, $file);
		} catch (ServerException $e) {
			$list = [];
			try {
				$list = Safe::ftp_nlist($conn, $file . '*');
			} catch (ServerException) {
			}
			if (in_array($file, $list, true)) {
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
		Safe::ftp_rename($this->getConnection(), $old, $new); // TODO: zachovat permissions
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
					Safe::ftp_mkdir($this->getConnection(), $path);
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
		$conn = $this->getConnection();
		$current = $this->getDir();
		try {
			Safe::ftp_chdir($conn, $dir);
			$res = true;
		} catch (ServerException) {
			$res = false;
		}
		Safe::ftp_chdir($conn, $current ?: '/');
		return $res;
	}


	/**
	 * Removes directory from FTP server if exists.
	 * @throws ServerException
	 */
	public function removeDir(string $dir): void
	{
		try {
			Safe::ftp_rmdir($this->getConnection(), $dir);
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
	public function purge(string $dir, ?callable $progress = null): void
	{
		if (!$this->isDir($dir)) {
			return;
		}

		$conn = $this->getConnection();
		$dirs = [];
		foreach ((array) Safe::ftp_nlist($conn, $dir) as $entry) {
			if ($entry == null || $entry === $dir || preg_match('#(^|/)\.+$#', $entry)) { // intentionally ==
				continue;
			} elseif (!str_contains($entry, '/')) {
				$entry = "$dir/$entry";
			}

			if ($this->isDir($entry)) {
				$dirs[] = $tmp = "$dir/.delete" . uniqid() . count($dirs);
				Safe::ftp_rename($conn, $entry, $tmp);
			} else {
				Safe::ftp_delete($conn, $entry);
			}

			if ($progress) {
				$progress($entry);
			}
		}

		foreach ($dirs as $subdir) {
			$this->purge($subdir, $progress);
			Safe::ftp_rmdir($conn, $subdir);
		}
	}


	/**
	 * Returns current directory.
	 * @throws ServerException
	 */
	public function getDir(): string
	{
		return rtrim(Safe::ftp_pwd($this->getConnection()), '/');
	}


	/**
	 * Executes a command on a remote server.
	 * @throws ServerException
	 */
	public function execute(string $command): string
	{
		Safe::ftp_exec($this->getConnection(), $command);
		return '';
	}


	/**
	 * @throws ServerException
	 */
	public function chmod(string $file, int $perms): void
	{
		try {
			Safe::ftp_chmod($this->getConnection(), $perms, $file);
		} catch (ServerException $e) {
			$perms = str_pad(decoct($perms), 4, '0', STR_PAD_LEFT);
			Safe::ftp_site($this->getConnection(), "CHMOD $perms $file");
		}
	}


	/**
	 * @return resource
	 */
	private function getConnection(): mixed
	{
		return $this->connection ?? throw new ServerException('Not connected. Call connect() first.');
	}
}
