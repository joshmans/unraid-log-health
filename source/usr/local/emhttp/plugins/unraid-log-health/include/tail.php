<?php
/* Reads only what a log has gained since the last scan, so a 700 MB Docker log
 * costs one small read per run instead of a full pass.
 *
 * $state is what the previous call returned for the same file: ['inode', 'offset'].
 * Returns ['lines' => string[], 'state' => array|null, 'note' => string].
 *   note: '' (normal) | 'first' (no state: only the tail was read) | 'rotated' (new
 *   file or truncated: read from the start) | 'skipped' (more new data than
 *   $maxBytes: the oldest part was dropped, so the file is flooding) | 'missing'. */
function lh_tail(string $path, ?array $state, int $firstBytes = 2097152, int $maxBytes = 8388608): array {
    clearstatcache(true, $path);
    $st = @stat($path);
    if ($st === false || !is_file($path)) return ['lines' => [], 'state' => null, 'note' => 'missing', 'skippedBytes' => 0];
    $size = $st['size']; $inode = $st['ino'];
    $note = ''; $start = 0;
    if ($state === null) {
        $start = max(0, $size - $firstBytes);
        $note = 'first';
    } elseif (($state['inode'] ?? null) !== $inode || ($state['offset'] ?? 0) > $size) {
        $note = 'rotated';
    } else {
        $start = (int)$state['offset'];
    }
    $skipped = 0;
    if ($size - $start > $maxBytes) {
        $skipped = $size - $start - $maxBytes;
        $start = $size - $maxBytes;
        $note = 'skipped';
    }
    if ($size <= $start) return ['lines' => [], 'state' => ['inode' => $inode, 'offset' => $size], 'note' => $note, 'skippedBytes' => $skipped];

    $fh = @fopen($path, 'rb');
    if (!$fh) return ['lines' => [], 'state' => $state, 'note' => 'missing', 'skippedBytes' => 0];
    fseek($fh, $start);
    $data = (string)fread($fh, $size - $start);
    fclose($fh);

    // a read that begins mid-line drops that fragment
    if ($start > 0 && ($note === 'first' || $note === 'skipped')) {
        $nl = strpos($data, "\n");
        $data = $nl === false ? '' : substr($data, $nl + 1);
        $start = $size - strlen($data);
    }
    // only whole lines: an unfinished last line waits for the next scan
    $last = strrpos($data, "\n");
    if ($last === false) return ['lines' => [], 'state' => ['inode' => $inode, 'offset' => $start], 'note' => $note, 'skippedBytes' => $skipped];
    $consumed = $last + 1;
    $lines = explode("\n", substr($data, 0, $last));
    return ['lines' => $lines, 'state' => ['inode' => $inode, 'offset' => $start + $consumed], 'note' => $note, 'skippedBytes' => $skipped];
}
