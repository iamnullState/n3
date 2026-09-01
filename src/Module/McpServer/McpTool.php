<?php

declare(strict_types=1);

namespace N3\Module\McpServer;

use stdClass;

interface McpTool
{
    public function name(): string;

    /** @return array<string, mixed> */
    public function definition(): array;

    /** @return array<string, mixed> */
    public function invoke(stdClass $arguments): array;
}
