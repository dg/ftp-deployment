<?php

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Deployment;


/**
 * Server.
 */
interface Server
{
	/**
	 * Connects to server.
	 * @throws ServerException
	 */
	function connect(): void;

	/**
	 * Reads file from server. Paths are absolute.
	 * @throws ServerException
	 */
	function readFile(string $remote, string $local): void;

	/**
	 * Uploads file to server. Paths are absolute.
	 * @throws ServerException
	 */
	function writeFile(string $local, string $remote, callable $progress = null): void;

	/**
	 * Removes file from server if exists. Path is absolute.
	 * @throws ServerException
	 */
	function removeFile(string $file): void;

	/**
	 * Renames and rewrites file on server. Paths are absolute.
	 * @throws ServerException
	 */
	function renameFile(string $old, string $new): void;

	/**
	 * Creates directories on server. Path is absolute.
	 * @throws ServerException
	 */
	function createDir(string $dir): void;

	/**
	 * Removes directory from server if exists. Path is absolute.
	 * @throws ServerException
	 */
	function removeDir(string $dir): void;

	/**
	 * Recursive deletes content of directory or file. Path is absolute.
	 * @throws ServerException
	 */
	function purge(string $path, callable $progress = null): void;

	/**
	 * Returns current directory.
	 */
	function getDir(): string;

	/**
	 * Executes a command on a remote server.
	 * @throws ServerException
	 */
	function execute(string $command): string;
}
