<?php
/* Markup for the Tools page; the behaviour lives in js/health.js. */
$lhBase = '/plugins/unraid-log-health';
$lhDir  = dirname(__DIR__);
$lhVer  = fn(string $f) => @filemtime("$lhDir/$f") ?: 0;
$lhCsrf = $GLOBALS['var']['csrf_token'] ?? '';
$lhApi  = $GLOBALS['lh_api_url'] ?? "$lhBase/include/api.php";
?>
<link rel="stylesheet" href="<?= $lhBase ?>/css/health.css?v=<?= $lhVer('css/health.css') ?>">
<div id="lh-app" data-api="<?= htmlspecialchars($lhApi) ?>" data-csrf="<?= htmlspecialchars($lhCsrf) ?>">
  <noscript>Log Health needs JavaScript.</noscript>
</div>
<script src="<?= $lhBase ?>/js/health.js?v=<?= $lhVer('js/health.js') ?>"></script>
