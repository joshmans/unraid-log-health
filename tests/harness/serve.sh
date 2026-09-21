#!/bin/sh
# Serve the Tools page on http://127.0.0.1:8766 against a throwaway fixture (busy box, two scans an hour apart).
cd "$(dirname "$0")" || exit 1
D=$(php make-fixture.php)
echo "fixture: $D"
LH_CONFIG="$D/cfg/config.json" LH_STATE_DIR="$D/state" LH_CRON_DIR="$D/cron" LH_NO_UPDATE_CRON=1 LH_PLUGIN_DIR="$(cd ../../source/usr/local/emhttp/plugins/unraid-log-health && pwd)" exec php -S 127.0.0.1:8766 router.php
