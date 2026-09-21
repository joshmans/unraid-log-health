<?php
define('LH_NO_DISPATCH', 1);
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-log-health/include/api.php';

$d = lh_tmpdir();
putenv("LH_CONFIG=$d/cfg/config.json"); putenv("LH_STATE_DIR=$d/state"); putenv("LH_CRON_DIR=$d/cron");
putenv('LH_NO_UPDATE_CRON=1'); putenv('LH_PLUGIN_DIR=' . realpath(__DIR__ . '/../source/usr/local/emhttp/plugins/unraid-log-health'));

[$st, $v] = lh_handle('state', [], 'GET');
check('state works before any scan', $st === 200 && $v['state'] === null && $v['settings']['schedule']['every'] === '15m' && $v['scanning'] === false);
check('the view lists the schedule choices with the default first among the recommended', count($v['schedules']) === 10 && $v['schedules'][3]['key'] === '15m');
check('the view carries the limits the form must respect', isset($v['limits']['min_count']) && $v['limits']['min_count'][0] === 'int');
check('the view says when the next scan is', $v['cron']['line'] !== null && $v['cron']['next'] > $v['now'] && $v['cron']['installed'] === false);
check('and says it in the server clock, as text', preg_match('/^(\w{3} )?\d{1,2}:\d{2} [AP]M$/', $v['cron']['next_at']) === 1, (string)$v['cron']['next_at']);
check('a time on another day carries the weekday', lh_clock(strtotime('2026-09-22 02:30'), strtotime('2026-09-21 14:00')) === 'Tue 2:30 AM' && lh_clock(strtotime('2026-09-21 21:30'), strtotime('2026-09-21 14:00')) === '9:30 PM' && lh_clock(null, 0) === null);

/* saving a schedule writes the cron file */
[$st, $v] = lh_handle('save', ['settings' => json_encode(['schedule' => ['every' => '30m']])]);
check('save works', $st === 200 && ($v['saved'] ?? false) === true && $v['settings']['schedule']['every'] === '30m');
check('save wrote the cron file', is_file(lh_cron_file()) && str_starts_with(file_get_contents(lh_cron_file()), '3-59/30 * * * * php'));
check('and the view reflects it', $v['cron']['installed'] === true);
[$st, $v] = lh_handle('save', ['settings' => json_encode(['schedule' => ['every' => 'off']])]);
check('choosing off removes the cron file', $st === 200 && !is_file(lh_cron_file()) && $v['cron']['line'] === null && $v['cron']['next'] === null);

[$st, $v] = lh_handle('save', ['settings' => json_encode(['schedule' => ['every' => 'sometimes'], 'thresholds' => ['min_count' => 5]])]);
check('an invalid save is refused with the reasons', $st === 422 && count($v['errors']) === 1 && $v['settings']['schedule']['every'] === 'off');
check('and nothing else in it was applied', $v['settings']['thresholds']['min_count'] === 50);
check('garbage settings are a 400', lh_handle('save', ['settings' => 'nope'])[0] === 400 && lh_handle('save', [])[0] === 400);
check('changing things needs POST', lh_handle('save', ['settings' => '{}'], 'GET')[0] === 405 && lh_handle('scan', [], 'GET')[0] === 405 && lh_handle('mute', ['ids' => '["aaaaaaaaaa"]'], 'GET')[0] === 405);
check('unknown action', lh_handle('explode', [])[0] === 400);

/* a scan, started in the background, produces state; muting hides what it flagged */
$logs = "$d/logs"; mkdir($logs);
$now = time(); date_default_timezone_set('UTC');
$lines = ''; for ($i = 0; $i < 400; $i++) $lines .= '[' . date('d-M-Y H:i:s', $now - 400 + $i) . " UTC] PHP Warning:  Undefined array key 1 in /usr/local/emhttp/plugins/spammy/x.php on line 78\n";
file_put_contents("$logs/phplog", $lines);
lh_handle('save', ['settings' => json_encode(['sources' => ['syslog' => ['enabled' => false], 'plugins' => ['enabled' => false], 'docker' => ['enabled' => false]]])]);
$cfg = lh_load_config(); $cfg['sources']['php']['files'] = ["$logs/phplog"]; lh_save_config($cfg);

[$st, $v] = lh_handle('scan', []);
check('scan starts', $st === 200 && $v['started'] === true);
for ($i = 0; $i < 100 && !is_file(lh_state_path()); $i++) usleep(100000);
check('the background scan produced state', is_file(lh_state_path()));
for ($i = 0; $i < 50 && lh_scan_running(); $i++) usleep(100000);
[, $v] = lh_handle('state', [], 'GET');
$spam = $v['state']['spam'] ?? [];
check('the page gets the flagged signature', count($spam) === 1 && array_values($spam)[0]['attr'] === 'spammy', json_encode(array_keys($spam)));
check('and the grouped incidents', count($v['state']['groups']) === 1);
check('read offsets are not sent to the page', !isset($v['state']['files']));
$id = array_key_first($spam);

$h = fopen(dirname(lh_state_path()) . '/scan.lock', 'c'); flock($h, LOCK_EX | LOCK_NB);
check('a running scan is reported', lh_handle('state', [], 'GET')[1]['scanning'] === true);
check('and a second Scan now does not start another', lh_handle('scan', [])[1]['started'] === false);
flock($h, LOCK_UN); fclose($h);

check('muting an id that was never flagged is refused', lh_handle('mute', ['ids' => '["0123456789"]'])[0] === 404);
check('a malformed id is refused', lh_handle('mute', ['ids' => '["../../etc"]'])[0] === 400 && lh_handle('mute', ['ids' => '[]'])[0] === 400 && lh_handle('mute', ['ids' => '"x"'])[0] === 400);
[$st, $v] = lh_handle('mute', ['ids' => json_encode([$id])]);
check('muting hides it at once, without a rescan', $st === 200 && $v['state']['spam'] === [] && $v['state']['groups'] === [] && $v['state']['muted_hidden'] >= 1);
check('it is listed as muted, with its text', isset($v['muted'][$id]) && str_contains($v['muted'][$id]['label'], 'Undefined array key'));
check('and stays muted through a rescan', (function () use ($id) { lh_spawn_scan(); usleep(1500000); for ($i = 0; $i < 50 && lh_scan_running(); $i++) usleep(100000); return lh_handle('state', [], 'GET')[1]['state']['groups'] === []; })());
[$st, $v] = lh_handle('unmute', ['ids' => json_encode([$id])]);
check('unmuting clears the mute', $st === 200 && $v['muted'] === []);
check('and it returns only when a scan sees it spamming again (a scan drops muted lines from its state)', $v['state']['groups'] === []);
$cfgNow = lh_load_config(); $cfgNow['muted'] = []; lh_save_config($cfgNow);
lh_handle('scan', []); usleep(1500000); for ($i = 0; $i < 50 && lh_scan_running(); $i++) usleep(100000);
file_put_contents("$logs/phplog", str_repeat('[' . date('d-M-Y H:i:s', time()) . " UTC] PHP Warning:  Undefined array key 1 in /usr/local/emhttp/plugins/spammy/x.php on line 78\n", 300), FILE_APPEND);
lh_handle('scan', []); usleep(1500000); for ($i = 0; $i < 50 && lh_scan_running(); $i++) usleep(100000);
check('once unmuted and still spamming, the next scan lists it again', count(lh_handle('state', [], 'GET')[1]['state']['groups']) === 1);

foreach (['LH_CONFIG', 'LH_STATE_DIR', 'LH_CRON_DIR', 'LH_NO_UPDATE_CRON', 'LH_PLUGIN_DIR'] as $k) putenv($k);
lh_rmtree($d);
finish('api');
