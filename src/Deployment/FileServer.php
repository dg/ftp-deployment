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
	public function __construct($url)
	{
		if (substr($url, 0, 7) !== 'file://') {
			throw new \InvalidArgumentException('Invalid URL');
		}
		$this->root = $url;
	}


	/**
	 * @return void
	 * @throws ServerException
	 */
	public function connect()
	{
		if (!is_dir($this->root)) {
			throw new ServerException('Directory does not exist');
		}
	}


	/**
	 * Reads remote file.
	 * @return void
	 * @throws ServerException
	 */
	public function readFile($remote, $local)
	{
		copy($this->root . $remote, $local);
	}


	/**
	 * Uploads file.
	 * @return void
	 * @throws ServerException
	 */
	public function writeFile($local, $remote, callable $progress = null)
	{
		copy($local, $this->root . $remote);
	}


	/**
	 * Removes file if exists.
	 * @return void
	 * @throws ServerException
	 */
	public function removeFile($file)
	{
		if (file_exists($path = $this->root . $file)) {
			unlink($path);
		}
	}


	/**
	 * Renames and rewrites file.
	 * @return void
	 * @throws ServerException
	 */
	public function renameFile($old, $new)
	{
		rename($this->root . $old, $this->root . $new);
	}


	/**
	 * Creates directories.
	 * @return void
	 * @throws ServerException
	 */
	public function createDir($dir)
	{
		if (trim($dir, '/') !== '' && !file_exists($path = $this->root . $dir)) {
			mkdir($path, 0777, true);
		}
	}


	/**
	 * Removes directory if exists.
	 * @return void
	 * @throws ServerException
	 */
	public function removeDir($dir)
	{
		if (file_exists($path = $this->root . $dir)) {
			rmdir($path);
		}
	}


	/**
	 * Recursive deletes content of directory or file.
	 * @param  string
	 * @return void
	 * @throws ServerException
	 */
	public function purge($dir, callable $progress = null)
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
	 * @return string
	 */
	public function getDir()
	{
		return '';
	}


	/**
	 * Executes a command on a remote server.
	 * @return string
	 * @throws ServerException
	 */
	public function execute($command)
	{
		exec($command, $out);
		return implode("\n", $out);
	}
}
