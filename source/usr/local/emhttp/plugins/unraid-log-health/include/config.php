<?php
/* Settings live on flash (/boot/config/plugins/unraid-log-health/config.json) and are only written when
 * the user changes something; everything a scan produces goes to RAM (/tmp/unraid-log-health). */

require_once __DIR__ . '/detect.php';
require_once __DIR__ . '/schedule.php';

function lh_config_path(): string { return getenv('LH_CONFIG') ?: '/boot/config/plugins/unraid-log-health/config.json'; }
function lh_state_path(): string  { return (getenv('LH_STATE_DIR') ?: '/tmp/unraid-log-health') . '/state.json'; }

function lh_defaults(): array {
    return [
        'schedule'   => ['every' => LH_DEFAULT_EVERY, 'daily_at' => '03:00'],
        'thresholds' => lh_default_thresholds(),
        'sources' => [
            'php'     => ['enabled' => true,  'files' => ['/var/log/phplog', '/var/log/nginx/error.log']],
            'syslog'  => ['enabled' => true,  'files' => ['/var/log/syslog']],
            'docker'  => ['enabled' => true,  'root' => '/var/lib/docker/containers', 'scan_content' => true, 'warn_mb' => 100, 'crit_mb' => 500],
            'plugins' => ['enabled' => true,  'glob' => '/var/log/*.log'],
        ],
        'muted' => [],   // signature id => ['label' => text, 'since' => unix time]
    ];
}

/** Defaults with the saved file laid over them; a missing or damaged file just means defaults. */
function lh_load_config(?string $file = null): array {
    $cfg = lh_defaults();
    $j = json_decode((string)@file_get_contents($file ?? lh_config_path()), true);
    if (!is_array($j)) return $cfg;
    foreach (['schedule', 'thresholds', 'sources'] as $k) {
        if (isset($j[$k]) && is_array($j[$k])) $cfg[$k] = array_replace_recursive($cfg[$k], $j[$k]);
    }
    if (isset($j['muted']) && is_array($j['muted'])) $cfg['muted'] = $j['muted'];
    return $cfg;
}

/** Writes the settings file, only if its content would change (it lives on flash). */
function lh_save_config(array $cfg, ?string $file = null): bool {
    $file ??= lh_config_path();
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    if (is_file($file) && file_get_contents($file) === $json) return true;
    if (!is_dir(dirname($file)) && !@mkdir(dirname($file), 0755, true)) return false;
    $tmp = $file . '.' . getmypid();
    if (@file_put_contents($tmp, $json) === false) return false;
    return @rename($tmp, $file);
}

/** The ranges the settings form may use. min and max are inclusive. */
function lh_threshold_limits(): array {
    return [
        'min_count'      => ['int',   5, 1000000, 'Minimum repeats'],
        'min_rate'       => ['float', 0.1, 100000, 'Noisy at (per minute)'],
        'flood_rate'     => ['float', 0.5, 1000000, 'Flooding at (per minute)'],
        'share_pct'      => ['float', 1, 100, 'Chatty at (% of its log)'],
        'min_total'      => ['int',   10, 10000000, 'Smallest log that counts for share'],
    ];
}

/**
 * Validates what the settings form sent and lays it over $current. Only known keys are read, and every value is
 * checked; anything wrong is reported and left as it was. File paths are not editable from the page.
 * Returns [new config, list of error strings].
 */
function lh_validate_settings(array $in, array $current): array {
    $cfg = $current; $errors = [];
    $sch = $in['schedule'] ?? [];
    if (isset($sch['every'])) {
        if (isset(lh_schedules()[$sch['every']])) $cfg['schedule']['every'] = $sch['every'];
        else $errors[] = 'Unknown scan frequency.';
    }
    if (isset($sch['daily_at'])) {
        if (is_string($sch['daily_at']) && lh_valid_time($sch['daily_at'])) $cfg['schedule']['daily_at'] = $sch['daily_at'];
        else $errors[] = 'The time of day must look like 03:00.';
    }
    foreach (['php', 'syslog', 'docker', 'plugins'] as $src) {
        if (isset($in['sources'][$src]['enabled'])) $cfg['sources'][$src]['enabled'] = filter_var($in['sources'][$src]['enabled'], FILTER_VALIDATE_BOOLEAN);
    }
    if (isset($in['sources']['docker']['scan_content'])) $cfg['sources']['docker']['scan_content'] = filter_var($in['sources']['docker']['scan_content'], FILTER_VALIDATE_BOOLEAN);
    foreach (['warn_mb', 'crit_mb'] as $k) {
        if (!isset($in['sources']['docker'][$k])) continue;
        $v = $in['sources']['docker'][$k];
        if (is_numeric($v) && $v >= 1 && $v <= 10000000) $cfg['sources']['docker'][$k] = (int)$v;
        else $errors[] = 'Docker log sizes must be between 1 and 10,000,000 MB.';
    }
    foreach (lh_threshold_limits() as $k => [$type, $min, $max, $label]) {
        if (!isset($in['thresholds'][$k])) continue;
        $v = $in['thresholds'][$k];
        if (is_numeric($v) && $v >= $min && $v <= $max) $cfg['thresholds'][$k] = $type === 'int' ? (int)$v : (float)$v;
        else $errors[] = "$label must be between $min and $max.";
    }
    if ($cfg['thresholds']['flood_rate'] <= $cfg['thresholds']['min_rate']) $errors[] = 'Flooding must be a higher rate than noisy.';
    if ($cfg['sources']['docker']['crit_mb'] <= $cfg['sources']['docker']['warn_mb']) $errors[] = 'The huge Docker log size must be bigger than the large one.';
    return $errors ? [$current, $errors] : [$cfg, []];
}
