#!/usr/bin/env bash
set -euo pipefail

exec docker compose \
  -f docker-compose.yml \
  -f docker-compose.prod.yml \
  -f docker-compose.vps.yml \
  "$@"
