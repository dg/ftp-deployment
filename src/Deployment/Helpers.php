<?php declare(strict_types=1);

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (https://davidgrudl.com)
 */

namespace Deployment;


/**
 * Helpers.
 */
class Helpers
{
	/**
	 * Computes hash.
	 */
	public static function hashFile(string $file): string
	{
		if (filesize($file) > 5e6) {
			return (string) md5_file($file);
		}
		$s = (string) file_get_contents($file);
		if (preg_match('#^[\x09\x0A\x0D\x20-\x7E\x80-\xFF]*+\z#', $s)) {
			$s = str_replace("\r\n", "\n", $s);
		}
		return md5($s);
	}


	/**
	 * Matches filename against patterns.
	 * @param  string   $path  relative path
	 * @param  string[]  $patterns
	 */
	public static function matchMask(string $path, array $patterns, bool $isDir = false): bool
	{
		$res = false;
		$path = explode('/', ltrim($path, '/'));
		foreach ($patterns as $pattern) {
			$pattern = strtr($pattern, '\\', '/');
			if ($neg = str_starts_with($pattern, '!')) {
				$pattern = substr($pattern, 1);
			}

			if (!str_contains($pattern, '/')) { // no slash means base name
				if (fnmatch($pattern, end($path), FNM_CASEFOLD)) {
					$res = !$neg;
				}
				continue;

			} elseif (str_ends_with($pattern, '/')) { // trailing slash means directory
				$pattern = trim($pattern, '/');
				if (!$isDir && count($path) <= count(explode('/', $pattern))) {
					continue;
				}
			}

			$parts = explode('/', ltrim($pattern, '/'));
			if (fnmatch(
				implode('/', $neg && $isDir ? array_slice($parts, 0, count($path)) : $parts),
				implode('/', array_slice($path, 0, count($parts))),
				FNM_CASEFOLD | FNM_PATHNAME,
			)) {
				$res = !$neg;
			}
		}
		return $res;
	}


	/**
	 * Processes HTTP request.
	 * @param ?array<string, scalar>  $postData
	 * @param-out ?string  $error
	 */
	public static function fetchUrl(string $url, ?string &$error, ?array $postData = null): string
	{
		if (extension_loaded('curl')) {
			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 FTP-deployment');
			if ($postData !== null) {
				curl_setopt($ch, CURLOPT_POST, true);
				curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData, '', '&'));
			}
			$output = curl_exec($ch);
			if (curl_errno($ch)) {
				$error = curl_error($ch);
			} elseif (($code = curl_getinfo($ch, CURLINFO_HTTP_CODE)) >= 400) {
				$error = "responds with HTTP code $code";
			}

		} else {
			$output = @file_get_contents($url, false, stream_context_create([ // @ the error is read below
				'http' => $postData === null ? [] : [
					'method' => 'POST',
					'header' => 'Content-type: application/x-www-form-urlencoded',
					'content' => http_build_query($postData, '', '&'),
				],
			]));
			$error = $output === false
				? preg_replace('#^file_get_contents\(.*?\): #', '', (string) (error_get_last()['message'] ?? ''))
				: null;
		}
		return (string) $output;
	}


	/**
	 * Checks whether an SSH server public host key matches the expected value.
	 * The expected value may be the full OpenSSH key ("ssh-rsa AAAA..."), the bare
	 * base64 blob, an OpenSSH-style "SHA256:..." fingerprint, or an MD5 fingerprint
	 * (hex, with or without colons and "MD5:" prefix).
	 * @param  string  $expected  configured host key/fingerprint
	 * @param  string  $actual    server public host key, "type base64 [comment]"
	 */
	public static function matchHostKey(string $expected, string $actual): bool
	{
		$expected = trim($expected);
		$actual = trim($actual);
		[$type, $base64] = explode(' ', $actual) + [1 => ''];
		$blob = base64_decode($base64, true);
		if ($expected === '' || !$blob) {
			return false;
		}

		return in_array($expected, [
			$actual,
			"$type $base64", // without the trailing comment
			$base64,
			'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '='),
		], strict: true)
			|| self::normalizeFingerprint($expected) === md5($blob);
	}


	/**
	 * Formats a byte count as a human-readable size.
	 */
	public static function formatSize(int $bytes): string
	{
		$units = ['B', 'kB', 'MB', 'GB', 'TB'];
		$i = $bytes > 0 ? min((int) floor(log($bytes, 1024)), count($units) - 1) : 0;
		$value = $bytes / 1024 ** $i;
		return ($i === 0 ? $value : round($value, 1)) . ' ' . $units[$i];
	}


	/**
	 * Normalizes a hex SSH fingerprint for comparison: lowercased, without colons,
	 * whitespace or a leading algorithm prefix (MD5:/SHA1:/SHA256:).
	 */
	public static function normalizeFingerprint(string $s): string
	{
		return strtolower((string) preg_replace('#^(md5|sha1|sha256):|[:\s]#i', '', trim($s)));
	}


	/**
	 * @param array{scheme?: string, host?: string, port?: int, user?: string, pass?: string, path?: string}  $url
	 */
	public static function buildUrl(array $url): string
	{
		return (isset($url['scheme']) ? $url['scheme'] . '://' : '')
			. ($url['user'] ?? '')
			. (isset($url['pass']) ? ':' . $url['pass'] : '')
			. (isset($url['user']) || isset($url['pass']) ? '@' : '')
			. ($url['host'] ?? '')
			. (isset($url['port']) ? ':' . $url['port'] : '')
			. ($url['path'] ?? '');
	}
}
