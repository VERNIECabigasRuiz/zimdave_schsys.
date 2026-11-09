<?php

declare(strict_types=1);

namespace App\Tests\Access\Integration\Api;

use App\Access\Application\Api\AccountDirectoryInterface;
use App\Access\Domain\Model\User;
use App\Access\Domain\Service\Password\PasswordHasherInterface;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AccountDirectoryTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private AccountDirectoryInterface $accounts;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->accounts = static::getContainer()->get(AccountDirectoryInterface::class);
        $this->clearSchoolData();
    }

    public function testFindsAnAccountByIdAndByUsernameInAnyCase(): void
    {
        // Initialize
        $ana = $this->plainUser('ana.reyes', User::ROLE_TEACHER);

        // Execute
        $byId = $this->accounts->findById((int) $ana->getId());
        $byName = $this->accounts->findOneByUsername(' Ana.Reyes ');
        $unknown = $this->accounts->findOneByUsername('nobody');

        // Assert
        self::assertSame($ana, $byId);
        self::assertSame($ana, $byName);
        self::assertNull($unknown);
    }

    public function testRegisterWaitsForTheFlush(): void
    {
        // Initialize
        $account = (new User())->setUsername('new.teacher')->setFullName('New Teacher')
            ->setRole(User::ROLE_TEACHER)->setPassword('x');

        // Execute
        $this->accounts->register($account);
        $beforeFlush = $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM user WHERE username = 'new.teacher'");
        $this->em->flush();

        // Assert
        self::assertIsNumeric($beforeFlush);
        self::assertSame(0, (int) $beforeFlush);
        self::assertSame($account, $this->accounts->findOneByUsername('new.teacher'));
    }

    public function testATemporaryPasswordIsHashedAndMustBeChanged(): void
    {
        // Initialize
        $account = $this->plainUser('ana.reyes', User::ROLE_TEACHER);
        $account->setMustChangePassword(false);

        // Execute
        $password = $this->accounts->setTemporaryPassword($account);

        // Assert
        self::assertNotSame($password, $account->getPassword());
        self::assertTrue(static::getContainer()->get(PasswordHasherInterface::class)->isValid($account, $password));
        self::assertTrue($account->mustChangePassword());
    }

    public function testAUsernameIsTakenByAnotherAccountOnly(): void
    {
        // Initialize
        $ana = $this->plainUser('ana.reyes', User::ROLE_TEACHER);

        // Execute
        $takenForNew = $this->accounts->isUsernameTaken('ANA.REYES');
        $freeForHerself = $this->accounts->isUsernameTaken('ana.reyes', (int) $ana->getId());
        $freeName = $this->accounts->isUsernameTaken('ben.cruz');
        $blank = $this->accounts->isUsernameTaken('  ');

        // Assert
        self::assertTrue($takenForNew);
        self::assertFalse($freeForHerself);
        self::assertFalse($freeName);
        self::assertFalse($blank);
    }

    public function testCountsAccountsByRoleAndInactive(): void
    {
        // Initialize
        $this->plainUser('count.admin', User::ROLE_ADMIN);
        $this->plainUser('count.principal', User::ROLE_PRINCIPAL);
        $this->plainUser('count.teacher1', User::ROLE_TEACHER);
        $this->plainUser('count.teacher2', User::ROLE_TEACHER, false);
        $this->plainUser('count.student', User::ROLE_STUDENT);

        // Execute
        $counts = $this->accounts->accountCounts();

        // Assert
        self::assertSame(
            ['accounts' => 5, 'principals' => 1, 'teachers' => 2, 'students' => 1, 'inactive' => 1],
            $counts,
        );
    }
}
