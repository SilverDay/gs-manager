<?php

declare(strict_types=1);

namespace GsppManager\Tests\Unit\Security;

use GsppManager\Security\PasswordHasher;
use GsppManager\Tests\Unit\UnitTestCase;

class PasswordHasherTest extends UnitTestCase
{
    public function test_hash_returns_argon2id_formatted_string(): void
    {
        $hash = PasswordHasher::hash('password');

        $this->assertStringStartsWith('$argon2id$', $hash);
    }

    public function test_hash_produces_different_output_each_call(): void
    {
        $this->assertNotSame(PasswordHasher::hash('same'), PasswordHasher::hash('same'));
    }

    public function test_verify_returns_true_for_correct_password(): void
    {
        $hash = PasswordHasher::hash('correct-horse');

        $this->assertTrue(PasswordHasher::verify('correct-horse', $hash));
    }

    public function test_verify_returns_false_for_wrong_password(): void
    {
        $hash = PasswordHasher::hash('correct-horse');

        $this->assertFalse(PasswordHasher::verify('wrong-horse', $hash));
    }

    public function test_verify_returns_false_for_empty_password(): void
    {
        $hash = PasswordHasher::hash('not-empty');

        $this->assertFalse(PasswordHasher::verify('', $hash));
    }

    public function test_needs_rehash_returns_false_for_current_algorithm(): void
    {
        $hash = PasswordHasher::hash('password');

        $this->assertFalse(PasswordHasher::needsRehash($hash));
    }

    public function test_needs_rehash_returns_true_for_bcrypt_hash(): void
    {
        // bcrypt hash — old algorithm, must be upgraded on next login
        $bcryptHash = password_hash('password', PASSWORD_BCRYPT, ['cost' => 12]);

        $this->assertTrue(PasswordHasher::needsRehash($bcryptHash));
    }
}
