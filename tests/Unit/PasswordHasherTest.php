<?php

namespace Dynart\Dpress\Test\Unit;

use Dynart\Dpress\Security\PasswordHasher;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Dynart\Dpress\Security\PasswordHasher
 */
class PasswordHasherTest extends TestCase {

    private PasswordHasher $hasher;

    protected function setUp(): void {
        $this->hasher = new PasswordHasher();
    }

    public function testHashAndVerify(): void {
        $hash = $this->hasher->hash('correct horse battery');
        $this->assertTrue($this->hasher->verify('correct horse battery', $hash));
    }

    public function testVerifyRejectsAWrongPassword(): void {
        $hash = $this->hasher->hash('correct horse battery');
        $this->assertFalse($this->hasher->verify('wrong', $hash));
    }

    /**
     * A user row with no hash must never accidentally accept an empty password.
     */
    public function testVerifyRejectsAnEmptyHash(): void {
        $this->assertFalse($this->hasher->verify('', ''));
        $this->assertFalse($this->hasher->verify('anything', ''));
    }

    public function testTheSamePasswordHashesDifferentlyEachTime(): void {
        $this->assertNotSame(
            $this->hasher->hash('same password'),
            $this->hasher->hash('same password')
        );
    }

    public function testAFreshHashDoesNotNeedRehashing(): void {
        $this->assertFalse($this->hasher->needsRehash($this->hasher->hash('whatever')));
    }

    // --- Tokens ---

    public function testCreateTokenReturnsTheTokenAndItsHash(): void {
        [$token, $hash] = $this->hasher->createToken();
        $this->assertSame(64, strlen($token)); // 32 bytes as hex
        $this->assertSame($this->hasher->hashToken($token), $hash);
    }

    public function testTokensAreUnique(): void {
        [$first] = $this->hasher->createToken();
        [$second] = $this->hasher->createToken();
        $this->assertNotSame($first, $second);
    }

    /**
     * Unlike a password, a token hash has to be deterministic - it is looked up by hash on a
     * unique index, which a salted hash could not support.
     */
    public function testTokenHashingIsDeterministic(): void {
        $this->assertSame($this->hasher->hashToken('abc'), $this->hasher->hashToken('abc'));
        $this->assertSame(64, strlen($this->hasher->hashToken('abc')));
    }

    public function testTheTokenIsNotRecoverableFromItsHash(): void {
        [$token, $hash] = $this->hasher->createToken();
        $this->assertNotSame($token, $hash);
    }
}
