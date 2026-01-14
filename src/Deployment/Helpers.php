<?php

/**
 * FTP Deployment
 *
 * Copyright (c) 2009 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

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
	 * @param  string   $path  relative path
	 * @param  string[]  $patterns
	 */
	public static function matchMask(string $path, array $patterns, bool $isDir = false): bool
	{
		$res = false;
		$path = explode('/', ltrim($path, '/'));
		foreach ($patterns as $pattern) {
			$pattern = strtr($pattern, '\\', '/');
			if ($neg = substr($pattern, 0, 1) === '!') {
				$pattern = substr($pattern, 1);
			}

			if (!str_contains($pattern, '/')) { // no slash means base name
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
	 */
	public static function fetchUrl(string $url, ?string &$error, ?array $postData = null): string
	{
		if (extension_loaded('curl')) {
			$ch = curl_init($url);
			$options = [
				CURLOPT_RETURNTRANSFER => 1,
				CURLOPT_FOLLOWLOCATION => 1,
				CURLOPT_USERAGENT => 'Mozilla/5.0 FTP-deployment',
			];
			if ($postData !== null) {
				$options[CURLOPT_POST] = true;
				$options[CURLOPT_POSTFIELDS] = http_build_query($postData, '', '&');
			}
			curl_setopt_array($ch, $options);
			$output = curl_exec($ch);
			if (curl_errno($ch)) {
				$error = curl_error($ch);
			} elseif (($code = curl_getinfo($ch, CURLINFO_HTTP_CODE)) >= 400) {
				$error = "responds with HTTP code $code";
			}

		} else {
			$output = @file_get_contents($url, false, stream_context_create([
				'http' => $postData === null ? [] : [
					'method' => 'POST',
					'header' => 'Content-type: application/x-www-form-urlencoded',
					'content' => http_build_query($postData, '', '&'),
				],
			]));
			$error = $output === false
				? preg_replace('#^file_get_contents\(.*?\): #', '', error_get_last()['message'])
				: null;
		}
		return (string) $output;
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


	public static function getHiddenInput(string $prompt = ''): string
	{
		if ($prompt) {
			echo $prompt;
		}
		@exec('stty -echo 2>&1');
		$password = stream_get_line(STDIN, 1024, PHP_EOL);
		echo PHP_EOL;
		@exec('stty echo 2>&1');
		return $password;
	}
}
