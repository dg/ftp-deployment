<?php

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (http://davidgrudl.com)
 *
 * For the full copyright and license information, please view
 * the file license.txt that was distributed with this source code.
 */



/**
 * Synchronizes local and remote.
 *
 * @author     David Grudl
 */
class Deployment
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

	/** @var Ftp */
	private $ftp;



	/**
	 * @param  Ftp
	 * @param  string  local directory
	 */
	public function __construct(Ftp $ftp, $local, Logger $logger)
	{
		if (!$local) {
			throw new InvalidArgumentException;
		}
		$this->ftp = $ftp;
		$this->local = $local;
		$this->logger = $logger;
	}


	/**
	 * Synchronize remote and local.
	 * @return void
	 */
	public function deploy()
	{
		$this->logger->log("Connecting to server");
		$this->ftp->connect();

		if (!is_dir($this->tempDir)) {
			$this->logger->log("Creating temporary directory $this->tempDir");
			mkdir($this->tempDir);
		}

		$remoteFiles = $this->loadDeploymentFile();
		if (is_array($remoteFiles)) {
			$this->logger->log("Loaded remote $this->deploymentFile file");
		} else {
			$this->logger->log("Remote $this->deploymentFile file not found");
			$remoteFiles = [];
		}

		$this->logger->log("Scanning files in $this->local");
		chdir($this->local);
		$localFiles = $this->collectFiles('');
		unset($localFiles["/$this->deploymentFile"]);

		$toDelete = $this->allowDelete ? array_keys(array_diff_key($remoteFiles, $localFiles)) : [];
		$toUpload = array_keys(array_diff_assoc($localFiles, $remoteFiles));

		if (!$toUpload && !$toDelete) {
			$this->logger->log('Already synchronized.', 'light-green');
			return;

		} elseif ($this->testMode) {
			$this->logger->log("\nUploading:\n" . implode("\n", $toUpload), 'green');
			$this->logger->log("\nDeleting:\n" . implode("\n", $toDelete), 'red');
			return;
		}

		if ($this->runBefore) {
			$this->logger->log("\nBefore-jobs:");
			foreach ((array) $this->runBefore as $job) {
				if (is_string($job)) {
					$this->logger->log("$job: " . trim(file_get_contents($job)));
				} elseif (is_callable($job)) {
					$job($this->ftp, $this->logger, $this);
				}
			}
		}

		$this->writeDeploymentFile($localFiles);
		$toUpload[] = "/$this->deploymentFile"; // must be the last one

		if ($toUpload) {
			$this->logger->log("\nUploading:");
			$this->uploadFiles($toUpload);
		}

		if ($toDelete) {
			$this->logger->log("\nDeleting:");
			$this->deleteFiles($toDelete);
		}

		foreach ((array) $this->toPurge as $path) {
			$this->logger->log("Cleaning $path");
			$this->ftp->purge($path, function() {
				static $counter;
				echo str_pad(str_repeat('.', $counter++ % 40), 40), "\x0D";
			});
		}

		unlink($this->deploymentFile);

		if ($this->runAfter) {
			$this->logger->log("\nAfter-jobs:");
			foreach ((array) $this->runAfter as $job) {
				if (is_string($job)) {
					$this->logger->log("$job: " . trim(file_get_contents($job)));
				} elseif (is_callable($job)) {
					$job($this->ftp, $this->logger, $this);
				}
			}
		}
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
	 * Downloads and decodes .htdeployment from the FTP server.
	 * @return void
	 */
	private function loadDeploymentFile()
	{
		$tempFile = tempnam($this->tempDir, 'deploy');
		try {
			$this->ftp->readFile($this->deploymentFile, $tempFile);
		} catch (FtpException $e) {
			return FALSE;
		}
		$content = gzinflate(file_get_contents($tempFile));
		$res = [];
		foreach (explode("\n", $content) as $item) {
			if (count($item = explode('=', $item, 2)) === 2) {
				$res[$item[1]] = $item[0];
			}
		}
		return $res;
	}


	/**
	 * Prepares .htdeployment for upload.
	 * @return void
	 */
	private function writeDeploymentFile($localFiles)
	{
		$s = '';
		foreach ($localFiles as $k => $v) {
			$s .= "$v=$k\n";
		}
		file_put_contents($this->deploymentFile, gzdeflate($s, 9));
	}


	/**
	 * Uploades files.
	 * @return void
	 */
	private function uploadFiles(array $files)
	{
		$root = rtrim($this->ftp->getDir(), '/');
		$prevDir = NULL;
		$toRename = [];
		foreach ($files as $num => $file) {
			$remoteFile = $root . $file;
			$remoteDir = substr($remoteFile, -1) === '/' ? $remoteFile : dirname($remoteFile);
			if ($remoteDir !== $prevDir) {
				$prevDir = $remoteDir;
				$this->ftp->createDir($remoteDir);
			}

			if (substr($remoteFile, -1) === '/') { // is dir?
				$this->writeProgress($num + 1, count($files), $file, NULL, 'green');
				continue;
			}

			$localFile = $this->preprocess($orig = ".$file");
			if (realpath($orig) !== $localFile) {
				$file .= ' (filters was applied)';
			}

			$toRename[] = $remoteFile;
			$this->ftp->writeFile($localFile, $remoteFile . self::TEMPORARY_SUFFIX, function($percent) use ($num, $files, $file) {
				$this->writeProgress($num + 1, count($files), $file, $percent, 'green');
			});
			$this->writeProgress($num + 1, count($files), $file, NULL, 'green');
		}

		$this->logger->log("\nRenaming:");
		foreach ($toRename as $num => $file) {
			$this->writeProgress($num + 1, count($toRename), "Renaming $file", NULL, 'brown');
			$this->ftp->rename($file . self::TEMPORARY_SUFFIX, $file);
		}
	}


	/**
	 * Deletes files.
	 * @return void
	 */
	private function deleteFiles(array $files)
	{
		rsort($files);
		$root = rtrim($this->ftp->getDir(), '/');
		foreach ($files as $num => $file) {
			$remoteFile = $root . $file;
			$this->writeProgress($num + 1, count($files), "Deleting $file", NULL, 'red');
			try {
				if (substr($file, -1) === '/') { // is directory?
					$this->ftp->removeDir($remoteFile);
				} else {
					$this->ftp->removeFile($remoteFile);
				}
			} catch (FtpException $e) {
				$this->logger->log("Unable to delete $remoteFile", 'light-red');
			}
		}
	}


	/**
	 * Scans local directory.
	 * @param  string
	 * @return array
	 */
	private function collectFiles($dir)
	{
		$list = [];
		$iterator = dir(".$dir");
		$counter = 0;
		while (FALSE !== ($entry = $iterator->read())) {
			echo str_pad(str_repeat('.', $counter++ % 40), 40), "\x0D";

			$path = ".$dir/$entry";
			if ($entry == '.' || $entry == '..') {
				continue;

			} elseif (!is_readable($path)) {
				continue;

			} elseif ($this->matchMask($path, $this->ignoreMasks)) {
				$this->logger->log("Ignoring $path", 'dark-grey');
				continue;

			} elseif (is_dir($path)) {
				$list["$dir/$entry/"] = TRUE;
				$list += $this->collectFiles("$dir/$entry");

			} elseif (is_file($path)) {
				$list["$dir/$entry"] = md5_file($this->preprocess($path));
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
	 * Matches filename against patterns.
	 * @param  string  file name
	 * @param  array   patterns
	 * @return bool
	 */
	public static function matchMask($path, array $patterns)
	{
		$res = FALSE;
		foreach ($patterns as $pattern) {
			$pattern = strtr($pattern, '\\', '/');
			if ($neg = substr($pattern, 0, 1) === '!') {
				$pattern = substr($pattern, 1);
			}
			if (substr($pattern, -1) === '/') { // trailing slash means directory
				if (!is_dir($path)) {
					continue;
				}
				$pattern = substr($pattern, 0, -1);
			}
			if (strpos($pattern, '/') === FALSE) { // no slash means file name
				if (fnmatch($pattern, basename($path), FNM_CASEFOLD)) {
					$res = !$neg;
				}
			} elseif (fnmatch('./' . ltrim($pattern, '/'), $path, FNM_CASEFOLD | FNM_PATHNAME)) { // $path always starts with ./
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
