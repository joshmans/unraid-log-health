<?php
/* Tiny test kit: check(), and temp dirs. */

require_once __DIR__ . '/../source/usr/local/emhttp/plugins/unraid-log-health/include/scan.php';

$GLOBALS['lh_fail'] = 0;
$GLOBALS['lh_pass'] = 0;

function check(string $label, bool $ok, string $detail = ''): void {
    if ($ok) { $GLOBALS['lh_pass']++; return; }
    $GLOBALS['lh_fail']++;
    echo "FAIL: $label" . ($detail !== '' ? "\n      $detail" : '') . "\n";
}

function finish(string $name): void {
    echo "$name: {$GLOBALS['lh_pass']} passed, {$GLOBALS['lh_fail']} failed\n";
    exit($GLOBALS['lh_fail'] ? 1 : 0);
}

function lh_tmpdir(): string {
    $d = sys_get_temp_dir() . '/lh-test-' . bin2hex(random_bytes(4));
    mkdir($d, 0777, true);
    return $d;
}

function lh_rmtree(string $d): void {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($d);
}

function lh_fixture(string $name): string { return __DIR__ . "/fixtures/$name"; }
