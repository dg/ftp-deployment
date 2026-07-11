<?php declare(strict_types=1);

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (https://davidgrudl.com)
 */

namespace Deployment;

use Nette\CommandLine\Ansi;


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

	/** Reuse cached local file hashes keyed by path+size+mtime to speed up rescans. */
	public bool $useHashCache = false;

	/** @var string[] relative paths */
	public array $toPurge = [];

	/** @var list<string|(callable(Server, Logger, Deployer): (false|void))> */
	public array $runBefore = [];

	/** @var list<string|(callable(Server, Logger, Deployer): (false|void))> */
	public array $runAfterUpload = [];

	/** @var list<string|(callable(Server, Logger, Deployer): (false|void))> */
	public array $runAfter = [];
	public string $tempDir = '';

	/** @var string[] */
	public array $preprocessMasks = [];

	private readonly string $localDir;
	private string $remoteDir;

	/** @var string[] temporary files to delete when the run finishes */
	private array $garbage = [];

	/** @var array<string, string> memoized preprocess() results, keyed by relative path */
	private array $preprocessed = [];

	/** @var array<string, array{size: int, mtime: int, hash: string}>|null lazily loaded hash cache */
	private ?array $hashCache = null;
	private bool $hashCacheModified = false;

	/** @var array<string, list<array{filter: callable(string, string): ?string, cached: bool, name: string}>> */
	private array $filters = [];


	public function __construct(
		private readonly Server $server,
		string $localDir,
		private readonly Logger $logger,
		private readonly ?InterruptHandler $interruptHandler = null,
	) {
		$path = realpath($localDir);
		if (!$path) {
			throw new \InvalidArgumentException("Directory $localDir not found.");
		}
		$this->localDir = $path;
	}


	/**
	 * Synchronize remote and local.
	 */
	public function deploy(): void
	{
		$this->logger->log('Connecting to server');
		$this->server->connect();
		$this->remoteDir = $this->server->getDir();

		$localBefore = $otherBefore = [];
		foreach ($this->runBefore as $job) {
			if (is_string($job) && preg_match('#^local:#', $job)) {
				$localBefore[] = $job;
			} else {
				$otherBefore[] = $job;
			}
		}

		if ($localBefore) {
			$this->logger->log("\nLocal-jobs:");
			$this->runJobs($localBefore);
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
		$key = serialize([$this->localDir, $this->ignoreMasks, $this->includeMasks, array_keys($this->filters), $this->preprocessMasks]);
		$localPaths = &$cache[$key];
		if ($localPaths === null) {
			$localPaths = $this->collectPaths();
		}

		unset($localPaths["/$this->deploymentFile"], $remotePaths["/$this->deploymentFile"]);
		$toDelete = $this->allowDelete ? array_keys(array_diff_key($remotePaths, $localPaths)) : [];
		$toUpload = array_keys(array_diff_assoc($localPaths, $remotePaths));

		if ($localPaths !== $remotePaths) { // ignores allowDelete
			$deploymentFile = $this->writeDeploymentFile($localPaths);
		}

		if (!$toUpload && !$toDelete && !isset($deploymentFile)) {
			$this->logger->log('Already synchronized.', 'lime');

			$runAfterLocal = array_values(array_filter($this->runAfter, fn($job) => is_string($job) && preg_match('#^local:#', $job)));
			if ($runAfterLocal) {
				$this->logger->log("\nLocal-after-jobs:");
				$this->runJobs($runAfterLocal);
			}
			$this->cleanup();
			return;

		} elseif ($this->testMode) {
			$this->logTestSummary($toUpload, $toDelete);
			if (isset($deploymentFile)) {
				unlink($deploymentFile);
			}
			$this->cleanup();
			return;
		}

		if ($otherBefore) {
			$this->logger->log("\nBefore-jobs:");
			$this->runJobs($otherBefore);
		}

		$failed = false;
		try {
			$tempFiles = [];
			$skippedPaths = [];
			if ($toUpload) {
				$this->logger->log("\nUploading:");
				$skippedPaths = $this->uploadPaths($toUpload, $tempFiles);
				$toUpload = array_values(array_diff($toUpload, $skippedPaths));
			}

			if (isset($deploymentFile)) {
				$adjustedPaths = $localPaths;
				$this->revertSkippedPaths($adjustedPaths, $skippedPaths, $remotePaths);
				$deploymentFile = $this->writeDeploymentFile($adjustedPaths);
				$toUpload[] = "/$this->deploymentFile";
				$tempFiles["/$this->deploymentFile" . self::TemporarySuffix] = true;
				$this->server->writeFile(
					$deploymentFile,
					$this->remoteDir . '/' . $this->deploymentFile . self::TemporarySuffix,
				);
			}

			if ($this->runAfterUpload) {
				$this->logger->log("\nAfter-upload-jobs:");
				$this->runJobs($this->runAfterUpload);
			}

			$this->logger->log("Creating remote file $this->deploymentFile.running");
			$runningFile = "$this->remoteDir/$this->deploymentFile.running";
			$this->server->createDir(str_replace('\\', '/', dirname($runningFile)));
			$this->server->writeFile($this->createTempFile(), $runningFile);

			if ($toUpload) {
				$this->logger->log("\nRenaming:");
				$skippedRenames = $this->renamePaths($toUpload, $tempFiles);
				// Skipped renames mean the .deploytmp file will be cleaned up by finally,
				// leaving the old version on server. Re-generate the deployment file to
				// reflect the actual server state, otherwise next deploy would skip these files.
				if ($skippedRenames && isset($adjustedPaths)) {
					$this->revertSkippedPaths($adjustedPaths, $skippedRenames, $remotePaths);
					$deploymentFile = $this->writeDeploymentFile($adjustedPaths);
					$this->server->writeFile(
						$deploymentFile,
						$this->remoteDir . '/' . $this->deploymentFile,
					);
				}
			}

			if ($toDelete) {
				$this->logger->log("\nDeleting:");
				$skippedDeletes = $this->deletePaths($toDelete);
				// Skipped deletes leave files on server that the deployment file
				// no longer tracks. Add them back so next deploy retries the deletion.
				if ($skippedDeletes && isset($adjustedPaths)) {
					$this->revertSkippedPaths($adjustedPaths, $skippedDeletes, $remotePaths);
					$deploymentFile = $this->writeDeploymentFile($adjustedPaths);
					$this->server->writeFile(
						$deploymentFile,
						$this->remoteDir . '/' . $this->deploymentFile,
					);
				}
			}

			foreach ($this->toPurge as $path) {
				$this->logger->log("\nCleaning $path");
				$this->server->purge($this->remoteDir . '/' . $path, function ($path) {
					static $counter;
					$path = (string) substr($path, strlen($this->remoteDir));
					$path = Ansi::truncate($path, 60, keepEnd: true); // the name of the file is at the end
					$this->logger->progress($path . ' ' . str_repeat('.', $counter++ % 30));
				});
				$this->logger->progress('');
			}

			if ($this->runAfter) {
				$this->logger->log("\nAfter-jobs:");
				$this->runJobs($this->runAfter);
			}

		} catch (\Throwable $e) {
			$failed = true;
			throw $e;

		} finally {
			// After a failure the connection may be broken, so clean up best-effort:
			// skip the full retry storm and never let a cleanup error mask the original one.
			if (isset($runningFile)) {
				$this->logger->log("\nDeleting remote file $this->deploymentFile.running");
				$this->removeRemoteFile($runningFile, bestEffort: $failed);
			}
			if (isset($deploymentFile)) {
				unlink($deploymentFile);
			}
			if ($tempFiles) {
				$this->logger->log("\nDeleting temporary files:");
				$this->deletePaths(array_keys($tempFiles), bestEffort: $failed);
			}
			$this->cleanup();
		}
	}


	/**
	 * Appends preprocessor for files.
	 * @param callable(string, string): ?string  $filter
	 */
	public function addFilter(string $extension, callable $filter, bool $cached = false): void
	{
		$r = new \ReflectionFunction(\Closure::fromCallable($filter));
		$name = $r->getClosureScopeClass()?->name . '::' . $r->name;
		$this->filters[$extension][] = ['filter' => $filter, 'cached' => $cached, 'name' => $name];
	}


	/**
	 * Downloads and decodes .htdeployment from the server.
	 * @return ?array<string, string|true> relative paths, starts with /
	 */
	private function loadDeploymentFile(): ?array
	{
		$tempFile = $this->createTempFile();
		try {
			if ($this->server instanceof RetryServer) {
				$this->server->noRetry('readFile', $this->remoteDir . '/' . $this->deploymentFile, $tempFile);
			} else {
				$this->server->readFile($this->remoteDir . '/' . $this->deploymentFile, $tempFile);
			}
		} catch (ServerException) {
			return null;
		}
		$s = file_get_contents($tempFile);
		if ($s === false) {
			return null;
		}
		$content = @gzinflate($s) ?: gzdecode($s); // @ the data may be gzip, not raw deflate
		if ($content === false) {
			return null;
		}
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
	 * @param array<string, string|true>  $localPaths
	 */
	public function writeDeploymentFile(array $localPaths): string
	{
		$s = '';
		foreach ($localPaths as $k => $v) {
			$s .= "$v=$k\n";
		}
		$file = $this->localDir . '/' . $this->deploymentFile;
		@mkdir(dirname($file), 0o777, true); // @ dir may exists
		file_put_contents($file, gzencode($s, 9));
		return $file;
	}


	/**
	 * Uploades files and creates directories.
	 * @param  string[]  $paths  relative paths, starts with /
	 * @param  array<string, true>  $tempFiles
	 * @return string[]  skipped paths
	 */
	private function uploadPaths(array $paths, array &$tempFiles): array
	{
		$skippedPaths = [];
		$prevDir = null;
		foreach ($paths as $num => $path) {
			try {
				$this->interruptHandler?->check("Upload $path");
			} catch (SkipException) {
				$skippedPaths[] = $path;
				continue;
			}

			$remotePath = $this->remoteDir . $path;
			$isDir = str_ends_with($remotePath, '/');
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
			$displayPath = $localFile !== $this->localDir . $path
				? $path . ' (filters applied)'
				: $path;

			try {
				$this->server->writeFile(
					$localFile,
					$remotePath . self::TemporarySuffix,
					// Interrupt check inside progress callback allows Ctrl+C mid-upload
					function ($percent) use ($num, $paths, $displayPath) {
						$this->writeProgress($num + 1, count($paths), $displayPath, $percent, 'green');
						$this->interruptHandler?->check("Upload $displayPath");
					},
				);
				$this->writeProgress($num + 1, count($paths), $displayPath, null, 'green');
			} catch (SkipException) {
				$skippedPaths[] = $path;
			}
		}

		return $skippedPaths;
	}


	/**
	 * Renames uploaded files.
	 * @param  string[]  $paths  relative paths, starts with /
	 * @param  array<string, true>  $tempFiles
	 * @return string[]  skipped paths
	 */
	private function renamePaths(array $paths, array &$tempFiles): array
	{
		$skippedPaths = [];
		$files = array_values(array_filter($paths, fn($path) => !str_ends_with($path, '/')));
		foreach ($files as $num => $file) {
			try {
				// Deployment file rename is never skippable to keep server state consistent
				if ($file !== "/$this->deploymentFile") {
					$this->interruptHandler?->check("Rename $file");
				}

				$this->writeProgress($num + 1, count($files), "Renaming $file", null, 'olive');
				$remoteFile = $this->remoteDir . $file;
				$this->server->renameFile($remoteFile . self::TemporarySuffix, $remoteFile);
			} catch (SkipException) {
				$skippedPaths[] = $file;
				continue; // skipped temp file stays in $tempFiles, cleaned up by finally block
			}
			unset($tempFiles[$file . self::TemporarySuffix]);
		}
		return $skippedPaths;
	}


	/**
	 * Deletes files and directories.
	 * @param  string[]  $paths  relative paths, starts with /
	 * @return string[]  skipped paths
	 */
	private function deletePaths(array $paths, bool $bestEffort = false): array
	{
		$skippedPaths = [];
		rsort($paths);
		foreach ($paths as $num => $path) {
			$remotePath = $this->remoteDir . $path;
			try {
				if (!$bestEffort) {
					$this->interruptHandler?->check("Delete $path");
				}
				$this->writeProgress($num + 1, count($paths), "Deleting $path", null, 'maroon');
				$method = str_ends_with($path, '/') ? 'removeDir' : 'removeFile'; // is directory?
				$this->serverCall($method, $remotePath, noRetry: $bestEffort);
			} catch (SkipException) {
				$skippedPaths[] = $path;
			} catch (ServerException) {
				$this->logger->log("Unable to delete $remotePath", 'red');
			}
		}
		return $skippedPaths;
	}


	/**
	 * Removes a remote file, optionally best-effort (no retries, errors swallowed).
	 */
	private function removeRemoteFile(string $file, bool $bestEffort): void
	{
		try {
			$this->serverCall('removeFile', $file, noRetry: $bestEffort);
		} catch (ServerException $e) {
			if (!$bestEffort) {
				throw $e;
			}
			$this->logger->log("Unable to delete $file", 'red');
		}
	}


	/**
	 * Invokes a single-argument server method, bypassing RetryServer when requested.
	 */
	private function serverCall(string $method, string $arg, bool $noRetry): void
	{
		if ($noRetry && $this->server instanceof RetryServer) {
			$this->server->noRetry($method, $arg);
		} else {
			$this->server->$method($arg);
		}
	}


	/**
	 * Scans directory.
	 * @param  string  $subdir  relative subdir, starts with /
	 * @return array<string, string|true> relative paths, starts with /
	 */
	public function collectPaths(string $subdir = ''): array
	{
		$list = [];
		$iterator = dir($this->localDir . $subdir);
		if (!$iterator) {
			throw new \RuntimeException("Cannot open directory {$this->localDir}{$subdir}");
		}
		$counter = 0;
		while (($entry = $iterator->read()) !== false) {
			$this->logger->progress(str_repeat('.', $counter++ % 40));

			$path = "$this->localDir$subdir/$entry";
			$short = "$subdir/$entry";
			if ($entry === '.' || $entry === '..') {
				continue;

			} elseif (Helpers::matchMask($short, $this->ignoreMasks, is_dir($path))) {
				$this->logger->log("Ignoring .$short", 'gray');
				continue;

			} elseif ($this->includeMasks && !Helpers::matchMask($short, $this->includeMasks, is_dir($path))) {
				$this->logger->log("Not included .$short", 'gray');
				continue;

			} elseif (is_dir($path)) {
				$list[$short . '/'] = true;
				$list += $this->collectPaths($short);

			} elseif (is_file($path)) {
				$list[$short] = $this->hashLocalFile($short, $path);
			}
		}
		$iterator->close();
		return $list;
	}


	/**
	 * Hashes a local file, reusing a cached hash when enabled and the file's
	 * size and mtime are unchanged. Preprocessed files are always hashed directly
	 * because their output may depend on imported files not captured by mtime.
	 * @param  string  $relPath   relative path, starts with /
	 * @param  string  $fullPath  absolute path on disk
	 */
	private function hashLocalFile(string $relPath, string $fullPath): string
	{
		if (!$this->useHashCache || $this->isPreprocessed($relPath)) {
			return Helpers::hashFile($this->preprocess($relPath));
		}

		$stat = @stat($fullPath); // @ - may be unreadable
		if ($stat !== false) {
			$this->loadHashCache();
			$entry = $this->hashCache[$fullPath] ?? null;
			if ($entry && $entry['size'] === $stat['size'] && $entry['mtime'] === $stat['mtime']) {
				return $entry['hash'];
			}
		}

		$hash = Helpers::hashFile($fullPath);
		if ($stat !== false) {
			$this->hashCache[$fullPath] = ['size' => $stat['size'], 'mtime' => $stat['mtime'], 'hash' => $hash];
			$this->hashCacheModified = true;
		}
		return $hash;
	}


	/**
	 * Whether a file is transformed by a registered preprocessor.
	 * @param  string  $file  relative path, starts with /
	 */
	private function isPreprocessed(string $file): bool
	{
		$ext = pathinfo($file, PATHINFO_EXTENSION);
		return isset($this->filters[$ext]) && Helpers::matchMask($file, $this->preprocessMasks);
	}


	private function loadHashCache(): void
	{
		if ($this->hashCache !== null) {
			return;
		}
		$file = $this->tempDir . '/.hashcache';
		$data = is_file($file) ? @unserialize((string) file_get_contents($file)) : null; // @ a corrupted cache is rebuilt
		$this->hashCache = is_array($data) ? $data : [];
	}


	private function saveHashCache(): void
	{
		if ($this->hashCacheModified && $this->hashCache !== null && $this->tempDir !== '') {
			file_put_contents($this->tempDir . '/.hashcache', serialize($this->hashCache));
			$this->hashCacheModified = false;
		}
	}


	/**
	 * Calls preprocessors on file, memoizing the result for the run.
	 * The same file is preprocessed for both hashing (collectPaths) and uploading,
	 * so caching avoids running non-cached filters twice and creating duplicate temp files.
	 * @param  string  $file  relative path, starts with /
	 * @return string  full path
	 */
	private function preprocess(string $file): string
	{
		return $this->preprocessed[$file] ??= $this->doPreprocess($file);
	}


	/**
	 * @param  string  $file  relative path, starts with /
	 * @return string  full path
	 */
	private function doPreprocess(string $file): string
	{
		$ext = pathinfo($file, PATHINFO_EXTENSION);
		if (!isset($this->filters[$ext]) || !Helpers::matchMask($file, $this->preprocessMasks)) {
			return $this->localDir . $file;
		}

		$full = $this->localDir . str_replace('/', DIRECTORY_SEPARATOR, $file);
		$content = file_get_contents($full);
		if ($content === false) {
			return $this->localDir . $file;
		}

		foreach ($this->filters[$ext] as $info) {
			$cacheFile = $info['cached']
				? $this->tempDir . '/' . md5($content . $info['name'])
				: null;

			if ($cacheFile && is_file($cacheFile)) {
				$content = (string) file_get_contents($tempFile = $cacheFile);
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
			$tempFile = $this->createTempFile();
			file_put_contents($tempFile, $content);
		}
		return $tempFile;
	}


	/**
	 * Creates a temporary file that is deleted when the run finishes.
	 */
	private function createTempFile(): string
	{
		$file = tempnam($this->tempDir, 'deploy');
		if ($file === false) {
			throw new \RuntimeException("Unable to create a temporary file in '$this->tempDir'.");
		}
		return $this->garbage[] = $file;
	}


	/**
	 * Deletes temporary files created during the run.
	 */
	public function cleanup(): void
	{
		foreach ($this->garbage as $file) {
			@unlink($file); // @ - file may have been moved or already removed
		}
		$this->garbage = [];
		$this->preprocessed = [];
		$this->saveHashCache();
	}


	/**
	 * @param list<string|(callable(Server, Logger, Deployer): (false|void))>  $jobs
	 */
	private function runJobs(array $jobs): void
	{
		$runner = new JobRunner($this->server, $this->localDir, $this->remoteDir);

		foreach ($jobs as $job) {
			$jobName = is_string($job) ? $job : 'callable';
			try {
				$this->interruptHandler?->check("Job $jobName");

				if (is_string($job) && preg_match('#^(https?|local|remote|upload|download):\s*(.+)#', $job, $m)) {
					$this->logger->log($job);
					$method = $m[1];
					if ($method === 'http' || $method === 'https') {
						[$out, $err] = $runner->http($m[0]);
					} else {
						[$out, $err] = $runner->$method($m[2]);
					}

					if ($out != null) { // intentionally !=, skips null and empty string
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
					throw new \InvalidArgumentException("Invalid job $job, must start with http:, local:, remote:, upload: or download:");
				}
			} catch (SkipException) {
				// already recorded by InterruptHandler::check()
			}
		}
	}


	/**
	 * Reverts skipped paths to their remote state in the deployment file data.
	 * For existing files, restores the old hash. For new files (not on server), removes them.
	 * @param  array<string, string|true>  $adjustedPaths
	 * @param  string[]  $skippedPaths
	 * @param  array<string, string|true>  $remotePaths
	 */
	private function revertSkippedPaths(array &$adjustedPaths, array $skippedPaths, array $remotePaths): void
	{
		foreach ($skippedPaths as $path) {
			if (isset($remotePaths[$path])) {
				$adjustedPaths[$path] = $remotePaths[$path];
			} else {
				unset($adjustedPaths[$path]);
			}
		}
	}


	/**
	 * Prints a human-readable summary of pending changes in test mode.
	 * @param  string[]  $toUpload
	 * @param  string[]  $toDelete
	 */
	private function logTestSummary(array $toUpload, array $toDelete): void
	{
		$files = array_filter($toUpload, fn($p) => !str_ends_with($p, '/'));
		$dirs = array_filter($toUpload, fn($p) => str_ends_with($p, '/'));
		$totalSize = 0;
		foreach ($files as $p) {
			$size = @filesize($this->localDir . $p); // @ - file may be unreadable
			if ($size !== false) {
				$totalSize += $size;
			}
		}

		$summary = count($files) . ' file(s)'
			. ($dirs ? ', ' . count($dirs) . ' new dir(s)' : '')
			. ' (' . Helpers::formatSize($totalSize) . ')';
		$this->logger->log("\nUploading" . ($toUpload ? " $summary:" : ' nothing'), 'green');
		if ($toUpload) {
			$this->logger->log(implode("\n", $toUpload), 'green', 0);
		}

		$this->logger->log("\nDeleting" . ($toDelete ? ' ' . count($toDelete) . ' item(s):' : ' nothing'), 'maroon');
		if ($toDelete) {
			$this->logger->log(implode("\n", $toDelete), 'maroon', 0);
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
