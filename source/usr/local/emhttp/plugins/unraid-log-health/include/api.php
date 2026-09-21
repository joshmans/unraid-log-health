<?php
/* JSON endpoint for the Tools page. Every action that changes anything is POST only, so Unraid's CSRF check in
 * local_prepend.php always runs before this file does. */
require_once __DIR__ . '/scan.php';

/** True while a scan holds the lock (cron or Scan now). */
function lh_scan_running(): bool {
    $f = dirname(lh_state_path()) . '/scan.lock';
    if (!is_file($f)) return false;
    $h = fopen($f, 'c');
    if (!$h) return false;
    $free = flock($h, LOCK_EX | LOCK_NB);
    if ($free) flock($h, LOCK_UN);
    fclose($h);
    return !$free;
}

/** Starts a scan in the background and returns at once: a first scan can take longer than a web request should. */
function lh_spawn_scan(): void {
    @exec('nohup php -q ' . escapeshellarg(lh_plugin_dir() . '/scripts/scan') . ' >/dev/null 2>&1 &');
}

/** The last scan with muted signatures taken out, so muting shows at once instead of at the next scan. */
function lh_visible_state(?array $state, array $cfg): ?array {
    if ($state === null) return null;
    $hidden = 0;
    foreach ($state['spam'] ?? [] as $id => $e) if (isset($cfg['muted'][$id])) { unset($state['spam'][$id]); $hidden++; }
    $state['muted_hidden'] = ($state['muted_hidden'] ?? 0) + $hidden;
    $state['groups'] = lh_group($state['spam'] ?? [], 'total', $state['ts'] ?? time(), $state['active_minutes'] ?? 30);
    unset($state['files']);   // read offsets: the page has no use for them
    return $state;
}

/** A clock time in the server's timezone, which is the one cron runs in: "9:30 PM", or "Tue 2:30 AM" on another day. */
function lh_clock(?int $ts, int $now): ?string {
    if ($ts === null) return null;
    return date(date('Y-m-d', $ts) === date('Y-m-d', $now) ? 'g:i A' : 'D g:i A', $ts);
}

function lh_view(array $extra = []): array {
    $cfg = lh_load_config();
    $state = json_decode((string)@file_get_contents(lh_state_path()), true);
    $now = time();
    return $extra + [
        'now'       => $now,
        'version'   => trim((string)@file_get_contents(dirname(__DIR__) . '/VERSION')) ?: 'dev',
        'settings'  => ['schedule' => $cfg['schedule'], 'thresholds' => $cfg['thresholds'], 'sources' => $cfg['sources']],
        'limits'    => lh_threshold_limits(),
        'schedules' => array_map(fn($k, $v) => ['key' => $k, 'label' => $v[0]], array_keys(lh_schedules()), lh_schedules()),
        'cron'      => ['line' => lh_cron_line($cfg), 'next' => ($next = lh_next_run($cfg, $now)), 'next_at' => lh_clock($next, $now), 'installed' => is_file(lh_cron_file())],
        'muted'     => $cfg['muted'],
        'scanning'  => lh_scan_running(),
        'state'     => lh_visible_state(is_array($state) ? $state : null, $cfg),
    ];
}

/** @return array [http status, body] */
function lh_handle(string $action, array $req, string $method = 'POST'): array {
    if ($action !== 'state' && $method !== 'POST') return [405, ['error' => 'POST required']];
    switch ($action) {
        case 'state':
            return [200, lh_view()];

        case 'save':
            $in = json_decode((string)($req['settings'] ?? ''), true);
            if (!is_array($in)) return [400, ['error' => 'bad settings']];
            $cur = lh_load_config();
            [$cfg, $errors] = lh_validate_settings($in, $cur);
            if ($errors) return [422, lh_view(['errors' => $errors])];
            if (!lh_save_config($cfg)) return [500, ['error' => 'could not write ' . lh_config_path()]];
            $cron = lh_cron_apply($cfg);
            return [200, lh_view(['saved' => true] + ($cron['error'] ? ['warning' => $cron['error']] : []))];

        case 'scan':
            if (lh_scan_running()) return [200, lh_view(['started' => false])];
            lh_spawn_scan();
            return [200, lh_view(['started' => true, 'scanning' => true])];

        case 'mute':
        case 'unmute':
            $ids = json_decode((string)($req['ids'] ?? ''), true);
            if (!is_array($ids) || !$ids) return [400, ['error' => 'no ids']];
            $cfg = lh_load_config();
            $state = json_decode((string)@file_get_contents(lh_state_path()), true) ?: [];
            foreach ($ids as $id) {
                if (!is_string($id) || !preg_match('/^[0-9a-f]{10}$/', $id)) return [400, ['error' => 'bad id']];
                if ($action === 'unmute') { unset($cfg['muted'][$id]); continue; }
                $e = $state['spam'][$id] ?? null;
                if ($e === null) return [404, ['error' => 'unknown signature']];   // only what was actually flagged can be muted
                $cfg['muted'][$id] = ['label' => lh_cut((string)$e['sig'], 160), 'source' => $e['key'], 'since' => time()];
            }
            if (!lh_save_config($cfg)) return [500, ['error' => 'could not write ' . lh_config_path()]];
            return [200, lh_view(['saved' => true])];
    }
    return [400, ['error' => 'unknown action']];
}

if (!defined('LH_NO_DISPATCH')) {
    $lhAction = $_POST['action'] ?? $_GET['action'] ?? 'state';
    try {
        [$lhStatus, $lhBody] = lh_handle($lhAction, $_POST, $_SERVER['REQUEST_METHOD'] ?? 'GET');
    } catch (Throwable $e) {
        [$lhStatus, $lhBody] = [500, ['error' => $e->getMessage()]];
    }
    http_response_code($lhStatus);
    header('Content-Type: application/json');
    echo json_encode($lhBody, JSON_UNESCAPED_SLASHES);
}
