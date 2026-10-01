#!/usr/bin/env bash
# Restore test (PRD §84, IMPLEMENTATION_PLAN risk "backup never tested"): restores the newest archive into a scratch
# database and a scratch directory and checks it. Does NOT touch the live database or files.
#   restore-test.sh [--archive <file.tar.enc>]      (default: newest in $BACKUP_DIR/daily; falls back to the remote)
# On success records last_restore_verified_at in the status file (read by ops:check).
source "$(dirname "$0")/lib.sh"

archive=""
if [ "${1:-}" = "--archive" ]; then archive="${2:-}"; fi

work="$(mktemp -d)"
scratch="restore_test_$(date -u +%s)"
cleanup() { dropdb --if-exists "$scratch" >/dev/null 2>&1 || true; rm -rf "${work:?}"; }
trap cleanup EXIT
trap 'log "restore test FAILED"; merge_status restore_status=failed restore_attempt_at="$(now_iso)"' ERR

require_key

if [ -z "$archive" ]; then
    newest="$(sorted_archives "$BACKUP_DIR/daily" | tail -n 1)"
    if [ -n "$newest" ]; then
        archive="$BACKUP_DIR/daily/$newest"
    elif [ -n "$BACKUP_REMOTE" ]; then
        newest="$(rclone lsf --files-only "$BACKUP_REMOTE/daily" | grep -E '^reportflow-[0-9TZ]+\.tar\.enc$' | sort | tail -n 1)"
        [ -n "$newest" ] || die "no archive on the remote"
        rclone copy "$BACKUP_REMOTE/daily/$newest" "$work/" && rclone copy "$BACKUP_REMOTE/daily/$newest.sha256" "$work/"
        archive="$work/$newest"
    else
        die "no archive found"
    fi
fi
[ -f "$archive" ] || die "archive not found"
log "restore test of $(basename "$archive")"

if [ -f "$archive.sha256" ]; then
    (cd "$(dirname "$archive")" && sha256sum -c "$(basename "$archive").sha256" >/dev/null) || die "checksum mismatch"
    log "checksum OK"
fi

openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -in "$archive" -out "$work/bundle.tar" -pass "file:$KEY_FILE" || die "cannot decrypt (wrong key or corrupt archive)"
tar -xf "$work/bundle.tar" -C "$work"
bundle="$work/bundle"
[ -s "$bundle/db.dump" ] && [ -s "$bundle/storage.tar.gz" ] && [ -f "$bundle/files.sha256" ] || die "archive is incomplete"

# files: extract and compare with the manifest taken at backup time
mkdir -p "$work/files"
tar -xzf "$bundle/storage.tar.gz" -C "$work/files"
(cd "$work/files/private" && sha256sum -c --quiet "$bundle/files.sha256") || die "restored files differ from the manifest"
files_count="$(wc -l < "$bundle/files.sha256" | tr -d ' ')"

# database: restore into a scratch database and compare the schema with the live one
createdb "$scratch"
pg_restore --no-owner --dbname="$scratch" "$bundle/db.dump"
tables_restored="$(psql -At -d "$scratch" -c "select count(*) from information_schema.tables where table_schema = 'public'")"
tables_live="$(psql -At -d "$PGDATABASE" -c "select count(*) from information_schema.tables where table_schema = 'public'")"
[ "$tables_restored" -gt 0 ] || die "restored database has no tables"
[ "$tables_restored" -le "$tables_live" ] || die "restored schema has more tables than the live database"

migrations_restored="$(psql -At -d "$scratch" -c 'select count(*) from migrations')"
log "restored: $tables_restored tables, $migrations_restored migrations, $files_count files"
for table in users projects tasks activities inbound_messages reports; do
    printf '%s %s rows: %s\n' "$(date -u +%FT%TZ)" "$table" "$(psql -At -d "$scratch" -c "select count(*) from $table")"
done

merge_status restore_status=ok last_restore_verified_at="$(now_iso)" restore_archive="$(basename "$archive")" restore_tables="$tables_restored"
log "restore test OK"
