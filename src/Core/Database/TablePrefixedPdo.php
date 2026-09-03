<?php

declare(strict_types=1);

namespace N3\Core\Database;

use PDO;
use PDOException;
use PDOStatement;

final class TablePrefixedPdo extends PDO
{
    public function __construct(
        string $dsn,
        ?string $username,
        #[\SensitiveParameter] ?string $password,
        ?array $options,
        private readonly TableNames $tableNames,
    ) {
        parent::__construct($dsn, $username, $password, $options);
    }

    public function tableNames(): TableNames
    {
        return $this->tableNames;
    }

    public function exec(string $statement): int|false
    {
        return parent::exec($this->tableNames->rewrite($statement));
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare($this->tableNames->rewrite($query), $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $query = $this->tableNames->rewrite($query);

        return $fetchMode === null
            ? parent::query($query)
            : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function assertInstallationIdentity(): void
    {
        $desiredState = $this->tableNames->physical('installation_state');
        $desiredHistory = $this->tableNames->physical('schema_migrations');
        $statement = parent::prepare(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() '
            . 'AND (RIGHT(table_name, CHAR_LENGTH(:state_length)) = :state_value '
            . 'OR RIGHT(table_name, CHAR_LENGTH(:history_length)) = :history_value)',
        );
        $statement->execute([
            'state_length' => 'installation_state',
            'state_value' => 'installation_state',
            'history_length' => 'schema_migrations',
            'history_value' => 'schema_migrations',
        ]);
        $tables = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));

        if (in_array($desiredState, $tables, true)) {
            try {
                $prefix = parent::query(sprintf(
                    'SELECT table_prefix FROM `%s` WHERE id = 1 LIMIT 1',
                    $desiredState,
                ))?->fetchColumn();
            } catch (PDOException $exception) {
                throw new DatabaseException('The installation-state table is invalid.', previous: $exception);
            }
            if (!is_string($prefix) || !hash_equals($prefix, $this->tableNames->prefix())) {
                throw new DatabaseException('DB_TABLE_PREFIX does not match the immutable installation state.');
            }

            return;
        }

        foreach ($tables as $table) {
            if ($table === $desiredHistory) {
                continue;
            }
            if (str_ends_with($table, 'installation_state') && $this->isInstallationStateTable($table)) {
                throw new DatabaseException('DB_TABLE_PREFIX does not match the immutable installation state.');
            }
            if (str_ends_with($table, 'schema_migrations') && $this->isN3MigrationRepository($table)) {
                throw new DatabaseException('DB_TABLE_PREFIX cannot be changed after N3 schema creation.');
            }
        }
    }

    private function isInstallationStateTable(string $table): bool
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $table) !== 1) {
            return false;
        }

        try {
            $value = parent::query(sprintf(
                'SELECT table_prefix FROM `%s` WHERE id = 1 LIMIT 1',
                $table,
            ))?->fetchColumn();
            if (!is_string($value)) {
                return false;
            }
            $names = new TableNames($value);

            return hash_equals($names->physical('installation_state'), $table);
        } catch (PDOException | DatabaseException) {
            return false;
        }
    }

    private function isN3MigrationRepository(string $table): bool
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $table) !== 1) {
            return false;
        }

        try {
            $statement = parent::prepare(sprintf(
                'SELECT 1 FROM `%s` WHERE version = :version LIMIT 1',
                $table,
            ));
            $statement->execute(['version' => '202608270001_create_users']);

            return $statement->fetchColumn() !== false;
        } catch (PDOException) {
            return false;
        }
    }
}
