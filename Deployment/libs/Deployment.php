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
	const RETRIES = 10;
	const BLOCK_SIZE = 400000;
	const TEMPORARY_SUFFIX = '.deploytmp';

	/** @var string */
	public $deploymentFile = '.htdeployment';

	/** @var array */
	public $ignoreMasks = array();

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
	private $remote;

	/** @var string */
	private $local;

	/** @var Logger */
	private $logger;

	/** @var array */
	private $filters;

	/** @var Ftp */
	private $ftp;



	/**
	 * @param  string  remote FTP url
	 * @param  string  local directory
	 */
	public function __construct($remote, $local, Logger $logger)
	{
		if (!$remote || !$local) {
			throw new InvalidArgumentException;
		}
		$this->remote = $remote;
		$this->local = realpath($local);
		$this->logger = $logger;
	}



	/**
	 * Synchronize remote and local.
	 * @return void
	 */
	public function deploy()
	{
		$this->logger->log("Connecting to server");
		$this->ftp = new Ftp($this->remote);

		$remoteFiles = $this->loadDeploymentFile();
		if (is_array($remoteFiles)) {
			$this->logger->log("Loaded remote $this->deploymentFile file");
		} else {
			$this->logger->log("Remote $this->deploymentFile file not found");
			$remoteFiles = array();
		}

		$this->logger->log("Scanning files in $this->local");
		chdir($this->local);
		$localFiles = $this->collectFiles('');
		unset($localFiles["/$this->deploymentFile"]);

		$toDelete = $this->allowDelete ? array_keys(array_diff_key($remoteFiles, $localFiles)) : array();
		$toUpload = array_keys(array_diff_assoc($localFiles, $remoteFiles));

		if (!$toUpload && !$toDelete) {
			$this->logger->log('Already synchronized.');
			return;

		} elseif ($this->testMode) {
			$this->logger->log("\nUploading:\n" . implode("\n", $toUpload));
			$this->logger->log("\nDeleting:\n" . implode("\n", $toDelete));
			return;
		}

		if ($this->runBefore) {
			$this->logger->log("\nBefore-jobs:");
			foreach ((array) $this->runBefore as $url) {
				$this->logger->log("$url: " . trim(file_get_contents($url)));
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
			try {
				$this->ftp->deleteRecursive($path, TRUE);
			} catch (FtpException $e) {
				$this->logger->log("Error {$e->getMessage()}");
			}
		}

		unlink($this->deploymentFile);

		if ($this->runAfter) {
			$this->logger->log("\nAfter-jobs:");
			foreach ((array) $this->runAfter as $url) {
				$this->logger->log("$url: " . trim(file_get_contents($url)));
			}
		}
	}



	/**
	 * Appends preprocessor for files.
	 * @param  string  file extension
	 * @param  callable
	 * @return void
	 */
	public function addFilter($extension, $filter)
	{
		$this->filters[$extension][] = $filter;
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
			$this->ftp->get($tempFile, $this->deploymentFile, Ftp::BINARY);
		} catch (FtpException $e) {
			return FALSE;
		}
		$content = gzinflate(file_get_contents($tempFile));
		$res = array();
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
		$root = $this->ftp->pwd();
		$root = $root === '/' ? '' : $root;
		$prevDir = NULL;
		$toRename = array();
		foreach ($files as $num => $file) {
			$remoteFile = $root . $file;
			$remoteDir = dirname($remoteFile);
			if ($remoteDir !== $prevDir) {
				$prevDir = $remoteDir;
				if (trim($remoteDir, '\\/') !== '' && !$this->ftp->isDir($remoteDir)) {
					$this->ftp->mkDirRecursive($remoteDir);
				}
			}

			if (substr($remoteFile, -1) === '/') { // is dir?
				continue;
			}

			$localFile = $this->preprocess($orig = ".$file");
			if (realpath($orig) !== $localFile) {
				$file .= ' (filters was applied)';
			}

			$toRename[] = $remoteFile;
			$size = filesize($localFile);
			$retry = self::RETRIES;
			upload:
			$blocks = 0;
			do {
				$this->writeProgress($num + 1, count($files), $file, min(round($blocks * self::BLOCK_SIZE / max($size, 1)), 100));
				try {
					$ret = $blocks === 0
						? $this->ftp->nbPut($remoteFile . self::TEMPORARY_SUFFIX, $localFile, Ftp::BINARY)
						: $this->ftp->nbContinue(); // Ftp::AUTORESUME

				} catch (FtpException $e) {
					$this->ftp->reconnect();
					if (--$retry) {
						goto upload;
					}
					throw new Exception("Cannot upload file $file, number of retries exceeded.");
				}
				$blocks++;
			} while ($ret === Ftp::MOREDATA);

			$this->writeProgress($num + 1, count($files), $file);
		}

		foreach ($toRename as $num => $file) {
			$this->writeProgress($num + 1, count($toRename), "Renaming $file");
			$this->ftp->tryDelete($file);
			$this->ftp->rename($file . self::TEMPORARY_SUFFIX, $file); // TODO: zachovat permissions
		}
	}



	/**
	 * Deletes files.
	 * @return void
	 */
	private function deleteFiles(array $files)
	{
		$root = $this->ftp->pwd();
		foreach ($files as $num => $file) {
			$remoteFile = $root . $file;
			$this->writeProgress($num + 1, count($files), "Deleting $file");
			if (substr($file, -1) === '/') { // is directory?
				$this->ftp->rmdir($remoteFile);
			} else {
				$this->ftp->delete($remoteFile);
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
		$list = array();
		$iterator = dir(".$dir");
		$counter = 0;
		while (FALSE !== ($entry = $iterator->read())) {
			echo str_pad(str_repeat('.', $counter++ % 40), 40), "\x0D";

			$path = ".$dir/$entry";
			if ($entry == '.' || $entry == '..' || isset($disallow[$entry])) {
				continue;

			} elseif (!is_readable($path)) {
				continue;

			} elseif ($this->matchMask($path, $this->ignoreMasks)) {
				$this->logger->log("Ignoring $path");
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
		static $cache;
		$file = realpath($file);
		if (isset($cache[$file])) {
			return $cache[$file];
		}

		$ext = pathinfo($file, PATHINFO_EXTENSION);
		if (!isset($this->filters[$ext])) {
			return $file;
		}

		$content = $orig = file_get_contents($file);
		foreach ($this->filters[$ext] as $filter) {
			$content = call_user_func($filter, $content, $file);
		}
		if ($content === $orig) {
			return $file;
		}

		$tempFile = tempnam($this->tempDir, 'deploy');
		file_put_contents($tempFile, $content);
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
			if (substr($pattern, -1) === '/') { // leading slash means directory
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



	private function writeProgress($count, $total, $file, $percent = NULL)
	{
		$len = strlen((string) $total);
		$s = sprintf("(% {$len}d of %-{$len}d) %s", $count, $total, $file);
		if ($percent === NULL) {
			$this->logger->log($s);
		} else {
			echo $s . " [$percent%]\x0D";
		}
	}

}
