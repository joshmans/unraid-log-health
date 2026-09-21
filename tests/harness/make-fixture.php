<?php
/* Builds a throwaway set of logs and a Docker root that look like a busy box, scans them twice (an hour apart, so growth
 * shows), and prints the directory. Used by serve.sh to look at the page without an Unraid box. */
require_once __DIR__ . '/../lib.php';
date_default_timezone_set('UTC');

$d = lh_tmpdir();
$now = time();
mkdir("$d/logs"); mkdir("$d/docker"); mkdir("$d/state");

function php_lines(int $now, int $n, int $secs, string $msg, string $file): string {
    $o = ''; for ($i = 0; $i < $n; $i++) $o .= '[' . date('d-M-Y H:i:s', $now - $secs + intdiv($i * $secs, $n)) . " UTC] PHP $msg in $file\n";
    return $o;
}
file_put_contents("$d/logs/phplog",
    php_lines($now, 900, 900, 'Warning:  Undefined array key 1', '/usr/local/emhttp/plugins/dynamix.system.temp/nchan/system_temp on line 78')
  . php_lines($now, 300, 3000, 'Warning:  Undefined array key "PluginAuthor"', '/usr/local/emhttp/plugins/community.applications/include/helpers.php on line 670')
  . php_lines($now, 12, 3000, 'Notice:  Only a few', '/usr/local/emhttp/plugins/quiet/a.php on line 3'));
$sys = ''; for ($i = 0; $i < 400; $i++) $sys .= date('M j H:i:s', $now - 2400 + $i * 6) . ' tower sshd-session[' . (1000 + $i) . ']: Failed publickey for root from 192.0.2.' . ($i % 200) . ' port ' . (50000 + $i) . " ssh2: RSA SHA256:abcdefghijklmnopqrstuvwxyz0123456789ABC$i\n";
file_put_contents("$d/logs/syslog", $sys);
file_put_contents("$d/logs/tailscale.log", str_repeat("2026/09/20 10:00:00 monitor: RTM_DELROUTE: src=, dst=fe80::/64, gw=, outif=3, table=255\n", 300));

/* containers: [name, real size, max-size, lines to write into the tail] */
$mk = function (string $id, string $name, int $size, ?string $max, callable $lines) use ($d, $now) {
    mkdir("$d/docker/$id");
    file_put_contents("$d/docker/$id/config.v2.json", json_encode(['Name' => "/$name"]));
    file_put_contents("$d/docker/$id/hostconfig.json", json_encode(['LogConfig' => ['Type' => 'json-file', 'Config' => $max ? ['max-size' => $max, 'max-file' => '3'] : new stdClass]]));
    $body = ''; foreach ($lines($now) as $l) $body .= json_encode(['log' => $l . "\n", 'stream' => 'stderr', 'time' => gmdate('Y-m-d\TH:i:s', $l === '' ? $now : ($GLOBALS['t'] += $GLOBALS['step'])) . '.000000000Z']) . "\n";
    $f = fopen("$d/docker/$id/$id-json.log", 'w'); ftruncate($f, max($size, strlen($body))); fseek($f, max($size, strlen($body)) - strlen($body)); fwrite($f, $body); fclose($f);
};
$GLOBALS['t'] = $now - 900; $GLOBALS['step'] = 1;
$crash = function ($now) { $o = []; for ($i = 0; $i < 300; $i++) { $o[] = 'Traceback (most recent call last):'; $o[] = '  File "/lsiopy/lib/python3.12/site-packages/selkies/__main__.py", line ' . (100 + $i % 3) . ', in run'; $o[] = "FileNotFoundError: [Errno 2] No usable temporary directory found in ['/tmp', '/var/tmp', '/usr/tmp']"; } return $o; };
$mk(str_repeat('1', 64), 'file-manager', 759 << 20, '1g', $crash);
$mk(str_repeat('2', 64), 'game-station', 734 << 20, '1g', $crash);
$mk(str_repeat('3', 64), 'ip-tracker', 92 << 20, '1g', function ($n) { $o = []; for ($i = 0; $i < 900; $i++) $o[] = "info: Microsoft.EntityFrameworkCore.Database.Command[$i]"; return $o; });
$GLOBALS['step'] = 8; $GLOBALS['t'] = $now - 7200;
$mk(str_repeat('4', 64), 'recipes-web', 20 << 20, '1g', function ($n) { $o = []; for ($i = 0; $i < 900; $i++) $o[] = '::1 - - [20/Sep/2026:10:00:00 -0500] "GET / HTTP/1.1" 200 5 "-" "curl/8.0" "-"'; return $o; });
$GLOBALS['step'] = 1; $GLOBALS['t'] = $now - 900;
$mk(str_repeat('5', 64), 'photo-library', 471 << 20, '1g', fn($n) => ['[Nest] 7 - LOG ok']);
$mk(str_repeat('6', 64), 'tidy', 3 << 20, null, fn($n) => ['fine']);
file_put_contents("$d/daemon.json", '{}');

$cfg = lh_defaults();
$cfg['sources']['php']['files'] = ["$d/logs/phplog"];
$cfg['sources']['syslog']['files'] = ["$d/logs/syslog"];
$cfg['sources']['docker']['root'] = "$d/docker";
$cfg['sources']['plugins']['glob'] = "$d/logs/*.log";
$cfg['muted'] = ['deadbeef01' => ['label' => 'Warning: Undefined array key "Title" @ dynamix/include/DefaultPageLayout/Navigation/Main.php:64', 'source' => 'php:dynamix', 'since' => $now - 86400]];
mkdir("$d/cfg"); lh_save_config($cfg, "$d/cfg/config.json");
putenv("LH_STATE_DIR=$d/state");
$s1 = lh_scan($cfg, [], $now - 3600);
file_put_contents("$d/state/state.json", json_encode(lh_scan($cfg, $s1, $now)));
echo $d;
