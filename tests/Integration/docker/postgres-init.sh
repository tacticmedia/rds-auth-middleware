#!/bin/sh
# Enables SSL with a self-signed certificate. ALTER SYSTEM instead of command
# arguments because the entrypoint starts its first-boot temporary server with
# the same arguments, before this script has created the certificate.
set -eu

openssl req -new -x509 -days 3650 -nodes -subj "/CN=postgres-test" \
    -keyout "$PGDATA/server.key" -out "$PGDATA/server.crt"
chmod 600 "$PGDATA/server.key"

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
    -c "ALTER SYSTEM SET ssl = on" \
    -c "ALTER SYSTEM SET ssl_cert_file = 'server.crt'" \
    -c "ALTER SYSTEM SET ssl_key_file = 'server.key'"
