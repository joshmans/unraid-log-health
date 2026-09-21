<?php
/* One scan: read what each enabled source gained since last time, count signatures, flag the spammy ones. */

require_once __DIR__ . '/parsers.php';
require_once __DIR__ . '/tail.php';
require_once __DIR__ . '/detect.php';
require_once __DIR__ . '/docker.php';
require_once __DIR__ . '/config.php';

const LH_KEEP_SECONDS = 86400;   // a flagged signature stays listed this long after it was last seen

/** Reads one file, feeding parsed lines into $counts. Returns the per-file report. */
function lh_scan_file(string $path, string $kind, string $keyPrefix, string $attrFallback, ?array $prevFile, array &$counts, int $now, int $maxBytes): array {
    $r = lh_tail($path, $prevFile['state'] ?? null, 2097152, $maxBytes);
    $parse = ['php' => 'lh_parse_php', 'syslog' => 'lh_parse_syslog', 'docker' => 'lh_parse_docker', 'plain' => 'lh_parse_plain'][$kind];
    $read = 0;
    foreach ($r['lines'] as $line) {
        $p = $kind === 'syslog' ? lh_parse_syslog($line, $now) : $parse($line);
        if ($p === null) continue;
        if ($p['ts'] !== null && $p['ts'] < $now - LH_KEEP_SECONDS) continue;   // history a first scan drags in is not news
        $read++;
        $attr = $p['attr'] !== '' ? $p['attr'] : $attrFallback;
        $key = "$keyPrefix:$attr";
        $sig = lh_normalize($p['msg']) . ($p['where'] !== '' ? ' @ ' . $p['where'] : '');
        $id = lh_sig_id($key, $sig);
        $c = &$counts[$id];
        if ($c === null) $c = ['n' => 0, 'first' => $p['ts'], 'last' => $p['ts'], 'sig' => $sig, 'key' => $key, 'attr' => $attr,
                               'where' => $p['where'], 'kind' => $kind, 'path' => $path, 'example' => lh_cut($p['msg'], 300)];
        $c['n']++;
        if ($p['ts'] !== null) { $c['first'] = $c['first'] === null ? $p['ts'] : min($c['first'], $p['ts']); $c['last'] = max($c['last'] ?? 0, $p['ts']); }
        unset($c);
    }
    return ['path' => $path, 'kind' => $kind, 'lines' => $read, 'note' => $r['note'], 'skipped_bytes' => $r['skippedBytes'], 'state' => $r['state']];
}

/**
 * $cfg from lh_load_config(), $prev the state the last scan returned (or []), returns the new state:
 *  ts, window, files (offsets), sources (per-file reports), spam (flagged, kept 24 h), docker (size report), muted (count hidden).
 */
function lh_scan(array $cfg, array $prev = [], ?int $now = null): array {
    $now ??= time();
    $activeMin = max(30, 2 * lh_interval_minutes($cfg));   // "still happening" must survive a missed scan
    $window = isset($prev['ts']) ? max(60, min(LH_KEEP_SECONDS, $now - $prev['ts'])) : LH_KEEP_SECONDS;
    $counts = []; $reports = []; $total = 0; $perPath = [];
    $S = $cfg['sources'];
    $jobs = [];   // [path, kind, keyPrefix, fallback attr, maxBytes]
    if ($S['php']['enabled'])    foreach ($S['php']['files'] as $f)    $jobs[] = [$f, $f === '/var/log/nginx/error.log' ? 'plain' : 'php', 'php', basename(dirname($f)) === 'nginx' ? 'nginx' : 'php', 8388608];
    if ($S['syslog']['enabled']) foreach ($S['syslog']['files'] as $f) $jobs[] = [$f, 'syslog', 'syslog', 'syslog', 8388608];
    if ($S['plugins']['enabled']) foreach (glob($S['plugins']['glob']) ?: [] as $f) {
        if (in_array($f, array_merge($S['php']['files'], $S['syslog']['files']), true)) continue;
        $jobs[] = [$f, 'plain', 'log', preg_replace('/\.log$/', '', basename($f)), 2097152];
    }
    $dockerReport = ['enabled' => false, 'daemon' => null, 'containers' => []];
    $files = [];
    foreach ($jobs as [$path, $kind, $prefix, $fallback, $max]) {
        $rep = lh_scan_file($path, $kind, $prefix, $fallback, $prev['files'][$path] ?? null, $counts, $now, $max);
        $total += $rep['lines']; $perPath[$path] = $rep['lines']; $files[$path] = ['state' => $rep['state']]; unset($rep['state']); $reports[] = $rep;
    }
    if ($S['docker']['enabled']) {
        $dockerReport = ['enabled' => true, 'daemon' => lh_docker_daemon_opts(), 'containers' => []];
        foreach (lh_docker_logs($S['docker']['root']) as $d) {
            $prevSize = $prev['docker']['containers'][$d['id']] ?? null;
            $level = lh_size_level($d['bytes'], $S['docker']['warn_mb'] * 1048576, $S['docker']['crit_mb'] * 1048576);
            // this container's own limit wins over the daemon default
            $maxSize = $d['max_size'] ?? $dockerReport['daemon']['max_size'];
            $maxFile = $d['max_file'] ?? $dockerReport['daemon']['max_file'] ?? '1';
            $perFile = lh_parse_size($maxSize);
            $dockerReport['containers'][$d['id']] = [
                'id' => $d['id'], 'name' => $d['name'], 'bytes' => $d['bytes'], 'level' => $level,
                'growth_per_hour' => lh_growth($prevSize['bytes'] ?? null, $prev['ts'] ?? null, $d['bytes'], $now),
                'limited' => $perFile !== null,
                'max_size' => $maxSize, 'max_file' => $maxFile,
                'worst_case_bytes' => $perFile !== null ? $perFile * max(1, (int)$maxFile) : null,   // what it may grow to before old data is dropped
                'pct_of_file' => $perFile ? (int)round(100 * $d['bytes'] / $perFile) : null,
            ];
            if ($S['docker']['scan_content']) {
                $rep = lh_scan_file($d['path'], 'docker', 'docker', $d['name'], $prev['files'][$d['path']] ?? null, $counts, $now, 2097152);
                $total += $rep['lines']; $perPath[$d['path']] = $rep['lines']; $files[$d['path']] = ['state' => $rep['state']];
                $rep['container'] = $d['name']; unset($rep['state']); $reports[] = $rep;
            }
        }
    }

    $muted = 0; $flagged = [];
    foreach (lh_flag($counts, $perPath, $window, $cfg['thresholds']) as $id => $f) {
        if (isset($cfg['muted'][$id])) { $muted++; continue; }
        $flagged[$id] = $f;
    }
    // merge into what was flagged earlier: totals accumulate while it keeps being flagged, and it ages out after a day of quiet
    $spam = [];
    foreach ($prev['spam'] ?? [] as $id => $old) {
        if (($old['last_seen'] ?? 0) >= $now - LH_KEEP_SECONDS && !isset($cfg['muted'][$id])) $spam[$id] = $old;
    }
    foreach ($flagged as $id => $f) {
        $o = $spam[$id] ?? ['total' => 0, 'first_seen' => $now, 'peak_rate' => 0];
        $spam[$id] = [
            'id' => $id, 'key' => $f['key'], 'attr' => $f['attr'], 'kind' => $f['kind'], 'sig' => $f['sig'], 'where' => $f['where'],
            'example' => $f['example'], 'path' => $f['path'], 'level' => $f['level'], 'rate' => $f['rate'], 'share' => $f['share'],
            'total' => $o['total'] + $f['n'], 'first_seen' => $o['first_seen'], 'last_seen' => $now, 'last_ts' => $f['last'] ?? $now,
            'peak_rate' => max($o['peak_rate'], $f['rate']),
        ];
    }
    uasort($spam, fn($a, $b) => lh_rank($b, $now, $activeMin) <=> lh_rank($a, $now, $activeMin));
    return ['ts' => $now, 'window' => $window, 'files' => $files, 'sources' => $reports, 'spam' => $spam, 'groups' => lh_group($spam, 'total', $now, $activeMin), 'docker' => $dockerReport, 'muted_hidden' => $muted, 'lines_read' => $total, 'active_minutes' => $activeMin];
}
