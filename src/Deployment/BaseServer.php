<?php declare(strict_types=1);

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (https://davidgrudl.com)
 */

namespace Deployment;


/**
 * Shared state for Server implementations.
 */
abstract class BaseServer implements Server
{
	/** permissions applied to uploaded files, or null to keep server defaults */
	public ?int $filePermissions = null;

	/** permissions applied to created directories, or null to keep server defaults */
	public ?int $dirPermissions = null;


	/**
	 * Runs a shell command when the SFTP subsystem refuses an operation the shell allows.
	 * @throws ServerException  the original one if the command fails too
	 */
	protected function executeFallback(string $command, ServerException $e): void
	{
		try {
			$ok = trim($this->execute($command . ' && echo OK')) === 'OK';
		} catch (ServerException) {
			$ok = false;
		}
		if (!$ok) {
			throw $e;
		}
	}
}
