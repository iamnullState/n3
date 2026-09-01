<?php

declare(strict_types=1);

namespace N3\Module\McpServer;

use InvalidArgumentException;
use stdClass;

final class SystemStatusTool implements McpTool
{
    public const NAME = 'n3_system_status';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): array
    {
        return [
            'name' => self::NAME,
            'title' => 'N3 system status',
            'description' => 'Returns only whether the local N3 MCP process is available. It does not inspect application or host state.',
            'inputSchema' => [
                'type' => 'object',
                'additionalProperties' => false,
            ],
            'outputSchema' => [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string', 'const' => 'available'],
                ],
                'required' => ['status'],
                'additionalProperties' => false,
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ],
        ];
    }

    public function invoke(stdClass $arguments): array
    {
        if (get_object_vars($arguments) !== []) {
            throw new InvalidArgumentException('The system status tool accepts no arguments.');
        }

        $status = ['status' => 'available'];

        return [
            'resultType' => 'complete',
            'content' => [[
                'type' => 'text',
                'text' => '{"status":"available"}',
            ]],
            'structuredContent' => $status,
            'isError' => false,
        ];
    }
}
