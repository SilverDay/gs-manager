<?php

declare(strict_types=1);

namespace GsppManager\Security;

use RuntimeException;

/**
 * AES-256-GCM field-level encryption for sensitive database columns
 * (API keys, TOTP secrets, etc.)
 */
class FieldEncryptor
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;

    private string $key;

    public function __construct()
    {
        $hexKey = $_ENV['FIELD_ENCRYPTION_KEY'] ?? '';

        if (strlen($hexKey) !== 64 || !ctype_xdigit($hexKey)) {
            throw new RuntimeException('FIELD_ENCRYPTION_KEY must be 64 hex characters (32 bytes)');
        }

        $this->key = hex2bin($hexKey);

        if ($this->key === false || strlen($this->key) !== 32) {
            throw new RuntimeException('Invalid FIELD_ENCRYPTION_KEY format');
        }
    }

    /**
     * Encrypt a plaintext value
     *
     * @return string Base64-encoded ciphertext (nonce + tag + ciphertext)
     */
    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(12); // 96-bit nonce for GCM
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed');
        }

        // Pack: nonce (12) + tag (16) + ciphertext
        return base64_encode($nonce . $tag . $ciphertext);
    }

    /**
     * Decrypt a previously encrypted value
     */
    public function decrypt(string $encoded): string
    {
        $data = base64_decode($encoded, true);

        if ($data === false || strlen($data) < 28) { // 12 + 16 minimum
            throw new RuntimeException('Invalid encrypted data');
        }

        $nonce = substr($data, 0, 12);
        $tag = substr($data, 12, self::TAG_LENGTH);
        $ciphertext = substr($data, 12 + self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed — wrong key or corrupted data');
        }

        return $plaintext;
    }
}
