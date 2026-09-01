<?php

declare(strict_types=1);

namespace N3\Module\McpServer;

use JsonException;

final readonly class McpStdioServer
{
    public const MAXIMUM_MESSAGE_BYTES = 1_048_576;

    public function __construct(private McpServer $server)
    {
    }

    /** @param resource $input @param resource $output @param resource $error */
    public function run($input, $output, $error): int
    {
        while (($line = fgets($input, self::MAXIMUM_MESSAGE_BYTES + 2)) !== false) {
            $hasNewline = str_ends_with($line, "\n");
            $message = rtrim($line, "\r\n");

            if (strlen($message) > self::MAXIMUM_MESSAGE_BYTES || (!$hasNewline && !feof($input))) {
                if (!$hasNewline) {
                    while (($remainder = fgets($input, 8192)) !== false && !str_ends_with($remainder, "\n")) {
                    }
                }
                $this->write($output, [
                    'jsonrpc' => '2.0',
                    'error' => ['code' => -32600, 'message' => 'Message exceeds the input limit'],
                ]);
                continue;
            }

            $response = $this->server->handle($message);
            if ($response !== null) {
                $this->write($output, $response);
            }
        }

        if (!feof($input)) {
            fwrite($error, "MCP input stream failed.\n");
            return 1;
        }

        return 0;
    }

    /** @param resource $output @param array<string, mixed> $message */
    private function write($output, array $message): void
    {
        try {
            fwrite($output, json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
        } catch (JsonException) {
            fwrite($output, '{"jsonrpc":"2.0","error":{"code":-32603,"message":"Internal error"}}' . "\n");
        }
    }
}
