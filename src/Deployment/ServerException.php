<?php

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Deployment;


class ServerException extends \Exception
{
	public function __construct(string $message, string $file = null, int $line = null)
	{
		parent::__construct($message);
		if ($file) {
			$this->file = $file;
			$this->line = $line;
		}
	}
}
