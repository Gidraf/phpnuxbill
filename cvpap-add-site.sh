#!/usr/bin/env bash
# =============================================================================
# cvpap-add-site.sh — onboard a new MikroTik hotspot site in one command.
#
# Run ON THE CLOUD SERVER (5.78.137.59). It:
#   1. Ensures the WireGuard server is set up (first run only, idempotent).
#   2. Allocates the next free tunnel IP (10.99.0.2, .3, .4 …).
#   3. Generates the MikroTik's WireGuard keypair *here* and registers it as a
#      peer — so there is NO copy-back-and-forth of keys.
#   4. Writes a ready-to-paste RouterOS script:  ~/cvpap-sites/<site>.rsc
#
# Usage:
#   ./cvpap-add-site.sh <site-name>
#   ./cvpap-add-site.sh gidraf-housing
#
# Then: paste that .rsc into the MikroTik terminal (or upload + /import it),
# and register the router in the CVPAP dashboard using the tunnel IP it prints.
# =============================================================================
set -euo pipefail

SITE="${1:-}"
if [ -z "$SITE" ]; then
  echo "Usage: $0 <site-name>   (e.g. $0 gidraf-housing)"; exit 1
fi
SITE_SLUG="$(echo "$SITE" | tr '[:upper:] ' '[:lower:]-' | tr -cd 'a-z0-9-')"

# ── config (edit these once if your setup differs) ───────────────────────────
SERVER_ENDPOINT="${CVPAP_WG_ENDPOINT:-5.78.137.59}"   # this server's public IP/host
SERVER_PORT=51820
WG_IF=wg0
WG_DIR=/etc/wireguard
TUNNEL_NET="10.99.0"        # /24 tunnel subnet; server is .1, sites are .2+
API_USER=nuxbill
HOTSPOT_INTERFACE="bridge"  # the MikroTik interface your APs are bridged to
PORTAL_HOST="${CVPAP_PORTAL_HOST:-api.ajiriwa.gidraf.dev}"
OUT_DIR="$HOME/cvpap-sites"

if [ "$(id -u)" != "0" ]; then echo "Run as root (sudo)."; exit 1; fi
mkdir -p "$OUT_DIR" "$WG_DIR"; chmod 700 "$OUT_DIR"

# ── 1. ensure the WireGuard server is up (self-healing) ──────────────────────
command -v wg >/dev/null 2>&1 || { apt-get update -qq && apt-get install -y -qq wireguard; }
umask 077
[ -f "$WG_DIR/server.key" ] || wg genkey | tee "$WG_DIR/server.key" | wg pubkey > "$WG_DIR/server.pub"
[ -f "$WG_DIR/server.pub" ] || wg pubkey < "$WG_DIR/server.key" > "$WG_DIR/server.pub"

# If the interface isn't actually up (fresh, or a broken config like a literal
# placeholder peer), write a clean interface-only config and (re)start it.
if ! wg show "$WG_IF" >/dev/null 2>&1; then
  echo "[+] WireGuard $WG_IF is not up — writing a clean config and starting it…"
  # preserve any already-saved real peers
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
wg show "$WG_IF" >/dev/null 2>&1 || { echo "ERROR: WireGuard $WG_IF still not up. Check: systemctl status wg-quick@$WG_IF"; exit 1; }
SERVER_PUB="$(cat "$WG_DIR/server.pub")"

# ── 2. allocate the next free tunnel IP ──────────────────────────────────────
USED="$(wg show "$WG_IF" allowed-ips 2>/dev/null | grep -oE "${TUNNEL_NET}\.[0-9]+" | grep -oE '[0-9]+$' || true)"
NEXT=2
while echo "$USED" | grep -qx "$NEXT"; do NEXT=$((NEXT+1)); done
SITE_IP="${TUNNEL_NET}.${NEXT}"

# ── 3. generate the MikroTik keypair here + register the peer ────────────────
umask 077
MT_PRIV="$(wg genkey)"
MT_PUB="$(echo "$MT_PRIV" | wg pubkey)"
wg set "$WG_IF" peer "$MT_PUB" allowed-ips "${SITE_IP}/32"
wg-quick save "$WG_IF"

# a per-site RouterOS API password
API_PASS="$(openssl rand -hex 12)"

# ── 4. write the ready-to-paste MikroTik script ──────────────────────────────
RSC="$OUT_DIR/${SITE_SLUG}.rsc"
cat > "$RSC" <<EOF
# ===========================================================================
# CVPAP MikroTik onboarding — site: ${SITE}
# Paste this whole block into the MikroTik terminal, OR upload the file and
# run:  /import file-name=${SITE_SLUG}.rsc
# Tunnel IP for this router: ${SITE_IP}
# ===========================================================================

# --- 1. WireGuard tunnel back to the billing server -------------------------
/interface wireguard add name=wg-cvpap listen-port=13231 private-key="${MT_PRIV}"
/interface wireguard peers add interface=wg-cvpap \\
    public-key="${SERVER_PUB}" \\
    endpoint-address=${SERVER_ENDPOINT} endpoint-port=${SERVER_PORT} \\
    allowed-address=${TUNNEL_NET}.0/24 persistent-keepalive=25s
/ip address add address=${SITE_IP}/24 interface=wg-cvpap

# --- 2. RouterOS API user for nuxbill (locked to the tunnel) ----------------
/user add name=${API_USER} group=full password="${API_PASS}" address=${TUNNEL_NET}.0/24
/ip service set api disabled=no port=8728 address=${TUNNEL_NET}.0/24

# --- 3. Hotspot on the AP bridge --------------------------------------------
# (Adjust HOTSPOT_INTERFACE at the top of cvpap-add-site.sh if not 'bridge'.)
/ip hotspot setup
# ^ the wizard asks: interface=${HOTSPOT_INTERFACE}, address pool, DNS.
#   After it finishes, run:
/ip hotspot profile set [find] login-by=http-pap
/ip hotspot walled-garden add dst-host=${PORTAL_HOST}

# --- 4. Portal redirect -----------------------------------------------------
# After you register this router in the CVPAP dashboard you'll get a portal
# slug. Then replace the hotspot login.html with the redirect stub (ask CVPAP
# for the exact line, or run cvpap-site-portal.sh <slug>).
EOF
chmod 600 "$RSC"

# ── summary ──────────────────────────────────────────────────────────────────
cat <<EOF

============================================================================
 Site ready:  ${SITE}
============================================================================
 MikroTik script written to:  ${RSC}

 NEXT STEPS
 1. Open the file and paste it into the MikroTik terminal:
        cat ${RSC}
    (or upload it to the router and run:  /import file-name=${SITE_SLUG}.rsc )

 2. Verify the tunnel — on this server:
        ping -c3 ${SITE_IP}

 3. Register the router in the CVPAP dashboard → WiFi Billing → Add Router:
        IP:        ${SITE_IP}:8728
        Username:  ${API_USER}
        Password:  ${API_PASS}

 (Keep this password — it is also inside the .rsc file, chmod 600.)
============================================================================
EOF
