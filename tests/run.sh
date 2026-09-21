#!/bin/sh
# Runs every *_test.php; needs only php-cli.
cd "$(dirname "$0")" || exit 1
rc=0
for t in *_test.php; do
  php "$t" || rc=1
done
exit $rc
