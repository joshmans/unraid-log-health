<?php
/* One parser per log format. Each takes a raw line and returns
 *   ['ts' => unix time or null, 'msg' => the message, 'attr' => who is talking,
 *    'where' => where in the code, or '']
 * or null for a line that isn't a log entry (stack-trace continuation, blank). */

require_once __DIR__ . '/signature.php';

/** /usr/local/emhttp/plugins/<name>/... -> "<name>"; Unraid's own pages -> "webGui". */
function lh_attribute_path(string $path): string {
    if (preg_match('#^/usr/local/emhttp/plugins/([^/]+)/#', $path, $m)) return $m[1];
    if (preg_match('#^/usr/local/emhttp/(?:webGui|state|[a-z]+\.php)#', $path)) return 'webGui';
    if (preg_match('#^/boot/config/plugins/([^/]+)/#', $path, $m)) return $m[1];
    return 'other';
}

/** PHP error log: [20-Sep-2026 22:27:08 America/Chicago] PHP Warning:  msg in /file.php on line 78 */
function lh_parse_php(string $line): ?array {
    if (!preg_match('/^\[(\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2})(?: ([^\]]+))?\] PHP (Fatal error|Warning|Notice|Deprecated|Parse error|Error):\s+(.*)$/', $line, $m)) return null;
    $ts = null;
    try {
        $tz = new DateTimeZone($m[2] !== '' ? $m[2] : 'UTC');
        $d = DateTimeImmutable::createFromFormat('d-M-Y H:i:s', $m[1], $tz);
        if ($d) $ts = $d->getTimestamp();
    } catch (Exception $e) { /* unknown zone name: keep the message, lose the time */ }
    $msg = $m[4]; $file = ''; $ln = '';
    if (preg_match('/^(.*?) in (\/\S+?)(?: on line (\d+)|:(\d+))?$/', $msg, $f)) {
        $msg = $f[1]; $file = $f[2]; $ln = ($f[3] ?? '') !== '' ? $f[3] : ($f[4] ?? '');
    }
    return [
        'ts'    => $ts,
        'msg'   => $m[3] . ': ' . $msg,
        'attr'  => $file !== '' ? lh_attribute_path($file) : 'php',
        'where' => $file !== '' ? preg_replace('#^/usr/local/emhttp/plugins/#', '', $file) . ($ln !== '' ? ":$ln" : '') : '',
    ];
}

/** syslog: Sep 20 23:00:04 host prog[pid]: message. The year isn't in the line, so it is inferred from $now. */
function lh_parse_syslog(string $line, ?int $now = null): ?array {
    if (!preg_match('/^([A-Z][a-z]{2}) +(\d{1,2}) (\d{2}:\d{2}:\d{2}) (\S+) ([^:\[\s]+)(?:\[\d+\])?: (.*)$/', $line, $m)) return null;
    $now ??= time();
    $ts = null;
    $d = DateTimeImmutable::createFromFormat('Y M j H:i:s', date('Y', $now) . " {$m[1]} {$m[2]} {$m[3]}");
    if ($d) {
        $ts = $d->getTimestamp();
        if ($ts > $now + 86400) $ts = $d->modify('-1 year')->getTimestamp();   // late December read in January
    }
    return ['ts' => $ts, 'msg' => $m[6], 'attr' => $m[5], 'where' => ''];
}

/** Docker json-file: {"log":"text\n","stream":"stderr","time":"2026-09-20T22:27:08.123456789Z"} */
function lh_parse_docker(string $line): ?array {
    if ($line === '' || $line[0] !== '{') return null;
    $j = json_decode($line, true);
    if (!is_array($j) || !isset($j['log']) || !is_string($j['log'])) return null;
    $msg = rtrim($j['log'], "\r\n");
    if ($msg === '') return null;
    $ts = isset($j['time']) ? (strtotime(substr($j['time'], 0, 19) . 'Z') ?: null) : null;
    return ['ts' => $ts, 'msg' => $msg, 'attr' => '', 'where' => ''];
}

/** A log with no known structure: the whole line is the message. */
function lh_parse_plain(string $line): ?array {
    $t = trim($line);
    return $t === '' ? null : ['ts' => null, 'msg' => $t, 'attr' => '', 'where' => ''];
}
