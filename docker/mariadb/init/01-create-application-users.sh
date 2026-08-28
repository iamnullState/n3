#!/usr/bin/env bash

# This file is sourced by the official MariaDB entrypoint during first-time
# initialization. Values are restricted before interpolation into SQL.

n3_validate_identifier() {
    local value="$1"
    local label="$2"

    if [[ -z "$value" || "$value" =~ [^a-zA-Z0-9_] ]]; then
        echo "Invalid ${label}: use only letters, numbers, and underscores." >&2
        return 1
    fi
}

n3_validate_secret() {
    local value="$1"
    local label="$2"

    if [[ ${#value} -lt 24 || "$value" == replace-with-* || "$value" =~ [^a-zA-Z0-9_-] ]]; then
        echo "Invalid ${label}: use at least 24 letters, numbers, underscores, or hyphens." >&2
        return 1
    fi
}

n3_validate_identifier "${MARIADB_DATABASE:-}" "database name" || return 1
n3_validate_identifier "${N3_DB_RUNTIME_USER:-}" "runtime user" || return 1
n3_validate_identifier "${N3_DB_MIGRATION_USER:-}" "migration user" || return 1
n3_validate_secret "${MARIADB_ROOT_PASSWORD:-}" "root password" || return 1
n3_validate_secret "${N3_DB_RUNTIME_PASSWORD:-}" "runtime password" || return 1
n3_validate_secret "${N3_DB_MIGRATION_PASSWORD:-}" "migration password" || return 1

if [[ "$MARIADB_ROOT_PASSWORD" == "$N3_DB_RUNTIME_PASSWORD" \
    || "$MARIADB_ROOT_PASSWORD" == "$N3_DB_MIGRATION_PASSWORD" \
    || "$N3_DB_RUNTIME_PASSWORD" == "$N3_DB_MIGRATION_PASSWORD" ]]; then
    echo "Database passwords must be distinct." >&2
    return 1
fi

mariadb --protocol=socket -uroot -p"${MARIADB_ROOT_PASSWORD}" <<-EOSQL
CREATE USER IF NOT EXISTS '${N3_DB_RUNTIME_USER}'@'%' IDENTIFIED BY '${N3_DB_RUNTIME_PASSWORD}';
CREATE USER IF NOT EXISTS '${N3_DB_MIGRATION_USER}'@'%' IDENTIFIED BY '${N3_DB_MIGRATION_PASSWORD}';

GRANT SELECT, INSERT, UPDATE, DELETE
    ON \`${MARIADB_DATABASE}\`.*
    TO '${N3_DB_RUNTIME_USER}'@'%';

GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES
    ON \`${MARIADB_DATABASE}\`.*
    TO '${N3_DB_MIGRATION_USER}'@'%';

FLUSH PRIVILEGES;
EOSQL

unset -f n3_validate_identifier n3_validate_secret
