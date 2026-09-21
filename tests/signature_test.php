<?php
require_once __DIR__ . '/lib.php';

function same(string $a, string $b): bool { return lh_normalize($a) === lh_normalize($b); }

check('numbers do not split a signature', same('Undefined array key 1', 'Undefined array key 27'));
check('a quoted key does', !same('Undefined array key "Title"', 'Undefined array key "Icon"'));
check('ip addresses collapse', same('Failed publickey for root from 192.0.2.10 port 55464 ssh2', 'Failed publickey for root from 10.0.0.9 port 61 ssh2'));
check('ssh key hashes collapse', same('RSA SHA256:gAEQF5uzEW4fB2pSXEGou3jH1v+IWiUjEaur0HYEIPg', 'RSA SHA256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'));
check('device letters collapse', same('read SMART /dev/sdd', 'read SMART /dev/sdaf'));
check('nvme and md devices collapse', same('error on /dev/nvme0n1p2', 'error on /dev/md12p1'));
check('times and dates collapse', same('2026-09-20T22:27:08.123Z started', '2027-01-01 01:02:03 started'));
check('clock times collapse', same('at 23:00:04 done', 'at 01:02:59 done'));
check('uuids collapse', same('id 123e4567-e89b-12d3-a456-426614174000 ok', 'id 00000000-0000-0000-0000-000000000000 ok'));
check('container ids collapse', same('container e80b95ee1ea1 died', 'container 94e77bd40023 died'));
check('ipv6 collapses', same('from 2001:db8::d701:ef6f up', 'from fe80:0:0:1:2:3:4:5 up'));
check('a bare prefix is not an address', lh_normalize('dst=fe80::/64') === 'dst=fe80::/#');
check('versions collapse', same('python3.12 failed', 'python3.13 failed') && same('v1.2.3 loaded', 'v4.5.6 loaded'));
check('words with digits inside are left alone', lh_normalize('sha256 ssh2 ipv4') === 'sha256 ssh2 ipv4');
check('sizes keep their unit', lh_normalize('used 12.5MB of 100MB') === 'used #MB of #MB');
check('colour codes are stripped', lh_normalize("\x1b[31mERROR\x1b[0m boom") === 'ERROR boom');
check('whitespace is collapsed and trimmed', lh_normalize("  a \t  b  ") === 'a b');
check('different messages stay different', !same('disk full', 'disk failing'));
check('cut is utf-8 safe', lh_cut(str_repeat('é', 300), 240) === str_repeat('é', 240));
check('cut survives invalid utf-8', strlen(lh_cut("\xff\xfe" . str_repeat('a', 500), 240)) === 240);
check('signature ids are stable and 10 chars', lh_sig_id('php:x', 'a') === lh_sig_id('php:x', 'a') && strlen(lh_sig_id('php:x', 'a')) === 10);
check('signature ids differ by source key', lh_sig_id('php:x', 'a') !== lh_sig_id('php:y', 'a'));
finish('signature');
