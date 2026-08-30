<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\App\Content\PageValidator;
use PHPUnit\Framework\TestCase;

final class PageValidatorTest extends TestCase
{
    public function testItNormalizesAndAcceptsAPlainTextDraft(): void
    {
        $validator = new PageValidator();

        self::assertSame('hello-world', $validator->normalizeSlug('  HELLO-WORLD '));
        self::assertSame([], $validator->errors('Hello', 'hello-world', 'Summary', '<script>stored as text</script>'));
        self::assertSame([], $validator->errors('Empty draft', 'empty-draft', '', ''));
    }

    public function testItRejectsInvalidFieldsAndBlankPublishedBodies(): void
    {
        $errors = (new PageValidator())->errors('', 'Bad Slug!', str_repeat('x', 501), '', true);

        self::assertArrayHasKey('title', $errors);
        self::assertArrayHasKey('slug', $errors);
        self::assertArrayHasKey('excerpt', $errors);
        self::assertArrayHasKey('body', $errors);
    }
}
