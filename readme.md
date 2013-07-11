FTP Deployment: smart upload via FTP
====================================

FTP deployment is a tool for automated deployment to an FTP server.

There is nothing worse than uploading web applications to FTP server manually,
using such tool as Total Commander. (Although, even worse is editing files directly
on the server and then trying keep some kind of synchronization ;-)

Once the process is automated, it costs you fraction of time and minimizes risk of error
(didn't I forget to upload some files?). Today exists sophisticated deploying techniques,
but many people are still using FTP. This tool is designed for them.

FTP Deployment is a script written in PHP (requires PHP 5.3 or never) and will automate
the entire process. Just say which local folder where upload to. This
information is stored in a text file `deployment.ini`, which you can associate
with script `deployment.php`, so deployment will become a one click thing.

```
php deployment.php deployment.ini
```

And what file `deployment.ini` contains? Required is only item `remote`, all others are optional:

```ini
[my site] ; There may be more than one section
; remote FTP server
remote = ftp://user:secretpassword@ftp.example.com/directory

; local path (optional)
local = .

; run in test-mode? (can be enabled by option -t or --test too)
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
; is allowed to delete remote files? (defaults to yes)
allowdelete = yes

; jobs to run before file upload
before[] = http://example.com/deployment.php?before

; jobs to run after file upload
after[] = http://example.com/deployment.php?after

; directories to purge after file upload
purge[] = temp/cache

; preprocess JS and CSS files? (defaults to yes)
preprocess = yes
```

In test mode (with `-t` option) uploading or deleting files is skipped, so you can use it
to verify your settings.

Item `ignore` uses the same format as [`.gitignore`](http://git-scm.com/docs/gitignore):

```
log - ignore all 'log' files or directories in all subfolders
/log - ignore 'log' file or directory in the root
app/log - ignore 'log' file or directory in the subfolder 'app'
data/* - ignore everything inside the folder 'data', but the folder will be created on FTP
!data/session - make an exception for the previous rules and do not ignore file or folder 'session'
project.pp[jx] - ignore files or folders 'project.ppj' and 'project.ppx'
```

Before uploading is started and after is finished you can call own scripts on
server (see `before` and `after`), which may for example switch server to maintenance mode.

Syncing a large number of files attempts to run in (something like) transaction: all files are
uploaded with extension `.deploytmp` and then are quickly renamed.

To the server is uploaded file `.htdeployment` with MD5 hashes of all the files and this
is used for synchronization. So next time you run `deployment.php`, only the changed files are uploaded
and deleted files are deleted on server (if it is not forbidden via directive `allowdelete`).

Uploaded files can be processed by preprocessor. In `deployment.php` there are predefined these rules: `.css` files
are compressed using the YUI Compressor and `.js` minified by Google Closure Compiler. These
tools are already included in the distribution, however, they require the presence of Java.

There is also rule for expanding [mod_include](http://httpd.apache.org/docs/current/mod/mod_include.html) Apache directives.
For example, you can create a file `combined.js`:

```
<!--#include file="jquery.js" -->
<!--#include file="jquery.fancybox.js" -->
<!--#include file="main.js" -->
```

This tool will combine scripts together and minifies them via Closure Compiler
and speed-up your website.

In the `deployment.ini` you can create multiple sections, i.e. you may have separated
rules for data and for application.
