<?php

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (https://davidgrudl.com)
 */

namespace Deployment;


/**
 * Synchronizes local and remote.
 */
class Deployer
{
	const TEMPORARY_SUFFIX = '.deploytmp';

	/** @var string */
	public $deploymentFile = '.htdeployment';

	/** @var string[] */
	public $includeMasks;

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


	/**
	 * @param  Server
	 * @param  string  local directory
	 */
	public function __construct(Server $server, $localDir, Logger $logger)
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
	 * @return void
	 */
	public function deploy()
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
		$localPaths = $this->collectPaths();

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
			$this->logger->log("\nUploading:\n" . implode("\n", $toUpload), 'green', false);
			$this->logger->log("\nDeleting:\n" . implode("\n", $toDelete), 'maroon', false);
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
				$path = substr($path, strlen($this->remoteDir));
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
	 * @param  string  file extension
	 * @param  callable
	 * @return void
	 */
	public function addFilter($extension, $filter, $cached = false)
	{
		$this->filters[$extension][] = ['filter' => $filter, 'cached' => $cached];
		return $this;
	}


	/**
	 * Downloads and decodes .htdeployment from the server.
	 * @return string[]|null  relative paths, starts with /
	 */
	private function loadDeploymentFile()
	{
		$tempFile = tempnam($this->tempDir, 'deploy');
		try {
			$this->server->readFile($this->remoteDir . '/' . $this->deploymentFile, $tempFile);
		} catch (ServerException $e) {
			return;
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
	 * @return string
	 */
	public function writeDeploymentFile($localPaths)
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
	 * @param  string[]  relative paths, starts with /
	 * @return void
	 */
	private function uploadPaths(array $paths)
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
	 * @param  string[]  relative paths, starts with /
	 * @return void
	 */
	private function renamePaths(array $paths)
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
	 * @param  string[]  relative paths, starts with /
	 * @return void
	 */
	private function deletePaths(array $paths)
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
	 * @param  string   relative subdir, starts with /
	 * @return string[] relative paths, starts with /
	 */
	public function collectPaths($subdir = '')
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

			} elseif (is_array($this->includeMasks) && ! Helpers::matchMask($short, $this->includeMasks, is_dir($path))) {
			    $this->logger->log(str_pad("Not into Includes .$short", 40), 'gray');
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
	 * @param  string  relative path, starts with /
	 * @return string  full path
	 */
	private function preprocess($file)
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
				$content = call_user_func($info['filter'], $content, $full);
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
	 * @param  array of string|callable
	 * @return void
	 */
	private function runJobs(array $jobs)
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
					list($localFile, $remotePath) = explode(' ', $m[2]);
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
					$this->logger->log("-> $out", 'gray');
				}
				if ($err) {
					throw new \RuntimeException('Job failed, ' . $err);
				}

			} elseif (is_callable($job)) {
				if ($job($this->server, $this->logger, $this) === false) {
					throw new \RuntimeException('Job failed');
				}

			} else {
				throw new \InvalidArgumentException("Invalid job $job.");
			}
		}
	}


	private function writeProgress($count, $total, $path, $percent = null, $color = null)
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
