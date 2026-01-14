<?php

declare(strict_types=1);

namespace Deployment;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;

class PhpsecServer implements Server
{
	public ?int $filePermissions = null;
	public ?int $dirPermissions = null;

	/** @var array{scheme?: string, host?: string, port?: int, user?: string, pass?: string, path?: string, query?: string, fragment?: string} */
	private array $url;
	private ?string $publicKey;
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
		$this->url = parse_url($url);
		if (!isset($this->url['scheme'], $this->url['user'], $this->url['host']) || $this->url['scheme'] !== 'phpsec') {
			throw new \InvalidArgumentException('Invalid URL or missing username');
		}
		$this->publicKey = $publicKey;
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
			$passPhrase = $this->passPhrase ?? false;
			if ($passPhrase === 'STDIN') {
				$passPhrase = Helpers::getHiddenInput("Enter password for private key: ");
			}
			$key = PublicKeyLoader::load(file_get_contents($this->privateKey), $passPhrase);
			if (!$sftp->login(urldecode($this->url['user']), $key)) {
				throw new ServerException('Login failed with private key');
			}
		} else {
			if (!$sftp->login(urldecode($this->url['user']), urldecode($this->url['pass']))) {
				throw new ServerException('Login failed with password');
			}
		}
		$this->sftp = $sftp;
	}


	public function readFile(string $remote, string $local): void
	{
		if ($this->sftp->get($remote, $local) === false) {
			throw new ServerException('Unable to read file');
		}
	}


	public function writeFile(string $local, string $remote, ?callable $progress = null): void
	{
		if ($this->sftp->put($remote, $local, SFTP::SOURCE_LOCAL_FILE, -1, -1, $progress) === false) {
			throw new ServerException('Unable to write file');
		}
		if ($this->filePermissions) {
			if ($this->sftp->chmod($this->filePermissions, $remote) === false) {
				throw new ServerException('Unable to chmod after file creation');
			}
		}
	}


	public function removeFile(string $file): void
	{
		if ($this->sftp->file_exists($file)) {
			if ($this->sftp->delete($file) === false) {
				throw new ServerException('Unable to delete file');
			}
		}
	}


	public function renameFile(string $old, string $new): void
	{
		if ($this->sftp->file_exists($new)) {
			$perms = $this->sftp->fileperms($new);
			if ($this->sftp->delete($new) === false) {
				throw new ServerException('Unable to delete target file during rename');
			}
		}
		if ($this->sftp->rename($old, $new) === false) {
			throw new ServerException('Unable to rename file');
		}
		if (!empty($perms)) {
			if ($this->sftp->chmod($perms, $new) === false) {
				throw new ServerException('Unable to chmod file after renaming');
			}
		}
	}


	public function createDir(string $dir): void
	{
		if ($dir !== '' && !$this->sftp->file_exists($dir)) {
			if ($this->sftp->mkdir($dir) === false) {
				throw new ServerException('Unable to create directory');
			}
			if ($this->sftp->chmod($this->dirPermissions ?: 0777, $dir) === false) {
				throw new ServerException('Unable to chmod after creating a directory');
			}
		}
	}


	public function removeDir(string $dir): void
	{
		if ($this->sftp->file_exists($dir)) {
			if ($this->sftp->rmdir($dir) === false) {
				throw new ServerException('Unable to remove directory');
			}
		}
	}


	public function purge(string $path, ?callable $progress = null): void
	{
		if ($this->sftp->file_exists($path)) {
			if ($this->sftp->delete($path, true) === false) {
				throw new ServerException('Unable to purge directory/file');
			}
		}
	}


	public function chmod(string $path, int $permissions): void
	{
		if ($this->sftp->chmod($permissions, $path) === false) {
			throw new ServerException('Unable to chmod file');
		}
	}


	public function getDir(): string
	{
		return isset($this->url['path']) ? rtrim($this->url['path'], '/') : '';
	}


	public function execute(string $command): string
	{
		return $this->sftp->exec($command);
	}
}
