<?php

declare(strict_types=1);

namespace App\Tests\Access\Integration\Presentation;

use App\Access\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CreateUserConsoleCommandTest extends KernelTestCase
{
    private CommandTester $tester;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->createQuery('DELETE FROM ' . User::class . " u WHERE u.username LIKE 'cli-%'")->execute();
        $this->tester = new CommandTester((new Application($kernel))->find('app:user:create'));
    }

    public function testCreatesAnAccountThatMustChangeItsPassword(): void
    {
        // Execute
        $status = $this->tester->execute([
            'username' => ' CLI-Ana ',
            'password' => 'temp-1234',
            'full-name' => 'Ana Reyes',
            '--role' => 'teacher',
        ]);

        // Assert
        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString(
            'Created teacher "cli-ana". They will be asked to set a new password when they first sign in.',
            $this->display(),
        );
        $this->em->clear();
        $ana = $this->em->getRepository(User::class)->findOneBy(['username' => 'cli-ana']);
        self::assertNotNull($ana);
        self::assertSame(User::ROLE_TEACHER, $ana->getRole());
        self::assertTrue($ana->mustChangePassword());
    }

    public function testStudentIsTheDefaultRole(): void
    {
        // Execute
        $status = $this->tester->execute(['username' => 'cli-ben', 'password' => 'temp-1234',
            'full-name' => 'Ben Cruz']);

        // Assert
        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Created student "cli-ben".', $this->display());
    }

    public function testUnknownRoleIsRefused(): void
    {
        // Execute
        $status = $this->tester->execute(['username' => 'cli-ana', 'password' => 'x', 'full-name' => 'Ana',
            '--role' => 'janitor']);

        // Assert
        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Role must be one of: admin, principal, teacher, student.', $this->display());
    }

    public function testExistingUsernameIsRefused(): void
    {
        // Initialize
        $this->tester->execute(['username' => 'cli-ana', 'password' => 'temp-1234', 'full-name' => 'Ana Reyes']);

        // Execute
        $status = $this->tester->execute(['username' => 'cli-ana', 'password' => 'temp-1234',
            'full-name' => 'Other Ana']);

        // Assert
        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('User "cli-ana" already exists.', $this->display());
    }

    public function testInvalidUsernameShowsTheValidationError(): void
    {
        // Execute
        $status = $this->tester->execute(['username' => 'cli ana', 'password' => 'temp-1234',
            'full-name' => 'Ana Reyes']);

        // Assert
        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString(
            'username: Use only letters, numbers, dots, dashes and underscores.',
            $this->display(),
        );
    }

    /** The output on one line, as SymfonyStyle wraps long messages. */
    private function display(): string
    {
        return (string) preg_replace('/\s+/', ' ', str_replace(['[OK]', '[ERROR]'], '', $this->tester->getDisplay()));
    }
}
