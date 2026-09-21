<?php
require_once __DIR__ . '/lib.php';

$php = lh_parse_php('[20-Sep-2026 22:27:08 America/Chicago] PHP Warning:  Undefined array key 1 in /usr/local/emhttp/plugins/dynamix.system.temp/nchan/system_temp on line 78');
check('php: parses', $php !== null);
check('php: message keeps the level', $php['msg'] === 'Warning: Undefined array key 1');
check('php: plugin is attributed from the path', $php['attr'] === 'dynamix.system.temp');
check('php: where is file:line under the plugins dir', $php['where'] === 'dynamix.system.temp/nchan/system_temp:78');
check('php: time is read in its own zone', $php['ts'] === (new DateTimeImmutable('2026-09-20 22:27:08', new DateTimeZone('America/Chicago')))->getTimestamp());
$core = lh_parse_php('[20-Sep-2026 23:03:49 America/Chicago] PHP Deprecated:  trim(): Passing null to parameter #1 ($string) of type string is deprecated in /usr/local/emhttp/plugins/dynamix/include/Translations.php on line 30');
check('php: Unraid core files are attributed to dynamix', $core['attr'] === 'dynamix');
$colon = lh_parse_php('[01-Jan-2026 00:00:00 UTC] PHP Fatal error:  Uncaught Error: boom in /usr/local/emhttp/plugins/foo/a.php:12');
check('php: file:line form', $colon['where'] === 'foo/a.php:12' && $colon['attr'] === 'foo');
check('php: no file gives the generic source', lh_parse_php('[01-Jan-2026 00:00:00 UTC] PHP Notice:  hello')['attr'] === 'php');
check('php: stack trace lines are not entries', lh_parse_php('PHP Stack trace:') === null && lh_parse_php('#0 /a.php(1): foo()') === null);
check('php: unknown zone keeps the message', lh_parse_php('[01-Jan-2026 00:00:00 Mars/Base] PHP Warning:  x in /a.php on line 1')['msg'] === 'Warning: x');

$now = (new DateTimeImmutable('2026-09-21 04:00:00'))->getTimestamp();
$sys = lh_parse_syslog('Sep 20 23:00:04 tower emhttpd: read SMART /dev/sdd', $now);
check('syslog: parses', $sys !== null && $sys['attr'] === 'emhttpd' && $sys['msg'] === 'read SMART /dev/sdd');
check('syslog: time is this year', $sys['ts'] === (new DateTimeImmutable('2026-09-20 23:00:04'))->getTimestamp());
check('syslog: the pid is not part of the program', lh_parse_syslog('Sep 20 23:00:04 tower sshd-session[897724]: Read error', $now)['attr'] === 'sshd-session');
$jan = lh_parse_syslog('Dec 31 23:59:59 tower x: y', (new DateTimeImmutable('2027-01-01 00:10:00'))->getTimestamp());
check('syslog: december read in january is last year', $jan['ts'] === (new DateTimeImmutable('2026-12-31 23:59:59'))->getTimestamp());
check('syslog: junk is not an entry', lh_parse_syslog('not a syslog line', $now) === null);

$dk = lh_parse_docker('{"log":"boom happened\n","stream":"stderr","time":"2026-09-20T22:27:08.123456789Z"}');
check('docker: message without the newline', $dk['msg'] === 'boom happened');
check('docker: time', $dk['ts'] === gmmktime(22, 27, 8, 9, 20, 2026));
check('docker: blank and malformed lines are skipped', lh_parse_docker('{"log":"\n"}') === null && lh_parse_docker('garbage') === null && lh_parse_docker('{"nolog":1}') === null);
check('plain: blank is skipped', lh_parse_plain('  ') === null && lh_parse_plain(' x ')['msg'] === 'x');

/* the real sample files parse */
$n = 0; $ok = 0;
foreach (file(lh_fixture('phplog.sample'), FILE_IGNORE_NEW_LINES) as $l) { $n++; if (lh_parse_php($l)) $ok++; }
check('real phplog sample: every line parses', $n > 0 && $n === $ok, "$ok of $n");
$n = 0; $ok = 0;
foreach (file(lh_fixture('syslog.sample'), FILE_IGNORE_NEW_LINES) as $l) { $n++; if (lh_parse_syslog($l, $now)) $ok++; }
check('real syslog sample: every line parses', $n > 0 && $n === $ok, "$ok of $n");
check('attribute: flash path', lh_attribute_path('/boot/config/plugins/foo/x.php') === 'foo' && lh_attribute_path('/tmp/x.php') === 'other');
finish('parsers');
