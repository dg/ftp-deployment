<?php

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (https://davidgrudl.com)
 */

namespace Deployment;


/**
 * Server.
 */
interface Server
{

	/**
	 * Connects to server.
	 * @return void
	 * @throws ServerException
	 */
	function connect();

	/**
	 * Reads file from server. Paths are absolute.
	 * @return void
	 * @throws ServerException
	 */
	function readFile($remote, $local);

	/**
	 * Uploads file to server. Paths are absolute.
	 * @return void
	 * @throws ServerException
	 */
	function writeFile($local, $remote, callable $progress = null);

	/**
	 * Removes file from server if exists. Path is absolute.
	 * @return void
	 * @throws ServerException
	 */
	function removeFile($file);

	/**
	 * Renames and rewrites file on server. Paths are absolute.
	 * @return void
	 * @throws ServerException
	 */
	function renameFile($old, $new);

	/**
	 * Creates directories on server. Path is absolute.
	 * @return void
	 * @throws ServerException
	 */
	function createDir($dir);

	/**
	 * Removes directory from server if exists. Path is absolute.
	 * @return void
	 * @throws ServerException
	 */
	function removeDir($dir);

	/**
	 * Recursive deletes content of directory or file. Path is absolute.
	 * @return void
	 * @throws ServerException
	 */
	function purge($path, callable $progress = null);

	/**
	 * Returns current directory.
	 * @return string
	 */
	function getDir();

	/**
	 * Executes a command on a remote server.
	 * @return string
	 * @throws ServerException
	 */
	function execute($command);
}
