#!/bin/bash
# ./build.sh [version]   builds packages/unraid-log-health-<version>.txz and stamps
# unraid-log-health.plg with the version, SHA-256 and CHANGELOG.md
set -e
NAME=unraid-log-health
VERSION="${1:-$(date +%Y.%m.%d)}"
cd "$(dirname "$0")"

rm -rf package-temp packages
mkdir -p package-temp packages
cp -R source/* package-temp/
echo "$VERSION" > "package-temp/usr/local/emhttp/plugins/$NAME/VERSION"
find package-temp -type d -exec chmod 755 {} \;
find package-temp -type f -exec chmod 644 {} \;
chmod 755 "package-temp/usr/local/emhttp/plugins/$NAME/scripts/"*

# COPYFILE_DISABLE keeps macOS from adding ._ files to the archive
COPYFILE_DISABLE=1 tar -C package-temp -cJf "packages/$NAME-$VERSION.txz" usr
rm -rf package-temp

if command -v sha256sum >/dev/null; then SHA256=$(sha256sum "packages/$NAME-$VERSION.txz" | cut -d' ' -f1); else SHA256=$(shasum -a 256 "packages/$NAME-$VERSION.txz" | cut -d' ' -f1); fi

# the plg ships the changelog; "]]>" is the one string that would end the CDATA early
python3 - "$VERSION" "$SHA256" <<'PY'
import re, sys
version, sha256 = sys.argv[1:3]
s = open('unraid-log-health.plg').read()
log = open('CHANGELOG.md').read().replace(']]>', ']]]]><![CDATA[>')
s = re.sub(r'<!ENTITY version "[^"]*">', f'<!ENTITY version "{version}">', s)
s = re.sub(r'<!ENTITY sha256 "[^"]*">', f'<!ENTITY sha256 "{sha256}">', s)
s = re.sub(r'(<CHANGES><!\[CDATA\[\n).*?(\n\]\]></CHANGES>)', lambda m: m.group(1) + log.strip() + m.group(2), s, flags=re.S)
open('unraid-log-health.plg', 'w').write(s)
PY
python3 -c "import xml.etree.ElementTree as E; E.parse('unraid-log-health.plg')"
echo "built packages/$NAME-$VERSION.txz (sha256 $SHA256)"
