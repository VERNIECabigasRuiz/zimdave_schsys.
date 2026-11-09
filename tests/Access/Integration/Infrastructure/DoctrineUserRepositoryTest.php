<?php

declare(strict_types=1);

namespace App\Tests\Access\Integration\Infrastructure;

use App\Access\Domain\Model\Repository\UserRepositoryInterface;
use App\Access\Domain\Model\User;
use App\Access\Infrastructure\Repository\DoctrineUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\InMemoryUser;

/** Accounts made here start with "rt-", so other tests' accounts never get in the way. */
final class DoctrineUserRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DoctrineUserRepository $users;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->users = static::getContainer()->get(DoctrineUserRepository::class);
        $this->em->createQuery('DELETE FROM ' . User::class . " u WHERE u.username LIKE 'rt-%'")->execute();
    }

    public function testSearchFiltersAndPages(): void
    {
        // Initialize
        $this->account('rt-ana', User::ROLE_TEACHER, 'Ana Reyes');
        $this->account('rt-ben', User::ROLE_TEACHER, 'Ben Cruz', active: false);
        $this->account('rt-cora', User::ROLE_PRINCIPAL, 'Cora Lim');
        $this->em->flush();

        // Execute
        $teachers = $this->users->search('rt-', User::ROLE_TEACHER, null, 1, 20);
        $activeTeachers = $this->users->search('rt-', User::ROLE_TEACHER, true, 1, 20);
        $byName = $this->users->search('  Cora Lim ', null, null, 1, 20);
        $secondPage = $this->users->search('rt-', null, null, 2, 2);

        // Assert
        self::assertSame(['rt-ben', 'rt-ana'], $this->usernames($teachers['items']), 'Newest first');
        self::assertSame(2, $teachers['total']);
        self::assertSame(['rt-ana'], $this->usernames($activeTeachers['items']));
        self::assertSame(['rt-cora'], $this->usernames($byName['items']));
        self::assertSame(3, $secondPage['total']);
        self::assertSame(['rt-ana'], $this->usernames($secondPage['items']));
    }

    public function testSearchTreatsWildcardsAsText(): void
    {
        // Initialize
        $this->account('rt-ana_reyes', User::ROLE_TEACHER, 'Ana Reyes');
        $this->account('rt-anaxreyes', User::ROLE_TEACHER, 'Ana X');
        $this->em->flush();

        // Execute
        $found = $this->users->search('rt-ana_', null, null, 1, 20);

        // Assert
        self::assertSame(['rt-ana_reyes'], $this->usernames($found['items']));
    }

    public function testCounts(): void
    {
        // Initialize
        $all = $this->users->countAll();
        $teachers = $this->users->countByRole(User::ROLE_TEACHER);
        $inactive = $this->users->countInactive();
        $this->account('rt-ana', User::ROLE_TEACHER, 'Ana Reyes');
        $this->account('rt-ben', User::ROLE_TEACHER, 'Ben Cruz', active: false);
        $this->account('rt-cora', User::ROLE_PRINCIPAL, 'Cora Lim');
        $this->em->flush();

        // Execute
        $allAfter = $this->users->countAll();
        $teachersAfter = $this->users->countByRole(User::ROLE_TEACHER);
        $inactiveAfter = $this->users->countInactive();

        // Assert
        self::assertSame($all + 3, $allAfter);
        self::assertSame($teachers + 2, $teachersAfter);
        self::assertSame($inactive + 1, $inactiveAfter);
    }

    public function testFindsByIdAndUsernameIgnoringCaseAndSpaces(): void
    {
        // Initialize
        $ana = $this->account('rt-ana', User::ROLE_TEACHER, 'Ana Reyes');
        $this->em->flush();

        // Execute
        $byUsername = $this->users->findOneByUsername('  RT-Ana ');
        $byId = $this->users->findById((int) $ana->getId());

        // Assert
        self::assertSame($ana, $byUsername);
        self::assertSame($ana, $byId);
        self::assertNull($this->users->findOneByUsername('rt-nobody'));
    }

    public function testUpgradePasswordStoresTheNewHash(): void
    {
        // Initialize
        $ana = $this->account('rt-ana', User::ROLE_TEACHER, 'Ana Reyes');
        $this->em->flush();

        // Execute
        $this->users->upgradePassword($ana, 'new-hash');
        $this->em->clear();

        // Assert
        self::assertSame('new-hash', $this->users->findOneByUsername('rt-ana')?->getPassword());
    }

    public function testUpgradePasswordRefusesOtherUsers(): void
    {
        // Expect
        $this->expectException(UnsupportedUserException::class);

        // Execute
        $this->users->upgradePassword(new InMemoryUser('ana', 'x'), 'new-hash');
    }

    public function testTheInterfaceIsTheDoctrineRepository(): void
    {
        // Execute
        $service = static::getContainer()->get(UserRepositoryInterface::class);

        // Assert
        self::assertSame($this->users, $service);
    }

    private function account(string $username, string $role, string $fullName, bool $active = true): User
    {
        $user = (new User())->setUsername($username)->setFullName($fullName)->setRole($role)->setActive($active);
        $user->setPassword('x');
        $this->users->add($user);

        return $user;
    }

    /**
     * @param list<User> $users
     *
     * @return list<string>
     */
    private function usernames(array $users): array
    {
        return array_map(static fn (User $user) => $user->getUsername(), $users);
    }
}
