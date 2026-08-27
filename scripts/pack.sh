#!/usr/bin/env bash
# Build a FreePBX-compatible archive: one top-level folder named after rawname.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VER="$(sed -n 's/.*<version>\([^<]*\)<\/version>.*/\1/p' "$ROOT/module.xml" | head -1)"
if [[ -z "$VER" ]]; then
	echo "Could not read <version> from module.xml" >&2
	exit 1
fi

REPO="${GITHUB_REPOSITORY:-}"
TAG="${TAG:-v${VER}}"
DIST="$ROOT/dist"
STAGE="$DIST/stage/exunity"

rm -rf "$DIST"
mkdir -p "$STAGE"

tar -C "$ROOT" \
	--exclude='./.git' \
	--exclude='./.github' \
	--exclude='./dist' \
	--exclude='./scripts' \
	--exclude='./.gitignore' \
	-cf - . | tar -C "$STAGE" -xf -

tar -C "$DIST/stage" -czf "$DIST/exunity.tgz" exunity
cp -f "$DIST/exunity.tgz" "$DIST/exunity-${VER}.tgz"

LOCATION=""
if [[ -n "$REPO" ]]; then
	LOCATION="https://github.com/${REPO}/releases/download/${TAG}/exunity.tgz"
fi

python3 - "$DIST/update.json" "$VER" "$LOCATION" <<'PY'
import json, sys
path, ver, location = sys.argv[1], sys.argv[2], sys.argv[3]
data = {
    "rawname": "exunity",
    "name": "eXTools",
    "version": ver,
    "publisher": "eXTools",
    "license": "GPLv3+",
    "repo": "unsupported",
    "description": "eXTools: массовое создание внутренников, Telegram (включая голосовую почту), HTTP-провижининг телефонов, телефонная книга, записи и очереди. / Bulk extensions, Telegram notifications, HTTP auto-provisioning, Contact Manager phonebooks, recordings, and queues.",
    "changelog": f"*{ver}* See CHANGELOG.md",
}
if location:
    data["location"] = location
    data["downloadurl"] = location
with open(path, "w", encoding="utf-8") as fh:
    json.dump(data, fh, indent=2)
    fh.write("\n")
PY

echo "Built $DIST/exunity.tgz (version $VER)"
if [[ -n "$LOCATION" ]]; then
	echo "update.json location: $LOCATION"
fi
