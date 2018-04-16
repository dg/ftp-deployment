<?php

use Deployment\Helpers;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


Assert::same('ftp://domain', Helpers::buildUrl(parse_url('ftp://domain')));
Assert::same('ftp://domain:123', Helpers::buildUrl(parse_url('ftp://domain:123')));
Assert::same('ftp://domain:123/', Helpers::buildUrl(parse_url('ftp://domain:123/')));
Assert::same('ftp://domain:123/path', Helpers::buildUrl(parse_url('ftp://domain:123/path')));
Assert::same('ftp://user@domain:123/path', Helpers::buildUrl(parse_url('ftp://user@domain:123/path')));
Assert::same('ftp://user:pass@domain:123/path', Helpers::buildUrl(parse_url('ftp://user:pass@domain:123/path')));
Assert::same('ftp://:pass@domain:123/path', Helpers::buildUrl(parse_url('ftp://:pass@domain:123/path')));
Assert::same('file://a', Helpers::buildUrl(parse_url('file://a')));
