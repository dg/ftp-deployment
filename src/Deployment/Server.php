<?php

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (https://davidgrudl.com)
 */

namespace Deployment;


/**
 * Server.
 *
 * @author     David Grudl
 */
interface Server
{

	/**
	 * Connects to server.
	 * @return void
	 */
	function connect();

	/**
	 * Reads file from server. Paths are absolute.
	 * @return void
	 */
	function readFile($remote, $local);

	/**
	 * Uploads file to server. Paths are absolute.
	 * @return void
	 */
	function writeFile($local, $remote, callable $progress = NULL);

	/**
	 * Removes file from server if exists. Path is absolute.
	 * @return void
	 */
	function removeFile($file);

	/**
	 * Renames and rewrites file on server. Paths are absolute.
	 * @return void
	 */
	function renameFile($old, $new);

	/**
	 * Creates directories on server. Path is absolute.
	 * @return void
	 */
	function createDir($dir);

	/**
	 * Removes directory from server if exists. Path is absolute.
	 * @return void
	 */
	function removeDir($dir);

	/**
	 * Recursive deletes content of directory or file. Path is absolute.
	 * @return void
	 */
	function purge($path, callable $progress = NULL);

	/**
	 * Returns current directory.
	 * @return string
	 */
	function getDir();

	/**
	 * Executes a command on a remote server.
	 * @return string
	 */
	function execute($command);

}
