# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

FTP Deployment is a PHP CLI tool for automated deployment of web applications to FTP/SFTP servers. It intelligently synchronizes local and remote files, uploading only changed files and optionally deleting removed files. The tool supports preprocessing (JS/CSS minification), pre/post deployment hooks, and transactional-like uploads.

## Core Architecture

### Main Components

**Deployer** (`src/Deployment/Deployer.php`)
- Core synchronization engine that compares local and remote file states
- Manages the deployment lifecycle: scanning → comparing → uploading → renaming → cleanup
- Maintains `.htdeployment` file on server with MD5 hashes for incremental sync
- Uses temporary `.deploytmp` suffix during upload for transactional-like behavior

**Server Interface** (`src/Deployment/Server.php`)
- Abstract interface for all server types (FTP, SFTP, local filesystem)
- Implementations: `FtpServer`, `SshServer`, `PhpsecServer`, `FileServer`
- `SshServer` requires ext-ssh2; `PhpsecServer` uses phpseclib for pure PHP SSH
- `RetryServer` wraps any server implementation with automatic retry logic

**JobRunner** (`src/Deployment/JobRunner.php`)
- Executes pre/post deployment jobs (local shell commands, remote commands, HTTP callbacks)
- Supports job types: `local:`, `remote:`, `upload:`, and `http://`
- Can also execute PHP callables passed from config

**Preprocessor** (`src/Deployment/Preprocessor.php`)
- Minifies CSS (via clean-css) and JS (via uglifyjs) files
- Expands Apache mod_include directives (`<!--#include file="..." -->`)
- Requires Node.js tools installed globally

**CommandLine** (`src/Deployment/CommandLine.php`)
- Parses configuration files (both `.ini` and `.php` formats)
- Sets up the Deployer with all configuration options

**CliRunner** (`src/Deployment/CliRunner.php`)
- Entry point for the CLI tool
- Handles command-line arguments and orchestrates the deployment process

### Key Mechanisms

**Incremental Sync via Hash File**
- `.htdeployment` file stores MD5 hashes of all deployed files
- On each deployment, compares local file hashes against this file
- Only uploads changed/new files and deletes removed files (if `allowDelete=true`)

**Transactional Upload Pattern**
- Files uploaded with `.deploytmp` suffix
- All files renamed simultaneously after successful upload
- Minimizes window where site has inconsistent state

**Mask Matching** (`Helpers::matchMask()`)
- Custom implementation similar to `.gitignore` syntax
- Supports: wildcards `*`, character classes `[xy]`, negation `!`, path anchoring `/`
- Used for both `ignore` and `include` configuration directives

## Development Commands

### Testing

```bash
# Run all tests
vendor/bin/tester tests -s -C

# Run specific test file
vendor/bin/tester tests/Helpers.matchMask.phpt -s -C

# Run tests with colors (-C) and show skipped tests (-s)
vendor/bin/tester tests -s -C
```

Tests use Nette Tester with `.phpt` format. The `test()` helper function is defined in `tests/bootstrap.php`.

### Static Analysis

```bash
# Run PHPStan (informative only - not blocking)
composer phpstan
```

Note: PHPStan is configured to be non-blocking (continue-on-error in CI).

### Running Deployment

```bash
# Run deployment with config file
php deployment deployment.ini

# Test mode (dry-run, no actual changes)
php deployment deployment.ini --test

# Or use the created PHAR
php create-phar/deployment.phar deployment.ini
```

### Building PHAR

```bash
# Build single-file executable
php create-phar/create-phar.php
```

Creates `create-phar/deployment.phar` which can be distributed as standalone tool.

## Configuration

Supports both `.ini` and `.php` config formats:

**INI Format** (`deployment.sample.ini`)
- Standard INI sections for multiple deployment targets
- String-based job definitions

**PHP Format** (`deployment.sample.php`)
- Returns associative array
- Can use PHP callables for `before`/`after`/`afterUpload` hooks
- Callable signature: `function(Server $server, Logger $logger, Deployer $deployer)`

## Coding Conventions

- PHP 8.0+ required, all files must have `declare(strict_types=1)`
- Uses tabs for indentation (per user's global CLAUDE.md preferences)
- Follows Nette Coding Standard (PSR-12 based)
- Namespace: `Deployment\`
- Sensitive parameters marked with `#[\SensitiveParameter]` attribute

## PHP Extensions

**Required:**
- `ext-zlib` - for gzip compression

**Optional (protocol-dependent):**
- `ext-ftp` - for `ftp://` and `ftps://` protocols
- `ext-openssl` - for `ftps://` protocol
- `ext-ssh2` - for `sftp://` protocol using native SSH2
- `ext-json` - for CSS preprocessing via online services

If `ext-ssh2` is not available, use `phpsec://` protocol which uses phpseclib (pure PHP implementation).

## Testing Philosophy

- Unit tests for utility functions (`Helpers.*`)
- Focused tests using `Assert::*` from Nette Tester
- Test fixtures in `tests/fixtures/`
- Temporary files in `tests/tmp/` (auto-cleaned)

## Common Workflows

### Adding New Server Type

1. Create class implementing `Server` interface
2. Add protocol parsing in `CommandLine::createServer()`
3. Add protocol to README examples

### Adding New Preprocessor

1. Add method to `Preprocessor` class (e.g., `compressXxx()`)
2. Register in `Deployer::collectPaths()` based on file extension
3. Update default masks in `CommandLine::loadConfig()`

### Modifying Job Types

Job types are handled in `JobRunner`:
- `local:` → `JobRunner::local()` (executes via PHP `exec()`)
- `remote:` → `JobRunner::remote()` (parses and executes via Server interface)
- `upload:` → `JobRunner::upload()` (uploads single file)
- `http://` → `Helpers::fetchUrl()` (HTTP callback)
