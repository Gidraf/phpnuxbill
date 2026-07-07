#!/usr/bin/env bash
# =============================================================================
# cvpap-site-portal.sh — generate the MikroTik hotspot login.html redirect for
# a site, AFTER you've registered the router in the CVPAP dashboard and gotten
# its portal slug.
#
# Usage:
#   ./cvpap-site-portal.sh <portal-slug> [site-name]
#   ./cvpap-site-portal.sh gidraf-housing-a1b2 gidraf-housing
#
# Writes ~/cvpap-sites/<slug>-login.html — upload it to the MikroTik as the
# hotspot login page (Files → hotspot/login.html), and it will redirect every
# new client to your branded CVPAP captive portal.
# =============================================================================
set -euo pipefail

SLUG="${1:-}"
SITE="${2:-$SLUG}"
if [ -z "$SLUG" ]; then
  echo "Usage: $0 <portal-slug> [site-name]"; exit 1
fi
PORTAL_BASE="${CVPAP_PORTAL_BASE:-https://api.ajiriwa.gidraf.dev}"
OUT_DIR="$HOME/cvpap-sites"; mkdir -p "$OUT_DIR"
OUT="$OUT_DIR/${SLUG}-login.html"

# MikroTik hotspot variables ($(...)) are expanded by the router, not the shell.
cat > "$OUT" <<'HTML'
<!DOCTYPE html>
<html><head><meta charset="utf-8">
<meta http-equiv="refresh" content="0; url=__PORTAL__/wifi/portal/__SLUG__?link-login-only=$(link-login-only)&link-orig=$(link-orig-esc)&mac=$(mac-esc)&ip=$(ip)&error=$(error)">
</head><body>
Redirecting to WiFi purchase…
<script>location.href="__PORTAL__/wifi/portal/__SLUG__?link-login-only=$(link-login-only)&link-orig=$(link-orig-esc)&mac=$(mac-esc)&ip=$(ip)&error=$(error)";</script>
</body></html>
HTML
sed -i "s|__PORTAL__|${PORTAL_BASE}|g; s|__SLUG__|${SLUG}|g" "$OUT"

cat <<EOF

============================================================================
 Portal redirect for site: ${SITE}   (slug: ${SLUG})
============================================================================
 Written: ${OUT}

 INSTALL ON THE MIKROTIK
 - MikroTik WinBox/WebFig → Files → open the 'hotspot' folder →
   replace login.html with this file (drag & drop / upload).
 - Also make sure the portal host is in the walled garden (already added by
   cvpap-add-site.sh):  /ip hotspot walled-garden print

 TEST: connect a phone to the WiFi → it should open your CVPAP portal.
============================================================================
EOF
