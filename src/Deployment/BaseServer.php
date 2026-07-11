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
}
