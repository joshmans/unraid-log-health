<?php
/* Docker json-file logs: which container owns which file, how big it is, and whether
 * anything limits it. Reads config files only; never touches docker itself. */

/** [['id','name','path','bytes','mtime','max_size','max_file'], ...] biggest first. */
function lh_docker_logs(string $root = '/var/lib/docker/containers'): array {
    $out = [];
    foreach (glob("$root/*", GLOB_ONLYDIR) ?: [] as $dir) {
        $id = basename($dir);
        $log = "$dir/$id-json.log";
        if (!is_file($log)) continue;
        $cfg = json_decode((string)@file_get_contents("$dir/config.v2.json"), true) ?: [];
        $host = json_decode((string)@file_get_contents("$dir/hostconfig.json"), true) ?: [];
        $opts = $host['LogConfig']['Config'] ?? [];
        $out[] = [
            'id'       => substr($id, 0, 12),
            'name'     => ltrim((string)($cfg['Name'] ?? $id), '/'),
            'path'     => $log,
            'bytes'    => (int)filesize($log),
            'mtime'    => (int)filemtime($log),
            'max_size' => $opts['max-size'] ?? null,
            'max_file' => $opts['max-file'] ?? null,
        ];
    }
    usort($out, fn($a, $b) => $b['bytes'] <=> $a['bytes']);
    return $out;
}

/** The daemon-wide default from daemon.json: ['driver', 'max_size', 'max_file'] (nulls when unset). */
function lh_docker_daemon_opts(string $file = '/etc/docker/daemon.json'): array {
    $j = json_decode((string)@file_get_contents($file), true) ?: [];
    $o = $j['log-opts'] ?? [];
    return ['driver' => $j['log-driver'] ?? 'json-file', 'max_size' => $o['max-size'] ?? null, 'max_file' => $o['max-file'] ?? null];
}

/** "1g", "50m", "500k", "100" -> bytes (Docker's max-size notation); null for anything else. */
function lh_parse_size(?string $s): ?int {
    if ($s === null || !preg_match('/^(\d+)\s*([kmgKMG]?)[bB]?$/', trim($s), $m)) return null;
    return (int)$m[1] * (['' => 1, 'k' => 1024, 'm' => 1048576, 'g' => 1073741824][strtolower($m[2])]);
}
