<?php
/* Decides which signatures are spam. Input is what one scan counted:
 *   $counts[id] = ['n' => count, 'first' => ts|null, 'last' => ts|null, 'sig' => signature, 'key' => source key,
 *                  'attr' => ..., 'where' => ..., 'example' => one raw line]
 * $windowSeconds is how much time the scan covered (rate needs it). $total is how many lines were read: an int
 * for one log, or [path => lines] so each signature's share is measured against its own log.
 *
 * A signature is flagged when it repeats a lot AND either fast or as a big share of the log:
 *   n >= min_count  and  (rate/min >= min_rate  or  (share >= share_pct and total >= min_total)).
 * The level says how much it matters, and depends on rate alone:
 *   flooding  rate >= flood_rate   (about one a second: this is what fills logs)
 *   noisy     rate >= min_rate
 *   chatty    flagged only for its share: a healthcheck or access-log line that is most of a small log but
 *             repeats slowly. Worth knowing about, not worth an alert. */

function lh_default_thresholds(): array {
    return ['min_count' => 50, 'min_rate' => 5.0, 'share_pct' => 20.0, 'min_total' => 200, 'flood_rate' => 60.0];
}

function lh_flag(array $counts, int|array $total, int $windowSeconds, array $th = []): array {
    $th += lh_default_thresholds();
    $out = [];
    foreach ($counts as $id => $c) {
        $n = (int)$c['n'];
        if ($n < $th['min_count']) continue;
        $span = $windowSeconds;
        if (isset($c['first'], $c['last']) && $c['last'] > $c['first']) $span = min($span, max(60, $c['last'] - $c['first']));
        $rate  = $n / (max(60, $span) / 60);
        $of = is_array($total) ? (int)($total[$c['path'] ?? ''] ?? 0) : $total;
        $share = $of > 0 ? 100.0 * $n / $of : 0.0;
        $byRate  = $rate >= $th['min_rate'];
        $byShare = $share >= $th['share_pct'] && $of >= $th['min_total'];
        if (!$byRate && !$byShare) continue;
        $out[$id] = array_merge($c, ['id' => $id, 'rate' => round($rate, 1), 'share' => round($share, 1),
            'level' => $rate >= $th['flood_rate'] ? 'flooding' : ($byRate ? 'noisy' : 'chatty')]);
    }
    uasort($out, fn($a, $b) => $b['n'] <=> $a['n']);
    return $out;
}

/** Bytes per hour between two size readings; null when there is no earlier reading or the file shrank. */
function lh_growth(?int $prevBytes, ?int $prevTs, int $bytes, int $ts): ?int {
    if ($prevBytes === null || $prevTs === null || $ts <= $prevTs || $bytes < $prevBytes) return null;
    return (int)round(($bytes - $prevBytes) / (($ts - $prevTs) / 3600));
}

/** A log file that is simply too big: 'large' at $warn bytes, 'huge' at $crit; null below. */
function lh_size_level(int $bytes, int $warn = 104857600, int $crit = 524288000): ?string {
    return $bytes >= $crit ? 'huge' : ($bytes >= $warn ? 'large' : null);
}

/** How much a line looks like the point of an incident rather than its surroundings. */
function lh_informative(string $sig): int {
    $score = preg_match('/(error|exception|fatal|fail(?:ed|ure)?|refused|denied|traceback|unable|cannot|critical|panic|undefined)/i', $sig) ? 2 : 0;
    if (preg_match('/(?:error|exception)\b.*:/i', $sig)) $score += 1;            // "FooError: what went wrong" beats "Traceback (most recent call last):"
    return $score + (strlen($sig) >= 20 && strlen($sig) <= 200 ? 1 : 0);
}

/**
 * One multi-line event (a traceback, a dumped error object) repeats as many signatures with the same count.
 * Groups signatures from the same log whose totals are within 1% (at least 2) of each other into one incident,
 * represented by its most informative line. Returns [['id' => rep id, 'ids' => all member ids, 'related' => n-1, 'rep' => entry,
 * 'others' => up to 5 other signatures], ...] biggest first. Grouping is a heuristic on counts; the members stay
 * individually addressable, and muting a group mutes every member.
 */
function lh_group(array $spam, string $countField = 'total', ?int $now = null, int $activeMinutes = 30): array {
    $byLog = [];
    foreach ($spam as $id => $e) $byLog[($e['key'] ?? '') . "\0" . ($e['path'] ?? '')][] = $e;
    $groups = [];
    foreach ($byLog as $members) {
        usort($members, fn($a, $b) => $b[$countField] <=> $a[$countField]);
        $cur = [];
        foreach ($members as $m) {
            if ($cur && abs($m[$countField] - $cur[0][$countField]) > max(2, (int)ceil(0.01 * $cur[0][$countField]))) { $groups[] = $cur; $cur = []; }
            $cur[] = $m;
        }
        if ($cur) $groups[] = $cur;
    }
    $out = [];
    foreach ($groups as $g) {
        usort($g, fn($a, $b) => [lh_informative($b['sig']), $b[$countField]] <=> [lh_informative($a['sig']), $a[$countField]]);
        $rep = $g[0];
        $out[] = ['id' => $rep['id'], 'ids' => array_column($g, 'id'), 'related' => count($g) - 1, 'rep' => $rep,
                  'others' => array_slice(array_column(array_slice($g, 1), 'sig'), 0, 5)];
    }
    $now = $now ?? time();
    usort($out, fn($a, $b) => lh_rank($b['rep'], $now, $activeMinutes) <=> lh_rank($a['rep'], $now, $activeMinutes));
    return $out;
}

/** Sort key: still happening first, then flooding > noisy > chatty, then the biggest. */
function lh_rank(array $e, int $now, int $activeMinutes = 30): array {
    $active = isset($e['last_ts']) && $e['last_ts'] >= $now - $activeMinutes * 60;
    return [(int)$active, ['flooding' => 3, 'noisy' => 2, 'chatty' => 1][$e['level'] ?? ''] ?? 0, $e['total'] ?? $e['n'] ?? 0];
}
