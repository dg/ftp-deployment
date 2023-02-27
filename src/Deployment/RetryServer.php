<?php

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Deployment;


/**
 * Retry pseudo server.
 */
class RetryServer implements Server
{
	private const RETRIES = 20;
	private const DELAY = 2;

	private Server $server;
	private Logger $logger;


	public function __construct(Server $server, Logger $logger)
	{
		$this->server = $server;
		$this->logger = $logger;
	}


	public function connect(): void
	{
		$this->server->connect();
	}


	public function readFile(string $remote, string $local): void
	{
		$this->retry(__FUNCTION__, func_get_args());
	}


	public function writeFile(string $local, string $remote, callable $progress = null): void
	{
		$this->retry(__FUNCTION__, func_get_args());
	}


	public function removeFile(string $file): void
	{
		$this->retry(__FUNCTION__, func_get_args());
	}


	public function renameFile(string $old, string $new): void
	{
		$this->retry(__FUNCTION__, func_get_args());
	}


	public function createDir(string $dir): void
	{
		$this->retry(__FUNCTION__, func_get_args());
	}


	public function removeDir(string $dir): void
	{
		$this->retry(__FUNCTION__, func_get_args());
	}


	public function purge(string $path, callable $progress = null): void
	{
		$this->retry(__FUNCTION__, func_get_args());
	}


	public function chmod(string $path, int $permissions): void
	{
		$this->retry(__FUNCTION__, func_get_args());
	}


	public function getDir(): string
	{
		return $this->retry(__FUNCTION__, func_get_args());
	}


	public function execute(string $command): string
	{
		return $this->retry(__FUNCTION__, func_get_args());
	}


	public function noRetry(string $method, ...$args)
	{
		$this->server->$method(...$args);
	}


	/**
	 * @return mixed
	 * @throws ServerException
	 */
	private function retry(string $method, array $args)
	{
		$counter = 0;
		$lastError = null;
		retry:
		try {
			return $this->server->$method(...$args);

		} catch (ServerException $e) {
			if ($counter < self::RETRIES) {
				if ($e->getMessage() !== $lastError) {
					$lastError = $e->getMessage();
					$this->logger->log("Error: $e", 'red');
				}

				if ($method !== 'connect') {
					$this->retry('connect', []); // first try to reconnect
				}

				$counter++;
				$this->logger->progress('retrying ' . str_pad(str_repeat('.', $counter % 40), 40));

				sleep(self::DELAY);
				goto retry;
			}
			throw $e;
		}
	}
}
