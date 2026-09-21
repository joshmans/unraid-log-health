<?php
require_once __DIR__ . '/lib.php';

$cur = lh_defaults();
[$ok, $e] = lh_validate_settings(['schedule' => ['every' => '1h'], 'sources' => ['php' => ['enabled' => false], 'docker' => ['scan_content' => false]], 'thresholds' => ['min_rate' => '10']], $cur);
check('a valid change is accepted', $e === [] && $ok['schedule']['every'] === '1h' && $ok['sources']['php']['enabled'] === false && $ok['sources']['docker']['scan_content'] === false && $ok['thresholds']['min_rate'] === 10.0);
check('what was not sent is left alone', $ok['sources']['syslog']['enabled'] === true && $ok['thresholds']['min_count'] === 50);
check('the current config is not modified', $cur['schedule']['every'] === '15m');

foreach ([
    'unknown frequency'      => [['schedule' => ['every' => '2m']]],
    'bad time'               => [['schedule' => ['daily_at' => '9am']]],
    'count too low'          => [['thresholds' => ['min_count' => 1]]],
    'rate not a number'      => [['thresholds' => ['min_rate' => 'lots']]],
    'share over 100'         => [['thresholds' => ['share_pct' => 500]]],
    'flood not above noisy'  => [['thresholds' => ['min_rate' => 100, 'flood_rate' => 50]]],
    'crit not above warn'    => [['sources' => ['docker' => ['warn_mb' => 900, 'crit_mb' => 100]]]],
    'docker size zero'       => [['sources' => ['docker' => ['warn_mb' => 0]]]],
] as $label => [$in]) {
    [$out, $errs] = lh_validate_settings($in, $cur);
    check("rejected: $label", $errs !== [] && $out === $cur, json_encode($errs));
}
[$out] = lh_validate_settings(['schedule' => ['every' => '5m'], 'thresholds' => ['min_count' => 1]], $cur);
check('one bad field rejects the whole save', $out === $cur);

[$inj] = lh_validate_settings(['sources' => ['php' => ['files' => ['/etc/shadow'], 'enabled' => true], 'evil' => ['enabled' => true]], 'muted' => ['x' => 1], 'thresholds' => ['nonsense' => 5]], $cur);
check('file paths and unknown keys cannot be set from the form', $inj['sources']['php']['files'] === $cur['sources']['php']['files'] && !isset($inj['sources']['evil']) && $inj['muted'] === [] && !isset($inj['thresholds']['nonsense']));
[$b] = lh_validate_settings(['sources' => ['php' => ['enabled' => 'false'], 'syslog' => ['enabled' => '0'], 'plugins' => ['enabled' => 'true']]], $cur);
check('checkbox strings become booleans', $b['sources']['php']['enabled'] === false && $b['sources']['syslog']['enabled'] === false && $b['sources']['plugins']['enabled'] === true);

/* saving */
$d = lh_tmpdir(); $f = "$d/sub/config.json";
check('save creates the folder and file', lh_save_config($ok, $f) && is_file($f));
check('a saved file loads back the same', lh_load_config($f)['schedule']['every'] === '1h' && lh_load_config($f)['sources']['php']['enabled'] === false);
$mt = filemtime($f); sleep(1);
check('saving identical settings does not rewrite flash', lh_save_config($ok, $f) && filemtime($f) === $mt);
check('an unwritable path fails cleanly', lh_save_config($ok, '/proc/nope/x/config.json') === false);
file_put_contents($f, json_encode(['schedule' => ['every' => 'bogus']]));
check('a saved but invalid frequency is still just data (the scan treats it as off)', lh_interval_minutes(lh_load_config($f)) === 0);
lh_rmtree($d);
finish('config');
