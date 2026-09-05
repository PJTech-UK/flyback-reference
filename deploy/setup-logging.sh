#!/usr/bin/env bash
#
# Prepare per-site access logging on a Forge server. Run as root, once.
#
#   sudo deploy/setup-logging.sh <site-user> <site-home>
#   sudo deploy/setup-logging.sh fbref /home/fbref/flyback-reference.net
#
# Under Forge's site isolation the site user is the per-site account, not
# `forge`. It owns the site directory:  stat -c %U <site-home>
#
# What it does:
#   1. creates <site-home>/logs and an access.log owned by the site user
#   2. writes the map and log_format into the site's forge-conf before/ include,
#      which is http context and survives Forge regenerating the site file
#   3. installs and dry-runs the log rotation config
#   4. prints the two fragments you must paste into Forge's General Config,
#      because that file is Forge's to own and editing it behind the UI's back
#      is how you lose the change on the next deploy
set -euo pipefail

USER_NAME="${1:-}"
SITE_HOME="${2:-}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

[[ -n "$USER_NAME" && -n "$SITE_HOME" ]] || {
    echo "usage: sudo deploy/setup-logging.sh <site-user> <site-home>" >&2; exit 1; }
[[ $EUID -eq 0 ]] || { echo "error: run as root — it writes under /etc/nginx" >&2; exit 1; }
id "$USER_NAME" >/dev/null 2>&1 || { echo "error: no such user: $USER_NAME" >&2; exit 1; }
[[ -d "$SITE_HOME" ]] || { echo "error: no such directory: $SITE_HOME" >&2; exit 1; }

OWNER="$(stat -c %U "$SITE_HOME")"
[[ "$OWNER" == "$USER_NAME" ]] || {
    echo "warning: $SITE_HOME is owned by $OWNER, not $USER_NAME — check you have" >&2
    echo "         the right user before continuing." >&2; }

# --- 1. the log file, owned by the site user -------------------------------
#
# nginx's master runs as root and opens log files itself, so it can write into
# an isolated site's home whatever the workers run as. But a file nginx creates
# is owned by root, and the site user then cannot read its own log — so create
# it first, with the right owner, and nginx appends to it.
LOG_DIR="$SITE_HOME/logs"
LOG="$LOG_DIR/access.log"
install -d -o "$USER_NAME" -g "$USER_NAME" -m 0755 "$LOG_DIR"
[[ -e "$LOG" ]] || install -o "$USER_NAME" -g "$USER_NAME" -m 0640 /dev/null "$LOG"
echo "1. log ready: $LOG ($(stat -c '%U:%G %a' "$LOG"))"

# --- 2. map + log_format into the before/ include --------------------------
#
# Find the site's Forge id from whichever nginx site file mentions this home.
SITE_CONF="$(grep -rls "root $SITE_HOME/public;" /etc/nginx/sites-available/ 2>/dev/null | head -1 || true)"
FORGE_ID=""
[[ -n "$SITE_CONF" ]] && FORGE_ID="$(grep -oE 'forge-conf/[0-9]+/' "$SITE_CONF" | head -1 | tr -dc '0-9' || true)"

extract() {  # print one PART block from nginx-logging.conf, comments stripped
    awk -v n="$1" '
        $0 ~ "^# PART " n " " {inblk=1; next}
        inblk && /^# =+$/ {next}
        inblk && $0  ~ "^# PART " {exit}
        inblk {print}
    ' "$HERE/nginx-logging.conf" | sed -e "s|SITE_HOME|$SITE_HOME|g" \
        | grep -v '^# ===' | sed '/./,$!d'
}

BEFORE_DIR="/etc/nginx/forge-conf/$FORGE_ID/before"
if [[ -n "$FORGE_ID" && -d "$BEFORE_DIR" ]]; then
    extract 1 > "$BEFORE_DIR/logging.conf"
    chmod 0644 "$BEFORE_DIR/logging.conf"
    echo "2. http-context config written: $BEFORE_DIR/logging.conf"
else
    echo "2. could not find the site's forge-conf before/ directory."
    echo "   Paste this at the very TOP of the Domain conf, above the first include:"
    echo
    extract 1 | sed 's/^/     /'
fi

# --- 3. rotation -----------------------------------------------------------
#
# Ubuntu's stock logrotate covers /var/log/nginx/*.log and nothing else, so a
# log in the site's home is rotated by nothing at all.
ROTATE="/etc/logrotate.d/$USER_NAME"
sed -e "s|SITE_HOME|$SITE_HOME|g" -e "s|SITE_USER|$USER_NAME|g" \
    "$HERE/logrotate.conf" > "$ROTATE"
chmod 0644 "$ROTATE"
echo "3. rotation installed: $ROTATE"
logrotate -d "$ROTATE" 2>&1 | sed 's/^/     /'

# --- 4. what you must do by hand -------------------------------------------
cat <<BANNER

4. Paste these into Forge -> Site -> General Config. That file is included
   INSIDE the server { } block, which is why the map and log_format above do
   not go there; these two do.

   Replace the existing 'access_log off;' with:

BANNER
extract 2 | sed 's/^/     /'
echo
echo "   And add, among the other location blocks:"
echo
extract 3 | sed 's/^/     /'
cat <<BANNER

   Then reload nginx and confirm a line arrives with a zeroed final octet:

     nginx -t && systemctl reload nginx
     tail -n 3 $LOG
BANNER
