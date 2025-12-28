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
deliberate safety brake, not a bug).

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

`Server` implementations (`FtpServer`, `SshServer`, `PhpsecServer`, `FileServer`) are
wrapped by **`RetryServer`**, which retries on a `ServerException` with a reconnect —
so callers that must bypass retries (e.g. probing whether `.running` exists) invoke
the method with a no-retry flag that unwraps `RetryServer`. `FtpServer::writeFile`
carries its own **FTPS mid-upload reconnect** for dropped secure connections.
**`InterruptHandler`** is a cooperative, flag-based cancel checked at defined points;
**skip and cancel have different cleanup semantics** for the `.deploytmp` files, which
is why skipped paths flow back into the deployment-file regeneration above.
