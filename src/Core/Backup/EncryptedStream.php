<?php

declare(strict_types=1);

namespace N3\Core\Backup;

use Generator;

final class EncryptedStream
{
    private const MAGIC = "N3ENC01\n";
    private const CHUNK_BYTES = 65536;

    /** @param iterable<string> $chunks @return array{sha256: string, bytes: int} */
    public function encrypt(iterable $chunks, string $destination, string $key, string $associatedData): array
    {
        $handle = @fopen($destination, 'x+b');
        if (!is_resource($handle)) {
            throw new BackupException('Unable to create an encrypted backup artifact.');
        }
        @chmod($destination, 0600);
        $hash = hash_init('sha256');
        $bytes = 0;

        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            $this->writeAll($handle, self::MAGIC . $header);
            foreach ($chunks as $chunk) {
                if (!is_string($chunk)) {
                    throw new BackupException('Backup streams must contain string chunks.');
                }
                while ($chunk !== '') {
                    $plain = substr($chunk, 0, self::CHUNK_BYTES);
                    $chunk = substr($chunk, strlen($plain));
                    hash_update($hash, $plain);
                    $bytes += strlen($plain);
                    $cipher = sodium_crypto_secretstream_xchacha20poly1305_push(
                        $state,
                        $plain,
                        $associatedData,
                        SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
                    );
                    $this->writeRecord($handle, $cipher);
                }
            }
            $final = sodium_crypto_secretstream_xchacha20poly1305_push(
                $state,
                '',
                $associatedData,
                SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL,
            );
            $this->writeRecord($handle, $final);
        } catch (\Throwable $exception) {
            fclose($handle);
            @unlink($destination);
            if ($exception instanceof BackupException) {
                throw $exception;
            }
            throw new BackupException('Unable to encrypt a backup artifact.', previous: $exception);
        }
        fclose($handle);

        return ['sha256' => hash_final($hash), 'bytes' => $bytes];
    }

    /** @return Generator<int, string> */
    public function decrypt(string $source, string $key, string $associatedData): Generator
    {
        if (!is_file($source) || is_link($source)) {
            throw new BackupException('An encrypted backup artifact is missing or unsafe.');
        }
        $handle = @fopen($source, 'rb');
        if (!is_resource($handle)) {
            throw new BackupException('Unable to read an encrypted backup artifact.');
        }

        try {
            $prefix = $this->readExact(
                $handle,
                strlen(self::MAGIC) + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES,
            );
            if (!str_starts_with($prefix, self::MAGIC)) {
                throw new BackupException('Encrypted backup artifact format is invalid.');
            }
            $header = substr($prefix, strlen(self::MAGIC));
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            $final = false;
            while (!feof($handle)) {
                $lengthBytes = fread($handle, 4);
                if ($lengthBytes === false || $lengthBytes === '') {
                    break;
                }
                if (strlen($lengthBytes) !== 4) {
                    throw new BackupException('Encrypted backup artifact is truncated.');
                }
                $length = unpack('Nlength', $lengthBytes)['length'] ?? 0;
                $maximum = self::CHUNK_BYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
                if ($length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $length > $maximum) {
                    throw new BackupException('Encrypted backup artifact record is invalid.');
                }
                $pulled = sodium_crypto_secretstream_xchacha20poly1305_pull(
                    $state,
                    $this->readExact($handle, $length),
                    $associatedData,
                );
                if (!is_array($pulled)) {
                    throw new BackupException('Encrypted backup artifact authentication failed.');
                }
                [$plain, $tag] = $pulled;
                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    $trailing = fread($handle, 1);
                    if ($plain !== '' || $trailing === false || $trailing !== '') {
                        throw new BackupException('Encrypted backup artifact has invalid trailing data.');
                    }
                    $final = true;
                    break;
                }
                if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE) {
                    throw new BackupException('Encrypted backup artifact tag is invalid.');
                }
                yield $plain;
            }
            if (!$final) {
                throw new BackupException('Encrypted backup artifact is incomplete.');
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private function writeRecord($handle, string $cipher): void
    {
        $this->writeAll($handle, pack('N', strlen($cipher)) . $cipher);
    }

    /** @param resource $handle */
    private function writeAll($handle, string $bytes): void
    {
        while ($bytes !== '') {
            $written = fwrite($handle, $bytes);
            if ($written === false || $written === 0) {
                throw new BackupException('Unable to write an encrypted backup artifact.');
            }
            $bytes = substr($bytes, $written);
        }
    }

    /** @param resource $handle */
    private function readExact($handle, int $length): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $chunk = fread($handle, $length - strlen($bytes));
            if ($chunk === false || $chunk === '') {
                throw new BackupException('Encrypted backup artifact is truncated.');
            }
            $bytes .= $chunk;
        }

        return $bytes;
    }
}
