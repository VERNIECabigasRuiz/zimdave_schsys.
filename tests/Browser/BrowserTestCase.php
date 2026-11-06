<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Access\Domain\Model\User;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\PantherTestCase;

/**
 * Drives the app in headless Chrome. The test data is written to the test database,
 * which the browser's web server reads too.
 */
abstract class BrowserTestCase extends PantherTestCase
{
    use SchoolFixturesTrait;

    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
    }

    /** A user who can sign in with Secret123 and doesn't have to change it. */
    protected function createUser(string $username, string $role): User
    {
        $user = (new User())
            ->setUsername($username)
            ->setFullName(ucfirst($username) . ' Tester')
            ->setRole($role)
            ->setMustChangePassword(false)
            ->setActive(true);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'Secret123'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** Signs in through the login form and waits for the page it lands on. */
    protected function logIn(Client $client, string $username, string $password = 'Secret123'): void
    {
        $client->request('GET', '/login');
        $client->submitForm('Sign in', ['_username' => $username, '_password' => $password]);
        // The login page has an h1 too, so wait for the signed-in side menu
        $client->waitFor('.sidebar__nav');
    }
}
