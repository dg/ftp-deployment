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
        if (!isset($this->url['scheme'], $this->url['user']) || $this->url['scheme'] !== 'sftp') {
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
        $this->sftp->get($remote, $local);
    }

    function writeFile(string $local, string $remote, callable $progress = null): void
    {
        $this->sftp->put($remote, $local, SFTP::SOURCE_LOCAL_FILE, -1, -1, $progress);
        if ($this->filePermissions) {
            $this->sftp->chmod($this->filePermissions, $remote);
        }
    }

    function removeFile(string $file): void
    {
        if ($this->sftp->file_exists($file)) {
            $this->sftp->delete($file);
        }
    }

    function renameFile(string $old, string $new): void
    {
        if ($this->sftp->file_exists($new)) {
            $perms = $this->sftp->fileperms($new);
            $this->sftp->delete($new);
        }
        $this->sftp->rename($old, $new);
        if (!empty($perms)) {
            $this->sftp->chmod($perms, $new);
        }
    }

    function createDir(string $dir): void
    {
        if (trim($dir, '/') !== '' && !$this->sftp->file_exists($dir)) {
            $this->sftp->mkdir($dir);
            $this->sftp->chmod($this->dirPermissions ?: 0777, $dir);
        }
    }

    function removeDir(string $dir): void
    {
        if ($this->sftp->file_exists($dir)) {
            $this->sftp->rmdir($dir);
        }
    }

    function purge(string $path, callable $progress = null): void
    {
        if ($this->sftp->file_exists($path)) {
            $this->sftp->delete($path, true);
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
}