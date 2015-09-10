<?php

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (http://davidgrudl.com)
 */

namespace Deployment;


/**
 * Synchronizes local and remote.
 *
 * @author     David Grudl
 */
class Deployer
{
	const TEMPORARY_SUFFIX = '.deploytmp';

	/** @var string */
	public $deploymentFile = '.htdeployment';

	/** @var array */
	public $ignoreMasks = [];

	/** @var bool */
	public $testMode = FALSE;

	/** @var bool */
	public $allowDelete = FALSE;

	/** @var array */
	public $toPurge;

	/** @var array */
	public $runBefore;

	/** @var array */
	public $runAfter;

	/** @var string */
	public $tempDir = '';

	/** @var string */
	private $local;

	/** @var Logger */
	private $logger;

	/** @var array */
	public $preprocessMasks = [];

	/** @var array */
	private $filters;

	/** @var Server */
	private $server;



	/**
	 * @param  Server
	 * @param  string  local directory
	 */
	public function __construct(Server $server, $local, Logger $logger)
	{
		$this->local = realpath($local);
		if (!$this->local) {
			throw new \InvalidArgumentException("Directory $local not found.");
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
		$this->logger->log("Connecting to server");
		$this->server->connect();

		$runBefore = [NULL, NULL];
		foreach ($this->runBefore as $job) {
			$runBefore[is_string($job) && preg_match('#^local:#', $job)][] = $job;
		}

		if ($runBefore[1]) {
			$this->logger->log("\nLocal-jobs:");
			$this->runJobs($runBefore[1]);
			$this->logger->log('');
		}

		$remoteFiles = $this->loadDeploymentFile();
		if (is_array($remoteFiles)) {
			$this->logger->log("Loaded remote $this->deploymentFile file");
		} else {
			$this->logger->log("Remote $this->deploymentFile file not found");
			$remoteFiles = [];
		}

		$this->logger->log("Scanning files in $this->local");
		$localFiles = $this->collectFiles();

		unset($localFiles["/$this->deploymentFile"], $remoteFiles["/$this->deploymentFile"]);
		$toDelete = $this->allowDelete ? array_keys(array_diff_key($remoteFiles, $localFiles)) : [];
		$toUpload = array_keys(array_diff_assoc($localFiles, $remoteFiles));

		if ($localFiles !== $remoteFiles) { // ignores allowDelete
			$deploymentFile = $this->writeDeploymentFile($localFiles);
			$toUpload[] = "/$this->deploymentFile"; // must be last
		}

		if (!$toUpload && !$toDelete) {
			$this->logger->log('Already synchronized.', 'lime');
			return;

		} elseif ($this->testMode) {
			$this->logger->log("\nUploading:\n" . implode("\n", $toUpload), 'green', FALSE);
			$this->logger->log("\nDeleting:\n" . implode("\n", $toDelete), 'maroon', FALSE);
			if (isset($deploymentFile)) {
				unlink($deploymentFile);
			}
			return;
		}

		$this->logger->log("Creating remote file $this->deploymentFile.running");
		$root = $this->server->getDir();
		$runningFile = "$root/$this->deploymentFile.running";
		$this->server->createDir(str_replace('\\', '/', dirname($runningFile)));
		$this->server->writeFile(tempnam($this->tempDir, 'deploy'), $runningFile);

		if ($runBefore[0]) {
			$this->logger->log("\nBefore-jobs:");
			$this->runJobs($runBefore[0]);
		}

		if ($toUpload) {
			$this->logger->log("\nUploading:");
			$this->uploadFiles($toUpload);
			unlink($deploymentFile);
		}

		if ($toDelete) {
			$this->logger->log("\nDeleting:");
			$this->deleteFiles($toDelete);
		}

		foreach ((array) $this->toPurge as $path) {
			$this->logger->log("\nCleaning $path");
			$this->server->purge($root . '/' . $path, function($file) use ($root) {
				static $counter;
				$file = substr($file, strlen($root));
				$file = preg_match('#/(.{1,60})$#', $file, $m) ? $m[1] : substr(basename($file), 0, 60);
				echo str_pad($file . ' ' . str_repeat('.', $counter++ % 30 + 60 - strlen($file)), 90), "\x0D";
			});
			echo str_repeat(' ', 91) . "\x0D";
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
	public function addFilter($extension, $filter, $cached = FALSE)
	{
		$this->filters[$extension][] = ['filter' => $filter, 'cached' => $cached];
		return $this;
	}


	/**
	 * Downloads and decodes .htdeployment from the server.
	 * @return void
	 */
	private function loadDeploymentFile()
	{
		$root = $this->server->getDir();
		$tempFile = tempnam($this->tempDir, 'deploy');
		try {
			$this->server->readFile($root . '/' . $this->deploymentFile, $tempFile);
		} catch (ServerException $e) {
			return FALSE;
		}
		$content = gzinflate(file_get_contents($tempFile));
		$res = [];
		foreach (explode("\n", $content) as $item) {
			if (count($item = explode('=', $item, 2)) === 2) {
				$res[$item[1]] = $item[0] === '1' ? TRUE : $item[0];
			}
		}
		return $res;
	}


	/**
	 * Prepares .htdeployment for upload.
	 * @return string
	 */
	public function writeDeploymentFile($localFiles)
	{
		$s = '';
		foreach ($localFiles as $k => $v) {
			$s .= "$v=$k\n";
		}
		$file = $this->local . '/' . $this->deploymentFile;
		@mkdir(dirname($file), 0777, TRUE); // @ dir may exists
		file_put_contents($file, gzdeflate($s, 9));
		return $file;
	}


	/**
	 * Uploades files.
	 * @return void
	 */
	private function uploadFiles(array $files)
	{
		$root = $this->server->getDir();
		$prevDir = NULL;
		$toRename = [];
		foreach ($files as $num => $file) {
			$remoteFile = $root . $file;
			$isDir = substr($remoteFile, -1) === '/';
			$remoteDir = $isDir ? substr($remoteFile, 0, -1) : str_replace('\\', '/', dirname($remoteFile));
			if ($remoteDir !== $prevDir) {
				$prevDir = $remoteDir;
				$this->server->createDir($remoteDir);
			}

			if ($isDir) {
				$this->writeProgress($num + 1, count($files), $file, NULL, 'green');
				continue;
			}

			$localFile = $this->preprocess($orig = $this->local . $file);
			if (realpath($orig) !== $localFile) {
				$file .= ' (filters applied)';
			}

			$toRename[] = $remoteFile;
			$this->server->writeFile($localFile, $remoteFile . self::TEMPORARY_SUFFIX, function($percent) use ($num, $files, $file) {
				$this->writeProgress($num + 1, count($files), $file, $percent, 'green');
			});
			$this->writeProgress($num + 1, count($files), $file, NULL, 'green');
		}

		$this->logger->log("\nRenaming:");
		foreach ($toRename as $num => $file) {
			$this->writeProgress($num + 1, count($toRename), "Renaming $file", NULL, 'olive');
			$this->server->renameFile($file . self::TEMPORARY_SUFFIX, $file);
		}
	}


	/**
	 * Deletes files.
	 * @return void
	 */
	private function deleteFiles(array $files)
	{
		rsort($files);
		$root = $this->server->getDir();
		foreach ($files as $num => $file) {
			$remoteFile = $root . $file;
			$this->writeProgress($num + 1, count($files), "Deleting $file", NULL, 'maroon');
			try {
				if (substr($file, -1) === '/') { // is directory?
					$this->server->removeDir($remoteFile);
				} else {
					$this->server->removeFile($remoteFile);
				}
			} catch (ServerException $e) {
				$this->logger->log("Unable to delete $remoteFile", 'red');
			}
		}
	}


	/**
	 * Scans local directory.
	 * @param  string
	 * @return array
	 */
	public function collectFiles($subdir = '')
	{
		$list = [];
		$iterator = dir($this->local . $subdir);
		$counter = 0;
		while (FALSE !== ($entry = $iterator->read())) {
			echo str_pad(str_repeat('.', $counter++ % 40), 40), "\x0D";

			$path = "$this->local$subdir/$entry";
			$short = "$subdir/$entry";
			if ($entry == '.' || $entry == '..') {
				continue;

			} elseif (!is_readable($path)) {
				continue;

			} elseif ($this->matchMask($short, $this->ignoreMasks, is_dir($path))) {
				$this->logger->log(str_pad("Ignoring .$short", 40), 'gray');
				continue;

			} elseif (is_dir($path)) {
				$list[$short . '/'] = TRUE;
				$list += $this->collectFiles($short);

			} elseif (is_file($path)) {
				$list[$short] = self::hashFile($this->preprocess($path));
			}
		}
		$iterator->close();
		return $list;
	}


	/**
	 * Calls preprocessors on file.
	 * @param  string  file name
	 * @return string  file name
	 */
	private function preprocess($file)
	{
		$path = realpath($file);
		$ext = pathinfo($path, PATHINFO_EXTENSION);
		if (!isset($this->filters[$ext]) || !$this->matchMask($file, $this->preprocessMasks)) {
			return $path;
		}

		$content = file_get_contents($path);
		foreach ($this->filters[$ext] as $info) {
			if ($info['cached'] && is_file($tempFile = $this->tempDir . '/' . md5($content))) {
				$content = file_get_contents($tempFile);
			} else {
				$content = call_user_func($info['filter'], $content, $path);
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
	 * @return void
	 */
	private function runJobs(array $jobs)
	{
		foreach ($jobs as $job) {
			if (is_string($job) && preg_match('#^(https?|local|remote):(.+)#', $job, $m)) {
				if ($m[1] === 'local') {
					$out = @system($m[2], $code);
					$err = $code !== 0;
				} elseif ($m[1] === 'remote') {
					$out = $this->server->execute($m[2]);
					$err = FALSE;
				} else {
					$err = ($out = @file_get_contents($job)) === FALSE;
				}
				$this->logger->log("$job: $out");
				if ($err) {
					throw new \RuntimeException("Error in job $job");
				}

			} elseif (is_callable($job)) {
				if ($job($this->server, $this->logger, $this) === FALSE) {
					throw new \RuntimeException('Error in job');
				}

			} else {
				throw new \InvalidArgumentException("Invalid job $job.");
			}
		}
	}


	/**
	 * Computes hash.
	 * @param  string
	 * @return string
	 */
	public static function hashFile($file)
	{
		if (filesize($file) > 5e6) {
			return md5_file($file);
		} else {
			$s = file_get_contents($file);
			if (preg_match('#^[\x09\x0A\x0D\x20-\x7E\x80-\xFF]*+\z#', $s)) {
				$s = str_replace("\r\n", "\n", $s);
			}
			return md5($s);
		}
	}


	/**
	 * Matches filename against patterns.
	 * @param  string  file name
	 * @param  array   patterns
	 * @return bool
	 */
	public static function matchMask($path, array $patterns, $isDir = FALSE)
	{
		$res = FALSE;
		$path = explode('/', ltrim($path, '/'));
		foreach ($patterns as $pattern) {
			$pattern = strtr($pattern, '\\', '/');
			if ($neg = substr($pattern, 0, 1) === '!') {
				$pattern = substr($pattern, 1);
			}

			if (strpos($pattern, '/') === FALSE) { // no slash means base name
				if (fnmatch($pattern, end($path), FNM_CASEFOLD)) {
					$res = !$neg;
				}
				continue;

			} elseif (substr($pattern, -1) === '/') { // trailing slash means directory
				$pattern = trim($pattern, '/');
				if (!$isDir && count($path) <= count(explode('/', $pattern))) {
					continue;
				}
			}

			$parts = explode('/', ltrim($pattern, '/'));
			if (fnmatch(
				implode('/', $neg && $isDir ? array_slice($parts, 0, count($path)) : $parts),
				implode('/', array_slice($path, 0, count($parts))),
				FNM_CASEFOLD | FNM_PATHNAME
			)) {
				$res = !$neg;
			}
		}
		return $res;
	}


	private function writeProgress($count, $total, $file, $percent = NULL, $color = NULL)
	{
		$len = strlen((string) $total);
		$s = sprintf("(% {$len}d of %-{$len}d) %s", $count, $total, $file);
		if ($percent === NULL) {
			$this->logger->log($s, $color);
		} else {
			echo $s . ' [' . round($percent) . "%]\x0D";
		}
	}

}
