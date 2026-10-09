<?php declare(strict_types=1);

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (https://davidgrudl.com)
 */

namespace Deployment;


class JobRunner
{
	public function __construct(
		private readonly Server $server,
		private readonly string $localDir,
		private readonly string $remoteDir,
	) {
	}


	/** @return array{?string, ?string} */
	public function local(string $command): array
	{
		@exec($command, $out, $code); // @ the process may fail to start
		$out = trim(implode("\n", $out));
		$err = $code !== 0 ? "exit code $code" : null;
		return [$out, $err];
	}


	/** @return array{?string, ?string} */
	public function remote(string $command): array
	{
		if (preg_match('#^(mkdir|rmdir|unlink|mv|chmod)\s+(\S+)(?:\s+(\S+))?()$#', $command, $m)) {
			[, $cmd, $a, $b] = $m;
			$a = $this->resolveRemotePath($a);
			$b = $this->resolveRemotePath($b);
			if ($cmd === 'mkdir') {
				$this->server->createDir($a);
			} elseif ($cmd === 'rmdir') {
				$this->server->removeDir($a);
			} elseif ($cmd === 'unlink') {
				$this->server->removeFile($a);
			} elseif ($cmd === 'mv') {
				$this->server->renameFile($a, $b);
			} else { // chmod
				$this->server->chmod($b, (int) octdec($m[2]));
			}
			return [null, null];
		}

		$out = $this->server->execute($command);
		return [$out, null];
	}


	/** @return array{?string, ?string} */
	public function download(string $command): array
	{
		[$remotePath, $localFile] = $this->parseArgs($command, 'download');
		$localFile = $this->localDir . '/' . $localFile;
		if (is_file($localFile)) {
			throw new JobException("File $localFile already exist.");
		}
		$remotePath = $this->remoteDir . '/' . $remotePath;
		$this->server->readFile($remotePath, $localFile);
		return [null, null];
	}


	/** @return array{?string, ?string} */
	public function upload(string $command): array
	{
		[$localFile, $remotePath] = $this->parseArgs($command, 'upload');
		$localFile = $this->localDir . '/' . $localFile;
		if (!is_file($localFile)) {
			throw new JobException("File $localFile doesn't exist.");
		}
		$remotePath = $this->remoteDir . '/' . $remotePath;
		$this->server->createDir(str_replace('\\', '/', dirname($remotePath)));
		$this->server->writeFile($localFile, $remotePath);
		return [null, null];
	}


	/** @return array{?string, ?string} */
	public function http(string $url): array
	{
		$out = Helpers::fetchUrl($url, $err);
		return [$out, $err];
	}


	/**
	 * Splits a job command into exactly two arguments, honouring double quotes
	 * so that paths may contain spaces, e.g. upload: "my file.txt" remote/path.
	 * @return array{string, string}
	 * @throws JobException
	 */
	private function parseArgs(string $command, string $job): array
	{
		$args = array_values(array_filter(
			str_getcsv($command, ' ', '"', ''),
			fn($s) => $s !== null && $s !== '',
		));
		if (count($args) !== 2) {
			throw new JobException("Invalid $job job '$command', expected two paths (quote paths containing spaces).");
		}
		return [$args[0], $args[1]];
	}


	private function resolveRemotePath(string $path): string
	{
		return str_starts_with($path, '/')
			? $path
			: $this->remoteDir . '/' . $path;
	}
}
