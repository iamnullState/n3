<?php

declare(strict_types=1);

namespace N3\Module\McpServer;

use InvalidArgumentException;

final class McpToolRegistry
{
    /** @var array<string, McpTool> */
    private array $tools = [];

    /** @param list<McpTool> $tools */
    public function __construct(array $tools)
    {
        foreach ($tools as $tool) {
            $name = $tool->name();
            if (!preg_match('/^[A-Za-z0-9_.-]{1,128}$/D', $name)) {
                throw new InvalidArgumentException('MCP tool names must use the bounded portable name format.');
            }
            if (isset($this->tools[$name])) {
                throw new InvalidArgumentException(sprintf('Duplicate MCP tool "%s".', $name));
            }
            $this->tools[$name] = $tool;
        }

        ksort($this->tools, SORT_STRING);
    }

    /** @return list<array<string, mixed>> */
    public function definitions(): array
    {
        return array_values(array_map(
            static fn (McpTool $tool): array => $tool->definition(),
            $this->tools,
        ));
    }

    public function get(string $name): ?McpTool
    {
        return $this->tools[$name] ?? null;
    }
}
