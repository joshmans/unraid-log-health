<?php
require_once __DIR__ . '/lib.php';

function c(int $n, ?int $first = null, ?int $last = null, string $path = '/l', string $sig = 's', string $key = 'k'): array {
    return ['n' => $n, 'first' => $first, 'last' => $last, 'sig' => $sig, 'key' => $key, 'path' => $path, 'level' => '', 'attr' => 'a', 'where' => '', 'kind' => 'plain', 'example' => 'e'];
}

/* rate: 600 in a 5 minute window = 120/min */
$f = lh_flag(['a' => c(600)], ['/l' => 5000], 300);
check('a fast repeat is flagged', isset($f['a']) && $f['a']['rate'] === 120.0);
check('a very fast one is flooding', $f['a']['level'] === 'flooding');
check('a slow, small repeat is not flagged', lh_flag(['a' => c(60)], ['/l' => 5000], 3600) === []);
check('below the minimum count is never flagged', lh_flag(['a' => c(49)], ['/l' => 60], 60) === []);
$share = lh_flag(['a' => c(300)], ['/l' => 1000], 86400);
check('slow but a big share of its own log is flagged, as chatty', isset($share['a']) && $share['a']['share'] === 30.0 && $share['a']['level'] === 'chatty');
check('a dominant line that is also fast is not chatty', lh_flag(['a' => c(600)], ['/l' => 650], 300)['a']['level'] === 'flooding' && lh_flag(['a' => c(600)], ['/l' => 650], 6000)['a']['level'] === 'noisy');
check('share needs a log big enough to mean something', lh_flag(['a' => c(60)], ['/l' => 100], 86400) === []);
check('share is against its own log, not the total', lh_flag(['a' => c(300, null, null, '/small')], ['/small' => 100000, '/other' => 10], 86400) === []);
check('a plain int total still works', isset(lh_flag(['a' => c(300)], 1000, 86400)['a']));
check('thresholds can be tightened', isset(lh_flag(['a' => c(20)], ['/l' => 100], 60, ['min_count' => 10, 'min_rate' => 10])['a']));
check('window shorter than the data span uses the span', lh_flag(['a' => c(120, 1000, 1060)], ['/l' => 5000], 86400)['a']['rate'] === 120.0);
check('results are biggest first', array_keys(lh_flag(['a' => c(100), 'b' => c(900)], ['/l' => 5000], 300)) === ['b', 'a']);

check('growth per hour', lh_growth(1000, 0, 4600, 1800) === 7200);
check('growth is unknown with no earlier reading', lh_growth(null, null, 5, 5) === null);
check('growth is unknown if the file shrank', lh_growth(9000, 0, 100, 3600) === null);
check('size levels', lh_size_level(10) === null && lh_size_level(200 << 20) === 'large' && lh_size_level(600 << 20) === 'huge');

/* grouping: one traceback = many signatures with the same count */
$mk = fn($id, $sig, $total, $key = 'docker:x', $path = '/x') => ['id' => $id, 'sig' => $sig, 'total' => $total, 'key' => $key, 'path' => $path, 'level' => 'noisy'];
$spam = [
    't1' => $mk('t1', 'Traceback (most recent call last):', 406),
    't2' => $mk('t2', 'File "/app/main.py", line #, in run', 406),
    't3' => $mk('t3', 'FileNotFoundError: [Errno #] No usable temporary directory', 407),
    'o1' => $mk('o1', 'heartbeat ok', 5000),
    'y1' => $mk('y1', 'Traceback (most recent call last):', 406, 'docker:y', '/y'),
];
$g = lh_group($spam);
check('grouped by count within a log', count($g) === 3);
$byRep = array_column($g, null, 'id');
check('the most informative line represents the group', isset($byRep['t3']) && $byRep['t3']['related'] === 2 && sort($byRep['t3']['ids']) === true);
check('members stay addressable', count($byRep['t3']['ids']) === 3 && in_array('t1', $byRep['t3']['ids'], true));
check('an unrelated count is its own incident', isset($byRep['o1']) && $byRep['o1']['related'] === 0);
check('the same counts in another log are not merged', isset($byRep['y1']));
check('other lines are listed for drill-down', count($byRep['t3']['others']) === 2);
check('biggest incident first', lh_group($spam, 'total', 0)[0]['id'] === 'o1');

$flood = ['a' => ['level' => 'flooding'] + $mk('a', 'x', 10), 'b' => ['level' => 'noisy'] + $mk('b', 'y', 9999, 'k2', '/p')];
check('flooding sorts above noisy regardless of size', lh_group($flood, 'total', 0)[0]['id'] === 'a');
check('still-happening sorts above quiet', lh_group(['q' => ['level' => 'flooding', 'last_ts' => 0] + $mk('q', 'x', 9999, 'kq', '/q'), 'w' => ['level' => 'noisy', 'last_ts' => 100000] + $mk('w', 'y', 10, 'kw', '/w')], 'total', 100000)[0]['id'] === 'w');
check('rank: active, then level, then size', lh_rank(['level' => 'noisy', 'total' => 5, 'last_ts' => 100], 100) === [1, 2, 5] && lh_rank(['level' => 'chatty', 'total' => 5], 100) === [0, 1, 5]);
check('nothing in, nothing out', lh_group([]) === []);
check('parse size: docker notation', lh_parse_size('1g') === 1073741824 && lh_parse_size('50m') === 52428800 && lh_parse_size('500K') === 512000 && lh_parse_size('100') === 100 && lh_parse_size('10MB') === 10485760);
check('parse size: junk and unset', lh_parse_size('lots') === null && lh_parse_size(null) === null && lh_parse_size('') === null);
finish('detect');
