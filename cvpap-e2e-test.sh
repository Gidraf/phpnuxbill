#!/usr/bin/env bash
# =============================================================================
# Zero-config wrapper for cvpap-e2e-test.py — run ON THE SERVER:
#
#   ./cvpap-e2e-test.sh [--keep]
#
# Reads the api key from the database, reads (or creates) the plugin's shared
# secret in tbl_appconfig — the same row the admin UI's "CVPAP Bridge" page
# uses — then runs the end-to-end billing test.
# =============================================================================
set -euo pipefail
cd "$(dirname "$0")"

if ! command -v mysql >/dev/null 2>&1; then
  echo "mysql client not found — run this on the server." >&2
  exit 2
fi

# de-duplicate settings rows (keep the newest — matches how the app reads
# config: later rows win), otherwise wrapper and plugin can disagree
mysql nuxbill -e "DELETE t1 FROM tbl_appconfig t1
  JOIN tbl_appconfig t2 ON t1.setting = t2.setting AND t1.id < t2.id
  WHERE t1.setting IN ('api_key','cvpap_shared_secret');"

API_KEY=$(mysql nuxbill -N -e "SELECT value FROM tbl_appconfig WHERE setting='api_key' ORDER BY id DESC LIMIT 1;")
if [ -z "$API_KEY" ]; then
  API_KEY=$(openssl rand -hex 20)
  mysql nuxbill -e "INSERT INTO tbl_appconfig (setting, value) VALUES ('api_key', '$API_KEY');"
  echo "api_key was missing — generated and saved one."
fi

SECRET=$(mysql nuxbill -N -e "SELECT value FROM tbl_appconfig WHERE setting='cvpap_shared_secret' ORDER BY id DESC LIMIT 1;")
if [ -z "$SECRET" ]; then
  SECRET=$(openssl rand -hex 32)
  mysql nuxbill -e "INSERT INTO tbl_appconfig (setting, value) VALUES ('cvpap_shared_secret', '$SECRET');"
  echo "cvpap_shared_secret was missing — generated and saved one (visible in Settings → CVPAP Bridge)."
fi

echo "Using api_key=${API_KEY:0:8}…  shared_secret=${SECRET:0:8}…"
echo "(CVPAP .env later: NUXBILL_API_KEY=$API_KEY  NUXBILL_WEBHOOK_SECRET=$SECRET)"
echo

NUXBILL_API_KEY="$API_KEY" NUXBILL_SECRET="$SECRET" exec python3 cvpap-e2e-test.py "$@"
