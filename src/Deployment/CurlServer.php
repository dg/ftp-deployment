<?php declare(strict_types=1);

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (https://davidgrudl.com)
 */

namespace Deployment;


/**
 * FTP server accessed via cURL.
 */
class CurlServer extends BaseServer
{
	private const Timeout = 90;

	private ?\CurlHandle $connection = null;

	/** @var array{scheme: string, host: string, user: string, pass: string, port?: int, path?: string} */
	private array $url;
	private bool $passiveMode = true;
	private bool $verifyCertificate = false;
	private string $dir = '';

	/** @var list<string> */
	private array $replies = [];

	/** @var ?array{string, string} rename that failed, so its outcome is unknown */
	private ?array $pendingRename = null;


	/**
	 * @param  string  $url  ftp://... or ftps://...
	 * @throws \Exception
	 */
	public function __construct(
		#[\SensitiveParameter]
		string $url,
		bool $passiveMode = true,
		bool $verifyCertificate = false,
	) {
		if (!extension_loaded('curl')) {
			throw new \Exception('PHP extension cURL is not loaded.');
		}
		$url = parse_url($url);
		if (
			!$url
			|| !isset($url['scheme'], $url['user'], $url['pass'], $url['host'])
			|| ($url['scheme'] !== 'ftp' && $url['scheme'] !== 'ftps')
		) {
			throw new \InvalidArgumentException('Invalid URL or missing username, password or host');
		}
		$this->url = $url;
		$this->passiveMode = $passiveMode;
		$this->verifyCertificate = $verifyCertificate;
	}


	/**
	 * Connects to FTP server.
	 * @throws ServerException
	 */
	public function connect(): void
	{
		$this->connection = curl_init();
		// in one request, the next one may get a new connection in the login directory (libcurl 8.5 never reuses FTPS ones)
		$commands = isset($this->url['path']) ? ['CWD ' . $this->url['path'], 'PWD'] : ['PWD'];
		$this->command(...$commands);
		if (!preg_match('#^257 "((?:[^"]|"")*)"#', $this->findReply('257') ?? '', $m)) {
			throw new ServerException('Unable to detect the current directory.');
		}
		$this->dir = rtrim(str_replace('""', '"', $m[1]), '/');
	}


	/**
	 * Reads remote file from FTP server.
	 * @throws ServerException
	 */
	public function readFile(string $remote, string $local): void
	{
		$handle = Safe::fopen($local, 'wb');
		try {
			$this->request([
				CURLOPT_URL => $this->buildUrl($remote),
				CURLOPT_FILE => $handle,
			]);
		} finally {
			fclose($handle);
		}
	}


	/**
	 * Uploads file to FTP server.
	 * @throws ServerException
	 */
	public function writeFile(string $local, string $remote, ?callable $progress = null): void
	{
		$handle = Safe::fopen($local, 'rb');
		$size = (int) filesize($local);
		$options = [
			CURLOPT_URL => $this->buildUrl($remote),
			CURLOPT_UPLOAD => true,
			CURLOPT_INFILE => $handle,
			CURLOPT_INFILESIZE => $size,
			CURLOPT_POSTQUOTE => $this->filePermissions ? [$this->chmodCommand($remote, $this->filePermissions)] : [],
		];
		$interruption = null;
		if ($progress) {
			$options[CURLOPT_NOPROGRESS] = false;
			$options[CURLOPT_XFERINFOFUNCTION] = function (
				$ch,
				$downloadSize,
				$downloaded,
				$uploadSize,
				int $uploaded,
			) use ($progress, $size, &$interruption): int {
				try {
					$progress(min($uploaded * 100 / max($size, 1), 100));
					return 0;
				} catch (\Throwable $e) {
					// an exception must not cross cURL, a non-zero return aborts the transfer
					$interruption = $e;
					return 1;
				}
			};
		}

		try {
			$this->request($options);
		} catch (ServerException $e) {
			throw $interruption ?? $e;
		} finally {
			fclose($handle);
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
			$this->command("DELE $file");
		} catch (ServerException $e) {
			if ($this->isFile($file)) {
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
		// a retry after a lost reply to RNTO would delete the renamed file and then fail on the missing $old
		if ($this->pendingRename === [$old, $new] && !$this->isFile($old) && $this->isFile($new)) {
			$this->pendingRename = null;
			return;
		}
		$this->removeFile($new);
		$this->pendingRename = [$old, $new];
		$this->command("RNFR $old", "RNTO $new");
		$this->pendingRename = null;
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
					$this->command("MKD $path");
					if ($this->dirPermissions) {
						$this->chmod($path, $this->dirPermissions);
					}
				}
			} catch (ServerException) {
				if (!$this->isDir($path)) {
					throw new ServerException("Cannot create directory '$path'.");
				}
			}
			$path .= '/';
		}
	}


	/**
	 * Removes directory from FTP server if exists.
	 * @throws ServerException
	 */
	public function removeDir(string $dir): void
	{
		try {
			$this->command("RMD $dir");
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
	public function purge(string $path, ?callable $progress = null): void
	{
		if (!$this->isDir($path)) {
			return;
		}

		$dirs = [];
		foreach ($this->listDir($path) as $entry) {
			$entry = "$path/$entry";
			if ($this->isDir($entry)) {
				$dirs[] = $tmp = "$path/.delete" . uniqid() . count($dirs);
				$this->command("RNFR $entry", "RNTO $tmp");
			} else {
				$this->command("DELE $entry");
			}

			if ($progress) {
				$progress($entry);
			}
		}

		foreach ($dirs as $subdir) {
			$this->purge($subdir, $progress);
			$this->command("RMD $subdir");
		}
	}


	/**
	 * Returns current directory.
	 * @throws ServerException
	 */
	public function getDir(): string
	{
		$this->getConnection();
		return $this->dir;
	}


	/**
	 * Executes a command on a remote server.
	 * @throws ServerException
	 */
	public function execute(string $command): string
	{
		$this->command("SITE EXEC $command");
		return '';
	}


	/**
	 * @throws ServerException
	 */
	public function chmod(string $path, int $permissions): void
	{
		$this->command($this->chmodCommand($path, $permissions));
	}


	private function chmodCommand(string $path, int $permissions): string
	{
		return 'SITE CHMOD ' . str_pad(decoct($permissions), 4, '0', STR_PAD_LEFT) . ' ' . $path;
	}


	private function isDir(string $dir): bool
	{
		try {
			$this->command("CWD $dir");
			return true;
		} catch (ServerException) {
			return false;
		}
	}


	private function isFile(string $file): bool
	{
		try {
			$this->command('TYPE I', "SIZE $file");
			return true;
		} catch (ServerException) {
			return false;
		}
	}


	/**
	 * Returns names of entries in the directory.
	 * @return list<string>
	 * @throws ServerException
	 */
	private function listDir(string $dir): array
	{
		$list = $this->request([
			CURLOPT_URL => $this->buildUrl($dir) . '/',
			CURLOPT_DIRLISTONLY => true,
			CURLOPT_RETURNTRANSFER => true,
		]);
		$names = [];
		foreach (preg_split('#\r?\n#', $list, flags: PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
			$name = basename($entry);
			if ($name !== '.' && $name !== '..') {
				$names[] = $name;
			}
		}
		return $names;
	}


	/**
	 * Sends commands over the control connection; a refused command throws.
	 * @throws ServerException
	 */
	private function command(string ...$commands): void
	{
		if (preg_match('#[\r\n]#', implode('', $commands))) {
			throw new ServerException('Path contains a line break.');
		}

		// cURL would close the connection after a refused command, the asterisk makes it go on
		$this->request([
			CURLOPT_URL => $this->buildUrl('/'),
			CURLOPT_NOBODY => true,
			CURLOPT_QUOTE => array_map(fn(string $command) => '*' . $command, $commands),
		]);

		$replies = array_slice(preg_grep('#^\d{3} #', $this->replies) ?: [], -count($commands));
		if (count($replies) < count($commands)) {
			throw new ServerException('Unexpected reply of the server.');
		}
		foreach ($replies as $reply) {
			if ($reply[0] >= '4') {
				throw new ServerException(substr($reply, 4));
			}
		}
	}


	/**
	 * Performs a request on the connection, which cURL keeps open between requests.
	 * @param  array<int, mixed>  $options
	 * @throws ServerException
	 */
	private function request(array $options): string
	{
		$connection = $this->getConnection();
		$this->replies = [];
		curl_reset($connection);
		curl_setopt_array($connection, $options + [
			CURLOPT_USERNAME => urldecode($this->url['user']),
			CURLOPT_PASSWORD => urldecode($this->url['pass']),
			CURLOPT_PORT => $this->url['port'] ?? 21,
			CURLOPT_USE_SSL => $this->url['scheme'] === 'ftps' ? CURLUSESSL_ALL : CURLUSESSL_NONE,
			CURLOPT_SSL_VERIFYPEER => $this->verifyCertificate,
			CURLOPT_SSL_VERIFYHOST => $this->verifyCertificate ? 2 : 0,
			// the certificate store of Windows completes a chain the server sends without its intermediate certificate
			CURLOPT_SSL_OPTIONS => PHP_OS_FAMILY === 'Windows' ? CURLSSLOPT_NATIVE_CA : 0,
			CURLOPT_FTP_FILEMETHOD => CURLFTPMETHOD_NOCWD,
			CURLOPT_FTP_SKIP_PASV_IP => true,
			CURLOPT_CONNECTTIMEOUT => self::Timeout,
			CURLOPT_FTP_RESPONSE_TIMEOUT => self::Timeout,
			CURLOPT_LOW_SPEED_LIMIT => 1, // a stalled transfer
			CURLOPT_LOW_SPEED_TIME => self::Timeout,
			CURLOPT_HEADERFUNCTION => function ($ch, string $line): int {
				$this->replies[] = rtrim($line);
				return strlen($line);
			},
		] + ($this->passiveMode ? [] : [CURLOPT_FTPPORT => '-']));

		$result = curl_exec($connection);
		if ($result === false) {
			if (curl_errno($connection) === CURLE_SSL_PEER_CERTIFICATE) { // not a ServerException, a retry would not help
				throw new \Exception('Certificate of the server was not verified: ' . curl_error($connection)
					. '. Use the host name the certificate is issued for.');
			}
			$reply = $this->findReply('[45]\d\d');
			throw new ServerException($reply === null || curl_errno($connection) === CURLE_OPERATION_TIMEDOUT
				? curl_error($connection)
				: substr($reply, 4));
		}
		return is_string($result) ? $result : '';
	}


	/**
	 * Returns the last reply of the server with the given code.
	 */
	private function findReply(string $code): ?string
	{
		foreach (array_reverse($this->replies) as $reply) {
			if (preg_match("#^$code #", $reply)) {
				return $reply;
			}
		}
		return null;
	}


	/**
	 * Builds URL of an absolute path on the server.
	 */
	private function buildUrl(string $path): string
	{
		return 'ftp://' . $this->url['host'] . '/' . implode('/', array_map(rawurlencode(...), explode('/', $path)));
	}


	private function getConnection(): \CurlHandle
	{
		return $this->connection ?? throw new ServerException('Not connected. Call connect() first.');
	}
}
