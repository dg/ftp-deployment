<?php

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Deployment;


/**
 * Synchronizes local and remote.
 */
class Deployer
{
	private const TemporarySuffix = '.deploytmp';

	public string $deploymentFile = '.htdeployment';

	/** @var string[] */
	public array $includeMasks = [];

	/** @var string[] */
	public array $ignoreMasks = [];
	public bool $testMode = false;
	public bool $allowDelete = false;

	/** @var string[] relative paths */
	public array $toPurge = [];

	/** @var array of string|callable */
	public array $runBefore = [];

	/** @var array of string|callable */
	public array $runAfterUpload = [];

	/** @var array of string|callable */
	public array $runAfter = [];
	public string $tempDir = '';

	/** @var string[] */
	public array $preprocessMasks = [];

	private string $localDir;
	private string $remoteDir;
	private Logger $logger;
	private array $filters = [];
	private Server $server;


	public function __construct(Server $server, string $localDir, Logger $logger)
	{
		$this->localDir = realpath($localDir);
		if (!$this->localDir) {
			throw new \InvalidArgumentException("Directory $localDir not found.");
		}
		$this->server = $server;
		$this->logger = $logger;
	}


	/**
	 * Synchronize remote and local.
	 */
	public function deploy(): void
	{
		$this->logger->log('Connecting to server');
		$this->server->connect();
		$this->remoteDir = $this->server->getDir();

		$runBefore = [null, null];
		foreach ($this->runBefore as $job) {
			$runBefore[is_string($job) && preg_match('#^local:#', $job)][] = $job;
		}

		if ($runBefore[1]) {
			$this->logger->log("\nLocal-jobs:");
			$this->runJobs($runBefore[1]);
			$this->logger->log('');
		}

		$remotePaths = $this->loadDeploymentFile();
		if (is_array($remotePaths)) {
			$this->logger->log("Loaded remote $this->deploymentFile file");
		} else {
			$this->logger->log("Remote $this->deploymentFile file not found");
			$remotePaths = [];
		}

		$this->logger->log("Scanning files in $this->localDir");
		static $cache;
		$localPaths = &$cache[serialize([$this->localDir, $this->ignoreMasks, $this->includeMasks, $this->filters, $this->preprocessMasks])];
		if ($localPaths === null) {
			$localPaths = $this->collectPaths();
		}

		unset($localPaths["/$this->deploymentFile"], $remotePaths["/$this->deploymentFile"]);
		$toDelete = $this->allowDelete ? array_keys(array_diff_key($remotePaths, $localPaths)) : [];
		$toUpload = array_keys(array_diff_assoc($localPaths, $remotePaths));

		if ($localPaths !== $remotePaths) { // ignores allowDelete
			$deploymentFile = $this->writeDeploymentFile($localPaths);
			$toUpload[] = "/$this->deploymentFile"; // must be last
		}

		if (!$toUpload && !$toDelete) {
			$this->logger->log('Already synchronized.', 'lime');

			$runAfterLocal = array_filter($this->runAfter, fn($job) => is_string($job) && preg_match('#^local:#', $job));
			if ($runAfterLocal) {
				$this->logger->log("\nLocal-after-jobs:");
				$this->runJobs($runAfterLocal);
			}
			return;

		} elseif ($this->testMode) {
			$this->logger->log("\nUploading:\n" . implode("\n", $toUpload), 'green', 0);
			$this->logger->log("\nDeleting:\n" . implode("\n", $toDelete), 'maroon', 0);
			if (isset($deploymentFile)) {
				unlink($deploymentFile);
			}
			return;
		}

		if ($runBefore[0]) {
			$this->logger->log("\nBefore-jobs:");
			$this->runJobs($runBefore[0]);
		}

		try {
			$tempFiles = [];
			if ($toUpload) {
				$this->logger->log("\nUploading:");
				$this->uploadPaths($toUpload, $tempFiles);
				if ($this->runAfterUpload) {
					$this->logger->log("\nAfter-upload-jobs:");
					$this->runJobs($this->runAfterUpload);
				}
			}

			$this->logger->log("Creating remote file $this->deploymentFile.running");
			$runningFile = "$this->remoteDir/$this->deploymentFile.running";
			$this->server->createDir(str_replace('\\', '/', dirname($runningFile)));
			$this->server->writeFile(tempnam($this->tempDir, 'deploy'), $runningFile);

			if ($toUpload) {
				$this->logger->log("\nRenaming:");
				$this->renamePaths($toUpload, $tempFiles);
			}

			if ($toDelete) {
				$this->logger->log("\nDeleting:");
				$this->deletePaths($toDelete);
			}

			foreach ($this->toPurge as $path) {
				$this->logger->log("\nCleaning $path");
				$this->server->purge($this->remoteDir . '/' . $path, function ($path) {
					static $counter;
					$path = (string) substr($path, strlen($this->remoteDir));
					$path = preg_match('#/(.{1,60})$#', $path, $m)
						? $m[1]
						: substr(basename($path), 0, 60);
					$this->logger->progress(str_pad($path . ' ' . str_repeat('.', $counter++ % 30 + 60 - strlen($path)), 90));
				});
				$this->logger->progress(str_repeat(' ', 91));
			}

			if ($this->runAfter) {
				$this->logger->log("\nAfter-jobs:");
				$this->runJobs($this->runAfter);
			}

		} finally {
			if (isset($runningFile)) {
				$this->logger->log("\nDeleting remote file $this->deploymentFile.running");
				$this->server->removeFile($runningFile);
			}
			if (isset($deploymentFile)) {
				unlink($deploymentFile);
			}
			if ($tempFiles) {
				$this->logger->log("\nDeleting temporary files:");
				$this->deletePaths(array_keys($tempFiles));
			}
		}
	}


	/**
	 * Appends preprocessor for files.
	 */
	public function addFilter(string $extension, callable $filter, bool $cached = false): void
	{
		$this->filters[$extension][] = ['filter' => $filter, 'cached' => $cached];
	}


	/**
	 * Downloads and decodes .htdeployment from the server.
	 * @return string[]|null  relative paths, starts with /
	 */
	private function loadDeploymentFile(): ?array
	{
		$tempFile = tempnam($this->tempDir, 'deploy');
		try {
			if ($this->server instanceof RetryServer) {
				$this->server->noRetry('readFile', $this->remoteDir . '/' . $this->deploymentFile, $tempFile);
			} else {
				$this->server->readFile($this->remoteDir . '/' . $this->deploymentFile, $tempFile);
			}
		} catch (ServerException $e) {
			return null;
		}
		$s = file_get_contents($tempFile);
		$content = @gzinflate($s) ?: gzdecode($s);
		$res = [];
		foreach (explode("\n", $content) as $item) {
			if (count($item = explode('=', $item, 2)) === 2) {
				$res[$item[1]] = $item[0] === '1' ? true : $item[0];
			}
		}
		return $res;
	}


	/**
	 * Prepares .htdeployment for upload.
	 */
	public function writeDeploymentFile(array $localPaths): string
	{
		$s = '';
		foreach ($localPaths as $k => $v) {
			$s .= "$v=$k\n";
		}
		$file = $this->localDir . '/' . $this->deploymentFile;
		@mkdir(dirname($file), 0777, true); // @ dir may exists
		file_put_contents($file, gzencode($s, 9));
		return $file;
	}


	/**
	 * Uploades files and creates directories.
	 * @param  string[]  $paths  relative paths, starts with /
	 */
	private function uploadPaths(array $paths, array &$tempFiles): void
	{
		$prevDir = null;
		foreach ($paths as $num => $path) {
			$remotePath = $this->remoteDir . $path;
			$isDir = substr($remotePath, -1) === '/';
			$remoteDir = $isDir
				? substr($remotePath, 0, -1)
				: str_replace('\\', '/', dirname($remotePath));
			if ($remoteDir !== $prevDir) {
				$prevDir = $remoteDir;
				$this->server->createDir($remoteDir);
			}

			if ($isDir) {
				$this->writeProgress($num + 1, count($paths), $path, null, 'green');
				continue;
			}

			$tempFiles[$path . self::TemporarySuffix] = true;
			$localFile = $this->preprocess($path);
			if ($localFile !== $this->localDir . $path) {
				$path .= ' (filters applied)';
			}

			$this->server->writeFile(
				$localFile,
				$remotePath . self::TemporarySuffix,
				function ($percent) use ($num, $paths, $path) {
					$this->writeProgress($num + 1, count($paths), $path, $percent, 'green');
				},
			);
			$this->writeProgress($num + 1, count($paths), $path, null, 'green');
		}
	}


	/**
	 * Renames uploaded files.
	 * @param  string[]  $paths  relative paths, starts with /
	 */
	private function renamePaths(array $paths, array &$tempFiles): void
	{
		$files = array_values(array_filter($paths, fn($path) => substr($path, -1) !== '/'));
		foreach ($files as $num => $file) {
			$this->writeProgress($num + 1, count($files), "Renaming $file", null, 'olive');
			$remoteFile = $this->remoteDir . $file;
			$this->server->renameFile($remoteFile . self::TemporarySuffix, $remoteFile);
			unset($tempFiles[$file . self::TemporarySuffix]);
		}
	}


	/**
	 * Deletes files and directories.
	 * @param  string[]  $paths  relative paths, starts with /
	 */
	private function deletePaths(array $paths): void
	{
		rsort($paths);
		foreach ($paths as $num => $path) {
			$remotePath = $this->remoteDir . $path;
			$this->writeProgress($num + 1, count($paths), "Deleting $path", null, 'maroon');
			try {
				if (substr($path, -1) === '/') { // is directory?
					$this->server->removeDir($remotePath);
				} else {
					$this->server->removeFile($remotePath);
				}
			} catch (ServerException $e) {
				$this->logger->log("Unable to delete $remotePath", 'red');
			}
		}
	}


	/**
	 * Scans directory.
	 * @param  string   $subdir  relative subdir, starts with /
	 * @return string[] relative paths, starts with /
	 */
	public function collectPaths(string $subdir = ''): array
	{
		$list = [];
		$iterator = dir($this->localDir . $subdir);
		$counter = 0;
		while (($entry = $iterator->read()) !== false) {
			$this->logger->progress(str_pad(str_repeat('.', $counter++ % 40), 40));

			$path = "$this->localDir$subdir/$entry";
			$short = "$subdir/$entry";
			if ($entry == '.' || $entry == '..') {
				continue;

			} elseif (Helpers::matchMask($short, $this->ignoreMasks, is_dir($path))) {
				$this->logger->log(str_pad("Ignoring .$short", 40), 'gray');
				continue;

			} elseif ($this->includeMasks && !Helpers::matchMask($short, $this->includeMasks, is_dir($path))) {
				$this->logger->log(str_pad("Not included .$short", 40), 'gray');
				continue;

			} elseif (is_dir($path)) {
				$list[$short . '/'] = true;
				$list += $this->collectPaths($short);

			} elseif (is_file($path)) {
				$list[$short] = Helpers::hashFile($this->preprocess($short));
			}
		}
		$iterator->close();
		return $list;
	}


	/**
	 * Calls preprocessors on file.
	 * @param  string  $file  relative path, starts with /
	 * @return string  full path
	 */
	private function preprocess(string $file): string
	{
		$ext = pathinfo($file, PATHINFO_EXTENSION);
		if (!isset($this->filters[$ext]) || !Helpers::matchMask($file, $this->preprocessMasks)) {
			return $this->localDir . $file;
		}

		$full = $this->localDir . str_replace('/', DIRECTORY_SEPARATOR, $file);
		$content = file_get_contents($full);

		foreach ($this->filters[$ext] as $info) {
			is_callable($info['filter'], false, $callable_name);
			$cacheFile = $info['cached']
				? $this->tempDir . '/' . md5($content . $callable_name)
				: null;

			if ($cacheFile && is_file($cacheFile)) {
				$content = file_get_contents($tempFile = $cacheFile);
			} else {
				$res = $info['filter']($content, $full);
				if ($res !== null) {
					$content = $res;
					if ($cacheFile) {
						file_put_contents($tempFile = $cacheFile, $content);
					} else {
						$tempFile = null;
					}
				}
			}
		}

		if (empty($tempFile)) {
			$tempFile = tempnam($this->tempDir, 'deploy');
			file_put_contents($tempFile, $content);
		}
		return $tempFile;
	}


	/**
	 * @param  array  $jobs  string|callable
	 */
	private function runJobs(array $jobs): void
	{
		$runner = new JobRunner($this->server, $this->localDir, $this->remoteDir);

		foreach ($jobs as $job) {
			if (is_string($job) && preg_match('#^(https?|local|remote|upload|download):\s*(.+)#', $job, $m)) {
				$this->logger->log($job);
				$method = $m[1];
				if ($method === 'http' || $method === 'https') {
					[$out, $err] = $runner->http($m[0]);
				} else {
					[$out, $err] = $runner->$method($m[2]);
				}

				if ($out != null) { // intentionally ==
					$this->logger->log("-> $out", 'gray', -3);
				}
				if ($err) {
					throw new JobException('Job failed, ' . $err);
				}

			} elseif (is_callable($job)) {
				if ($job($this->server, $this->logger, $this) === false) {
					throw new JobException('Job failed');
				}

			} else {
				throw new \InvalidArgumentException("Invalid job $job, must start with http:, local:, remote: or upload:");
			}
		}
	}


	private function writeProgress(
		int $count,
		int $total,
		string $path,
		?float $percent = null,
		?string $color = null,
	): void
	{
		$len = strlen((string) $total);
		$s = sprintf("(% {$len}d of %-{$len}d) %s", $count, $total, $path);
		if ($percent === null) {
			$this->logger->log($s, $color);
		} else {
			$this->logger->progress($s . ' [' . round($percent) . '%]');
		}
	}
}
