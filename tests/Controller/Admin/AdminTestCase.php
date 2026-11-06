<?php

namespace App\Tests\Controller\Admin;

use App\Core\Domain\Model\ActivityLog;
use App\Access\Domain\Model\User;
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

    /** A valid CSRF token of the client's session (a page must have been requested first). */
    protected function csrfToken(string $id): string
    {
        static::getContainer()->get('request_stack')->push($this->client->getRequest());
        $token = static::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
        $this->client->getRequest()->getSession()->save();

        return $token;
    }

    /** The account, freshly loaded; the test fails when there is none. */
    protected function findUser(string $username): User
    {
        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['username' => $username]);
        self::assertNotNull($user, \sprintf('No account "%s".', $username));

        return $user;
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
