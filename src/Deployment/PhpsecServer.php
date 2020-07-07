<?php

namespace Deployment;

use phpseclib\Net\SFTP;

class PhpsecServer implements Server
{
    /** @var int */
    public $filePermissions;

    /** @var int */
    public $dirPermissions;
    /**
     * @var array
     */
    private $url;
    /**
     * @var string|null
     */
    private $publicKey;
    /**
     * @var string|null
     */
    private $privateKey;
    /**
     * @var string|null
     */
    private $passPhrase;
    /**
     * @var SFTP
     */
    private $sftp;

    public function __construct(string $url, string $publicKey = null, string $privateKey = null, string $passPhrase = null)
    {
        $this->url = parse_url($url);
        if (!isset($this->url['scheme'], $this->url['user']) || $this->url['scheme'] !== 'phpsec') {
            throw new \InvalidArgumentException('Invalid URL or missing username');
        }
        $this->publicKey = $publicKey;
        $this->privateKey = $privateKey;
        $this->passPhrase = $passPhrase;
    }

    function connect(): void
    {
        $sftp = new SFTP($this->url['host'], $this->url['port'] ?? 22);
        if (!$sftp->login(urldecode($this->url['user']), urldecode($this->url['pass']))) {
            exit('Login Failed');
        }
        $this->sftp = $sftp;
    }

    function readFile(string $remote, string $local): void
    {
        $remote = $this->normalizePath($remote);
        if (false === $this->sftp->get($remote, $local)) {
            throw new ServerException("Unable to read file");
        };
    }

    function writeFile(string $local, string $remote, callable $progress = null): void
    {
        $remote = $this->normalizePath($remote);
        if (false === $this->sftp->put($remote, $local, SFTP::SOURCE_LOCAL_FILE, -1, -1, $progress)) {
            throw new ServerException("Unable to write file");
        }
        if ($this->filePermissions) {
            if (false === $this->sftp->chmod($this->filePermissions, $remote)) {
                throw new ServerException("Unable to chmod after file creation");
            }
        }
    }

    function removeFile(string $file): void
    {
        $file = $this->normalizePath($file);
        if ($this->sftp->file_exists($file)) {
            if (false === $this->sftp->delete($file)) {
                throw new ServerException("Unable to delete file");
            }
        }
    }

    function renameFile(string $old, string $new): void
    {
        $old = $this->normalizePath($old);
        $new = $this->normalizePath($new);
        if ($this->sftp->file_exists($new)) {
            $perms = $this->sftp->fileperms($new);
            if (false === $this->sftp->delete($new)) {
                throw new ServerException("Unable to delete target file during rename");
            }
        }
        if (false === $this->sftp->rename($old, $new)) {
            throw new ServerException("Unable to rename file");
        }
        if (!empty($perms)) {
            if (false === $this->sftp->chmod($perms, $new)) {
                throw new ServerException("Unable to chmod file after renaming");
            }
        }
    }

    function createDir(string $dir): void
    {
        $dir = $this->normalizePath($dir);
        if ($dir !== '' && !$this->sftp->file_exists($dir)) {
            if (false === $this->sftp->mkdir($dir)) {
                throw new ServerException("Unable to create directory");
            }
            if (false === $this->sftp->chmod($this->dirPermissions ?: 0777, $dir)) {
                throw new ServerException("Unable to chmod after creating a directory");
            }
        }
    }

    function removeDir(string $dir): void
    {
        $dir = $this->normalizePath($dir);
        if ($this->sftp->file_exists($dir)) {
            if (false === $this->sftp->rmdir($dir)) {
                throw new ServerException("Unable to remove directory");
            }
        }
    }

    function purge(string $path, callable $progress = null): void
    {
        $path = $this->normalizePath($path);
        if ($this->sftp->file_exists($path)) {
            if (false === $this->sftp->delete($path, true)) {
                throw new ServerException("Unable to purge directory/file");
            }
        }
    }

    function getDir(): string
    {
        return isset($this->url['path']) ? rtrim($this->url['path'], '/') : '';
    }

    function execute(string $command): string
    {
        return $this->sftp->exec($command);
    }

    public function normalizePath(string $dir): string
    {
        return trim($dir, '/');
    }
}