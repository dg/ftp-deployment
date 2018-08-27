FTP Deployment: smart upload [![Buy me a coffee](https://files.nette.org/images/coffee1s.png)](https://nette.org/make-donation?to=deployment)
====================================

[![Downloads this Month](https://img.shields.io/packagist/dm/dg/ftp-deployment.svg)](https://packagist.org/packages/dg/ftp-deployment)
[![Build Status](https://travis-ci.org/dg/ftp-deployment.svg?branch=master)](https://travis-ci.org/dg/ftp-deployment)
[![Latest Stable Version](https://poser.pugx.org/dg/ftp-deployment/v/stable)](https://github.com/dg/ftp-deployment/releases)
[![License](https://img.shields.io/badge/license-New%20BSD-blue.svg)](https://github.com/dg/ftp-deployment/blob/master/license.md)

FTP deployment is a tool for automated deployment to an FTP server.

There is nothing worse than uploading web applications to FTP server manually,
using tools like Total Commander. (Although, editing files directly on the server
and then trying to keep some kind of synchronization is even worse ;-)

Once the process is automated, it costs you a fraction of time and minimizes the risk of error
(didn't I forget to upload some files?). There are lots of sophisticated deploying techniques available today,
but many people are still using FTP. This tool is designed for them.

FTP Deployment is a script written in PHP and will automate
the entire process. Just say which local folder to upload and where. This
information is stored in a `deployment.ini` text file, which you can associate
with `deployment` script, so deployment will become a one click thing.

```
php deployment deployment.ini
```

And what does the `deployment.ini` file contain? **Only the `remote` item is required**, all the others are optional:

```ini
; log file (defaults to config file with .log extension)
log = ...

; directory for temporary files (defaults to system's temporary directory)
tempDir = /temp/deployment

; enable colored highlights? (defaults to autodetect)
colors = yes

[my site] ; Optional section (there may be more than one section).
; remote FTP server
remote = ftp://user:secretpassword@ftp.example.com/directory
; you can use ftps://, sftp:// or file:// protocols (sftp requires SSH2 extension)

; do not like to specify user & password in 'remote'? Use these options:
user = ...
password = ...

; FTP passive mode
passiveMode = yes

; local path (optional)
local = .

; run in test-mode? (can be enabled by option -t or --test)
test = no

; files and directories to ignore
ignore = "
	.git*
	project.pp[jx]
	/deployment.*
	/log
	temp/*
	!temp/.htaccess
"

; explicit list of files and directories to include (by default includes all files and directories)
include = "
	/app
	/app/*
	/index.php
"

; is allowed to delete remote files? (defaults to yes)
allowDelete = yes

; jobs to run before uploading
before[] = local: lessc assets/combined.less assets/combined.css
before[] = http://example.com/deployment.php?before

; jobs to run after uploading and before uploaded files are renamed
afterUpload[] = http://example.com/deployment.php?afterUpload

; directories to purge after uploading
purge[] = temp/cache

; jobs to run after everything (upload, rename, delete, purge) is done
after[] = remote: unzip api.zip
after[] = remote: chmod 0777 temp/cache  ; change permissions
after[] = upload: config.server.neon app/config.local.neon
after[] = http://example.com/deployment.php?after

; files to preprocess (defaults to none)
preprocess = *.js *.css

; file which contains hashes of all uploaded files (defaults to .htdeployment)
deploymentFile = .deployment

; default permissions for new files (defaults to none)
filePermissions = 0644

; default permissions for new directories (defaults to none)
dirPermissions = 0755
```

In the config file you can create multiple sections (like `[my site]`), so you may have separate
rules for data and for application.

Configuration can also be stored in a [PHP file](deployment.sample.php).

In test mode (with `-t` option) uploading or deleting files is skipped, so you can use it
to verify your settings.

Item `ignore` uses the similar format to [`.gitignore`](http://git-scm.com/docs/gitignore):

```
log - ignore all 'log' files or directories in all subfolders
/log - ignore 'log' file or directory in the root
app/log - ignore 'log' file or directory in the 'app' in the root
data/* - ignore everything inside the 'data' folder, but the folder will be created on FTP
!data/db/file.sdb - make an exception for the previous rule and do not ignore file 'file.sdb'
project.pp[jx] - ignore files or folders 'project.ppj' and 'project.ppx'
```

Before the upload starts, after it finishes and after all jobs, you can execute commands or call your scripts on
the server (see `before`, `afterUpload`, `after`), which can, for example, switch the server to a maintenance mode.
If you use PHP config, you can run lambda function with deployment environment:

```php
<?php

return [
	'remote' => 'ftp://user:secretpassword@ftp.example.com/directory',
	'local' => '.',
	'before' => [
		function (Deployment\Server $server, Deployment\Logger $logger, Deployment\Deployer $deployer) {
			... do something
		},
	],
	...
];
```

Syncing a large number of files attempts to run in (something like) a transaction: all files are
uploaded with extension `.deploytmp` and then quickly renamed.

An `.htdeployment` file is uploaded to the server, which contains MD5 hashes of all the files and
is used for synchronization. So the next time you run `deployment`, only modified files are uploaded
and deleted files are deleted from server (if it is not forbidden by the `allowDelete` directive).

Uploaded files can be processed by a preprocessor. These rules are predefined: `.css` files
are compressed using the Clean-CSS (via online service) and `.js` are minified by Google Closure Compiler (via Java utility).

There is also a rule for expanding [mod_include](http://httpd.apache.org/docs/current/mod/mod_include.html) Apache directives.
For example, you can create a file `combined.js`:

```
<!--#include file="jquery.js" -->
<!--#include file="jquery.fancybox.js" -->
<!--#include file="main.js" -->
```

This tool will combine scripts together and minify them with the Closure Compiler to speed-up your website.


Installing FTP Deployment
-------------------------

FTP Deployment 3.x requires PHP 7.1 or later (version 2.x requires PHP 5.4 or newer). It also requires openssl extensions for ftps:// and SSH2 extension for sftp:// connections.

The easiest way to obtain FTP Deployment is to download [a single PHAR file](https://github.com/dg/ftp-deployment/releases). If you want to use JavaScript minification,
download [compiler.jar](src/vendor/Google-Closure-Compiler/compiler.jar) to the same folder.

Or you can install it using Composer:

```
composer create-project dg/ftp-deployment
```

If you like it, **[please make a donation now](https://nette.org/make-donation?to=deployment)**. Thank you!
