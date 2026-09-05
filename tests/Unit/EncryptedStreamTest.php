<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\Core\Backup\BackupException;
use N3\Core\Backup\EncryptedStream;
use PHPUnit\Framework\TestCase;

final class EncryptedStreamTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/n3-encrypted-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testRoundTripAuthenticatesChunkedPlaintext(): void
    {
        $key = random_bytes(32);
        $plain = str_repeat('large private payload ', 7000);
        $result = (new EncryptedStream())->encrypt(
            [substr($plain, 0, 70001), substr($plain, 70001)],
            $this->file,
            $key,
            'backup-id' . "\0database",
        );

        self::assertSame(strlen($plain), $result['bytes']);
        self::assertSame(hash('sha256', $plain), $result['sha256']);
        self::assertSame($plain, implode('', iterator_to_array(
            (new EncryptedStream())->decrypt($this->file, $key, 'backup-id' . "\0database"),
        )));
        self::assertSame(0600, fileperms($this->file) & 0777);
        self::assertStringNotContainsString('large private payload', (string) file_get_contents($this->file));
    }

    public function testEmptyPayloadRoundTrips(): void
    {
        $key = random_bytes(32);
        $result = (new EncryptedStream())->encrypt([], $this->file, $key, 'empty');

        self::assertSame(0, $result['bytes']);
        self::assertSame([], iterator_to_array((new EncryptedStream())->decrypt($this->file, $key, 'empty')));
    }

    public function testWrongAssociatedDataIsRejected(): void
    {
        $key = random_bytes(32);
        (new EncryptedStream())->encrypt(['private'], $this->file, $key, 'correct');

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('authentication failed');
        iterator_to_array((new EncryptedStream())->decrypt($this->file, $key, 'wrong'));
    }

    public function testTruncatedArtifactIsRejected(): void
    {
        $key = random_bytes(32);
        (new EncryptedStream())->encrypt(['private'], $this->file, $key, 'context');
        $contents = (string) file_get_contents($this->file);
        self::assertNotFalse(file_put_contents($this->file, substr($contents, 0, -3)));

        $this->expectException(BackupException::class);
        iterator_to_array((new EncryptedStream())->decrypt($this->file, $key, 'context'));
    }
}
