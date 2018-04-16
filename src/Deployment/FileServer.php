<?php

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (https://davidgrudl.com)
 */

namespace Deployment;


/**
 * Filesystem pseudoserver.
 *
 * It has a dependency on the error handler that converts PHP errors to ServerException.
 */
class FileServer implements Server
{
	/** @var string */
	private $root;


	/**
	 * @param  string  URL file://...
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
		copy($this->root . $remote, $local);
	}


	/**
	 * Uploads file.
	 * @throws ServerException
	 */
	public function writeFile(string $local, string $remote, callable $progress = null): void
	{
		copy($local, $this->root . $remote);
	}


	/**
	 * Removes file if exists.
	 * @throws ServerException
	 */
	public function removeFile(string $file): void
	{
		if (file_exists($path = $this->root . $file)) {
			unlink($path);
		}
	}


	/**
	 * Renames and rewrites file.
	 * @throws ServerException
	 */
	public function renameFile(string $old, string $new): void
	{
		rename($this->root . $old, $this->root . $new);
	}


	/**
	 * Creates directories.
	 * @throws ServerException
	 */
	public function createDir(string $dir): void
	{
		if (trim($dir, '/') !== '' && !file_exists($path = $this->root . $dir)) {
			mkdir($path, 0777, true);
		}
	}


	/**
	 * Removes directory if exists.
	 * @throws ServerException
	 */
	public function removeDir(string $dir): void
	{
		if (file_exists($path = $this->root . $dir)) {
			rmdir($path);
		}
	}


	/**
	 * Recursive deletes content of directory or file.
	 * @throws ServerException
	 */
	public function purge(string $dir, callable $progress = null): void
	{
		$iterator = dir($path = $this->root . $dir);
		while (($entry = $iterator->read()) !== false) {
			if (is_dir("$path/$entry")) {
				if ($entry !== '.' && $entry !== '..') {
					$this->purge("$dir/$entry");
				}
			} else {
				unlink("$path/$entry");
			}
			if ($progress) {
				$progress($entry);
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
		exec($command, $out);
		return implode("\n", $out);
	}
}
