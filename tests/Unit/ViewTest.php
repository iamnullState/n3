<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\Core\View\View;
use PHPUnit\Framework\TestCase;

final class ViewTest extends TestCase
{
    public function testTemplateDataCanBeEscapedAtTheRenderContext(): void
    {
        $view = new View(dirname(__DIR__) . '/Fixtures/views');

        $html = $view->render('escaped', ['value' => '<script>alert("x")</script>'], null);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }
}
