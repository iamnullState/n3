<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\Module\Media\MediaConfig;
use PHPUnit\Framework\TestCase;

final class MediaConfigTest extends TestCase
{
    public function testDefaultsAreBoundedAndSecurityKeyIsRequired(): void
    {
        $previous = $this->setEnvironment(['SECURITY_HASH_KEY' => str_repeat('k', 32)]);
        try {
            $config = MediaConfig::fromEnvironment();
            self::assertSame(10_485_760, $config->maximumUploadBytes);
            self::assertSame(25_000_000, $config->maximumPixels);
            self::assertSame(12_000, $config->maximumDimension);
            self::assertSame(480, $config->previewMaximumDimension);
            self::assertSame(20, $config->uploadAttemptsPerHour);
            self::assertSame(85, $config->webpQuality);
        } finally {
            $this->restoreEnvironment($previous);
        }
    }

    public function testInvalidNumericConfigurationFailsClosed(): void
    {
        $previous = $this->setEnvironment([
            'SECURITY_HASH_KEY' => str_repeat('k', 32),
            'MEDIA_MAX_PIXELS' => '25 million',
        ]);
        try {
            $this->expectException(\RuntimeException::class);
            MediaConfig::fromEnvironment();
        } finally {
            $this->restoreEnvironment($previous);
        }
    }

    /** @param array<string, string> $values @return array<string, array{process: string|false, env: mixed, present: bool}> */
    private function setEnvironment(array $values): array
    {
        $previous = [];
        foreach ($values as $key => $value) {
            $previous[$key] = [
                'process' => getenv($key),
                'env' => $_ENV[$key] ?? null,
                'present' => array_key_exists($key, $_ENV),
            ];
            unset($_ENV[$key]);
            putenv($key . '=' . $value);
        }
        return $previous;
    }

    /** @param array<string, array{process: string|false, env: mixed, present: bool}> $previous */
    private function restoreEnvironment(array $previous): void
    {
        foreach ($previous as $key => $state) {
            $state['process'] === false ? putenv($key) : putenv($key . '=' . $state['process']);
            if ($state['present']) {
                $_ENV[$key] = $state['env'];
            } else {
                unset($_ENV[$key]);
            }
        }
    }
}
