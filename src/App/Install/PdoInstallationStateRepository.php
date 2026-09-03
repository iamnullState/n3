<?php

declare(strict_types=1);

namespace N3\App\Install;

use N3\Core\Database\TablePrefixedPdo;
use PDO;
use PDOException;
use RuntimeException;

final readonly class PdoInstallationStateRepository implements InstallationStateRepository
{
    public function __construct(private PDO $connection)
    {
    }

    public function status(): string
    {
        if (!$this->tableExists()) {
            return 'migrations_pending';
        }

        try {
            $value = $this->connection->query(
                'SELECT install_status FROM installation_state WHERE id = 1 LIMIT 1',
            )?->fetchColumn();
        } catch (PDOException) {
            return 'migrations_pending';
        }

        return $value === 'complete' ? 'complete' : 'pending_admin';
    }

    public function markComplete(): void
    {
        $statement = $this->connection->prepare(
            "UPDATE installation_state SET install_status = 'complete', completed_at = CURRENT_TIMESTAMP(6) "
            . "WHERE id = 1 AND install_status = 'pending_admin'",
        );
        $statement->execute();

        if ($statement->rowCount() !== 1 && !$this->isComplete()) {
            throw new RuntimeException('Installation completion could not be recorded.');
        }
    }

    public function isComplete(): bool
    {
        return $this->status() === 'complete';
    }

    private function tableExists(): bool
    {
        $table = $this->connection instanceof TablePrefixedPdo
            ? $this->connection->tableNames()->physical('installation_state')
            : 'installation_state';
        $statement = $this->connection->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table',
        );
        $statement->execute(['table' => $table]);

        return (int) $statement->fetchColumn() === 1;
    }
}
