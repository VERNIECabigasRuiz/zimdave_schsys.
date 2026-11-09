<?php

declare(strict_types=1);

namespace App\Tests\Access\Unit\Domain;

use App\Access\Domain\Model\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testUsernameIsTrimmedAndLowerCased(): void
    {
        // Initialize
        $user = new User();

        // Execute
        $user->setUsername('  Ana.Reyes ')->setFullName('  Ana Reyes  ');

        // Assert
        self::assertSame('ana.reyes', $user->getUsername());
        self::assertSame('ana.reyes', $user->getUserIdentifier());
        self::assertSame('Ana Reyes', $user->getFullName());
    }

    public function testBlankUsernameHasAPlaceholderIdentifier(): void
    {
        // Execute
        $identifier = (new User())->getUserIdentifier();

        // Assert
        self::assertSame('-', $identifier);
    }

    public function testOneRoleWithItsLabel(): void
    {
        // Initialize
        $user = new User();

        // Execute
        $user->setRole(User::ROLE_PRINCIPAL);

        // Assert
        self::assertSame(User::ROLE_PRINCIPAL, $user->getRole());
        self::assertSame('Principal', $user->getRoleLabel());
        self::assertSame([User::ROLE_PRINCIPAL, 'ROLE_USER'], $user->getRoles());
    }

    public function testNoRole(): void
    {
        // Initialize
        $user = (new User())->setRole(User::ROLE_TEACHER);

        // Execute
        $user->setRole(null);

        // Assert
        self::assertNull($user->getRole());
        self::assertSame('User', $user->getRoleLabel());
        self::assertSame(['ROLE_USER'], $user->getRoles());
        self::assertNull((new User())->setRole('')->getRole());
    }

    public function testUnknownRoleIsRefused(): void
    {
        // Expect
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown role "ROLE_JANITOR".');

        // Execute
        (new User())->setRole('ROLE_JANITOR');
    }

    public function testSetRolesKeepsTheList(): void
    {
        // Execute
        $user = (new User())->setRoles([User::ROLE_ADMIN]);

        // Assert
        self::assertSame(User::ROLE_ADMIN, $user->getRole());
    }

    public function testInitials(): void
    {
        // Execute
        $full = (new User())->setFullName('Ana Maria Reyes')->getInitials();
        $single = (new User())->setFullName('Zim')->getInitials();
        $fromUsername = (new User())->setUsername('ben')->getInitials();

        // Assert
        self::assertSame('AR', $full);
        self::assertSame('ZI', $single);
        self::assertSame('BE', $fromUsername);
    }

    public function testAccountFields(): void
    {
        // Initialize
        $user = new User();
        $login = new \DateTimeImmutable('2026-11-06 08:00');

        // Execute
        $user->setPassword('hash')->setMustChangePassword(false)->setActive(false)
            ->setLastLoginAt($login)->setPhotoPath('uploads/photos/a.webp');

        // Assert
        self::assertNull($user->getId());
        self::assertSame('hash', $user->getPassword());
        self::assertFalse($user->mustChangePassword());
        self::assertFalse($user->isActive());
        self::assertSame($login, $user->getLastLoginAt());
        self::assertSame('uploads/photos/a.webp', $user->getPhotoPath());
        self::assertLessThanOrEqual(new \DateTimeImmutable(), $user->getCreatedAt());
    }

    public function testSessionDataHoldsNoPasswordHash(): void
    {
        // Initialize
        $user = (new User())->setUsername('ana');
        $user->setPassword('$2y$13$secret-hash');

        // Execute
        $data = $user->__serialize();

        // Assert
        self::assertSame(hash('crc32c', '$2y$13$secret-hash'), $data["\0" . User::class . "\0password"]);
        self::assertStringNotContainsString('secret-hash', serialize($user));
    }
}
