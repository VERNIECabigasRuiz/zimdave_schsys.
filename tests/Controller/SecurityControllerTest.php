<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class SecurityControllerTest extends WebTestCase
{
    use SchoolFixturesTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        // users are referenced by learners, transfer requests, logs…: clear everything that points at them
        $this->clearSchoolData();
    }

    private function createUser(string $username, string $password, bool $admin = false, bool $mustChange = true, bool $active = true): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = (new User())
            ->setUsername($username)
            ->setFullName(ucfirst($username).' Tester')
            ->setRoles($admin ? [User::ROLE_ADMIN] : [])
            ->setMustChangePassword($mustChange)
            ->setActive($active);
        $user->setPassword($hasher->hashPassword($user, $password));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function signIn(string $username, string $password): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', [
            '_username' => $username,
            '_password' => $password,
        ]);
    }

    public function testAnonymousUserIsSentToLogin(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/login');
    }

    public function testLoginPageShowsTheForm(): void
    {
        $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Welcome to');
        self::assertSelectorExists('input[name="_username"]');
        self::assertSelectorExists('input[name="_password"]');
        self::assertSelectorExists('a[href="/forgot-password"]');
    }

    public function testWrongPasswordShowsAnError(): void
    {
        $this->createUser('juan', 'Secret123', mustChange: false);

        $this->signIn('juan', 'wrong-password');
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert--error', 'Invalid credentials');
        self::assertInputValueSame('_username', 'juan');
    }

    public function testDisabledAccountCannotSignIn(): void
    {
        $this->createUser('maria', 'Secret123', mustChange: false, active: false);

        $this->signIn('maria', 'Secret123');
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert--error', 'Your account is disabled');
    }

    public function testFirstSignInForcesPasswordChange(): void
    {
        $this->createUser('admin', 'Temp12345', admin: true);

        $this->signIn('admin', 'Temp12345');
        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertResponseRedirects('/change-password');

        // Every other page stays locked until the password is changed
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/change-password');

        $this->client->request('GET', '/change-password');
        self::assertSelectorTextContains('h2', 'Set a new password');
        self::assertSelectorNotExists('input[name="change_password_form[currentPassword]"]');

        $this->client->submitForm('Save password', [
            'change_password_form[plainPassword][first]' => 'NewPass123',
            'change_password_form[plainPassword][second]' => 'NewPass123',
        ]);
        self::assertResponseRedirects('/');

        $this->client->followRedirect();
        self::assertResponseRedirects('/admin');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert--success', 'Your password has been updated.');

        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['username' => 'admin']);
        self::assertFalse($user->mustChangePassword());
        self::assertNotNull($user->getLastLoginAt());
    }

    public function testNewPasswordIsValidated(): void
    {
        $this->createUser('pedro', 'Temp12345');
        $this->signIn('pedro', 'Temp12345');

        $this->client->request('GET', '/change-password');
        $this->client->submitForm('Save password', [
            'change_password_form[plainPassword][first]' => 'short',
            'change_password_form[plainPassword][second]' => 'short',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'at least 8 characters');

        $this->client->submitForm('Save password', [
            'change_password_form[plainPassword][first]' => 'Temp12345',
            'change_password_form[plainPassword][second]' => 'Temp12345',
        ]);
        self::assertSelectorTextContains('form', 'must be different');

        $this->client->submitForm('Save password', [
            'change_password_form[plainPassword][first]' => 'NewPass123',
            'change_password_form[plainPassword][second]' => 'Different123',
        ]);
        self::assertSelectorTextContains('form', 'do not match');
    }

    public function testNonAdminCannotOpenAdminArea(): void
    {
        $this->createUser('student1', 'Secret123', mustChange: false);
        $this->signIn('student1', 'Secret123');

        $this->client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(403);
    }

    public function testLogout(): void
    {
        $this->createUser('juan', 'Secret123', mustChange: false);
        $this->signIn('juan', 'Secret123');

        $this->client->request('GET', '/logout');
        self::assertResponseRedirects('/login');

        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login');
    }
}
