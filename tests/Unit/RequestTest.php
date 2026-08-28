<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
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
}
