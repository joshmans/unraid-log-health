<?php
/* How often to scan, in words the user picks, turned into a cron entry.
 *
 * Unraid's update_cron builds the crontab from /boot/config/plugins/<plugin>/*.cron, so writing that one file
 * (and running update_cron) is all it takes; it survives reboots because it lives on flash. */

const LH_DEFAULT_EVERY = '15m';
const LH_MINUTE_OFFSET = 3;      // keeps slow schedules off :00, where every other cron job on the box piles up

/** key => [label shown to the user, minutes between scans (null = not scheduled)]. Order is the order of the menu. */
function lh_schedules(): array {
    return [
        'off'   => ['Off: only when I press Scan now', null],
        '5m'    => ['Every 5 minutes', 5],
        '10m'   => ['Every 10 minutes', 10],
        '15m'   => ['Every 15 minutes (recommended)', 15],
        '30m'   => ['Every 30 minutes', 30],
        '1h'    => ['Every hour', 60],
        '3h'    => ['Every 3 hours', 180],
        '6h'    => ['Every 6 hours', 360],
        '12h'   => ['Every 12 hours', 720],
        'daily' => ['Once a day', 1440],
    ];
}

function lh_valid_time(string $t): bool { return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t); }

/** ['minutes' => int[], 'hours' => int[]|null (every hour)], or null when not scheduled or unknown. */
function lh_schedule_spec(string $every, string $dailyAt = '03:00'): ?array {
    $o = LH_MINUTE_OFFSET;
    switch ($every) {
        case '5m':  return ['minutes' => range(0, 55, 5),  'hours' => null, 'expr' => '*/5'];
        case '10m': return ['minutes' => range(0, 50, 10), 'hours' => null, 'expr' => '*/10'];
        case '15m': return ['minutes' => range($o, 59, 15), 'hours' => null, 'expr' => "$o-59/15"];
        case '30m': return ['minutes' => range($o, 59, 30), 'hours' => null, 'expr' => "$o-59/30"];
        case '1h':  return ['minutes' => [$o], 'hours' => null, 'expr' => (string)$o];
        case '3h':  return ['minutes' => [$o], 'hours' => range(0, 21, 3),  'hrexpr' => '*/3'];
        case '6h':  return ['minutes' => [$o], 'hours' => range(0, 18, 6),  'hrexpr' => '*/6'];
        case '12h': return ['minutes' => [$o], 'hours' => [0, 12],          'hrexpr' => '*/12'];
        case 'daily':
            if (!lh_valid_time($dailyAt)) $dailyAt = '03:00';
            [$h, $m] = array_map('intval', explode(':', $dailyAt));
            return ['minutes' => [$m], 'hours' => [$h], 'hrexpr' => (string)$h, 'expr' => (string)$m];
    }
    return null;
}

/** The five cron fields, e.g. "3-59/15 * * * *", or null when not scheduled. */
function lh_cron_expr(string $every, string $dailyAt = '03:00'): ?string {
    $s = lh_schedule_spec($every, $dailyAt);
    if ($s === null) return null;
    $min = $s['expr'] ?? (string)$s['minutes'][0];
    if ($s['hours'] === null) return "$min * * * *";
    return "$min " . ($s['hrexpr'] ?? implode(',', $s['hours'])) . ' * * *';
}

function lh_plugin_dir(): string { return getenv('LH_PLUGIN_DIR') ?: '/usr/local/emhttp/plugins/unraid-log-health'; }

/** The whole crontab line, or null when not scheduled. */
function lh_cron_line(array $cfg): ?string {
    $expr = lh_cron_expr($cfg['schedule']['every'] ?? LH_DEFAULT_EVERY, $cfg['schedule']['daily_at'] ?? '03:00');
    return $expr === null ? null : "$expr php -q " . lh_plugin_dir() . '/scripts/scan >/dev/null 2>&1';
}

/** Minutes between scans, for anything that needs the interval (0 when unscheduled). */
function lh_interval_minutes(array $cfg): int { return (int)(lh_schedules()[$cfg['schedule']['every'] ?? LH_DEFAULT_EVERY][1] ?? 0); }

/** Unix time of the next scheduled scan after $now, or null. Steps minute by minute, so it is exact and cheap. */
function lh_next_run(array $cfg, ?int $now = null): ?int {
    $s = lh_schedule_spec($cfg['schedule']['every'] ?? LH_DEFAULT_EVERY, $cfg['schedule']['daily_at'] ?? '03:00');
    if ($s === null) return null;
    $t = (($now ?? time()) - (($now ?? time()) % 60)) + 60;
    for ($i = 0; $i < 60 * 25; $i++, $t += 60) {
        if (in_array((int)date('i', $t), $s['minutes'], true) && ($s['hours'] === null || in_array((int)date('G', $t), $s['hours'], true))) return $t;
    }
    return null;
}

function lh_cron_file(): string { return (getenv('LH_CRON_DIR') ?: '/boot/config/plugins/unraid-log-health') . '/unraid-log-health.cron'; }

/**
 * Makes the crontab match the chosen schedule: writes (or removes) the plugin's cron file on flash, then lets
 * Unraid rebuild its crontab. Flash is only written when the content changes.
 * Returns ['line' => string|null, 'changed' => bool, 'error' => string|null].
 */
function lh_cron_apply(array $cfg): array {
    $file = lh_cron_file();
    $line = lh_cron_line($cfg);
    $want = $line === null ? null : $line . "\n";
    $have = is_file($file) ? (string)file_get_contents($file) : null;
    $changed = false; $err = null;
    if ($want !== $have) {
        if ($want === null) {
            $changed = @unlink($file);
        } else {
            if (!is_dir(dirname($file))) @mkdir(dirname($file), 0755, true);
            $changed = @file_put_contents($file, $want) !== false;
        }
        if (!$changed) $err = "could not write $file";
    }
    if ($changed && !getenv('LH_NO_UPDATE_CRON') && is_executable('/usr/local/sbin/update_cron')) {
        @exec('/usr/local/sbin/update_cron >/dev/null 2>&1');
    }
    return ['line' => $line, 'changed' => $changed, 'error' => $err];
}
