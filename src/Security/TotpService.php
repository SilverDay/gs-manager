<?php

declare(strict_types=1);

namespace GsppManager\Security;

/**
 * RFC 6238 TOTP implementation (SHA1, 6 digits, 30-second window)
 * No external library required — uses only hash_hmac() and random_bytes().
 */
class TotpService
{
    private const ALPHABET  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const PERIOD     = 30;
    private const DIGITS     = 6;
    private const ALGORITHM  = 'sha1';

    /**
     * Generate a new 20-byte secret encoded as Base32.
     */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /**
     * Return the otpauth:// URI consumed by authenticator apps.
     */
    public static function getOtpAuthUri(string $secret, string $email, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($email);
        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            $label,
            $secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD,
        );
    }

    /**
     * Verify a TOTP code against the secret.
     * Accepts ±1 time step (30 s each) to tolerate clock skew.
     */
    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $keyBytes = self::base32Decode($secret);
        if ($keyBytes === '') {
            return false;
        }

        $counter = (int) floor(time() / self::PERIOD);
        $expected = (int) $code;

        for ($i = -$window; $i <= $window; $i++) {
            if (self::hotp($keyBytes, $counter + $i) === $expected) {
                return true;
            }
        }

        return false;
    }

    // ──────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────

    private static function hotp(string $keyBytes, int $counter): int
    {
        // Pack counter as 8-byte big-endian unsigned integer
        $msg  = pack('J', $counter);
        $hmac = hash_hmac(self::ALGORITHM, $msg, $keyBytes, true); // 20 raw bytes

        // Dynamic truncation (RFC 4226 §5.4)
        $offset = ord($hmac[19]) & 0x0f;
        $code = (
            (ord($hmac[$offset])     & 0x7f) << 24 |
            (ord($hmac[$offset + 1]) & 0xff) << 16 |
            (ord($hmac[$offset + 2]) & 0xff) << 8  |
            (ord($hmac[$offset + 3]) & 0xff)
        ) % (10 ** self::DIGITS);

        return $code;
    }

    private static function base32Encode(string $input): string
    {
        $output = '';
        $buffer = 0;
        $bitsLeft = 0;

        for ($i = 0; $i < strlen($input); $i++) {
            $buffer = ($buffer << 8) | ord($input[$i]);
            $bitsLeft += 8;
            while ($bitsLeft >= 5) {
                $bitsLeft -= 5;
                $output .= self::ALPHABET[($buffer >> $bitsLeft) & 0x1f];
            }
        }

        if ($bitsLeft > 0) {
            $output .= self::ALPHABET[($buffer << (5 - $bitsLeft)) & 0x1f];
        }

        return $output;
    }

    private static function base32Decode(string $input): string
    {
        $input  = strtoupper(str_replace([' ', "\t", "\n", "\r", '-'], '', $input));
        $lookup = array_flip(str_split(self::ALPHABET));
        $output = '';
        $buffer = 0;
        $bitsLeft = 0;

        for ($i = 0; $i < strlen($input); $i++) {
            $c = $input[$i];
            if ($c === '=') {
                break; // padding
            }
            if (!isset($lookup[$c])) {
                return ''; // invalid character → reject
            }
            $buffer = ($buffer << 5) | $lookup[$c];
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xff);
            }
        }

        return $output;
    }
}
