<?php
/* A signature is a log message with everything that changes between repeats
 * taken out (times, ids, addresses, numbers), so the ten-thousandth
 * "Undefined array key 1" counts as the same thing as the first. Two lines with
 * the same signature and the same source key are the same spam. */

/** Reduces one message to its signature. Rules run most specific first. */
function lh_normalize(string $msg): string {
    static $rules = null;
    if ($rules === null) {
        $rules = [
            ['/\x1b\[[0-9;]*[A-Za-z]/',                                                   ''],            // terminal colours
            ['/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i',       '<uuid>'],
            ['/\b\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:[.,]\d+)?(?:Z|[+-]\d{2}:?\d{2})?/', '<time>'],
            ['/\b\d{1,2}[-\/](?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[-\/]\d{4}\b/', '<date>'],
            ['/\b\d{1,2}:\d{2}:\d{2}(?:[.,]\d+)?\b/',                                     '<time>'],
            ['/\b(?:\d{1,3}\.){3}\d{1,3}(?::\d+)?\b/',                                    '<ip>'],
            ['/\b(?:[0-9a-f]{2}:){5}[0-9a-f]{2}\b/i',                                     '<mac>'],
            ['/\b[0-9a-f]{0,4}(?::{1,2}[0-9a-f]{1,4}){3,8}\b/i',                             '<ip>'],        // IPv6, with or without ::
            ['/\/dev\/(?:sd[a-z]+|nvme\d+n\d+|md\d+p?|loop|dm-|sr|hd[a-z])\w*/',          '/dev/<dev>'],
            ['/\b(SHA256|MD5):[A-Za-z0-9+\/=]{16,}/',                                   '$1:<hash>'],
            ['/\b0x[0-9a-f]+\b/i',                                                        '<hex>'],
            ['/\b(?=[0-9a-f]*\d)[0-9a-f]{8,}\b/i',                                        '<hex>'],       // ids, hashes
            ['/(?:(?<![A-Za-z_\d])|(?<=\bv))\d+(?:\.\d+)*/',                              '#'],           // numbers, versions (v1.2 too)
        ];
    }
    $s = $msg;
    foreach ($rules as [$re, $to]) {
        $s = preg_replace($re, $to, $s) ?? $s;
    }
    $s = trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    return lh_cut($s, 240);
}

/** A short stable id for a (source key, signature) pair; what the mute list stores. */
function lh_sig_id(string $key, string $signature): string {
    return substr(hash('sha256', $key . "\0" . $signature), 0, 10);
}

/** Cuts to at most $n characters without splitting a UTF-8 sequence (no mbstring needed). */
function lh_cut(string $s, int $n): string {
    if (preg_match('/^.{0,' . $n . '}/su', $s, $m)) return $m[0];
    return substr($s, 0, $n);   // not valid UTF-8: cut bytes
}
