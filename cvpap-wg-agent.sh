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

# ── self-heal: keep wg0 up ───────────────────────────────────────────────────
# The #1 outage was wg0 being down (reboot without enable, or a crash), which
# strands every router. Since this agent runs every 30s, make it a watchdog:
# if wg0 isn't present, (re)start it and ensure it's enabled for next boot.
ensure_wg_up() {
  if wg show "$WG_IF" >/dev/null 2>&1; then
    return 0
  fi
  echo "[!] $WG_IF is DOWN — bringing it up"
  systemctl enable "wg-quick@$WG_IF" >/dev/null 2>&1 || true
  systemctl start "wg-quick@$WG_IF" >/dev/null 2>&1 || wg-quick up "$WG_IF" >/dev/null 2>&1 || true
  if ! wg show "$WG_IF" >/dev/null 2>&1; then
    echo "[X] could not bring $WG_IF up — check: systemctl status wg-quick@$WG_IF"
    return 1
  fi
  echo "[✓] $WG_IF is back up"
  # it came back possibly empty — force a re-apply of every known peer
  reapply_all_peers
  return 0
}

# Re-add every peer we have a spool record for (used after wg0 restarts empty).
reapply_all_peers() {
  shopt -s nullglob
  for f in "$SPOOL_DIR"/peer-*.json; do
    local pub ips
    pub="$(_field "$f" public_key)"; ips="$(_field "$f" allowed_ips)"
    [ -n "$pub" ] && [ -n "$ips" ] && wg set "$WG_IF" peer "$pub" allowed-ips "$ips" || true
  done
  wg-quick save "$WG_IF" 2>/dev/null || true
}

# Write a health heartbeat the dashboard can read (via the shared spool volume),
# including per-peer handshake age keyed by tunnel IP so the onboarding wizard
# can show live "tunnel handshaking" status for each router.
write_status() {
  local up=false peers=0 now peer_json=""
  now="$(date +%s)"
  if wg show "$WG_IF" >/dev/null 2>&1; then
    up=true
    # `wg show <if> dump` columns (peer rows): pubkey psk endpoint allowed-ips
    #   latest-handshake(unix) rx tx keepalive
    while IFS=$'\t' read -r _pub _psk _ep allowed hs _rx _tx _ka; do
      [ -z "$allowed" ] && continue
      local ip age
      ip="${allowed%%/*}"                    # 10.99.0.8/32 -> 10.99.0.8
      if [ -n "$hs" ] && [ "$hs" != "0" ]; then age=$(( now - hs )); else age=-1; fi
      peer_json="${peer_json:+$peer_json, }\"$ip\": $age"
      peers=$((peers+1))
    done < <(wg show "$WG_IF" dump 2>/dev/null | tail -n +2)
  fi
  printf '{"wg_up": %s, "peers": %s, "updated_at": "%s", "peer_handshake_age": {%s}}\n' \
    "$up" "$peers" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$peer_json" \
    > "$SPOOL_DIR/server-status.json" 2>/dev/null || true
}

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

run_pass() {
  ensure_wg_up || true   # keep wg0 alive (watchdog); non-fatal if it can't
  apply_once
  write_status           # heartbeat for the dashboard
}

if [ "$WATCH" = "1" ]; then
  echo "Watching $SPOOL_DIR for peer requests (Ctrl-C to stop)…"
  while true; do run_pass; sleep 20; done
else
  run_pass
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
