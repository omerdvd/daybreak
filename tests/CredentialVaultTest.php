<?php

declare(strict_types=1);

namespace Daybreak\Tests;

use Daybreak\Service\CredentialVault;
use RuntimeException;

/**
 * CredentialVault backs the ntfy webhook token encryption (see
 * WebhookServiceTest's ntfy auth-header tests for that integration) —
 * these tests cover the primitive itself: round-tripping, and that it
 * actually rejects tampering rather than silently returning garbage.
 */
final class CredentialVaultTest extends TestCase
{
    public function testEncryptDecryptRoundTrip(): void
    {
        $plaintext = 'tk_test_super_secret_token_123';
        $encrypted = CredentialVault::encrypt($plaintext);

        $this->assertFalse($plaintext === $encrypted);
        $this->assertSame($plaintext, CredentialVault::decrypt($encrypted));
    }

    public function testEncryptIsNotDeterministic(): void
    {
        // Random IV per call — encrypting the same plaintext twice must not
        // produce identical ciphertext (otherwise the IV isn't actually
        // random, which would undermine GCM's security guarantees).
        $plaintext = 'same-value-both-times';
        $first = CredentialVault::encrypt($plaintext);
        $second = CredentialVault::encrypt($plaintext);

        $this->assertFalse($first === $second);
        $this->assertSame($plaintext, CredentialVault::decrypt($first));
        $this->assertSame($plaintext, CredentialVault::decrypt($second));
    }

    public function testEncryptRejectsEmptyString(): void
    {
        $this->expectException(RuntimeException::class, function () {
            CredentialVault::encrypt('');
        });
    }

    public function testDecryptRejectsTamperedCiphertext(): void
    {
        $encrypted = CredentialVault::encrypt('a-real-secret');
        $raw = base64_decode($encrypted, true);

        // Flip a byte in the middle of the payload (inside the ciphertext,
        // past the IV+tag prefix) — GCM's authentication tag must catch
        // this and refuse to return the (now-garbage) plaintext.
        $midpoint = (int) (strlen($raw) / 2);
        $raw[$midpoint] = chr(ord($raw[$midpoint]) ^ 0xFF);
        $tampered = base64_encode($raw);

        $this->expectException(RuntimeException::class, function () use ($tampered) {
            CredentialVault::decrypt($tampered);
        });
    }

    public function testDecryptRejectsTamperedAuthTag(): void
    {
        $encrypted = CredentialVault::encrypt('another-real-secret');
        $raw = base64_decode($encrypted, true);

        // Flip a byte inside the auth tag itself (bytes 12-27, per
        // IV_LEN=12/TAG_LEN=16) — same expectation, different part of the
        // payload than the ciphertext-tampering test above.
        $raw[15] = chr(ord($raw[15]) ^ 0xFF);
        $tampered = base64_encode($raw);

        $this->expectException(RuntimeException::class, function () use ($tampered) {
            CredentialVault::decrypt($tampered);
        });
    }

    public function testDecryptRejectsInvalidBase64(): void
    {
        $this->expectException(RuntimeException::class, function () {
            CredentialVault::decrypt('not valid base64!!! ###');
        });
    }

    public function testDecryptRejectsPayloadTooShortToContainIvAndTag(): void
    {
        // Valid base64, but too short to even contain the IV+tag prefix
        // (12+16=28 bytes) — must be rejected before ever reaching
        // openssl_decrypt(), not just fail there.
        $tooShort = base64_encode('short');

        $this->expectException(RuntimeException::class, function () use ($tooShort) {
            CredentialVault::decrypt($tooShort);
        });
    }

    public function testDecryptRejectsEmptyString(): void
    {
        $this->expectException(RuntimeException::class, function () {
            CredentialVault::decrypt('');
        });
    }

    public function testRoundTripPreservesUnicodeAndSpecialCharacters(): void
    {
        $plaintext = "tk_日本語_émojis-🔒-and\nnewlines\tand\ttabs";
        $encrypted = CredentialVault::encrypt($plaintext);

        $this->assertSame($plaintext, CredentialVault::decrypt($encrypted));
    }
}
