<?php declare(strict_types=1);

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (https://davidgrudl.com)
 */

namespace Deployment;


/**
 * The server holds the .running lock of another deployment.
 */
class LockedException extends ServerException
{
}
