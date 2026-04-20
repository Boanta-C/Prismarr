<?php

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    public function testNewUserHasRoleUserByDefault(): void
    {
        $user = new User();
        $this->assertContains('ROLE_USER', $user->getRoles());
    }

    public function testGetRolesAlwaysIncludesRoleUser(): void
    {
        // Even when explicitly set without it, ROLE_USER must be appended.
        // Classic Symfony security pattern — prevents an empty-roles user
        // from accidentally bypassing access_control rules.
        $user = new User();
        $user->setRoles(['ROLE_ADMIN']);
        $roles = $user->getRoles();

        $this->assertContains('ROLE_ADMIN', $roles);
        $this->assertContains('ROLE_USER', $roles);
    }

    public function testGetRolesDeduplicates(): void
    {
        $user = new User();
        $user->setRoles(['ROLE_USER', 'ROLE_ADMIN', 'ROLE_USER']);
        $roles = $user->getRoles();

        $this->assertSame(
            ['ROLE_USER', 'ROLE_ADMIN'],
            array_values($roles)
        );
    }

    public function testUserIdentifierIsEmail(): void
    {
        $user = new User();
        $user->setEmail('joshua@example.com');
        $this->assertSame('joshua@example.com', $user->getUserIdentifier());
    }

    public function testDisplayNameFallsBackToEmail(): void
    {
        $user = new User();
        $user->setEmail('joshua@example.com');
        // No displayName set → fallback to email
        $this->assertSame('joshua@example.com', $user->getDisplayName());

        $user->setDisplayName('Joshua');
        $this->assertSame('Joshua', $user->getDisplayName());
    }

    public function testCreatedAtIsSetOnConstruction(): void
    {
        $before = new \DateTimeImmutable();
        $user = new User();
        $after = new \DateTimeImmutable();

        $createdAt = $user->getCreatedAt();
        $this->assertNotNull($createdAt);
        $this->assertGreaterThanOrEqual($before, $createdAt);
        $this->assertLessThanOrEqual($after, $createdAt);
    }

    public function testEraseCredentialsIsNoop(): void
    {
        $user = new User();
        $user->setPassword('hashed');
        $user->eraseCredentials();
        // Prismarr does not store any plaintext temporary credentials,
        // so eraseCredentials is intentionally empty.
        $this->assertSame('hashed', $user->getPassword());
    }
}
