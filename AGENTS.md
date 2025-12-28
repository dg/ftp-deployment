# To My Agents!

It is my fervent wish that this file guide every AI coding agent working with code in this repository.

## Documentation

Any distilled, agent-facing documentation for this package - how it works
internally and the rationale behind key design decisions - lives in `docs/`.
Consult it before non-trivial changes; it is the source of truth from which the
public manual is distilled.

Thin but real internals: the deployment lifecycle is a transaction whose invariants
(rename-as-commit, the `.running` marker, skipped-op regeneration, what may be
parallelized) are easy to break. Read `docs/internals.md` before touching them.

## Project Overview

FTP Deployment is a PHP CLI tool that synchronizes a web app to an FTP/SFTP server,
uploading only changed files (incremental sync via an `.htdeployment` hash file),
optionally deleting removed ones, with JS/CSS preprocessing and pre/post hooks.

- **PHP Version**: 8.2+
- **Package**: `dg/ftp-deployment` (namespace `Deployment\`)

## Essential Commands

```bash
# Run all tests
vendor/bin/tester tests -s -C
vendor/bin/tester tests/Helpers.matchMask.phpt -s -C

# Static analysis (informative only - non-blocking in CI)
composer phpstan

# Run a deployment (--test = dry run)
php deployment deployment.ini [--test]

# Build the standalone PHAR
php create-phar/create-phar.php     # -> create-phar/deployment.phar
```

## Conventions

- Every file starts with `declare(strict_types=1);`; **tabs**; Nette Coding
  Standard; namespace `Deployment\`. Mark sensitive parameters (credentials) with
  `#[\SensitiveParameter]`.
- Tests are Nette Tester `.phpt`; `tests/bootstrap.php` provides `test()`. Interrupt
  tests use a `TestInterruptHandler` subclass that overrides `check()` to throw at a
  chosen operation instead of depending on signals/STDIN. Fixtures in `tests/fixtures/`.
- Extensions are protocol-dependent: `ext-zlib` (required); `ext-ftp`/`ext-openssl`
  (ftp/ftps); `ext-ssh2` (sftp) - or the `phpsec://` protocol (pure-PHP phpseclib).

## Working in this repo

- **The deployment is a transaction and `rename` is the commit.** Files upload to a
  `<path>.deploytmp` sibling; after all uploads succeed, everything is renamed to its
  real name so the web server never sees a half-written file. The new `.htdeployment`
  is itself uploaded as `.deploytmp` and renamed **last**, so the recorded remote state
  flips atomically with the files.
- **A `.running` marker is written up front** to detect a concurrent deploy
  (`checkNotRunning()` refuses without `--force`); a leftover marker after a crash
  blocks the next deploy - a deliberate safety brake. The `finally` cleanup is
  **best-effort when the phase failed** and must never let a cleanup error mask the
  original error.
- **Skipped operations (e.g. interrupted) regenerate the deployment file** to reflect
  the *actual* server state - otherwise the next deploy would think those files are
  synced and never retry them.
- **Only the upload phase is parallelizable** (each file has its own `.deploytmp`, no
  ordering dependency). Rename (the commit), delete, and purge must stay serial and
  are ordering-sensitive (a directory only after its contents).
- **`RetryServer` wraps every `Server`** and retries on `ServerException` with a
  reconnect; callers that must not retry (e.g. probing `.running`) pass a no-retry flag
  that unwraps it. `InterruptHandler` is a cooperative flag-based cancel; **skip and
  cancel have different `.deploytmp` cleanup semantics**.
- User-facing how-to (the `.ini`/`.php` config formats, hook signatures, mask syntax,
  adding server types/preprocessors/job types) lives in the README, not here.
