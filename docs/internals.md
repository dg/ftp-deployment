# FtpDeployment internals

How `dg/ftp-deployment` deploys, for agents editing it. Thin but real: the value is
one emergent transactional model and the invariants around it. One file.

## The deployment lifecycle is a transaction, and rename is the commit

`Deployer::deploy()` computes the diff (remote state comes from a downloaded
`.htdeployment` hash file; `toUpload` = local files whose hash differs,
`toDelete` = remote files absent locally when `allowDelete`), then `transfer()` runs
the whole mutating phase inside a **transactional envelope**:

```
scan → compare → upload (into <path>.deploytmp) → write .htdeployment.deploytmp
     → rename all .deploytmp → real → delete → purge
```

The transactionality rests on one invariant: **files are uploaded to a
`.deploytmp` sibling and the atomic `rename` is the commit** — the web server never
sees a half-written file. The new `.htdeployment` is itself uploaded as a
`.deploytmp` and renamed last, so the recorded remote state flips atomically with the
files.

## The `.running` marker and best-effort cleanup

`transfer()` creates a **`.running` marker file up front** so a concurrent
deployment can detect an in-progress one (`checkNotRunning()` refuses to start unless
`--force`). A `finally` block then removes the marker **and** all `.deploytmp` temp
files. The subtlety: after a failure the connection may be broken, so the finally
cleanup is **best-effort when the phase failed** (`bestEffort: $failed`) — it skips
the full retry storm and **never lets a cleanup error mask the original error**. A
leftover `.running` after a crash blocks the next deploy until `--force` (a
deliberate safety brake, not a bug). The user's `finally` jobs run after this cleanup
(`runFinallyJobs()`), also when nothing was synchronized or the deployment failed; a
failing one is logged, the rest still run, and it only fails a deployment that
otherwise succeeded. The only exception is a deploy refused by `checkNotRunning()`
(`LockedException`): the `finally` jobs are skipped (and the skip is logged), since they
would interfere with the deployment that holds the marker. When the connection failed,
`$remoteDir` stays uninitialized (`deploy()` unsets it) and the jobs that need the
server (`remote:`, `upload:`, `download:`) are logged as skipped; the others run.

## Skipped operations rewrite the recorded state

Upload/rename/delete each return the paths they **skipped** (e.g. interrupted). Any
skip means the server no longer matches the `.htdeployment` that would otherwise be
written, so the deployment file is **regenerated to reflect the actual server state**
(`revertSkippedPaths`/`regenerateDeploymentFile`) — otherwise the next deploy would
wrongly believe those files are already synced and never retry them. This is the
non-obvious correctness glue between a partial deploy and the next one.

## What is safe to parallelize, and what is not

Only the **upload phase** is safely parallelizable: each file goes to its own
`.deploytmp` target with no cross-file ordering dependency. Everything else must stay
serial — **rename is the commit and must follow all uploads**; **delete and purge are
ordering-sensitive** (a directory only after its contents). The one shared
prerequisite of the upload phase is `createDir`, today satisfied by grouping files by
directory.

## Servers, retries, and interruption

`Server` implementations (`FtpServer`, `CurlServer`, `SshServer`, `PhpsecServer`, `FileServer`) are
wrapped by **`RetryServer`**, which retries on a `ServerException` with a reconnect —
so callers that must bypass retries (e.g. probing whether `.running` exists) invoke
the method with a no-retry flag that unwraps `RetryServer`. `FtpServer::writeFile`
carries its own **FTPS mid-upload reconnect** for dropped secure connections.
**`InterruptHandler`** is a cooperative, flag-based cancel checked at defined points;
**skip and cancel have different cleanup semantics** for the `.deploytmp` files, which
is why skipped paths flow back into the deployment-file regeneration above.

**`ftps://` is served by `CurlServer` whenever ext-curl is loaded** (`curl = no` forces
`FtpServer`). With TLS 1.3, ext-ftp fails to resume the TLS session on every second upload
against servers that require it (ProFTPD answers 425, php/php-src#25656), and the
reconnect that hides the failure costs about a second per file. `CurlServer` talks over
one cURL handle, which keeps the control connection open between requests: transfers are
requests with an absolute path (`CURLFTPMETHOD_NOCWD`, so no working directory to track),
everything else is a body-less request carrying the commands in `CURLOPT_QUOTE`. The
commands go out prefixed with `*`, because cURL closes the connection after a refused
command and a refusal is how "does not exist" is learnt; the verdict is read from the
replies collected by the header callback instead. Whether a file exists is asked with
`SIZE`, so on a server without it `removeFile()` cannot tell a failed `DELE` from a
missing file and stays silent.

With `verifyCertificate = yes`, `CurlServer` verifies the certificate of the server, which
ext-ftp cannot do. It is off by default, because hostings commonly serve a certificate of
their own domain under the customer's host name and an update of the tool must not stop
those deployments. A failed verification is thrown as a plain `\Exception`,
not a `ServerException`, so that `RetryServer` does not retry what cannot succeed. On
Windows the certificate store of the system is used besides the configured CA file,
because it completes a chain that the server sends without its intermediate certificate.

## One console draws everything the user sees

`Logger` writes to the log file and, through a `Nette\CommandLine\Console`, to the
screen; `progress()` is that console's **status**, drawn in place and erased by the next
`log()`. Everything written must go through **the same console instance** — `CliRunner`
creates it and hands it to the logger, to `InterruptHandler` and to the help renderer —
because the status lives in the instance, so the interrupt prompt written through another
console would leave it on the screen. The log file gets the text without escape sequences
(`Ansi::strip()`) and off a terminal the status draws nothing, so redirected output is
clean and nothing needs padding to a guessed width.
