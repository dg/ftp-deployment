<?php

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (http://davidgrudl.com)
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
	 * Reads file from server.
	 * @return void
	 */
	function readFile($remote, $local);

	/**
	 * Uploads file to server.
	 * @return void
	 */
	function writeFile($local, $remote, callable $progress = NULL);

	/**
	 * Removes file from server if exists.
	 * @return void
	 */
	function removeFile($file);

	/**
	 * Renames and rewrites file on server.
	 * @return void
	 */
	function renameFile($old, $new);

	/**
	 * Creates directories on server.
	 * @return void
	 */
	function createDir($dir);

	/**
	 * Removes directory from server if exists.
	 * @return void
	 */
	function removeDir($dir);

	/**
	 * Recursive deletes content of directory or file.
	 * @param  string
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
