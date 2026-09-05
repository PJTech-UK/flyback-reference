#!/usr/bin/env bash
#
# Prepare per-site access logging. Run as root on the server, once.
#
#   sudo deploy/setup-logging.sh <site-user> <site-home>
#   sudo deploy/setup-logging.sh fbref /home/fbref/flyback-reference.net
#
# Under Forge's site isolation the site user is the per-site account, not
# `forge`. It is the owner of the site directory:  stat -c %U <site-home>
#
# What it does:
#   - creates <site-home>/logs and an access.log owned by the site user
#   - installs the rotation config as /etc/logrotate.d/<site-user>
#   - dry-runs logrotate so you can see what it will do
#   - prints the nginx lines to paste into Forge
#
# It does NOT touch the nginx configuration: Forge owns that file and rewrites
# it, so pasting through the Forge UI is the only change that survives.
set -euo pipefail

USER_NAME="${1:-}"
SITE_HOME="${2:-}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [[ -z "$USER_NAME" || -z "$SITE_HOME" ]]; then
    echo "usage: sudo deploy/setup-logging.sh <site-user> <site-home>" >&2
    exit 1
fi
if [[ $EUID -ne 0 ]]; then
    echo "error: run this as root — it writes to /etc/logrotate.d" >&2
    exit 1
fi
if ! id "$USER_NAME" >/dev/null 2>&1; then
    echo "error: no such user: $USER_NAME" >&2
    exit 1
fi
if [[ ! -d "$SITE_HOME" ]]; then
    echo "error: no such directory: $SITE_HOME" >&2
    exit 1
fi

OWNER="$(stat -c %U "$SITE_HOME")"
if [[ "$OWNER" != "$USER_NAME" ]]; then
    echo "warning: $SITE_HOME is owned by $OWNER, not $USER_NAME." >&2
    echo "         Under site isolation the site user owns its own directory;" >&2
    echo "         check you have the right one before continuing." >&2
fi

LOG_DIR="$SITE_HOME/logs"
LOG="$LOG_DIR/access.log"

# nginx's master runs as root and opens log files itself, so it can write here
# whatever the workers run as. But a file it creates is owned by root, which the
# site user then cannot read — so create it first, with the right owner.
install -d -o "$USER_NAME" -g "$USER_NAME" -m 0755 "$LOG_DIR"
[[ -e "$LOG" ]] || install -o "$USER_NAME" -g "$USER_NAME" -m 0640 /dev/null "$LOG"
echo "log ready: $LOG ($(stat -c '%U:%G %a' "$LOG"))"

ROTATE="/etc/logrotate.d/$USER_NAME"
sed -e "s|SITE_HOME|$SITE_HOME|g" -e "s|SITE_USER|$USER_NAME|g" \
    "$HERE/logrotate.conf" > "$ROTATE"
chmod 0644 "$ROTATE"
echo "rotation installed: $ROTATE"
echo
logrotate -d "$ROTATE" 2>&1 | sed 's/^/  /'
echo
echo "Now paste this into Forge (Site -> Nginx Configuration)."
echo "The map and log_format go ABOVE the server { } block; the rest inside it:"
echo
sed -e "s|SITE_HOME|$SITE_HOME|g" "$HERE/nginx-logging.conf" \
  | grep -v '^#' | grep -v '^$' | sed 's/^/  /'
echo
echo "Then reload nginx and check a line arrives with a zeroed final octet:"
echo "  tail -n 3 $LOG"
