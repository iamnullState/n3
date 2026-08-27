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
    }
}
