<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\Module\McpServer\McpRateLimiter;
use N3\Module\McpServer\McpServer;
use N3\Module\McpServer\McpToolRegistry;
use N3\Module\McpServer\SystemStatusTool;
use PHPUnit\Framework\TestCase;

final class McpServerTest extends TestCase
{
    public function testDiscoveryDeclaresOnlyTheCurrentToolCapability(): void
    {
        $response = $this->server()->handle($this->request('server/discover', [], 'discover'));

        self::assertIsArray($response);
        self::assertSame('discover', $response['id']);
        self::assertSame('complete', $response['result']['resultType']);
        self::assertSame([McpServer::PROTOCOL_VERSION], $response['result']['supportedVersions']);
        self::assertSame(['tools' => ['listChanged' => false]], $response['result']['capabilities']);
        self::assertStringContainsString('No CMS', $response['result']['instructions']);
        self::assertSame(McpServer::SERVER_NAME, $response['result']['_meta']['io.modelcontextprotocol/serverInfo']['name']);
    }

    public function testToolListIsDeterministicStrictAndDataFree(): void
    {
        $server = $this->server();
        $first = $server->handle($this->request('tools/list', [], 1));
        $second = $server->handle($this->request('tools/list', [], 2));

        self::assertIsArray($first);
        self::assertIsArray($second);
        self::assertSame($first['result']['tools'], $second['result']['tools']);
        self::assertCount(1, $first['result']['tools']);
        $tool = $first['result']['tools'][0];
        self::assertSame(SystemStatusTool::NAME, $tool['name']);
        self::assertSame(['type' => 'object', 'additionalProperties' => false], $tool['inputSchema']);
        self::assertTrue($tool['annotations']['readOnlyHint']);
        self::assertFalse($tool['annotations']['openWorldHint']);

        $cursor = $server->handle($this->request('tools/list', ['cursor' => 'hostile'], 3));
        self::assertSame(-32602, $cursor['error']['code']);
    }

    public function testStatusToolReturnsOnlyAConstantStructuredStatus(): void
    {
        $response = $this->server()->handle($this->request('tools/call', [
            'name' => SystemStatusTool::NAME,
            'arguments' => (object) [],
        ], 7), 100);

        self::assertIsArray($response);
        self::assertSame(['status' => 'available'], $response['result']['structuredContent']);
        self::assertSame('{"status":"available"}', $response['result']['content'][0]['text']);
        self::assertFalse($response['result']['isError']);
        $encoded = json_encode($response, JSON_THROW_ON_ERROR);
        foreach (['database', 'hostname', 'environment', 'identity', 'module_list', 'secret'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, strtolower($encoded));
        }

        $withoutArguments = $this->server()->handle($this->request('tools/call', [
            'name' => SystemStatusTool::NAME,
        ], 8), 100);
        self::assertSame(['status' => 'available'], $withoutArguments['result']['structuredContent']);
    }

    public function testToolCallsRejectUnknownToolsArgumentsAndUnboundedFieldsWithoutReflection(): void
    {
        $server = $this->server();
        $unknown = $server->handle($this->request('tools/call', [
            'name' => 'steal_secrets',
            'arguments' => (object) [],
        ], 1));
        self::assertSame(-32602, $unknown['error']['code']);
        self::assertStringNotContainsString('steal_secrets', json_encode($unknown, JSON_THROW_ON_ERROR));

        $arguments = $server->handle($this->request('tools/call', [
            'name' => SystemStatusTool::NAME,
            'arguments' => (object) ['token' => '<script>secret</script>'],
        ], 2));
        self::assertSame(-32602, $arguments['error']['code']);
        self::assertStringNotContainsString('secret', json_encode($arguments, JSON_THROW_ON_ERROR));

        $extra = $server->handle($this->request('tools/call', [
            'name' => SystemStatusTool::NAME,
            'arguments' => (object) [],
            'requestState' => 'not-supported',
        ], 3));
        self::assertSame(-32602, $extra['error']['code']);
    }

    public function testMetadataVersionsMethodsAndMessageShapesFailWithControlledErrors(): void
    {
        $server = $this->server();
        $unsupported = $server->handle($this->request('tools/list', [], 1, '1900-01-01'));
        self::assertSame(-32022, $unsupported['error']['code']);
        self::assertSame([McpServer::PROTOCOL_VERSION], $unsupported['error']['data']['supported']);

        $missing = $server->handle('{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}');
        self::assertSame(-32602, $missing['error']['code']);

        $discoverExtra = $server->handle($this->request('server/discover', ['cursor' => 'not-allowed'], 21));
        self::assertSame(-32602, $discoverExtra['error']['code']);

        $badCapabilities = $server->handle(sprintf(
            '{"jsonrpc":"2.0","id":22,"method":"tools/list","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"%s","io.modelcontextprotocol/clientCapabilities":[]}}}',
            McpServer::PROTOCOL_VERSION,
        ));
        self::assertSame(-32602, $badCapabilities['error']['code']);

        $hostileVersion = $server->handle($this->request('tools/list', [], 23, str_repeat('secret', 100)));
        self::assertSame(-32602, $hostileVersion['error']['code']);
        self::assertStringNotContainsString('secret', json_encode($hostileVersion, JSON_THROW_ON_ERROR));

        $oversizedId = $server->handle($this->request('tools/list', [], str_repeat('i', 129)));
        self::assertSame(-32600, $oversizedId['error']['code']);
        self::assertArrayNotHasKey('id', $oversizedId);

        $method = $server->handle($this->request('resources/list', [], 3));
        self::assertSame(-32601, $method['error']['code']);

        self::assertSame(-32700, $server->handle('{not-json')['error']['code']);
        self::assertSame(-32600, $server->handle('[]')['error']['code']);
        self::assertSame(-32600, $server->handle('[{"jsonrpc":"2.0"}]')['error']['code']);
        self::assertNull($server->handle('{"jsonrpc":"2.0","method":"notifications/cancelled","params":{}}'));
    }

    public function testToolInvocationUsesABoundedFixedWindow(): void
    {
        $server = new McpServer(
            new McpToolRegistry([new SystemStatusTool()]),
            new McpRateLimiter(2, 60),
        );
        $params = ['name' => SystemStatusTool::NAME, 'arguments' => (object) []];

        self::assertArrayHasKey('result', $server->handle($this->request('tools/call', $params, 1), 100));
        self::assertArrayHasKey('result', $server->handle($this->request('tools/call', $params, 2), 159));
        self::assertSame(-31000, $server->handle($this->request('tools/call', $params, 3), 159)['error']['code']);
        self::assertArrayHasKey('result', $server->handle($this->request('tools/call', $params, 4), 160));
    }

    private function server(): McpServer
    {
        return McpServer::createDefault();
    }

    /** @param array<string, mixed> $params */
    private function request(string $method, array $params, string|int $id, string $version = McpServer::PROTOCOL_VERSION): string
    {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => $version,
            'io.modelcontextprotocol/clientCapabilities' => (object) [],
        ];

        return json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ], JSON_THROW_ON_ERROR);
    }
}
