<?php

declare(strict_types=1);

namespace N3\Core\Module;

use LogicException;
use PDO;

final readonly class PdoModuleLifecycleRepository implements ModuleLifecycleRepository
{
    public function __construct(private PDO $connection)
    {
    }

    public function all(): array
    {
        $statement = $this->connection->query(
            'SELECT module_id, installed_version, manifest_hash, state FROM modules ORDER BY module_id',
        );
        $states = [];

        foreach ($statement->fetchAll() as $row) {
            $state = new ModuleState(
                (string) $row['module_id'],
                (string) $row['installed_version'],
                (string) $row['manifest_hash'],
                (string) $row['state'],
            );
            $states[$state->id] = $state;
        }

        return $states;
    }

    public function apply(ModuleChange $change): void
    {
        match ($change->action) {
            'install' => $this->install($change),
            'update' => $this->update($change),
            'enable', 'disable' => $this->setState($change),
            default => throw new LogicException(sprintf('Unknown module lifecycle action "%s".', $change->action)),
        };

        $statement = $this->connection->prepare(
            'INSERT INTO module_events '
            . '(module_id, event_type, from_version, to_version, from_state, to_state) '
            . 'VALUES (:module_id, :event_type, :from_version, :to_version, :from_state, :to_state)',
        );
        $statement->execute([
            'module_id' => $change->moduleId,
            'event_type' => $change->action === 'install' ? 'installed' : ($change->action . 'd'),
            'from_version' => $change->fromVersion,
            'to_version' => $change->toVersion,
            'from_state' => $change->fromState,
            'to_state' => $change->toState,
        ]);
    }

    private function install(ModuleChange $change): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO modules (module_id, installed_version, manifest_hash, state) '
            . 'VALUES (:module_id, :version, :manifest_hash, :state)',
        );
        $statement->execute([
            'module_id' => $change->moduleId,
            'version' => $change->toVersion,
            'manifest_hash' => $change->manifestHash,
            'state' => $change->toState,
        ]);
    }

    private function update(ModuleChange $change): void
    {
        $statement = $this->connection->prepare(
            'UPDATE modules SET installed_version = :version, manifest_hash = :manifest_hash '
            . 'WHERE module_id = :module_id AND installed_version = :from_version',
        );
        $statement->execute([
            'module_id' => $change->moduleId,
            'version' => $change->toVersion,
            'manifest_hash' => $change->manifestHash,
            'from_version' => $change->fromVersion,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new LogicException('Module state changed during synchronization.');
        }
    }

    private function setState(ModuleChange $change): void
    {
        $statement = $this->connection->prepare(
            'UPDATE modules SET state = :state WHERE module_id = :module_id AND state = :from_state',
        );
        $statement->execute([
            'module_id' => $change->moduleId,
            'state' => $change->toState,
            'from_state' => $change->fromState,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new LogicException('Module state changed during synchronization.');
        }
    }
}
