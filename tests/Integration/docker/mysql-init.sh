#!/bin/sh
# Exports the auto-generated CA certificate for the host-side tests: pdo_mysql
# enables TLS only when a CA file is configured. The entrypoint sources this
# file into its own shell, so it must not call set -eu or exit.
install -m 644 /var/lib/mysql/ca.pem /mysql-export/ca.pem
