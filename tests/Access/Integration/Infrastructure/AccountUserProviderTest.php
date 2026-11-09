<?php

declare(strict_types=1);

namespace App\Tests\Access\Integration\Infrastructure;

use App\Access\Domain\Model\User;
use App\Access\Infrastructure\Security\AccountUserProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class AccountUserProviderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AccountUserProvider $provider;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->provider = static::getContainer()->get(AccountUserProvider::class);
        $this->em->createQuery('DELETE FROM ' . User::class . " u WHERE u.username LIKE 'up-%'")->execute();
    }

    public function testLoadsByUsername(): void
    {
        // Initialize
        $ana = $this->account('up-ana');

        // Execute
        $loaded = $this->provider->loadUserByIdentifier('up-ana');

        // Assert
        self::assertSame($ana, $loaded);
    }

    public function testUnknownUsernameIsNotFound(): void
    {
        // Expect
        $this->expectException(UserNotFoundException::class);

        // Execute
        $this->provider->loadUserByIdentifier('up-nobody');
    }

    public function testRefreshReloadsFromTheDatabase(): void
    {
        // Initialize
        $ana = $this->account('up-ana');
        $this->em->getConnection()
            ->executeStatement("UPDATE `user` SET full_name = 'Ana Renamed' WHERE username = 'up-ana'");
        $this->em->clear();

        // Execute
        $refreshed = $this->provider->refreshUser($ana);

        // Assert
        self::assertSame('Ana Renamed', $refreshed->getFullName());
    }

    public function testRefreshOfADeletedAccountIsNotFound(): void
    {
        // Expect
        $this->expectException(UserNotFoundException::class);

        // Initialize
        $ana = $this->account('up-ana');
        $this->em->getConnection()->executeStatement("DELETE FROM `user` WHERE username = 'up-ana'");
        $this->em->clear();

        // Execute
        $this->provider->refreshUser($ana);
    }

    public function testRefreshRefusesOtherUsers(): void
    {
        // Expect
        $this->expectException(UnsupportedUserException::class);

        // Execute
        $this->provider->refreshUser(new InMemoryUser('ana', 'x'));
    }

    public function testSupportsOnlyUser(): void
    {
        // Execute
        $supportsUser = $this->provider->supportsClass(User::class);
        $supportsOther = $this->provider->supportsClass(InMemoryUser::class);

        // Assert
        self::assertTrue($supportsUser);
        self::assertFalse($supportsOther);
    }

    public function testUpgradesThePassword(): void
    {
        // Initialize
        $ana = $this->account('up-ana');

        // Execute
        $this->provider->upgradePassword($ana, 'new-hash');
        $this->em->clear();

        // Assert
        self::assertSame('new-hash', $this->provider->loadUserByIdentifier('up-ana')->getPassword());
    }

    private function account(string $username): User
    {
        $user = (new User())->setUsername($username)->setFullName('Ana Reyes')->setRole(User::ROLE_TEACHER);
        $user->setPassword('old-hash');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
