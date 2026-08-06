<?php

declare(strict_types=1);

namespace Daybreak\Service;

use Daybreak\Config;
use RuntimeException;

/**
 * Encrypt/decrypt short application secrets (e.g., user API keys).
 */
final class CredentialVault
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LEN = 12;
    private const TAG_LEN = 16;

    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            throw new RuntimeException('credential is empty');
        }

        $iv = random_bytes(self::IV_LEN);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LEN
        );

        if (!is_string($ciphertext) || $tag === '') {
            throw new RuntimeException('credential encryption failed');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if (!is_string($raw) || strlen($raw) <= (self::IV_LEN + self::TAG_LEN)) {
            throw new RuntimeException('credential payload invalid');
        }

        $iv = substr($raw, 0, self::IV_LEN);
        $tag = substr($raw, self::IV_LEN, self::TAG_LEN);
        $ciphertext = substr($raw, self::IV_LEN + self::TAG_LEN);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            ''
        );

        if (!is_string($plaintext) || $plaintext === '') {
            throw new RuntimeException('credential decryption failed');
        }

        return $plaintext;
    }

    private static function key(): string
    {
        // Normalize arbitrary APP_KEY input to 32 bytes for AES-256.
        return hash('sha256', Config::requireAppKey(), true);
    }
}
