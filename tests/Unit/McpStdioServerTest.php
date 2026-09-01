<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\Module\McpServer\McpServer;
use N3\Module\McpServer\McpStdioServer;
use PHPUnit\Framework\TestCase;

final class McpStdioServerTest extends TestCase
{
    public function testStdioWritesOnlyNewlineDelimitedProtocolResponses(): void
    {
        $request = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'server/discover',
            'params' => ['_meta' => [
                'io.modelcontextprotocol/protocolVersion' => McpServer::PROTOCOL_VERSION,
                'io.modelcontextprotocol/clientCapabilities' => (object) [],
            ]],
        ], JSON_THROW_ON_ERROR);
        $notification = '{"jsonrpc":"2.0","method":"notifications/cancelled","params":{}}';
        [$exit, $output, $error] = $this->runServer($request . "\n" . $notification . "\n");

        self::assertSame(0, $exit);
        self::assertSame('', $error);
        self::assertStringEndsWith("\n", $output);
        $lines = array_values(array_filter(explode("\n", $output), static fn (string $line): bool => $line !== ''));
        self::assertCount(1, $lines);
        $response = json_decode($lines[0], true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(1, $response['id']);
        self::assertSame([McpServer::PROTOCOL_VERSION], $response['result']['supportedVersions']);
    }

    public function testStdioRejectsOversizedInputAndContinuesAtTheNextMessage(): void
    {
        [$exit, $output, $error] = $this->runServer(
            str_repeat('x', McpStdioServer::MAXIMUM_MESSAGE_BYTES + 1) . "\n{not-json\n",
        );

        self::assertSame(0, $exit);
        self::assertSame('', $error);
        $lines = array_values(array_filter(explode("\n", $output), static fn (string $line): bool => $line !== ''));
        self::assertCount(2, $lines);
        self::assertSame(-32600, json_decode($lines[0], true, 8, JSON_THROW_ON_ERROR)['error']['code']);
        self::assertSame(-32700, json_decode($lines[1], true, 8, JSON_THROW_ON_ERROR)['error']['code']);
    }

    /** @return array{int, string, string} */
    private function runServer(string $messages): array
    {
        $input = fopen('php://temp', 'w+b');
        $output = fopen('php://temp', 'w+b');
        $error = fopen('php://temp', 'w+b');
        self::assertIsResource($input);
        self::assertIsResource($output);
        self::assertIsResource($error);
        fwrite($input, $messages);
        rewind($input);

        $exit = (new McpStdioServer(McpServer::createDefault()))->run($input, $output, $error);
        rewind($output);
        rewind($error);
        $stdout = stream_get_contents($output);
        $stderr = stream_get_contents($error);
        fclose($input);
        fclose($output);
        fclose($error);

        return [$exit, (string) $stdout, (string) $stderr];
    }
}
