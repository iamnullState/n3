<?php

declare(strict_types=1);

use N3\Core\Database\Migration;

return new class implements Migration {
    public function version(): string
    {
        return '202608300005_create_module_lifecycle_and_jobs';
    }

    public function up(PDO $connection): void
    {
        $connection->exec(
            'CREATE TABLE modules ('
            . 'module_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY, '
            . 'installed_version VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'manifest_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . "state VARCHAR(16) NOT NULL DEFAULT 'enabled', "
            . 'created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), '
            . 'synchronized_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6), '
            . "CONSTRAINT modules_state_check CHECK (state IN ('enabled', 'disabled'))"
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $connection->exec(
            'CREATE TABLE module_events ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            . 'module_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'event_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'from_version VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . 'to_version VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . 'from_state VARCHAR(16) NULL, to_state VARCHAR(16) NULL, '
            . 'created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), '
            . 'CONSTRAINT module_events_module_fk FOREIGN KEY (module_id) REFERENCES modules(module_id) ON DELETE RESTRICT, '
            . "CONSTRAINT module_events_type_check CHECK (event_type IN ('installed', 'updated', 'enabled', 'disabled')), "
            . 'INDEX module_events_module_index (module_id, created_at), '
            . 'INDEX module_events_created_index (created_at)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $connection->exec(
            'CREATE TABLE jobs ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            . 'module_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'job_type VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'payload_json MEDIUMTEXT NOT NULL, '
            . 'idempotency_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . "status VARCHAR(16) NOT NULL DEFAULT 'pending', "
            . 'attempts TINYINT UNSIGNED NOT NULL DEFAULT 0, max_attempts TINYINT UNSIGNED NOT NULL, '
            . 'available_at TIMESTAMP(6) NOT NULL, '
            . 'lease_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . 'lease_owner VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . 'lease_expires_at TIMESTAMP(6) NULL, last_error_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . 'created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), '
            . 'updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6), '
            . 'completed_at TIMESTAMP(6) NULL, '
            . "CONSTRAINT jobs_status_check CHECK (status IN ('pending', 'running', 'succeeded', 'dead')), "
            . 'CONSTRAINT jobs_attempts_check CHECK (max_attempts BETWEEN 1 AND 25 AND attempts <= max_attempts), '
            . 'CONSTRAINT jobs_payload_check CHECK (JSON_VALID(payload_json)), '
            . 'CONSTRAINT jobs_idempotency_unique UNIQUE (module_id, idempotency_key), '
            . 'INDEX jobs_claim_index (status, available_at, id), '
            . 'INDEX jobs_lease_index (status, lease_expires_at), '
            . 'INDEX jobs_owner_index (module_id, job_type, status)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $connection->exec(
            'CREATE TABLE job_events ('
            . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, job_id BIGINT UNSIGNED NOT NULL, '
            . 'event_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'attempt TINYINT UNSIGNED NOT NULL, error_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . 'created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), '
            . 'CONSTRAINT job_events_job_fk FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE RESTRICT, '
            . "CONSTRAINT job_events_type_check CHECK (event_type IN ('enqueued', 'claimed', 'succeeded', 'retried', 'dead', 'recovered')), "
            . 'INDEX job_events_job_index (job_id, created_at), INDEX job_events_created_index (created_at)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    public function down(PDO $connection): void
    {
        $connection->exec('DROP TABLE job_events');
        $connection->exec('DROP TABLE jobs');
        $connection->exec('DROP TABLE module_events');
        $connection->exec('DROP TABLE modules');
    }
};
