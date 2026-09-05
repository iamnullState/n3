<?php

declare(strict_types=1);

namespace N3\Core\Backup;

use Generator;
use N3\Core\Database\DatabaseConfig;

final readonly class MariaDbCliBackupDriver implements DatabaseBackupDriver
{
    public function __construct(
        private string $dumpBinary = 'mariadb-dump',
        private string $clientBinary = 'mariadb',
    ) {
    }

    public function export(DatabaseConfig $database, array $tables): iterable
    {
        if ($tables === []) {
            throw new BackupException('No managed N3 tables exist to back up.');
        }
        $this->assertTables($tables);

        return $this->exportProcess($database, $tables);
    }

    public function import(DatabaseConfig $database, iterable $chunks): void
    {
        [$defaults, $errors] = $this->temporaryProcessFiles($database);
        $command = [
            $this->clientBinary,
            '--defaults-extra-file=' . $defaults,
            '--protocol=tcp',
            '--host=' . $database->host,
            '--port=' . (string) $database->port,
            '--default-character-set=utf8mb4',
            $database->database,
        ];
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', $errors, 'a'],
        ], $pipes);
        if (!is_resource($process) || !isset($pipes[0]) || !is_resource($pipes[0])) {
            $this->removeProcessFiles($defaults, $errors);
            throw new BackupException('Unable to start the MariaDB restore client.');
        }

        $closed = false;
        try {
            foreach ($chunks as $chunk) {
                if (!is_string($chunk)) {
                    throw new BackupException('Database restore streams must contain string chunks.');
                }
                $this->writeAll($pipes[0], $chunk);
            }
            fclose($pipes[0]);
            unset($pipes[0]);
            $exitCode = proc_close($process);
            $closed = true;
            if ($exitCode !== 0) {
                throw new BackupException('MariaDB restore command failed.');
            }
        } catch (\Throwable $exception) {
            if (isset($pipes[0]) && is_resource($pipes[0])) {
                fclose($pipes[0]);
            }
            if (!$closed) {
                @proc_terminate($process);
                @proc_close($process);
            }
            if ($exception instanceof BackupException) {
                throw $exception;
            }
            throw new BackupException('MariaDB restore command failed.', previous: $exception);
        } finally {
            $this->removeProcessFiles($defaults, $errors);
        }
    }

    /** @param list<string> $tables @return Generator<int, string> */
    private function exportProcess(DatabaseConfig $database, array $tables): Generator
    {
        [$defaults, $errors] = $this->temporaryProcessFiles($database);
        $command = [
            $this->dumpBinary,
            '--defaults-extra-file=' . $defaults,
            '--protocol=tcp',
            '--host=' . $database->host,
            '--port=' . (string) $database->port,
            '--default-character-set=utf8mb4',
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--skip-add-locks',
            '--skip-triggers',
            '--skip-comments',
            '--skip-dump-date',
            '--skip-add-drop-table',
            '--hex-blob',
            '--complete-insert',
            $database->database,
            ...$tables,
        ];
        $pipes = [];
        $process = proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', $errors, 'a'],
        ], $pipes);
        if (!is_resource($process) || !isset($pipes[1]) || !is_resource($pipes[1])) {
            $this->removeProcessFiles($defaults, $errors);
            throw new BackupException('Unable to start the MariaDB backup client.');
        }

        try {
            while (!feof($pipes[1])) {
                $chunk = fread($pipes[1], 65536);
                if ($chunk === false) {
                    throw new BackupException('Unable to read the MariaDB backup stream.');
                }
                if ($chunk !== '') {
                    yield $chunk;
                }
            }
            fclose($pipes[1]);
            unset($pipes[1]);
            if (proc_close($process) !== 0) {
                throw new BackupException('MariaDB backup command failed.');
            }
        } finally {
            if (isset($pipes[1]) && is_resource($pipes[1])) {
                fclose($pipes[1]);
                @proc_terminate($process);
                @proc_close($process);
            }
            $this->removeProcessFiles($defaults, $errors);
        }
    }

    /** @param list<string> $tables */
    private function assertTables(array $tables): void
    {
        foreach ($tables as $table) {
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $table) !== 1) {
                throw new BackupException('Database backup table inventory is invalid.');
            }
        }
    }

    /** @return array{string, string} */
    private function temporaryProcessFiles(DatabaseConfig $database): array
    {
        $defaults = tempnam(sys_get_temp_dir(), 'n3-db-auth-');
        $errors = tempnam(sys_get_temp_dir(), 'n3-db-error-');
        if (!is_string($defaults) || !is_string($errors)) {
            throw new BackupException('Unable to create private MariaDB process files.');
        }
        @chmod($defaults, 0600);
        @chmod($errors, 0600);
        $contents = "[client]\nuser=\"" . $this->optionValue($database->username)
            . "\"\npassword=\"" . $this->optionValue($database->password()) . "\"\n";
        if (file_put_contents($defaults, $contents, LOCK_EX) === false) {
            $this->removeProcessFiles($defaults, $errors);
            throw new BackupException('Unable to create private MariaDB authentication.');
        }

        return [$defaults, $errors];
    }

    private function optionValue(string $value): string
    {
        return strtr($value, [
            '\\' => '\\\\',
            '"' => '\\"',
            "\n" => '\\n',
            "\r" => '\\r',
            "\t" => '\\t',
        ]);
    }

    /** @param resource $stream */
    private function writeAll($stream, string $chunk): void
    {
        while ($chunk !== '') {
            $written = fwrite($stream, $chunk);
            if ($written === false || $written === 0) {
                throw new BackupException('Unable to write the MariaDB restore stream.');
            }
            $chunk = substr($chunk, $written);
        }
    }

    private function removeProcessFiles(string $defaults, string $errors): void
    {
        @unlink($defaults);
        @unlink($errors);
    }
}
