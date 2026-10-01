FROM alpine:3.20

# pg_dump 16 matches the postgres:16 service; rclone moves the encrypted archive off the server.
RUN apk add --no-cache bash postgresql16-client rclone openssl jq tar coreutils tzdata findutils

COPY scripts/backup /scripts
COPY docker/backup/entrypoint.sh /entrypoint.sh
RUN chmod +x /scripts/*.sh /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
