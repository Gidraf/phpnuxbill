#!/usr/bin/env bash
# =============================================================================
# cvpap-wg-agent.sh — host-side WireGuard peer agent.
#
# CVPAP runs in a container and cannot touch the host's wg0 directly. When a
# superadmin provisions a MikroTik site from the web, the CVPAP backend drops a
# small JSON request into the shared spool dir. This agent (run ON THE SERVER,
# 5.78.137.59) reads those requests and applies them to wg0.
#
#   peer-<ip>.json    → wg set wg0 peer <pubkey> allowed-ips <ip>/32
#   remove-<ip>.json  → wg set wg0 peer <pubkey> remove
#
# After applying, it runs `wg-quick save wg0` and marks the file applied.
#
# USAGE
#   sudo ./cvpap-wg-agent.sh            # single pass (good for cron)
#   sudo ./cvpap-wg-agent.sh --watch    # loop every 20s
#
# INSTALL AS A SYSTEMD TIMER (recommended)
#   sudo cp cvpap-wg-agent.sh /usr/local/bin/
#   # then create the unit + timer shown at the bottom of this file.
# =============================================================================
set -euo pipefail

WG_IF="${CVPAP_WG_IF:-wg0}"
SPOOL_DIR="${WG_SPOOL_DIR:-/mnt/HC_Volume_103347833/wg-spool}"
WATCH=0
[ "${1:-}" = "--watch" ] && WATCH=1

if [ "$(id -u)" != "0" ]; then echo "Run as root (sudo)."; exit 1; fi
command -v wg >/dev/null 2>&1 || { echo "wg not installed"; exit 1; }
mkdir -p "$SPOOL_DIR"

# jq is optional — fall back to grep/sed if missing.
_field() {  # _field <file> <key>
  if command -v jq >/dev/null 2>&1; then
    jq -r ".$2 // empty" "$1"
  else
    grep -oE "\"$2\"[[:space:]]*:[[:space:]]*\"[^\"]*\"" "$1" | sed -E "s/.*:[[:space:]]*\"([^\"]*)\"/\1/"
  fi
}

apply_once() {
  local applied=0
  shopt -s nullglob
  for f in "$SPOOL_DIR"/peer-*.json "$SPOOL_DIR"/remove-*.json; do
    # skip already-applied
    grep -q '"applied": *true' "$f" && continue

    local pub ips remove base
    pub="$(_field "$f" public_key)"
    ips="$(_field "$f" allowed_ips)"
    base="$(basename "$f")"
    [ -z "$pub" ] && { echo "[!] $base: no public_key, skipping"; continue; }

    if [[ "$base" == remove-* ]]; then
      echo "[-] removing peer $pub"
      wg set "$WG_IF" peer "$pub" remove || true
    else
      echo "[+] adding peer $pub allowed-ips $ips"
      wg set "$WG_IF" peer "$pub" allowed-ips "$ips"
    fi

    # mark applied (portable: rewrite the flag)
    sed -i 's/"applied": *false/"applied": true/' "$f" 2>/dev/null || \
      sed -i 's/"applied":false/"applied":true/' "$f" 2>/dev/null || true
    applied=1
  done
  if [ "$applied" = "1" ]; then
    wg-quick save "$WG_IF"
    echo "[✓] wg-quick save $WG_IF"
  fi
}

if [ "$WATCH" = "1" ]; then
  echo "Watching $SPOOL_DIR for peer requests (Ctrl-C to stop)…"
  while true; do apply_once; sleep 20; done
else
  apply_once
fi

# =============================================================================
# systemd install (paste on the server):
#
#   sudo tee /etc/systemd/system/cvpap-wg-agent.service >/dev/null <<'UNIT'
#   [Unit]
#   Description=CVPAP WireGuard peer agent
#   After=wg-quick@wg0.service
#   [Service]
#   Type=oneshot
#   Environment=WG_SPOOL_DIR=/mnt/HC_Volume_103347833/wg-spool
#   ExecStart=/usr/local/bin/cvpap-wg-agent.sh
#   UNIT
#
#   sudo tee /etc/systemd/system/cvpap-wg-agent.timer >/dev/null <<'UNIT'
#   [Unit]
#   Description=Run CVPAP WireGuard peer agent every 30s
#   [Timer]
#   OnBootSec=30
#   OnUnitActiveSec=30
#   AccuracySec=5
#   [Install]
#   WantedBy=timers.target
#   UNIT
#
#   sudo systemctl daemon-reload
#   sudo systemctl enable --now cvpap-wg-agent.timer
# =============================================================================
