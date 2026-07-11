<?php declare(strict_types=1);

use Deployment\Helpers;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';


$blob = 'AAAAC3NzaC1lZDI1NTE5AAAAIN1sample0000key0000blob0000bytes00=';
$key = "ssh-ed25519 $blob comment@host";
$sha256 = 'SHA256:ycHuPDwMY2vc31WnbIQ+U85SlvMGEQyQH8Qb9ia9wGk';
$md5 = '31:d5:fb:de:43:30:da:f4:ac:c3:27:9e:0c:3f:98:6f';

// full key, "type base64" without comment, bare blob and fingerprints all match
Assert::true(Helpers::matchHostKey($key, $key));
Assert::true(Helpers::matchHostKey("ssh-ed25519 $blob", $key)); // configured without the comment
Assert::true(Helpers::matchHostKey($blob, $key));
Assert::true(Helpers::matchHostKey($sha256, $key));
Assert::true(Helpers::matchHostKey($md5, $key));
Assert::true(Helpers::matchHostKey('MD5:' . $md5, $key));
Assert::true(Helpers::matchHostKey(str_replace(':', '', $md5), $key)); // md5 without colons
Assert::true(Helpers::matchHostKey("  $sha256  ", $key)); // surrounding whitespace

// wrong fingerprint and empty expectation are rejected
Assert::false(Helpers::matchHostKey('SHA256:AAAAdefinitelywrongfingerprintvalueAAAA', $key));
Assert::false(Helpers::matchHostKey('', $key));
Assert::false(Helpers::matchHostKey($sha256, 'ssh-ed25519 not-valid-base64!!!'));

// hex fingerprint normalization
Assert::same('31d5fbde4330daf4acc3279e0c3f986f', Helpers::normalizeFingerprint($md5));
Assert::same('31d5fbde4330daf4acc3279e0c3f986f', Helpers::normalizeFingerprint('MD5:' . strtoupper($md5)));
