#!/usr/bin/env bash
# Daily backup (PRD §84): database (pg_dump -Fc) + report files + configuration -> one archive, encrypted with a key that
# is NOT stored with the backups, copied off the server with rclone, then retention (7 daily + 4 weekly).
# Writes /status/status.json for ops:check; any failure marks the backup failed (the operator gets an alert).
source "$(dirname "$0")/lib.sh"

work=""
cleanup() { if [ -n "$work" ]; then rm -rf "${work:?}"; fi; }
trap cleanup EXIT
trap 'merge_status last_status=failed last_error="${ERROR_CODE:-backup_failed}" last_attempt_at="$(now_iso)"; log "backup FAILED (${ERROR_CODE:-backup_failed})"' ERR

ERROR_CODE=precondition
require_key
command -v pg_dump >/dev/null || die "pg_dump not found"

stamp="$(date -u +%Y%m%dT%H%M%SZ)"
name="reportflow-${stamp}.tar.enc"
work="$(mktemp -d)"
mkdir -p "$work/bundle/config" "$BACKUP_DIR/daily" "$BACKUP_DIR/weekly"

ERROR_CODE=database_dump
log "dumping the database"
pg_dump --format=custom --no-owner --file="$work/bundle/db.dump"

ERROR_CODE=storage_archive
log "archiving report files"
files_dir="$APP_DIR/storage/app/private"
mkdir -p "$files_dir"
tar -czf "$work/bundle/storage.tar.gz" -C "$APP_DIR/storage/app" private
(cd "$files_dir" && find . -type f -print0 | sort -z | xargs -0 -r sha256sum) > "$work/bundle/files.sha256"

ERROR_CODE=config_copy
[ -f "$APP_DIR/docker-compose.yml" ] && cp "$APP_DIR/docker-compose.yml" "$work/bundle/config/"
[ -f "$APP_DIR/.env" ] && cp "$APP_DIR/.env" "$work/bundle/config/dot-env"

ERROR_CODE=encrypt
log "bundling and encrypting"
tar -cf "$work/bundle.tar" -C "$work" bundle
openssl enc -aes-256-cbc -pbkdf2 -iter 600000 -salt -in "$work/bundle.tar" -out "$work/$name" -pass "file:$KEY_FILE"
(cd "$work" && sha256sum "$name" > "$name.sha256")
bytes="$(wc -c < "$work/$name" | tr -d ' ')"

ERROR_CODE=local_store
cp "$work/$name" "$work/$name.sha256" "$BACKUP_DIR/daily/"
weekly=0
if [ "$(date -u +%u)" = 7 ]; then
    cp "$work/$name" "$work/$name.sha256" "$BACKUP_DIR/weekly/"
    weekly=1
fi
"$SCRIPTS_DIR/prune.sh" local "$BACKUP_DIR/daily" "$KEEP_DAILY"
"$SCRIPTS_DIR/prune.sh" local "$BACKUP_DIR/weekly" "$KEEP_WEEKLY"

if [ -z "$BACKUP_REMOTE" ]; then
    # A backup that stays on the same server is not a backup (PRD §84).
    ERROR_CODE=no_offsite_remote
    log "BACKUP_REMOTE is not set: the archive exists only on this server"
    false
fi

ERROR_CODE=offsite_copy
log "copying off the server"
rclone copy "$work/$name" "$BACKUP_REMOTE/daily/"
rclone copy "$work/$name.sha256" "$BACKUP_REMOTE/daily/"
if [ "$weekly" = 1 ]; then
    rclone copy "$work/$name" "$BACKUP_REMOTE/weekly/"
    rclone copy "$work/$name.sha256" "$BACKUP_REMOTE/weekly/"
fi
remote_size="$(rclone size --json "$BACKUP_REMOTE/daily/$name" | jq -r '.bytes')"
if [ "$remote_size" != "$bytes" ]; then ERROR_CODE=offsite_size_mismatch; false; fi

ERROR_CODE=offsite_prune
"$SCRIPTS_DIR/prune.sh" remote "$BACKUP_REMOTE/daily" "$KEEP_DAILY"
"$SCRIPTS_DIR/prune.sh" remote "$BACKUP_REMOTE/weekly" "$KEEP_WEEKLY"

first="$(jq -r '.first_success_at // empty' "$STATUS_FILE" 2>/dev/null || true)"
merge_status last_status=ok last_error= last_success_at="$(now_iso)" first_success_at="${first:-$(now_iso)}" last_archive="$name" last_bytes="$bytes"
log "backup OK: $name ($bytes bytes)"
