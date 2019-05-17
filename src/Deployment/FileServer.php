<?php

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Deployment;


/**
 * Filesystem pseudoserver.
 */
class FileServer implements Server
{
	/** @var string */
	private $root;


	/**
	 * @param  string  $url  file://...
	 */
	public function __construct(string $url)
	{
		if (substr($url, 0, 7) !== 'file://') {
			throw new \InvalidArgumentException('Invalid URL');
		}
		$this->root = $url;
	}


	/**
	 * @throws ServerException
	 */
	public function connect(): void
	{
		if (!is_dir($this->root)) {
			throw new ServerException('Directory does not exist');
		}
	}


	/**
	 * Reads remote file.
	 * @throws ServerException
	 */
	public function readFile(string $remote, string $local): void
	{
		Safe::copy($this->root . $remote, $local);
	}


	/**
	 * Uploads file.
	 * @throws ServerException
	 */
	public function writeFile(string $local, string $remote, callable $progress = null): void
	{
		Safe::copy($local, $this->root . $remote);
	}


	/**
	 * Removes file if exists.
	 * @throws ServerException
	 */
	public function removeFile(string $file): void
	{
		if (file_exists($path = $this->root . $file)) {
			Safe::unlink($path);
		}
	}


	/**
	 * Renames and rewrites file.
	 * @throws ServerException
	 */
	public function renameFile(string $old, string $new): void
	{
		Safe::rename($this->root . $old, $this->root . $new);
	}


	/**
	 * Creates directories.
	 * @throws ServerException
	 */
	public function createDir(string $dir): void
	{
		if (trim($dir, '/') !== '' && !file_exists($path = $this->root . $dir)) {
			Safe::mkdir($path, 0777, true);
		}
	}


	/**
	 * Removes directory if exists.
	 * @throws ServerException
	 */
	public function removeDir(string $dir): void
	{
		if (file_exists($path = $this->root . $dir)) {
			Safe::rmdir($path);
		}
	}


	/**
	 * Recursive deletes content of directory or file.
	 * @throws ServerException
	 */
	public function purge(string $dir, callable $progress = null): void
	{
		$dir = $this->root . $dir;
		if (!file_exists($dir)) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($iterator as $name => $file) {
			$file->isDir() ? Safe::rmdir($name) : Safe::unlink($name);
			if ($progress) {
				$progress($name);
			}
		}
	}


	/**
	 * Returns current directory.
	 */
	public function getDir(): string
	{
		return '';
	}


	/**
	 * Executes a command on a remote server.
	 * @throws ServerException
	 */
	public function execute(string $command): string
	{
		Safe::exec($command, $out);
		return implode("\n", $out);
	}
}
