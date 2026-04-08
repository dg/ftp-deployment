<?php declare(strict_types=1);

namespace Deployment;


/**
 * In-memory server implementation for testing.
 * Records all operations and maintains a virtual filesystem.
 */
class MockServer implements Server
{
	/** @var array<string, string|true> path => content (string) or true (directory) */
	private array $files = [];

	/** @var list<array{method: string, args: list<mixed>}> */
	private array $operations = [];

	private string $dir;


	/**
	 * @param  array<string, string|true>  $files  initial virtual filesystem
	 */
	public function __construct(array $files = [], string $dir = '')
	{
		$this->files = $files;
		$this->dir = $dir;
	}


	public function connect(): void
	{
		$this->record('connect');
	}


	public function readFile(string $remote, string $local): void
	{
		$this->record('readFile', [$remote, $local]);
		if (!isset($this->files[$remote]) || $this->files[$remote] === true) {
			throw new ServerException("File not found: $remote");
		}
		file_put_contents($local, $this->files[$remote]);
	}


	public function writeFile(string $local, string $remote, ?callable $progress = null): void
	{
		$this->record('writeFile', [$local, $remote]);
		$this->files[$remote] = file_get_contents($local);
		if ($progress) {
			$progress(100);
		}
	}


	public function removeFile(string $file): void
	{
		$this->record('removeFile', [$file]);
		unset($this->files[$file]);
	}


	public function renameFile(string $old, string $new): void
	{
		$this->record('renameFile', [$old, $new]);
		if (isset($this->files[$old])) {
			$this->files[$new] = $this->files[$old];
			unset($this->files[$old]);
		}
	}


	public function createDir(string $dir): void
	{
		$this->record('createDir', [$dir]);
		$this->files[$dir] = true;
	}


	public function removeDir(string $dir): void
	{
		$this->record('removeDir', [$dir]);
		unset($this->files[$dir]);
	}


	public function purge(string $path, ?callable $progress = null): void
	{
		$this->record('purge', [$path]);
		foreach (array_keys($this->files) as $key) {
			if (str_starts_with($key, $path . '/')) {
				unset($this->files[$key]);
				if ($progress) {
					$progress($key);
				}
			}
		}
	}


	public function chmod(string $path, int $permissions): void
	{
		$this->record('chmod', [$path, $permissions]);
	}


	public function getDir(): string
	{
		return $this->dir;
	}


	public function execute(string $command): string
	{
		$this->record('execute', [$command]);
		return '';
	}


	// Assertion helpers


	/**
	 * @return list<array{method: string, args: list<mixed>}>
	 */
	public function getOperations(): array
	{
		return $this->operations;
	}


	/**
	 * @return list<string>
	 */
	public function getOperationNames(): array
	{
		return array_map(fn($op) => $op['method'], $this->operations);
	}


	/**
	 * Returns operations filtered by method name.
	 * @return list<array{method: string, args: list<mixed>}>
	 */
	public function getOperationsOfType(string $method): array
	{
		return array_values(array_filter(
			$this->operations,
			fn($op) => $op['method'] === $method,
		));
	}


	/**
	 * @return array<string, string|true>
	 */
	public function getFiles(): array
	{
		return $this->files;
	}


	public function hasFile(string $path): bool
	{
		return isset($this->files[$path]) && $this->files[$path] !== true;
	}


	public function hasDir(string $path): bool
	{
		return isset($this->files[$path]) && $this->files[$path] === true;
	}


	public function getFileContent(string $path): string
	{
		if (!$this->hasFile($path)) {
			throw new \RuntimeException("File not found in MockServer: $path");
		}
		return $this->files[$path];
	}


	/**
	 * Returns remote paths from writeFile operations (strips .deploytmp suffix).
	 * Excludes .htdeployment and .htdeployment.running.
	 * @return list<string>
	 */
	public function getUploadedPaths(): array
	{
		$paths = [];
		foreach ($this->getOperationsOfType('writeFile') as $op) {
			$remote = $op['args'][1];
			$clean = str_replace('.deploytmp', '', $remote);
			if (!str_contains($clean, '.htdeployment')) {
				$paths[] = $clean;
			}
		}
		return $paths;
	}


	private function record(string $method, array $args = []): void
	{
		$this->operations[] = ['method' => $method, 'args' => $args];
	}
}


/**
 * Builds gzipped .htdeployment content from path => hash array.
 * @param  array<string, string|true>  $paths
 */
function buildDeploymentData(array $paths): string
{
	$s = '';
	foreach ($paths as $k => $v) {
		$s .= "$v=$k\n";
	}
	return gzencode($s, 9);
}


/**
 * Decodes deployment file content from MockServer.
 * @return array<string, string|true>
 */
function decodeDeploymentFile(MockServer $server, string $path = '/.htdeployment'): array
{
	$content = gzdecode($server->getFileContent($path));
	$res = [];
	foreach (explode("\n", $content) as $item) {
		if (count($item = explode('=', $item, 2)) === 2) {
			$res[$item[1]] = $item[0] === '1' ? true : $item[0];
		}
	}
	return $res;
}
