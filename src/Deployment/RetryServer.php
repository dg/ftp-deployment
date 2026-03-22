<?php declare(strict_types=1);

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (https://davidgrudl.com)
 */

namespace Deployment;


/**
 * Retry pseudo server.
 */
class RetryServer implements Server
{
	private const Retries = 20;
	private const Delay = 2;

	private Server $server;
	private Logger $logger;
	private ?InterruptHandler $interruptHandler;


	public function __construct(Server $server, Logger $logger, ?InterruptHandler $interruptHandler = null)
	{
		$this->server = $server;
		$this->logger = $logger;
		$this->interruptHandler = $interruptHandler;
	}


	public function connect(): void
	{
		$this->server->connect();
	}


	public function readFile(string $remote, string $local): void
	{
		$this->retry(__FUNCTION__, func_get_args());
	}


	public function writeFile(string $local, string $remote, ?callable $progress = null): void
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


	public function purge(string $path, ?callable $progress = null): void
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


	public function noRetry(string $method, mixed ...$args): void
	{
		$this->server->$method(...$args);
	}


	/**
	 * @param list<mixed>  $args
	 * @throws ServerException
	 */
	private function retry(string $method, array $args): mixed
	{
		$interruptible = in_array($method, ['writeFile', 'readFile', 'removeFile', 'renameFile', 'removeDir'], true);
		$lastError = '';
		for ($counter = 0; ; $counter++) {
			try {
				return $this->server->$method(...$args);

			} catch (ServerException $e) {
				if ($counter >= self::Retries) {
					throw $e;
				}
				if ($e->getMessage() !== $lastError) {
					$lastError = $e->getMessage();
					$this->logger->log("Error: $e", 'red');
				}

				if ($method !== 'connect') {
					$this->retry('connect', []); // first try to reconnect
				}

				$this->logger->progress('retrying ' . str_pad(str_repeat('.', ($counter + 1) % 40), 40));

				for ($i = 0; $i < self::Delay * 5; $i++) {
					usleep(200_000);
					if ($interruptible) {
						$this->interruptHandler?->check("Retry $method");
					}
				}
			}
		}
	}
}
