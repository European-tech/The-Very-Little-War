#!/bin/bash
# TVLW log rotation — delete game logs older than 30 days.
# Install: cp cron/cleanup-logs.sh /etc/cron.daily/tvlw-cleanup-logs && chmod +x /etc/cron.daily/tvlw-cleanup-logs
# Or add to crontab: 0 3 * * * /var/www/html/cron/cleanup-logs.sh

LOG_DIR="${1:-/var/www/html/logs}"

if [ -d "$LOG_DIR" ]; then
    find "$LOG_DIR" -name "*.log" -mtime +30 -delete
fi
