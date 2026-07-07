#!/usr/bin/env bash
# =============================================================================
# cvpap-wg-server-init.sh — ONE-TIME WireGuard server bootstrap.
#
# Run this ONCE on the cloud server (5.78.137.59). After this, every new
# MikroTik/site is onboarded from the CVPAP web UI (Set up WiFi) — you never
# need to SSH in again to add a router.
#
# It:
#   1. Installs WireGuard and brings up wg0 (10.99.0.1/24) — idempotent.
#   2. Prints WG_SERVER_PUBKEY to paste into the CVPAP container env.
#   3. Installs the peer agent + systemd timer so peers queued by the web UI
#      get applied to wg0 automatically.
#
# Usage:  sudo ./cvpap-wg-server-init.sh
# =============================================================================
set -euo pipefail

SERVER_PORT="${CVPAP_WG_PORT:-51820}"
WG_IF="${CVPAP_WG_IF:-wg0}"
WG_DIR=/etc/wireguard
TUNNEL_NET="${CVPAP_WG_NET:-10.99.0}"
SPOOL_DIR="${WG_SPOOL_DIR:-/mnt/HC_Volume_103347833/wg-spool}"
AGENT_SRC="$(cd "$(dirname "$0")" && pwd)/cvpap-wg-agent.sh"

if [ "$(id -u)" != "0" ]; then echo "Run as root (sudo)."; exit 1; fi

# ── 1. WireGuard server (idempotent) ─────────────────────────────────────────
command -v wg >/dev/null 2>&1 || { apt-get update -qq && apt-get install -y -qq wireguard; }
umask 077
mkdir -p "$WG_DIR"
[ -f "$WG_DIR/server.key" ] || wg genkey | tee "$WG_DIR/server.key" | wg pubkey > "$WG_DIR/server.pub"
[ -f "$WG_DIR/server.pub" ] || wg pubkey < "$WG_DIR/server.key" > "$WG_DIR/server.pub"

if ! wg show "$WG_IF" >/dev/null 2>&1; then
  echo "[+] Bringing up $WG_IF…"
  # preserve any real peers already saved
  PEERS_BLOCK=""
  if [ -f "$WG_DIR/$WG_IF.conf" ]; then
    PEERS_BLOCK="$(awk '/^\[Peer\]/{p=1} p' "$WG_DIR/$WG_IF.conf" | grep -v '<.*>' || true)"
  fi
  cat > "$WG_DIR/$WG_IF.conf" <<EOF
[Interface]
Address = ${TUNNEL_NET}.1/24
ListenPort = ${SERVER_PORT}
PrivateKey = $(cat "$WG_DIR/server.key")
PostUp   = iptables -t nat -A POSTROUTING -s 172.16.0.0/12 -o ${WG_IF} -j MASQUERADE
PostDown = iptables -t nat -D POSTROUTING -s 172.16.0.0/12 -o ${WG_IF} -j MASQUERADE
${PEERS_BLOCK}
EOF
  sysctl -w net.ipv4.ip_forward=1 >/dev/null
  grep -q '^net.ipv4.ip_forward=1' /etc/sysctl.conf || echo 'net.ipv4.ip_forward=1' >> /etc/sysctl.conf
  systemctl enable "wg-quick@$WG_IF" >/dev/null 2>&1 || true
  systemctl restart "wg-quick@$WG_IF"
  command -v ufw >/dev/null 2>&1 && {
    ufw allow ${SERVER_PORT}/udp >/dev/null 2>&1 || true
    ufw route allow from 172.16.0.0/12 to ${TUNNEL_NET}.0/24 >/dev/null 2>&1 || true
  }
fi
wg show "$WG_IF" >/dev/null 2>&1 || { echo "ERROR: $WG_IF not up. Check: systemctl status wg-quick@$WG_IF"; exit 1; }
SERVER_PUB="$(cat "$WG_DIR/server.pub")"

# ── 2. install the peer agent + timer ────────────────────────────────────────
# The CVPAP container runs as a non-root user (appuser) and must be able to drop
# peer requests here. The earlier `umask 077` would make this 700/root-only, so
# force a shared, sticky-writable spool (like /tmp): container writes, root agent
# applies. Without this you get "Permission denied … wg-spool/peer-*.json".
mkdir -p "$SPOOL_DIR"
chmod 1777 "$SPOOL_DIR"
if [ -f "$AGENT_SRC" ]; then
  install -m 0755 "$AGENT_SRC" /usr/local/bin/cvpap-wg-agent.sh
else
  echo "[!] cvpap-wg-agent.sh not found next to this script — copy it to /usr/local/bin manually."
fi

cat > /etc/systemd/system/cvpap-wg-agent.service <<UNIT
[Unit]
Description=CVPAP WireGuard peer agent
After=wg-quick@${WG_IF}.service
[Service]
Type=oneshot
Environment=WG_SPOOL_DIR=${SPOOL_DIR}
Environment=CVPAP_WG_IF=${WG_IF}
ExecStart=/usr/local/bin/cvpap-wg-agent.sh
UNIT

cat > /etc/systemd/system/cvpap-wg-agent.timer <<UNIT
[Unit]
Description=Run CVPAP WireGuard peer agent every 30s
[Timer]
OnBootSec=30
OnUnitActiveSec=30
AccuracySec=5
[Install]
WantedBy=timers.target
UNIT

systemctl daemon-reload
systemctl enable --now cvpap-wg-agent.timer >/dev/null 2>&1 || true

# ── summary ──────────────────────────────────────────────────────────────────
cat <<EOF

============================================================================
 WireGuard server ready — one-time setup complete.
============================================================================
 From now on, onboard every MikroTik/site from the CVPAP web UI
 (WiFi → Set up WiFi). No more SSH needed.

 1. Put these in the CVPAP container environment (web + celery), then restart:

      WG_SERVER_PUBKEY=${SERVER_PUB}
      WG_SERVER_ENDPOINT=$(curl -4 -s ifconfig.me 2>/dev/null || curl -s ipv4.icanhazip.com 2>/dev/null || echo '<THIS_SERVER_PUBLIC_IPv4>')
      WG_SERVER_PORT=${SERVER_PORT}
      WG_TUNNEL_NET=${TUNNEL_NET}
      WG_SPOOL_DIR=${SPOOL_DIR}

 2. Make sure ${SPOOL_DIR} is mounted into the CVPAP container as a volume
    (so the web UI and this server share the peer queue):

      volumes:
        - ${SPOOL_DIR}:${SPOOL_DIR}

 3. Peer agent timer is installed and running:
      systemctl status cvpap-wg-agent.timer
============================================================================
EOF
