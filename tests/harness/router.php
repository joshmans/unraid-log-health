<?php
/* php -S router: serves the Tools page outside Unraid, against the fixture from make-fixture.php.
 * /?theme=dark shows it on a dark background so both Unraid themes can be checked. */
$src = realpath(__DIR__ . '/../../source/usr/local/emhttp/plugins/unraid-log-health');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/') {
    $GLOBALS['var'] = ['csrf_token' => 'test'];
    $dark = ($_GET['theme'] ?? '') === 'dark';
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Log Health harness</title>'
       . '<body style="font-family:clear-sans,sans-serif;font-size:13px;margin:16px 24px;'
       . ($dark ? 'background:#1c1b1b;color:#e3e3e3' : 'background:#fff;color:#1c1b1b') . '"><h2>Log Health</h2>';
    require "$src/include/page.php";
    return true;
}
if (strpos($path, '/plugins/unraid-log-health/') === 0) {
    $f = realpath($src . substr($path, strlen('/plugins/unraid-log-health')));
    if ($f && strpos($f, $src) === 0 && is_file($f)) {
        if (substr($f, -4) === '.php') { require $f; return true; }
        header('Content-Type: ' . (substr($f, -3) === 'css' ? 'text/css' : 'application/javascript'));
        readfile($f);
        return true;
    }
}
http_response_code(404);
