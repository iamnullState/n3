<?php

declare(strict_types=1);

namespace N3\Core\Logging;

use JsonException;
use Throwable;

final readonly class FileLogger
{
    public function __construct(private string $file)
    {
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function error(string $event, array $context = []): void
    {
        $record = [
            'timestamp' => gmdate(DATE_ATOM),
            'level' => 'error',
            'event' => $event,
            'context' => $context,
        ];

        try {
            $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        } catch (JsonException) {
            $line = '{"level":"error","event":"log_encoding_failed"}' . PHP_EOL;
        }

        $directory = dirname($this->file);

        if (!is_dir($directory) || !is_writable($directory)) {
            error_log(rtrim($line));

            return;
        }

        try {
            if (file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX) === false) {
                error_log(rtrim($line));
            }
        } catch (Throwable) {
            error_log(rtrim($line));
        }
    }
}
