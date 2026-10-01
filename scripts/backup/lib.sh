#!/usr/bin/env bash
# Shared helpers for the backup scripts (PRD §84). Sourced, not executed.
# Never print the encryption key, database password or rclone config.

set -euo pipefail

: "${BACKUP_DIR:=/backups}"
: "${STATUS_FILE:=/status/status.json}"
: "${KEY_FILE:=/run/secrets/backup_key}"
: "${APP_DIR:=/app}"
: "${BACKUP_REMOTE:=}"
: "${KEEP_DAILY:=7}"
: "${KEEP_WEEKLY:=4}"

SCRIPTS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

log() { printf '%s %s\n' "$(date -u +%FT%TZ)" "$*"; }

die() { log "ERROR: $*" >&2; exit 1; }

require_key() {
    [ -s "$KEY_FILE" ] || die "encryption key file missing or empty"
    [ "$(wc -c < "$KEY_FILE")" -ge 32 ] || die "encryption key must be at least 32 bytes"
}

# merge_status key=value ... : merges string fields into the JSON status file the application reads (ops:check).
merge_status() {
    local tmp current="{}"
    mkdir -p "$(dirname "$STATUS_FILE")"
    [ -s "$STATUS_FILE" ] && current="$(cat "$STATUS_FILE")"
    tmp="$(mktemp)"
    local args=() filter="."
    local pair key value
    for pair in "$@"; do
        key="${pair%%=*}"
        value="${pair#*=}"
        args+=(--arg "$key" "$value")
        filter="$filter | .[\"$key\"] = \$$key"
    done
    printf '%s' "$current" | jq "${args[@]}" "$filter" > "$tmp"
    mv "$tmp" "$STATUS_FILE"
}

now_iso() { date -u +%FT%TZ; }

# sorted_archives <dir> : archive file names (oldest first)
sorted_archives() { ls -1 "$1" 2>/dev/null | grep -E '^reportflow-[0-9TZ]+\.tar\.enc$' | sort || true; }
