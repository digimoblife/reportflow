#!/usr/bin/env bash
# Retention (PRD §84): keep the newest N archives, delete the rest (and their .sha256).
#   prune.sh local  <dir>          <keep>
#   prune.sh remote <remote:path>  <keep>      (rclone)
source "$(dirname "$0")/lib.sh"

mode="${1:-}"; target="${2:-}"; keep="${3:-}"
[[ "$mode" =~ ^(local|remote)$ && -n "$target" && "$keep" =~ ^[0-9]+$ ]] || die "usage: prune.sh local|remote <target> <keep>"
[ "$keep" -ge 1 ] || die "keep must be at least 1"

if [ "$mode" = local ]; then
    mapfile -t all < <(sorted_archives "$target")
else
    mapfile -t all < <(rclone lsf --files-only "$target" 2>/dev/null | grep -E '^reportflow-[0-9TZ]+\.tar\.enc$' | sort || true)
fi

total="${#all[@]}"
[ "$total" -gt "$keep" ] || { log "prune $mode $target: $total archive(s), nothing to delete"; exit 0; }

for ((i = 0; i < total - keep; i++)); do
    name="${all[$i]}"
    if [ "$mode" = local ]; then
        rm -f -- "${target:?}/${name:?}" "${target:?}/${name:?}.sha256"
    else
        rclone deletefile "${target:?}/${name:?}" && { rclone deletefile "${target:?}/${name:?}.sha256" 2>/dev/null || true; }
    fi
    log "pruned $name"
done
