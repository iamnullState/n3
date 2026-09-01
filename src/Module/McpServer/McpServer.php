<?php

declare(strict_types=1);

namespace N3\Module\McpServer;

use JsonException;
use stdClass;
use Throwable;

final class McpServer
{
    public const PROTOCOL_VERSION = '2026-07-28';
    public const SERVER_NAME = 'n3-mcp';
    public const SERVER_VERSION = '0.1.0';

    public function __construct(
        private readonly McpToolRegistry $tools,
        private readonly McpRateLimiter $rateLimiter,
    ) {
    }

    public static function createDefault(): self
    {
        return new self(
            new McpToolRegistry([new SystemStatusTool()]),
            new McpRateLimiter(),
        );
    }

    /** @return array<string, mixed>|null */
    public function handle(string $message, ?int $now = null): ?array
    {
        try {
            $request = json_decode($message, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->error(null, -32700, 'Parse error');
        }

        if (!$request instanceof stdClass) {
            return $this->error(null, -32600, 'Invalid Request');
        }

        $hasId = property_exists($request, 'id');
        $id = $hasId && (is_int($request->id)
            || (is_string($request->id) && strlen($request->id) <= 128))
            ? $request->id
            : null;

        if (($request->jsonrpc ?? null) !== '2.0' || !is_string($request->method ?? null) || $request->method === '') {
            return $hasId ? $this->error($id, -32600, 'Invalid Request') : null;
        }

        if (!$hasId) {
            return null;
        }
        if ($id === null) {
            return $this->error(null, -32600, 'Invalid Request');
        }

        $params = $request->params ?? null;
        if (!$params instanceof stdClass) {
            return $this->error($id, -32602, 'Invalid params');
        }

        $metadata = $params->_meta ?? null;
        if (!$metadata instanceof stdClass
            || !is_string($metadata->{'io.modelcontextprotocol/protocolVersion'} ?? null)
            || !(($metadata->{'io.modelcontextprotocol/clientCapabilities'} ?? null) instanceof stdClass)) {
            return $this->error($id, -32602, 'Invalid params');
        }

        $requestedVersion = $metadata->{'io.modelcontextprotocol/protocolVersion'};
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $requestedVersion)) {
            return $this->error($id, -32602, 'Invalid params');
        }
        if ($requestedVersion !== self::PROTOCOL_VERSION) {
            return $this->error($id, -32022, 'Unsupported protocol version', [
                'supported' => [self::PROTOCOL_VERSION],
                'requested' => $requestedVersion,
            ]);
        }

        try {
            return match ($request->method) {
                'server/discover' => $this->discover($id, $params),
                'tools/list' => $this->listTools($id, $params),
                'tools/call' => $this->callTool($id, $params, $now ?? time()),
                default => $this->error($id, -32601, 'Method not found'),
            };
        } catch (Throwable) {
            return $this->error($id, -32603, 'Internal error');
        }
    }

    /** @return array<string, mixed> */
    private function discover(string|int $id, stdClass $params): array
    {
        if (array_keys(get_object_vars($params)) !== ['_meta']) {
            return $this->error($id, -32602, 'Invalid params');
        }

        return $this->success($id, [
            'resultType' => 'complete',
            'supportedVersions' => [self::PROTOCOL_VERSION],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'instructions' => 'Local read-only server. No CMS, database, identity, file, host, or network data is exposed.',
        ]);
    }

    /** @return array<string, mixed> */
    private function listTools(string|int $id, stdClass $params): array
    {
        $allowed = ['_meta'];
        if (array_diff(array_keys(get_object_vars($params)), $allowed) !== []) {
            return $this->error($id, -32602, 'Invalid params');
        }

        return $this->success($id, [
            'resultType' => 'complete',
            'tools' => $this->tools->definitions(),
        ]);
    }

    /** @return array<string, mixed> */
    private function callTool(string|int $id, stdClass $params, int $now): array
    {
        $allowed = ['name', 'arguments', '_meta'];
        $arguments = $params->arguments ?? new stdClass();
        if (array_diff(array_keys(get_object_vars($params)), $allowed) !== []
            || !is_string($params->name ?? null)
            || !($arguments instanceof stdClass)) {
            return $this->error($id, -32602, 'Invalid params');
        }

        $tool = $this->tools->get($params->name);
        if ($tool === null) {
            return $this->error($id, -32602, 'Unknown tool');
        }
        if (!$this->rateLimiter->attempt($now)) {
            return $this->error($id, -31000, 'Tool call rate limit exceeded');
        }

        try {
            return $this->success($id, $tool->invoke($arguments));
        } catch (\InvalidArgumentException) {
            return $this->error($id, -32602, 'Invalid tool arguments');
        }
    }

    /** @param array<string, mixed> $result @return array<string, mixed> */
    private function success(string|int $id, array $result): array
    {
        $result['_meta'] = [
            'io.modelcontextprotocol/serverInfo' => [
                'name' => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ],
        ];

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @param array<string, mixed>|null $data @return array<string, mixed> */
    private function error(string|int|null $id, int $code, string $message, ?array $data = null): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }
        $response = ['jsonrpc' => '2.0'];
        if ($id !== null) {
            $response['id'] = $id;
        }
        $response['error'] = $error;

        return $response;
    }
}
