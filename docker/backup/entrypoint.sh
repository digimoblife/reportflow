#!/bin/sh
# Installs the backup schedule (UTC cron expression from BACKUP_CRON; default 19:00 UTC = 02:00 in Asia/Jakarta) and
# runs cron in the foreground. `docker compose run backup /scripts/restore-test.sh` runs a one-off command instead.
set -eu

if [ "$#" -gt 0 ]; then
    exec "$@"
fi

mkdir -p /etc/crontabs
echo "${BACKUP_CRON:-0 19 * * *} /scripts/backup.sh >> /proc/1/fd/1 2>&1" > /etc/crontabs/root
exec crond -f -l 8
