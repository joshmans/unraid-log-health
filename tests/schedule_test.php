<?php
require_once __DIR__ . '/lib.php';

$c = fn(string $every, string $at = '03:00') => ['schedule' => ['every' => $every, 'daily_at' => $at]];
$exprs = ['5m' => '*/5 * * * *', '10m' => '*/10 * * * *', '15m' => '3-59/15 * * * *', '30m' => '3-59/30 * * * *', '1h' => '3 * * * *',
          '3h' => '3 */3 * * *', '6h' => '3 */6 * * *', '12h' => '3 */12 * * *', 'daily' => '0 3 * * *'];
foreach ($exprs as $k => $e) check("cron for $k", lh_cron_expr($k) === $e, (string)lh_cron_expr($k));
check('off has no cron', lh_cron_expr('off') === null && lh_cron_line($c('off')) === null);
check('unknown key has no cron', lh_cron_expr('nonsense') === null);
check('daily uses the chosen time', lh_cron_expr('daily', '22:45') === '45 22 * * *');
check('a bad daily time falls back to 03:00', lh_cron_expr('daily', '25:99') === '0 3 * * *' && !lh_valid_time('3:00') && lh_valid_time('00:00') && lh_valid_time('23:59'));
check('the default is every 15 minutes', LH_DEFAULT_EVERY === '15m' && lh_defaults()['schedule']['every'] === '15m');
check('every option has a label and the recommended one says so', count(lh_schedules()) === 10 && str_contains(lh_schedules()['15m'][0], 'recommended'));
check('the line runs the scan script quietly', str_ends_with(lh_cron_line($c('15m')), 'scripts/scan >/dev/null 2>&1') && str_starts_with(lh_cron_line($c('15m')), '3-59/15 * * * * php -q '));
check('interval minutes', lh_interval_minutes($c('15m')) === 15 && lh_interval_minutes($c('daily')) === 1440 && lh_interval_minutes($c('off')) === 0);

/* every expression must be valid vixie-cron: five fields, and a step range that stays in bounds */
foreach ($exprs as $k => $e) check("five fields for $k", count(explode(' ', $e)) === 5);

/* next run, from a fixed moment: 2026-09-21 14:20:10 */
$now = strtotime('2026-09-21 14:20:10');
$next = fn(string $k, string $at = '03:00') => date('H:i', lh_next_run($c($k, $at), $now));
check('next: 5m', $next('5m') === '14:25');
check('next: 15m is on the offset, not :00', $next('15m') === '14:33');
check('next: 1h', $next('1h') === '15:03');
check('next: 3h skips to a multiple of 3', $next('3h') === '15:03');
check('next: 6h', $next('6h') === '18:03');
check('next: daily rolls to tomorrow when today has passed', $next('daily', '02:30') === '02:30' && date('Y-m-d', lh_next_run($c('daily', '02:30'), $now)) === '2026-09-22');
check('next: daily later today stays today', date('Y-m-d', lh_next_run($c('daily', '23:00'), $now)) === '2026-09-21');
check('next: never for off', lh_next_run($c('off'), $now) === null);
check('next is always in the future', lh_next_run($c('5m'), strtotime('2026-09-21 14:25:00')) === strtotime('2026-09-21 14:30:00'));
foreach (array_keys(lh_schedules()) as $k) {
    if ($k === 'off') continue;
    $n = lh_next_run($c($k), $now);
    check("next within the interval: $k", $n > $now && $n - $now <= lh_interval_minutes($c($k)) * 60 + 60);
}

/* writing the cron file */
$d = lh_tmpdir(); putenv("LH_CRON_DIR=$d/plugin"); putenv('LH_NO_UPDATE_CRON=1'); putenv('LH_PLUGIN_DIR=/usr/local/emhttp/plugins/unraid-log-health');
$r = lh_cron_apply($c('15m'));
check('apply writes the file with the line', $r['changed'] && $r['error'] === null && file_get_contents(lh_cron_file()) === '3-59/15 * * * * php -q /usr/local/emhttp/plugins/unraid-log-health/scripts/scan >/dev/null 2>&1' . "\n");
$mt = filemtime(lh_cron_file()); sleep(1);
check('applying the same schedule again does not touch flash', lh_cron_apply($c('15m'))['changed'] === false && filemtime(lh_cron_file()) === $mt);
check('changing it rewrites', lh_cron_apply($c('1h'))['changed'] && str_starts_with(file_get_contents(lh_cron_file()), '3 * * * * php'));
check('off removes the file', lh_cron_apply($c('off'))['changed'] && !is_file(lh_cron_file()));
check('off when already off does nothing', lh_cron_apply($c('off'))['changed'] === false);
putenv('LH_CRON_DIR=/proc/nope/never'); 
check('an unwritable location is an error, not a crash', lh_cron_apply($c('15m'))['error'] !== null);
putenv('LH_CRON_DIR'); putenv('LH_NO_UPDATE_CRON'); putenv('LH_PLUGIN_DIR');
/* scripts/cron: what the installer runs at every install and boot */
$d2 = lh_tmpdir();
$run = fn(string $arg = '') => trim((string)shell_exec("LH_CONFIG=$d2/config.json LH_CRON_DIR=$d2/cron LH_NO_UPDATE_CRON=1 LH_PLUGIN_DIR=/usr/local/emhttp/plugins/unraid-log-health php " . escapeshellarg(__DIR__ . '/../source/usr/local/emhttp/plugins/unraid-log-health/scripts/cron') . " $arg 2>&1"));
check('cron script with no saved settings installs the default', str_starts_with($run(), '3-59/15 * * * * php -q') && is_file("$d2/cron/unraid-log-health.cron"));
$cfg = lh_defaults(); $cfg['schedule']['every'] = '6h'; lh_save_config($cfg, "$d2/config.json");
check('and a saved schedule wins over the default on the next boot', str_starts_with($run(), '3 */6 * * * php -q') && str_starts_with(file_get_contents("$d2/cron/unraid-log-health.cron"), '3 */6 * * *'));
$cfg['schedule']['every'] = 'off'; lh_save_config($cfg, "$d2/config.json");
check('off stays off across boots', $run() === 'scheduled scans are off' && !is_file("$d2/cron/unraid-log-health.cron"));
$cfg['schedule']['every'] = '1h'; lh_save_config($cfg, "$d2/config.json"); $run();
check('remove deletes the file', $run('remove') === 'cron file removed' && !is_file("$d2/cron/unraid-log-health.cron"));
check('remove when there is nothing to remove is fine', $run('remove') === 'cron file removed');
lh_rmtree($d2);

lh_rmtree($d);
finish('schedule');
