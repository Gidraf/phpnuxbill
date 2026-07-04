#!/usr/bin/env bash
# =============================================================================
# CVPAP / phpNuxBill deployment preflight
#
# Run ON THE SERVER before installing phpNuxBill:
#
#   DB_PASS='<nuxbill db password>' bash cvpap-preflight.sh
#
# Checks every prerequisite from CVPAP docs/WIFI_NUXBILL_DEPLOYMENT.md and
# prints the exact fix command for anything that fails. Exit code 0 = ready.
# =============================================================================
set -u

VOLUME="/mnt/HC_Volume_103347833"
DATA_DIR="$VOLUME/nuxbill"
NET_PATTERN="app-network"
PORT="39090"
DB_NAME="nuxbill"
DB_USER="nuxbill"
DB_PASS="${DB_PASS:-}"

PASS=0; FAIL=0; WARN=0

ok()   { printf '\033[32m  PASS\033[0m  %s\n' "$1"; PASS=$((PASS+1)); }
bad()  { printf '\033[31m  FAIL\033[0m  %s\n' "$1"; [ -n "${2:-}" ] && printf '        fix: %s\n' "$2"; FAIL=$((FAIL+1)); }
warn() { printf '\033[33m  WARN\033[0m  %s\n' "$1"; [ -n "${2:-}" ] && printf '        note: %s\n' "$2"; WARN=$((WARN+1)); }
section() { printf '\n\033[1m%s\033[0m\n' "$1"; }

# ── 1. Storage layout ────────────────────────────────────────────────────────
section "1. Storage layout ($VOLUME)"

if mountpoint -q "$VOLUME"; then
  ok "data volume is mounted"
  avail_gb=$(df -BG --output=avail "$VOLUME" | tail -1 | tr -dc '0-9')
  if [ "${avail_gb:-0}" -ge 10 ]; then
    ok "volume free space: ${avail_gb}G"
  else
    warn "volume free space low: ${avail_gb}G" "consider growing the Hetzner volume"
  fi
else
  bad "data volume not mounted at $VOLUME" "mount it (check /etc/fstab)"
fi

for d in mysql uploads logs backups; do
  if [ -d "$DATA_DIR/$d" ]; then
    ok "directory $DATA_DIR/$d exists"
  else
    bad "missing $DATA_DIR/$d" "mkdir -p $DATA_DIR/{mysql,uploads,logs,backups}"
  fi
done

if [ -d "$DATA_DIR/uploads" ]; then
  owner_uid=$(stat -c %u "$DATA_DIR/uploads")
  if [ "$owner_uid" = "33" ]; then
    ok "uploads owned by uid 33 (www-data in the container)"
  else
    bad "uploads owned by uid $owner_uid, container writes as uid 33" \
        "chown -R 33:33 $DATA_DIR/uploads"
  fi
  if [ -f "$DATA_DIR/uploads/notifications.default.json" ]; then
    ok "uploads seeded with repo defaults"
  else
    bad "uploads not seeded (notification templates missing)" \
        "rsync -a --ignore-existing <repo>/system/uploads/ $DATA_DIR/uploads/"
  fi
fi

if [ -f "$DATA_DIR/config.php" ]; then
  ok "config.php placeholder exists"
else
  bad "config.php missing (bind-mount target)" \
      "touch $DATA_DIR/config.php && chown 33:33 $DATA_DIR/config.php"
fi

# ── 2. Docker ────────────────────────────────────────────────────────────────
section "2. Docker"

if command -v docker >/dev/null 2>&1; then
  ok "docker installed ($(docker --version | cut -d, -f1))"
  if docker info >/dev/null 2>&1; then
    ok "docker daemon running"
    root_dir=$(docker info --format '{{.DockerRootDir}}' 2>/dev/null)
    case "$root_dir" in
      "$VOLUME"*) ok "docker data-root on the volume ($root_dir)" ;;
      *) bad "docker data-root is $root_dir (not on the volume)" \
             "set {\"data-root\": \"$VOLUME/docker\"} in /etc/docker/daemon.json (see runbook)" ;;
    esac
    if docker compose version >/dev/null 2>&1; then
      ok "docker compose plugin available"
    else
      bad "docker compose plugin missing" "apt install docker-compose-plugin"
    fi
    net=$(docker network ls --format '{{.Name}}' | grep "$NET_PATTERN" | head -1)
    if [ -n "$net" ]; then
      ok "CVPAP docker network found: $net"
      [ "$net" != "cvpap_app-network" ] && \
        warn "network name differs from compose default" \
             "edit docker-compose.yml: networks.default.name = $net"
    else
      bad "no docker network matching '$NET_PATTERN'" \
          "start the CVPAP stack first (its compose creates the network)"
    fi
  else
    bad "docker daemon not running" "systemctl start docker"
  fi
else
  bad "docker not installed" "https://docs.docker.com/engine/install/ubuntu/"
fi

# ── 3. Port ──────────────────────────────────────────────────────────────────
section "3. Admin UI port ($PORT)"

if ss -ltn 2>/dev/null | awk '{print $4}' | grep -q ":$PORT\$"; then
  bad "port $PORT already in use" "pick another port in docker-compose.yml"
else
  ok "port $PORT is free"
fi

# ── 4. MariaDB ───────────────────────────────────────────────────────────────
section "4. MariaDB"

if command -v mysql >/dev/null 2>&1; then
  ok "mysql client installed"
else
  bad "mysql client missing" "apt install mariadb-server"
fi

if systemctl is-active --quiet mariadb 2>/dev/null || systemctl is-active --quiet mysql 2>/dev/null; then
  ok "MariaDB/MySQL service running"
else
  bad "MariaDB service not running" "systemctl start mariadb"
fi

if command -v mysql >/dev/null 2>&1 && mysql -e "SELECT 1" >/dev/null 2>&1; then
  datadir=$(mysql -N -e "SELECT @@datadir" 2>/dev/null)
  case "$datadir" in
    "$VOLUME"*) ok "datadir on the volume ($datadir)" ;;
    *) warn "datadir is $datadir (not on the volume)" \
            "optional move — runbook §1a (stop, rsync, datadir=, apparmor alias)" ;;
  esac

  bind=$(mysql -N -e "SHOW VARIABLES LIKE 'bind_address'" 2>/dev/null | awk '{print $2}')
  if [ "$bind" = "0.0.0.0" ] || [ "$bind" = "*" ]; then
    ok "bind-address accepts docker traffic ($bind)"
  else
    bad "bind-address is '$bind' — containers cannot connect" \
        "set bind-address = 0.0.0.0 in /etc/mysql/mariadb.conf.d/50-server.cnf && systemctl restart mariadb"
  fi

  if mysql -N -e "SHOW DATABASES" 2>/dev/null | grep -qx "$DB_NAME"; then
    ok "database '$DB_NAME' exists"
  else
    bad "database '$DB_NAME' missing" \
        "mysql -e \"CREATE DATABASE $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;\""
  fi

  docker_user=$(mysql -N -e "SELECT COUNT(*) FROM mysql.user WHERE user='$DB_USER' AND host LIKE '172.%'" 2>/dev/null)
  if [ "${docker_user:-0}" -ge 1 ]; then
    ok "user '$DB_USER'@'172.%' exists (docker access)"
    grants=$(mysql -N -e "SHOW GRANTS FOR '$DB_USER'@'172.%'" 2>/dev/null | grep -ci "$DB_NAME\|ALL PRIVILEGES ON \*")
    if [ "${grants:-0}" -ge 1 ]; then
      ok "grants on $DB_NAME look correct"
    else
      bad "user has no grants on $DB_NAME" \
          "mysql -e \"GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'172.%'; FLUSH PRIVILEGES;\""
    fi
  else
    bad "user '$DB_USER'@'172.%' missing" \
        "mysql -e \"CREATE USER '$DB_USER'@'172.%' IDENTIFIED BY '<PW>'; GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'172.%'; FLUSH PRIVILEGES;\""
  fi
else
  warn "cannot query MariaDB as root — DB checks skipped" "run as root or configure ~/.my.cnf"
fi

# ── 5. Container → MariaDB path (the exact route nuxbill will use) ──────────
section "5. Container → MariaDB connectivity"

if [ -z "$DB_PASS" ]; then
  warn "DB_PASS not set — skipping the live container connection test" \
       "re-run: DB_PASS='<pw>' bash cvpap-preflight.sh"
elif command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
  net=$(docker network ls --format '{{.Name}}' | grep "$NET_PATTERN" | head -1)
  net_opt=""
  [ -n "$net" ] && net_opt="--network $net"
  if docker run --rm $net_opt --add-host host.docker.internal:host-gateway \
       busybox sh -c "nc -z -w 3 host.docker.internal 3306" >/dev/null 2>&1; then
    ok "TCP 3306 reachable from a container (bind-address + firewall OK)"
  else
    bad "container cannot reach host:3306" \
        "check bind-address, and: ufw allow from 172.16.0.0/12 to any port 3306"
  fi
  # NOTE: uses the mariadb image (client included). Plain php images lack
  # pdo_mysql — the nuxbill Dockerfile installs it at build time.
  login_out=$(docker run --rm $net_opt --add-host host.docker.internal:host-gateway \
       -e P="$DB_PASS" mariadb:11 sh -c \
       "mariadb --skip-ssl --connect-timeout=5 -h host.docker.internal -u $DB_USER -p\"\$P\" $DB_NAME -e 'SELECT 1'" 2>&1)
  if [ $? -eq 0 ]; then
    ok "full login as '$DB_USER' from a container works (exactly what nuxbill does)"
  else
    bad "container login as '$DB_USER' failed" \
        "verify password + grants for '$DB_USER'@'172.%' (section 4 fixes)"
    printf '        error: %s\n' "$(echo "$login_out" | tail -1)"
  fi
else
  warn "docker unavailable — container connectivity test skipped"
fi

# ── 6. Cron & firewall ───────────────────────────────────────────────────────
section "6. Cron & firewall"

if systemctl is-active --quiet cron 2>/dev/null || systemctl is-active --quiet crond 2>/dev/null; then
  ok "cron daemon running (needed for expiry processing)"
else
  bad "cron not running" "systemctl enable --now cron"
fi

if command -v ufw >/dev/null 2>&1 && ufw status | grep -q "Status: active"; then
  if ufw status | grep -q "3306.*172.16.0.0/12"; then
    ok "ufw: 3306 allowed from docker subnets"
  else
    warn "ufw active but no docker-subnet rule for 3306" \
         "ufw allow from 172.16.0.0/12 to any port 3306 && ufw deny 3306"
  fi
else
  warn "ufw inactive/missing — ensure 3306 is not exposed to the internet" \
       "ss -ltn | grep 3306, and verify with a port scan from outside"
fi

# ── summary ──────────────────────────────────────────────────────────────────
printf '\n\033[1m%d passed, %d failed, %d warnings\033[0m\n' "$PASS" "$FAIL" "$WARN"
if [ "$FAIL" -eq 0 ]; then
  printf '\033[32mReady — proceed with: cp docker-compose.cvpap.yml docker-compose.yml && docker compose up -d --build\033[0m\n'
  exit 0
else
  printf '\033[31mFix the FAIL items above, then re-run.\033[0m\n'
  exit 1
fi
