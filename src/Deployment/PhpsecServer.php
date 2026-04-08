<?php declare(strict_types=1);

namespace Deployment;

use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;

class PhpsecServer implements Server
{
	public ?int $filePermissions = null;
	public ?int $dirPermissions = null;

	/** @var array{scheme: string, host: string, user: string, pass?: string, port?: int, path?: string} */
	private array $url;
	private ?string $privateKey;
	private ?string $passPhrase;
	private ?SFTP $sftp = null;


	public function __construct(
		string $url,
		?string $publicKey = null,
		#[\SensitiveParameter]
		?string $privateKey = null,
		#[\SensitiveParameter]
		?string $passPhrase = null,
	) {
		$url = parse_url($url);
		if (!$url || !isset($url['scheme'], $url['user'], $url['host']) || $url['scheme'] !== 'phpsec') {
			throw new \InvalidArgumentException('Invalid URL or missing username');
		}
		$this->url = $url;
		$this->privateKey = $privateKey;
		$this->passPhrase = $passPhrase;
	}


	public function connect(): void
	{
		if ($this->sftp) { // reconnect?
			@$this->sftp->disconnect(); // @ may fail
		}
		$sftp = new SFTP($this->url['host'], $this->url['port'] ?? 22);
		if ($this->privateKey) {
			$content = file_get_contents($this->privateKey);
			if ($content === false) {
				throw new ServerException("Unable to read private key file: $this->privateKey");
			}
			$key = PublicKeyLoader::load($content, $this->passPhrase ?? '');
			if (!$key instanceof PrivateKey || !$sftp->login(urldecode($this->url['user']), $key)) {
				throw new ServerException('Login failed with private key');
			}
		} else {
			if (!$sftp->login(urldecode($this->url['user']), urldecode($this->url['pass'] ?? ''))) {
				throw new ServerException('Login failed with password');
			}
		}
		$this->sftp = $sftp;
	}


	public function readFile(string $remote, string $local): void
	{
		if ($this->getConnection()->get($remote, $local) === false) {
			throw new ServerException('Unable to read file');
		}
	}


	public function writeFile(string $local, string $remote, ?callable $progress = null): void
	{
		$sftp = $this->getConnection();
		if ($sftp->put($remote, $local, SFTP::SOURCE_LOCAL_FILE, -1, -1, $progress) === false) {
			throw new ServerException('Unable to write file');
		}
		if ($this->filePermissions) {
			if ($sftp->chmod($this->filePermissions, $remote) === false) {
				throw new ServerException('Unable to chmod after file creation');
			}
		}
	}


	public function removeFile(string $file): void
	{
		$sftp = $this->getConnection();
		if ($sftp->file_exists($file)) {
			if ($sftp->delete($file) === false) {
				throw new ServerException('Unable to delete file');
			}
		}
	}


	public function renameFile(string $old, string $new): void
	{
		$sftp = $this->getConnection();
		if ($sftp->file_exists($new)) {
			$perms = $sftp->fileperms($new);
			if ($sftp->delete($new) === false) {
				throw new ServerException('Unable to delete target file during rename');
			}
		}
		if ($sftp->rename($old, $new) === false) {
			throw new ServerException('Unable to rename file');
		}
		if (!empty($perms)) {
			if ($sftp->chmod($perms, $new) === false) {
				throw new ServerException('Unable to chmod file after renaming');
			}
		}
	}


	public function createDir(string $dir): void
	{
		$sftp = $this->getConnection();
		if ($dir !== '' && !$sftp->file_exists($dir)) {
			if ($sftp->mkdir($dir) === false) {
				throw new ServerException('Unable to create directory');
			}
			if ($sftp->chmod($this->dirPermissions ?: 0o777, $dir) === false) {
				throw new ServerException('Unable to chmod after creating a directory');
			}
		}
	}


	public function removeDir(string $dir): void
	{
		$sftp = $this->getConnection();
		if ($sftp->file_exists($dir)) {
			if ($sftp->rmdir($dir) === false) {
				throw new ServerException('Unable to remove directory');
			}
		}
	}


	public function purge(string $path, ?callable $progress = null): void
	{
		$sftp = $this->getConnection();
		if ($sftp->file_exists($path)) {
			if ($sftp->delete($path, true) === false) {
				throw new ServerException('Unable to purge directory/file');
			}
		}
	}


	public function chmod(string $path, int $permissions): void
	{
		if ($this->getConnection()->chmod($permissions, $path) === false) {
			throw new ServerException('Unable to chmod file');
		}
	}


	public function getDir(): string
	{
		return isset($this->url['path']) ? rtrim($this->url['path'], '/') : '';
	}


	public function execute(string $command): string
	{
		$result = $this->getConnection()->exec($command);
		if (!is_string($result)) {
			throw new ServerException("Failed to execute command: $command");
		}
		return $result;
	}


	private function getConnection(): SFTP
	{
		return $this->sftp ?? throw new ServerException('Not connected. Call connect() first.');
	}
}
