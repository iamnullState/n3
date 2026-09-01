<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\Core\Http\Request;
use N3\Core\Http\UploadedFile;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function testItProvidesNormalizedHeadersAndTheExactRawBody(): void
    {
        $request = Request::create(
            'POST',
            '/webhook',
            server: ['HTTP_X_N3_WEBHOOK_ID' => 'delivery-1', 'CONTENT_TYPE' => 'application/json'],
            rawBody: "{\"exact\": true}\n",
        );

        self::assertSame('delivery-1', $request->header('X-N3-Webhook-ID'));
        self::assertSame('application/json', $request->header('Content-Type'));
        self::assertSame("{\"exact\": true}\n", $request->rawBody());
        self::assertSame("{\"exact\": true}\n", $request->withAttribute('checked', true)->rawBody());
    }

    public function testItNormalizesMethodPathQueryAndTrailingSlash(): void
    {
        $request = Request::create('get', '/about/?source=test');

        self::assertSame('GET', $request->method);
        self::assertSame('/about', $request->path);
        self::assertSame('test', $request->query('source'));
    }

    public function testItKeepsBodyServerAndRequestAttributesSeparate(): void
    {
        $request = Request::create(
            'post',
            '/register?email=query@example.test',
            ['email' => 'body@example.test'],
            ['REMOTE_ADDR' => '127.0.0.9'],
        )->withAttribute('request_id', 'abc123');

        self::assertSame('POST', $request->method);
        self::assertSame('body@example.test', $request->input('email'));
        self::assertSame('query@example.test', $request->query('email'));
        self::assertSame('127.0.0.9', $request->clientIp());
        self::assertSame('abc123', $request->attribute('request_id'));
    }

    public function testItDoesNotTrustAnInvalidRemoteAddress(): void
    {
        $request = Request::create('GET', '/', server: [
            'REMOTE_ADDR' => 'not-an-ip',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.5',
        ]);

        self::assertSame('0.0.0.0', $request->clientIp());
    }

    public function testItKeepsUploadedFilesSeparateAndPreservesThemAcrossAttributes(): void
    {
        $file = new UploadedFile('/private/upload', UPLOAD_ERR_OK, 123);
        $request = Request::create('POST', '/admin/media', files: ['image' => $file]);

        self::assertSame($file, $request->uploadedFile('image'));
        self::assertSame($file, $request->withAttribute('request_id', 'abc')->uploadedFile('image'));
        self::assertNull($request->uploadedFile('missing'));
    }

    public function testNestedOrMalformedGlobalUploadShapesAreRejected(): void
    {
        self::assertNull(UploadedFile::fromGlobal([
            'tmp_name' => ['/tmp/a'],
            'error' => [UPLOAD_ERR_OK],
            'size' => [10],
        ]));
    }
}
