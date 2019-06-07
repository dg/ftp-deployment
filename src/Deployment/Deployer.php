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
	private const TEMPORARY_SUFFIX = '.deploytmp';

	/** @var string */
	public $deploymentFile = '.htdeployment';

	/** @var string[] */
	public $includeMasks = [];

	/** @var string[] */
	public $ignoreMasks = [];

	/** @var bool */
	public $testMode = false;

	/** @var bool */
	public $allowDelete = false;

	/** @var string[] relative paths */
	public $toPurge = [];

	/** @var array of string|callable */
	public $runBefore = [];

	/** @var array of string|callable */
	public $runAfterUpload = [];

	/** @var array of string|callable */
	public $runAfter = [];

	/** @var string */
	public $tempDir = '';

	/** @var string[] */
	public $preprocessMasks = [];

	/** @var string */
	private $localDir;

	/** @var string */
	private $remoteDir;

	/** @var Logger */
	private $logger;

	/** @var array */
	private $filters = [];

	/** @var Server */
	private $server;


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
	 *
	 * @param array|null $localPaths
	 *
	 * @throws ServerException
	 */
	public function deploy(array &$localPaths = null): void
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

		if ($localPaths === null) {
			$localPaths = $this->collectPaths();
		} else {
			$this->logger->log("Used cached scanning from $this->localDir");
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
			return;

		} elseif ($this->testMode) {
			$this->logger->log("\nUploading:\n" . implode("\n", $toUpload), 'green', 0);
			$this->logger->log("\nDeleting:\n" . implode("\n", $toDelete), 'maroon', 0);
			if (isset($deploymentFile)) {
				unlink($deploymentFile);
			}
			return;
		}

		$this->logger->log("Creating remote file $this->deploymentFile.running");
		$runningFile = "$this->remoteDir/$this->deploymentFile.running";
		$this->server->createDir(str_replace('\\', '/', dirname($runningFile)));
		$this->server->writeFile(tempnam($this->tempDir, 'deploy'), $runningFile);

		if ($runBefore[0]) {
			$this->logger->log("\nBefore-jobs:");
			$this->runJobs($runBefore[0]);
		}

		if ($toUpload) {
			$this->logger->log("\nUploading:");
			$this->uploadPaths($toUpload);
			if ($this->runAfterUpload) {
				$this->logger->log("\nAfter-upload-jobs:");
				$this->runJobs($this->runAfterUpload);
			}
			$this->logger->log("\nRenaming:");
			$this->renamePaths($toUpload);
			unlink($deploymentFile);
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
				$path = preg_match('#/(.{1,60})$#', $path, $m) ? $m[1] : substr(basename($path), 0, 60);
				$this->logger->progress(str_pad($path . ' ' . str_repeat('.', $counter++ % 30 + 60 - strlen($path)), 90));
			});
			$this->logger->progress(str_repeat(' ', 91));
		}

		if ($this->runAfter) {
			$this->logger->log("\nAfter-jobs:");
			$this->runJobs($this->runAfter);
		}

		$this->logger->log("\nDeleting remote file $this->deploymentFile.running");
		$this->server->removeFile($runningFile);
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
		$content = gzinflate(file_get_contents($tempFile));
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
		file_put_contents($file, gzdeflate($s, 9));
		return $file;
	}


	/**
	 * Uploades files and creates directories.
	 * @param  string[]  $paths  relative paths, starts with /
	 */
	private function uploadPaths(array $paths): void
	{
		$prevDir = null;
		foreach ($paths as $num => $path) {
			$remotePath = $this->remoteDir . $path;
			$isDir = substr($remotePath, -1) === '/';
			$remoteDir = $isDir ? substr($remotePath, 0, -1) : str_replace('\\', '/', dirname($remotePath));
			if ($remoteDir !== $prevDir) {
				$prevDir = $remoteDir;
				$this->server->createDir($remoteDir);
			}

			if ($isDir) {
				$this->writeProgress($num + 1, count($paths), $path, null, 'green');
				continue;
			}

			$localFile = $this->preprocess($path);
			if ($localFile !== $this->localDir . $path) {
				$path .= ' (filters applied)';
			}

			$this->server->writeFile(
				$localFile,
				$remotePath . self::TEMPORARY_SUFFIX,
				function ($percent) use ($num, $paths, $path) {
					$this->writeProgress($num + 1, count($paths), $path, $percent, 'green');
				}
			);
			$this->writeProgress($num + 1, count($paths), $path, null, 'green');
		}
	}


	/**
	 * Renames uploaded files.
	 * @param  string[]  $paths  relative paths, starts with /
	 */
	private function renamePaths(array $paths): void
	{
		$files = array_values(array_filter($paths, function ($path) { return substr($path, -1) !== '/'; }));
		foreach ($files as $num => $file) {
			$this->writeProgress($num + 1, count($files), "Renaming $file", null, 'olive');
			$remoteFile = $this->remoteDir . $file;
			$this->server->renameFile($remoteFile . self::TEMPORARY_SUFFIX, $remoteFile);
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
			if ($info['cached'] && is_file($tempFile = $this->tempDir . '/' . md5($content))) {
				$content = file_get_contents($tempFile);
			} else {
				$content = $info['filter']($content, $full);
				if ($info['cached']) {
					file_put_contents($tempFile, $content);
				}
			}
		}

		if (empty($info['cached'])) {
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
		foreach ($jobs as $job) {
			if (is_string($job) && preg_match('#^(https?|local|remote|upload):\s*(.+)#', $job, $m)) {
				$this->logger->log($job);
				$out = $err = null;
				if ($m[1] === 'local') {
					@exec($m[2], $out, $code);
					$out = trim(implode("\n", $out));
					$err = $code !== 0 ? "exit code $code" : null;

				} elseif ($m[1] === 'remote') {
					$out = $this->server->execute($m[2]);

				} elseif ($m[1] === 'upload') {
					[$localFile, $remotePath] = explode(' ', $m[2]);
					$localFile = $this->localDir . '/' . $localFile;
					if (!is_file($localFile)) {
						throw new \RuntimeException("File $localFile doesn't exist.");
					}
					$remotePath = $this->remoteDir . '/' . $remotePath;
					$this->server->createDir(str_replace('\\', '/', dirname($remotePath)));
					$this->server->writeFile($localFile, $remotePath);
				} else {
					$out = Helpers::fetchUrl($job, $err);
				}

				if ($out != null) { // intentionally ==
					$this->logger->log("-> $out", 'gray', -3);
				}
				if ($err) {
					throw new \RuntimeException('Job failed, ' . $err);
				}

			} elseif (is_callable($job)) {
				if ($job($this->server, $this->logger, $this) === false) {
					throw new \RuntimeException('Job failed');
				}

			} else {
				throw new \InvalidArgumentException("Invalid job $job, must start with http:, local:, remote: or upload:");
			}
		}
	}


	private function writeProgress(int $count, int $total, string $path, float $percent = null, string $color = null): void
	{
		$len = strlen((string) $total);
		$s = sprintf("(% {$len}d of %-{$len}d) %s", $count, $total, $path);
		if ($percent === null) {
			$this->logger->log($s, $color);
		} else {
			$this->logger->progress($s . ' [' . round($percent) . '%]');
		}
	}


	/**
	 * @return string
	 */
	public function getLocalDir(): string
	{
		return $this->localDir;
	}
}
