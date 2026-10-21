<?php

namespace App\Tests\Controller\Admin;

use App\Entity\ActivityLog;
use App\Entity\User;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

abstract class AdminTestCase extends WebTestCase
{
    use SchoolFixturesTrait;

    protected KernelBrowser $client;
    protected EntityManagerInterface $em;
    protected User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();

        $this->admin = $this->createUser('zim', User::ROLE_ADMIN, 'Admin');
        $this->client->loginUser($this->admin);
    }

    protected function createUser(string $username, string $role, ?string $fullName = null, bool $active = true): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = (new User())
            ->setUsername($username)
            ->setFullName($fullName ?? ucfirst($username).' Tester')
            ->setRole($role)
            ->setMustChangePassword(false)
            ->setActive($active);
        $user->setPassword($hasher->hashPassword($user, 'Secret123'));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    protected function findUser(string $username): ?User
    {
        $this->em->clear();

        return $this->em->getRepository(User::class)->findOneBy(['username' => $username]);
    }

    /**
     * @return list<string>
     */
    protected function activityActions(): array
    {
        $this->em->clear();

        return array_map(
            static fn (ActivityLog $log) => $log->getAction(),
            $this->em->getRepository(ActivityLog::class)->findAll(),
        );
    }
}
