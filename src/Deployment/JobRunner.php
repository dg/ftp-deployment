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
	private Server $server;

	private string $localDir;

	private string $remoteDir;


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
		if (preg_match('#^(mkdir|rmdir|unlink|mv|chmod)\s+(\S+)(?:\s+(\S+))?()$#', $command, $m)) {
			[, $cmd, $a, $b] = $m;
			$a = '/' . ltrim($a, '/');
			$b = '/' . ltrim($b, '/');
			if ($cmd === 'mkdir') {
				$this->server->createDir($a);
			} elseif ($cmd === 'rmdir') {
				$this->server->removeDir($a);
			} elseif ($cmd === 'unlink') {
				$this->server->removeFile($a);
			} elseif ($cmd === 'mv') {
				$this->server->renameFile($a, $b);
			} elseif ($cmd === 'chmod') {
				$this->server->chmod($b, octdec($m[2]));
			}
			return [null, null];
		}

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


	public function http(string $url, bool $ignoreCert = false): array
	{
		$out = Helpers::fetchUrl($url, $err, null, $ignoreCert);
		return [$out, $err];
	}
}
