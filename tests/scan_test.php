<?php
require_once __DIR__ . '/lib.php';

$d = lh_tmpdir();
$now = (new DateTimeImmutable('2026-09-21 12:00:00', new DateTimeZone('America/Chicago')))->getTimestamp();
date_default_timezone_set('America/Chicago');

/* a php log where one plugin spams a warning, plus a little unrelated noise */
$php = '';
for ($i = 0; $i < 600; $i++) {
    $t = date('d-M-Y H:i:s', $now - 600 + $i);
    $php .= "[$t America/Chicago] PHP Warning:  Undefined array key 1 in /usr/local/emhttp/plugins/spammy/x.php on line 78\n";
    if ($i % 100 === 0) $php .= "[$t America/Chicago] PHP Notice:  once in /usr/local/emhttp/plugins/quiet/y.php on line 5\n";
}
file_put_contents("$d/phplog", $php);
file_put_contents("$d/syslog", implode('', array_map(fn($i) => date('M j H:i:s', $now - 100 + $i) . " tower emhttpd: read SMART /dev/sd" . chr(97 + $i % 6) . "\n", range(0, 5))));

/* an old flood that a first scan must not report as current */
$old = '';
for ($i = 0; $i < 400; $i++) $old .= '[' . date('d-M-Y H:i:s', $now - 10 * 86400 + $i) . " America/Chicago] PHP Warning:  ancient in /usr/local/emhttp/plugins/old/z.php on line 1\n";
file_put_contents("$d/oldphp", $old);

/* a docker root: one big container without limits, one limited, and a crash loop in the first */
$mkc = function (string $id, string $name, ?string $max, string $log) use ($d) {
    mkdir("$d/docker/$id", 0777, true);
    file_put_contents("$d/docker/$id/config.v2.json", json_encode(['Name' => "/$name"]));
    file_put_contents("$d/docker/$id/hostconfig.json", json_encode(['LogConfig' => ['Type' => 'json-file', 'Config' => $max ? ['max-size' => $max, 'max-file' => '3'] : new stdClass]]));
    file_put_contents("$d/docker/$id/$id-json.log", $log);
};
$loop = '';
for ($i = 0; $i < 300; $i++) {
    $ts = gmdate('Y-m-d\TH:i:s', $now - 300 + $i) . '.000000000Z';
    foreach (['Traceback (most recent call last):', '  File "/app/x.py", line ' . $i . ', in run', 'FileNotFoundError: [Errno 2] no tmp dir'] as $l)
        $loop .= json_encode(['log' => $l . "\n", 'stream' => 'stderr', 'time' => $ts]) . "\n";
}
$id1 = str_repeat('a', 64); $id2 = str_repeat('b', 64);
$mkc($id1, 'crashy', null, $loop);
$mkc($id2, 'tidy', '10m', json_encode(['log' => "fine\n", 'stream' => 'stdout', 'time' => gmdate('Y-m-d\TH:i:s', $now) . '.0Z']) . "\n");
file_put_contents("$d/daemon.json", '{}');

$cfg = lh_defaults();
$cfg['sources']['php']['files'] = ["$d/phplog", "$d/oldphp"];
$cfg['sources']['syslog']['files'] = ["$d/syslog"];
$cfg['sources']['docker']['root'] = "$d/docker";
$cfg['sources']['plugins']['enabled'] = false;

$s = lh_scan($cfg, [], $now);
check('scan reads every enabled source', $s['lines_read'] > 600 && count($s['sources']) === 5, "lines {$s['lines_read']} sources " . count($s['sources']));
$attrs = array_column($s['spam'], 'attr');
check('the spamming plugin is found and named', in_array('spammy', $attrs, true));
check('the quiet plugin is not', !in_array('quiet', $attrs, true));
check('a 10-day-old flood is not news on a first scan', !in_array('old', $attrs, true));
$spammy = array_values(array_filter($s['spam'], fn($x) => $x['attr'] === 'spammy'))[0];
check('it says where in the code', $spammy['where'] === 'spammy/x.php:78');
check('and how much and how fast (one a second is flooding)', $spammy['total'] === 600 && $spammy['rate'] >= 55 && $spammy['level'] === 'flooding', json_encode($spammy));
check('syslog varying only in device letter is one signature but too rare to flag', !in_array('emhttpd', $attrs, true));
$crash = array_values(array_filter($s['groups'], fn($g) => $g['rep']['attr'] === 'crashy'));
check('a docker crash loop is one incident, not one per traceback line', count($crash) === 1 && $crash[0]['related'] >= 1, json_encode(array_column($s['groups'], 'related')));
check('represented by the exception line', str_contains($crash[0]['rep']['sig'], 'FileNotFoundError'));
$dk = $s['docker']['containers'];
check('docker containers are named from their config', isset($dk[substr($id1, 0, 12)]) && $dk[substr($id1, 0, 12)]['name'] === 'crashy');
check('no daemon limit and no container limit is flagged unlimited', $dk[substr($id1, 0, 12)]['limited'] === false);
check('a container with its own max-size is limited', $dk[substr($id2, 0, 12)]['limited'] === true);
check('docker report carries the daemon default', array_key_exists('max_size', $s['docker']['daemon']));
check('a container limit is reported with its size and worst case', $dk[substr($id2, 0, 12)]['max_size'] === '10m' && $dk[substr($id2, 0, 12)]['worst_case_bytes'] === 3 * 10 * 1048576);
check('no limit anywhere means no worst case', $dk[substr($id1, 0, 12)]['worst_case_bytes'] === null && $dk[substr($id1, 0, 12)]['pct_of_file'] === null);

/* second scan: only what was appended is read, the total accumulates, the state carries over */
file_put_contents("$d/phplog", str_repeat('[' . date('d-M-Y H:i:s', $now + 30) . " America/Chicago] PHP Warning:  Undefined array key 1 in /usr/local/emhttp/plugins/spammy/x.php on line 78\n", 100), FILE_APPEND);
$s2 = lh_scan($cfg, $s, $now + 300);
$sp2 = array_values(array_filter($s2['spam'], fn($x) => $x['attr'] === 'spammy'))[0];
check('rescan reads only the new lines', $s2['lines_read'] === 100, "read {$s2['lines_read']}");
check('totals accumulate while it stays flagged', $sp2['total'] === 700 && $sp2['first_seen'] === $spammy['first_seen']);

/* aging: nothing new for over a day drops it */
$s3 = lh_scan($cfg, $s2, $now + 300 + 90000);
check('a signature ages out after a day of quiet', !in_array('spammy', array_column($s3['spam'], 'attr'), true));

/* muting */
$cfg['muted'][$spammy['id']] = ['label' => 'spammy', 'since' => $now];
$m = lh_scan($cfg, [], $now);
check('a muted signature is hidden and counted as hidden', !in_array('spammy', array_column($m['spam'], 'attr'), true) && $m['muted_hidden'] >= 1);

/* switching a source off */
$off = $cfg; $off['sources']['php']['enabled'] = false; $off['muted'] = [];
$o = lh_scan($off, [], $now);
check('a disabled source is not read', !in_array('spammy', array_column($o['spam'], 'attr'), true));
$off['sources']['docker']['enabled'] = false;
check('docker can be turned off entirely', lh_scan($off, [], $now)['docker']['enabled'] === false);
$off['sources']['docker'] = ['enabled' => true, 'root' => "$d/docker", 'scan_content' => false, 'warn_mb' => 100, 'crit_mb' => 500];
$sz = lh_scan($off, [], $now);
check('docker size can be watched without reading log content', count($sz['docker']['containers']) === 2 && !in_array('crashy', array_column($sz['spam'], 'attr'), true));

/* damaged config falls back to defaults */
file_put_contents("$d/bad.json", '{not json');
check('a damaged config file means defaults', lh_load_config("$d/bad.json") === lh_defaults());
file_put_contents("$d/ok.json", json_encode(['sources' => ['php' => ['enabled' => false]], 'muted' => ['abc' => ['label' => 'x']]]));
$lc = lh_load_config("$d/ok.json");
check('saved settings lie over the defaults', $lc['sources']['php']['enabled'] === false && $lc['sources']['syslog']['enabled'] === true && isset($lc['muted']['abc']) && $lc['sources']['php']['files'] === lh_defaults()['sources']['php']['files']);

lh_rmtree($d);
finish('scan');
