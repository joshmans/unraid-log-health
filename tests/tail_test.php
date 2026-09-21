<?php
require_once __DIR__ . '/lib.php';

$d = lh_tmpdir();
$f = "$d/log";

/* first read of a small file takes everything, later reads take only what was added */
file_put_contents($f, "one\ntwo\n");
$r = lh_tail($f, null);
check('first: whole small file', $r['lines'] === ['one', 'two'] && $r['note'] === 'first');
file_put_contents($f, "three\n", FILE_APPEND);
$r2 = lh_tail($f, $r['state']);
check('next: only the new line', $r2['lines'] === ['three'] && $r2['note'] === '');
check('nothing new reads nothing', lh_tail($f, $r2['state'])['lines'] === []);

/* an unfinished last line waits for the next scan */
file_put_contents($f, "four\nfi", FILE_APPEND);
$r3 = lh_tail($f, $r2['state']);
check('partial line is held back', $r3['lines'] === ['four']);
file_put_contents($f, "ve\n", FILE_APPEND);
$r4 = lh_tail($f, $r3['state']);
check('and delivered whole once finished', $r4['lines'] === ['five']);

/* truncated in place */
file_put_contents($f, "new\n");
$r5 = lh_tail($f, $r4['state']);
check('truncation restarts from the top', $r5['lines'] === ['new'] && $r5['note'] === 'rotated');

/* rotated: same name, different file */
$r6 = lh_tail($f, $r5['state']);
rename($f, "$f.1");
file_put_contents($f, "fresh1\nfresh2\n");
$r7 = lh_tail($f, $r6['state']);
check('rotation (new inode) reads the new file from the start', $r7['lines'] === ['fresh1', 'fresh2'] && $r7['note'] === 'rotated');

/* missing */
$m = lh_tail("$d/nope", null);
check('missing file', $m['note'] === 'missing' && $m['lines'] === [] && $m['state'] === null);

/* first read of a big file takes only the tail and drops the cut-off first line */
$big = "$d/big";
$fh = fopen($big, 'w'); for ($i = 1; $i <= 2000; $i++) fwrite($fh, sprintf("line-%05d xxxxxxxxxxxxxxxxxxxx\n", $i)); fclose($fh);
$b = lh_tail($big, null, 1000, 1 << 20);
check('big first read: tail only', $b['note'] === 'first' && count($b['lines']) > 10 && count($b['lines']) < 60 && end($b['lines']) === 'line-02000 xxxxxxxxxxxxxxxxxxxx');
check('big first read: no fragment at the start', preg_match('/^line-\d{5} x{20}$/', $b['lines'][0]) === 1, $b['lines'][0]);
$bs = lh_tail($big, null, 1 << 20, 1000);
check('a flooding log is sampled from the end and says so', $bs['note'] === 'skipped' && $bs['skippedBytes'] > 0 && end($bs['lines']) === 'line-02000 xxxxxxxxxxxxxxxxxxxx');
$bn = lh_tail($big, $b['state']);
check('after a first read the state points at the end', $bn['lines'] === []);

/* no trailing newline at all: nothing complete to give yet, and it must not lose the line */
file_put_contents("$d/nonl", "abc");
$n1 = lh_tail("$d/nonl", null);
check('an unterminated file yields nothing yet', $n1['lines'] === []);
file_put_contents("$d/nonl", "def\n", FILE_APPEND);
check('and the whole line later', lh_tail("$d/nonl", $n1['state'])['lines'] === ['abcdef']);

lh_rmtree($d);
finish('tail');
