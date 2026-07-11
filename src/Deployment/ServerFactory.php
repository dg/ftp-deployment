<?php declare(strict_types=1);

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (https://davidgrudl.com)
 */

namespace Deployment;


/**
 * Builds a Server instance (wrapped in RetryServer) from a configuration array.
 */
class ServerFactory
{
	public function __construct(
		private readonly Logger $logger,
		private readonly InterruptHandler $interruptHandler,
	) {
	}


	/**
	 * @param  array<string, mixed>  $config
	 * @throws \Exception
	 */
	public function create(array $config): Server
	{
		if (
			empty($config['remote'])
			|| !($urlParts = parse_url($config['remote']))
			|| !isset($urlParts['scheme'])
		) {
			throw new \Exception("Missing or invalid 'remote' URL in config.");
		}
		if (isset($config['user'])) {
			$urlParts['user'] = urlencode($config['user']);
		}
		if (isset($config['password'])) {
			$urlParts['pass'] = urlencode($config['password']);
		}

		if ($urlParts['scheme'] === 'ftp') {
			$this->logger->log('Note: connection is not encrypted', 'white/red');
		}

		$server = match ($urlParts['scheme']) {
			'phpsec' => new PhpsecServer(
				Helpers::buildUrl($urlParts),
				$config['publickey'] ?? null,
				$config['privatekey'] ?? null,
				$config['passphrase'] ?? null,
				$config['hostkey'] ?? null,
			),
			'sftp' => new SshServer(
				Helpers::buildUrl($urlParts),
				$config['publickey'] ?? null,
				$config['privatekey'] ?? null,
				$config['passphrase'] ?? null,
				$config['hostkey'] ?? null,
			),
			'file' => new FileServer($config['remote']),
			default => new FtpServer(Helpers::buildUrl($urlParts), (bool) ($config['passivemode'] ?? true)),
		};
		$server->filePermissions = empty($config['filepermissions'])
			? null
			: (int) octdec($config['filepermissions']);
		$server->dirPermissions = empty($config['dirpermissions'])
			? null
			: (int) octdec($config['dirpermissions']);

		return new RetryServer($server, $this->logger, $this->interruptHandler);
	}
}
