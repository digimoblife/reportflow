#!/usr/bin/env bash
# Disaster recovery: restores an archive into a NEW database and a NEW directory. It never overwrites the live database
# or files; you switch over deliberately (see docs/runbooks/backup-restore.md).
#   restore.sh --archive <file.tar.enc> --database <new_db_name> --files <new_dir> --yes
source "$(dirname "$0")/lib.sh"

archive=""; database=""; files=""; yes=0
while [ $# -gt 0 ]; do
    case "$1" in
        --archive) archive="$2"; shift 2 ;;
        --database) database="$2"; shift 2 ;;
        --files) files="$2"; shift 2 ;;
        --yes) yes=1; shift ;;
        *) die "unknown argument" ;;
    esac
done
[ -f "$archive" ] && [[ "$database" =~ ^[a-z][a-z0-9_]{2,40}$ && -n "$files" ]] || die "usage: restore.sh --archive FILE --database NAME --files DIR --yes"
[ "$yes" = 1 ] || die "refusing without --yes"
[ "$database" != "$PGDATABASE" ] || die "will not restore over the live database"
[ ! -e "$files" ] || [ -z "$(ls -A "$files" 2>/dev/null)" ] || die "target directory is not empty"
require_key

work="$(mktemp -d)"
trap 'rm -rf "${work:?}"' EXIT
openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -in "$archive" -out "$work/bundle.tar" -pass "file:$KEY_FILE" || die "cannot decrypt"
tar -xf "$work/bundle.tar" -C "$work"
createdb "$database"
pg_restore --no-owner --dbname="$database" "$work/bundle/db.dump"
mkdir -p "$files"
tar -xzf "$work/bundle/storage.tar.gz" -C "$files"
cp -r "$work/bundle/config" "$files/config"
log "restored into database $database and directory $files (config copies are in $files/config)"
