<?php

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Deployment;


/**
 * PHP functions rewritten to throw exceptions instead of returning false.
 *
 * @method static void copy(string $source, string $dest)
 * @method static \Directory dir(string $directory)
 * @method static resource fopen(string $filename, string $mode, bool $use_include_path = false)
 * @method static string fread(resource $handle, int $length)
 * @method static void ftp_chdir(resource $ftp_stream, string $directory)
 * @method static int ftp_chmod(resource $ftp_stream, int $mode, string $filename)
 * @method static void ftp_close(resource $ftp_stream)
 * @method static resource ftp_connect(string $host, int $port = 21, int $timeout = 90)
 * @method static void ftp_delete(resource $ftp_stream, string $path)
 * @method static void ftp_exec(resource $ftp_stream, string $command)
 * @method static void ftp_get(resource $ftp_stream, string $local_file, string $remote_file, int $mode = FTP_BINARY, int $resumepos = 0)
 * @method static void ftp_login(resource $ftp_stream, string $username, string $password)
 * @method static string ftp_mkdir(resource $ftp_stream, string $directory)
 * @method static int ftp_nb_continue(resource $ftp_stream)
 * @method static int ftp_nb_put(resource $ftp_stream, string $remote_file, string $local_file, int $mode = FTP_IMAGE, int $startpos = 0)
 * @method static array ftp_nlist(resource $ftp_stream, string $directory)
 * @method static void ftp_pasv(resource $ftp_stream, bool $pasv)
 * @method static string ftp_pwd(resource $ftp_stream)
 * @method static void ftp_rename(resource $ftp_stream, string $oldname, string $newname)
 * @method static void ftp_rmdir(resource $ftp_stream, string $directory)
 * @method static void ftp_set_option(resource $ftp_stream, int $option, mixed $value)
 * @method static void ftp_site(resource $ftp_stream, string $command)
 * @method static resource ftp_ssl_connect(string $host, int $port = 21, int $timeout = 90)
 * @method static int fwrite(resource $handle, string $string, int $length)
 * @method static void mkdir(string $pathname, int $mode = 0777, bool $recursive = false)
 * @method static void rename(string $oldname, string $newname)
 * @method static void rmdir(string $dirname)
 * @method static void ssh2_auth_agent(resource $session, string $username)
 * @method static void ssh2_auth_password(resource $session, string $username, string $password)
 * @method static void ssh2_auth_pubkey_file(resource $session, string $username, string $pubkeyfile, string $privkeyfile, string $passphrase)
 * @method static resource ssh2_connect(string $host, int $port = 22, array $methods = [], array $callbacks = [])
 * @method static resource ssh2_exec(resource $session, string $command, string $pty = '', array $env = [], int $width = 80, int $height = 25, int $width_height_type = SSH2_TERM_UNIT_CHARS)
 * @method static resource ssh2_sftp(resource $session)
 * @method static void ssh2_sftp_chmod(resource $sftp, string $filename, int $mode)
 * @method static void ssh2_sftp_mkdir(resource $sftp, string $dirname, int $mode = 0777, bool $recursive = false)
 * @method static void ssh2_sftp_rename(resource $sftp, string $from, string $to)
 * @method static string stream_get_contents(resource $handle, int $maxlength = -1, int $offset = -1)
 * @method static void stream_set_blocking(resource $stream, bool $mode)
 * @method static void unlink(string $filename)
 * @method static void chmod(string $filename, int $permissions)
 */
class Safe
{
	/**
	 * @return mixed
	 * @throws ServerException
	 */
	public static function __callStatic(string $func, array $args = [])
	{
		set_error_handler(function (int $severity, string $message) {
			if (ini_get('html_errors')) {
				$message = html_entity_decode(strip_tags($message));
			}
			if (preg_match('#^\w+\(\):\s*(.+)#', $message, $m)) {
				$message = $m[1];
			}
			$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
			throw new ServerException($message, $trace[2]['file'], $trace[2]['line']);
		});
		try {
			$res = $func(...$args);
			restore_error_handler();
		} catch (\Throwable $e) {
			restore_error_handler();
			throw $e;
		}
		if ($res === false) {
			$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
			throw new ServerException("$func() failures.", $trace[1]['file'], $trace[1]['line']);
		}
		return $res;
	}


	/** @throws ServerException */
	public static function exec(string $command, array &$output = null, int &$return_var = null): string
	{
		return self::__callStatic(__FUNCTION__, [$command, &$output, &$return_var]);
	}
}
