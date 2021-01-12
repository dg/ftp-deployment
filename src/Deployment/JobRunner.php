<?php

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Deployment;


class JobRunner
{
	/** @var Server */
	private $server;

	/** @var string */
	private $localDir;

	/** @var string */
	private $remoteDir;


	public function __construct(Server $server, string $localDir, string $remoteDir)
	{
		$this->server = $server;
		$this->localDir = $localDir;
		$this->remoteDir = $remoteDir;
	}


	public function local(string $command): array
	{
		@exec($command, $out, $code);
		$out = trim(implode("\n", $out));
		$err = $code !== 0 ? "exit code $code" : null;
		return [$out, $err];
	}


	public function remote(string $command): array
	{
		$out = $this->server->execute($command);
		return [$out, null];
	}


	public function download(string $command): array
	{
		[$remotePath, $localFile] = explode(' ', $command);
		$localFile = $this->localDir . '/' . $localFile;
		if (is_file($localFile)) {
			throw new JobException("File $localFile already exist.");
		}
		$remotePath = $this->remoteDir . '/' . $remotePath;
		$this->server->readFile($remotePath, $localFile);
		return [null, null];
	}


	public function upload(string $command): array
	{
		[$localFile, $remotePath] = explode(' ', $command);
		$localFile = $this->localDir . '/' . $localFile;
		if (!is_file($localFile)) {
			throw new JobException("File $localFile doesn't exist.");
		}
		$remotePath = $this->remoteDir . '/' . $remotePath;
		$this->server->createDir(str_replace('\\', '/', dirname($remotePath)));
		$this->server->writeFile($localFile, $remotePath);
		return [null, null];
	}


	public function http(string $url): array
	{
		$out = Helpers::fetchUrl($url, $err);
		return [$out, $err];
	}
}
