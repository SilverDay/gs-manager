<?php

declare(strict_types=1);

namespace GsppManager\Tests\Unit\Security;

use GsppManager\Security\FieldEncryptor;
use GsppManager\Tests\Unit\UnitTestCase;
use RuntimeException;

class FieldEncryptorTest extends UnitTestCase
{
    private FieldEncryptor $encryptor;
    private string $originalKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalKey = $_ENV['FIELD_ENCRYPTION_KEY'] ?? '';
        $_ENV['FIELD_ENCRYPTION_KEY'] = str_repeat('ab', 32); // valid 64-char hex
        $this->encryptor = new FieldEncryptor();
    }

    protected function tearDown(): void
    {
        $_ENV['FIELD_ENCRYPTION_KEY'] = $this->originalKey;
        parent::tearDown();
    }

    public function test_encrypt_returns_non_empty_base64_string(): void
    {
        $result = $this->encryptor->encrypt('hello');

        $this->assertNotEmpty($result);
        $this->assertNotFalse(base64_decode($result, true));
    }

    public function test_encrypt_decrypt_roundtrip_returns_original_plaintext(): void
    {
        $plaintext = 'my-secret-api-key';

        $this->assertSame($plaintext, $this->encryptor->decrypt($this->encryptor->encrypt($plaintext)));
    }

    public function test_encrypt_produces_different_ciphertext_each_call_due_to_random_nonce(): void
    {
        $ct1 = $this->encryptor->encrypt('same');
        $ct2 = $this->encryptor->encrypt('same');

        $this->assertNotSame($ct1, $ct2);
    }

    public function test_encrypt_decrypt_handles_empty_string(): void
    {
        $this->assertSame('', $this->encryptor->decrypt($this->encryptor->encrypt('')));
    }

    public function test_encrypt_decrypt_handles_unicode_content(): void
    {
        $value = 'Schlüssel mit Sonderzeichen: äöü€🔒';

        $this->assertSame($value, $this->encryptor->decrypt($this->encryptor->encrypt($value)));
    }

    public function test_decrypt_throws_on_corrupted_ciphertext(): void
    {
        $this->expectException(RuntimeException::class);

        $this->encryptor->decrypt('not-valid-base64!!@@##');
    }

    public function test_decrypt_throws_on_truncated_data(): void
    {
        $this->expectException(RuntimeException::class);

        // Less than 28 bytes (12 nonce + 16 tag minimum)
        $this->encryptor->decrypt(base64_encode('tooshort'));
    }

    public function test_decrypt_throws_when_tampered_tag(): void
    {
        $ciphertext = $this->encryptor->encrypt('secret');
        $raw        = base64_decode($ciphertext, true);

        // Flip a byte in the GCM tag (bytes 12–27)
        $raw[15] = chr(ord($raw[15]) ^ 0xFF);

        $this->expectException(RuntimeException::class);
        $this->encryptor->decrypt(base64_encode($raw));
    }

    public function test_constructor_throws_when_key_is_too_short(): void
    {
        $_ENV['FIELD_ENCRYPTION_KEY'] = 'tooshort';

        $this->expectException(RuntimeException::class);
        new FieldEncryptor();
    }

    public function test_constructor_throws_when_key_is_not_valid_hex(): void
    {
        $_ENV['FIELD_ENCRYPTION_KEY'] = str_repeat('zz', 32); // 64 chars but invalid hex

        $this->expectException(RuntimeException::class);
        new FieldEncryptor();
    }
}
