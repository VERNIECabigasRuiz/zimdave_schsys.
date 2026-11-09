<?php

declare(strict_types=1);

namespace App\Tests\Access\Unit\Application;

use App\Access\Application\Query\Command\GetAccountCommand;
use App\Access\Application\Query\Command\SearchAccountsCommand;
use App\Access\Application\Query\Handler\GetAccountHandler;
use App\Access\Application\Query\Handler\SearchAccountsHandler;
use App\Access\Application\UseCase\Command\CreateAccountCommand;
use App\Access\Application\UseCase\Command\ResetAccountPasswordCommand;
use App\Access\Application\UseCase\Command\ToggleAccountActiveCommand;
use App\Access\Application\UseCase\Command\UpdateAccountCommand;
use App\Access\Application\UseCase\Handler\CreateAccountHandler;
use App\Access\Application\UseCase\Handler\ResetAccountPasswordHandler;
use App\Access\Application\UseCase\Handler\ToggleAccountActiveHandler;
use App\Access\Application\UseCase\Handler\UpdateAccountHandler;
use App\Access\Domain\Exception\AccountNotFoundException;
use App\Access\Domain\Exception\AccountReactivationRefusedException;
use App\Access\Domain\Exception\AccountRoleChangeRefusedException;
use App\Access\Domain\Exception\OwnAccountException;
use App\Access\Domain\Exception\UsernameTakenException;
use App\Access\Domain\Model\Repository\UserRepositoryInterface;
use App\Access\Domain\Model\User;
use App\Access\Domain\Service\Account\AccountLearnerLinkInterface;
use App\Access\Domain\Service\Account\AccountReactivationGuardInterface;
use App\Access\Domain\Service\Account\AccountRoleChangeGuardInterface;
use App\Access\Domain\Service\Account\AccountSchoolNamesInterface;
use App\Access\Domain\Service\Password\PasswordHasherInterface;
use App\Access\Domain\Service\Password\TemporaryPasswordGenerator;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use PHPUnit\Framework\TestCase;

final class AccountHandlersTest extends TestCase
{
    public function testCreatesAnAccountThatMustChangeItsPassword(): void
    {
        // Expect
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findOneByUsername')->willReturn(null);
        $users->expects(self::once())->method('add');

        // Initialize
        $command = new CreateAccountCommand();
        $command->role = User::ROLE_TEACHER;
        $command->fullName = 'Ana Reyes';
        $command->username = 'Ana.Reyes';
        $command->temporaryPassword = 'temp-1234';

        // Execute
        $handler = new CreateAccountHandler($users, $this->hasher(), $this->logger('Created account: ana.reyes'));
        $result = $handler($command);

        // Assert
        self::assertSame(
            ['fullName' => 'Ana Reyes', 'username' => 'ana.reyes', 'role' => User::ROLE_TEACHER],
            $result->data(),
        );
        self::assertSame(['role' => User::ROLE_TEACHER, 'username' => 'Ana.Reyes'], $command->toLogArray());
    }

    public function testCreateRefusesATakenUsername(): void
    {
        // Expect
        $this->expectException(UsernameTakenException::class);
        $this->expectExceptionMessageIsOrContains('This username is already taken.');

        // Initialize
        $users = self::createStub(UserRepositoryInterface::class);
        $users->method('findOneByUsername')->willReturn(new User());
        $command = new CreateAccountCommand();
        $command->username = 'ana';

        // Execute
        (new CreateAccountHandler($users, $this->hasher(), $this->logger()))($command);
    }

    public function testUpdateSavesTheChanges(): void
    {
        // Initialize
        $ana = $this->user('ana', User::ROLE_TEACHER);
        $command = $this->update(User::ROLE_PRINCIPAL, 'ana', active: false, fullName: 'Ana R.');

        // Execute
        $handler = new UpdateAccountHandler($this->users($ana, $ana), $this->logger('Updated account: ana'), []);
        $result = $handler($command);

        // Assert
        self::assertSame(User::ROLE_PRINCIPAL, $ana->getRole());
        self::assertSame('Ana R.', $ana->getFullName());
        self::assertFalse($ana->isActive());
        self::assertIsArray($result->data());
        self::assertSame(
            ['id' => 7, 'role' => User::ROLE_PRINCIPAL, 'username' => 'ana', 'active' => false],
            $command->toLogArray(),
        );
    }

    public function testUpdateCollectsEveryRoleChangeRefusal(): void
    {
        // Initialize
        $ana = $this->user('ana', User::ROLE_PRINCIPAL);
        $command = $this->update(User::ROLE_TEACHER, 'ana');
        $guards = [
            $this->roleGuard('Still a principal.'),
            $this->roleGuard(null),
            $this->roleGuard('A learner account.'),
        ];

        // Execute
        try {
            (new UpdateAccountHandler($this->users($ana, $ana), $this->logger(), $guards))($command);
            self::fail('Expected AccountRoleChangeRefusedException');
        } catch (AccountRoleChangeRefusedException $e) {
            // Assert
            self::assertSame(['Still a principal.', 'A learner account.'], $e->reasons());
            self::assertSame('ROLE_CHANGE_REFUSED', $e->errorCode());
        }
    }

    public function testUpdateRefusesAUsernameOfAnotherAccount(): void
    {
        // Expect
        $this->expectException(UsernameTakenException::class);

        // Initialize
        $command = $this->update(User::ROLE_TEACHER, 'ben');

        // Execute
        (new UpdateAccountHandler($this->users($this->user('ana'), $this->user('ben')), $this->logger(), []))($command);
    }

    public function testUpdateOfAnUnknownAccountIsRefused(): void
    {
        // Expect
        $this->expectException(AccountNotFoundException::class);

        // Execute
        (new UpdateAccountHandler($this->users(null), $this->logger(), []))($this->update(null, 'a'));
    }

    public function testDeactivates(): void
    {
        // Initialize
        $ana = $this->user('ana');
        $command = new ToggleAccountActiveCommand(7, 'zim');

        // Execute
        $guards = [$this->failingReactivationGuard()];
        $logger = $this->logger('Deactivated account: ana');
        $handler = new ToggleAccountActiveHandler($this->users($ana), $logger, $guards);
        $result = $handler($command);

        // Assert
        self::assertFalse($ana->isActive());
        self::assertIsArray($result->data());
        self::assertSame(['id' => 7], $command->toLogArray());
    }

    public function testReactivationAsksEveryGuard(): void
    {
        // Expect
        $guard = $this->createMock(AccountReactivationGuardInterface::class);
        $guard->expects(self::once())->method('assertCanReactivate');

        // Initialize
        $ana = $this->user('ana')->setActive(false);

        // Execute
        $logger = $this->logger('Activated account: ana');
        $handler = new ToggleAccountActiveHandler($this->users($ana), $logger, [$guard]);
        $handler(new ToggleAccountActiveCommand(7, 'zim'));

        // Assert
        self::assertTrue($ana->isActive());
    }

    public function testReactivationRefusalIsPassedOn(): void
    {
        // Expect
        $this->expectException(AccountReactivationRefusedException::class);

        // Initialize
        $ana = $this->user('ana')->setActive(false);

        // Execute
        $guards = [$this->failingReactivationGuard()];
        $handler = new ToggleAccountActiveHandler($this->users($ana), $this->logger(), $guards);
        $handler(new ToggleAccountActiveCommand(7, 'zim'));
    }

    public function testOwnAccountCannotBeDeactivated(): void
    {
        // Expect
        $this->expectException(OwnAccountException::class);
        $this->expectExceptionMessageIsOrContains('You cannot deactivate your own account.');

        // Execute
        $handler = new ToggleAccountActiveHandler($this->users($this->user('zim')), $this->logger(), []);
        $handler(new ToggleAccountActiveCommand(7, 'zim'));
    }

    public function testToggleOfAnUnknownAccountIsRefused(): void
    {
        // Expect
        $this->expectException(AccountNotFoundException::class);

        // Execute
        $handler = new ToggleAccountActiveHandler($this->users(null), $this->logger(), []);
        $handler(new ToggleAccountActiveCommand(7, 'zim'));
    }

    public function testResetGivesANewTemporaryPassword(): void
    {
        // Initialize
        $ana = $this->user('ana');
        $ana->setMustChangePassword(false);
        $command = new ResetAccountPasswordCommand(7, 'zim');

        // Execute
        $result = $this->resetHandler($ana)($command);

        // Assert
        /** @var array{fullName: string, username: string, password: string} $data */
        $data = $result->data();
        self::assertSame('ana', $data['username']);
        self::assertSame('hashed:' . $data['password'], $ana->getPassword());
        self::assertTrue($ana->mustChangePassword());
        self::assertSame(['id' => 7], $command->toLogArray());
    }

    public function testOwnPasswordIsNotReset(): void
    {
        // Expect
        $this->expectException(OwnAccountException::class);
        $this->expectExceptionMessageIsOrContains('Use "Change password" to change your own password.');

        // Execute
        $this->resetHandler($this->user('zim'))(new ResetAccountPasswordCommand(7, 'zim'));
    }

    public function testResetOfAnUnknownAccountIsRefused(): void
    {
        // Expect
        $this->expectException(AccountNotFoundException::class);

        // Execute
        $this->resetHandler(null)(new ResetAccountPasswordCommand(7, 'zim'));
    }

    public function testSearchShapesTheAccountsWithTheirSchools(): void
    {
        // Initialize
        $users = self::createStub(UserRepositoryInterface::class);
        $users->method('search')->willReturn(['items' => [$this->user('ana')], 'total' => 1]);
        $schools = self::createStub(AccountSchoolNamesInterface::class);
        $schools->method('schoolNamesByAccount')->willReturn([7 => 'Pagsabungan NHS']);
        $command = new SearchAccountsCommand('ana', User::ROLE_TEACHER, true, 1, 20);

        // Execute
        $result = (new SearchAccountsHandler($users, $schools))($command);

        // Assert
        /** @var array{accounts: list<array{username: string}>, total: int, schoolNames: array<int, string>} $data */
        $data = $result->data();
        self::assertSame('ana', $data['accounts'][0]['username']);
        self::assertSame(1, $data['total']);
        self::assertSame([7 => 'Pagsabungan NHS'], $data['schoolNames']);
        self::assertSame(
            ['query' => 'ana', 'role' => User::ROLE_TEACHER, 'active' => true, 'page' => 1],
            $command->toLogArray(),
        );
    }

    public function testGetIncludesTheLearner(): void
    {
        // Initialize
        $learners = self::createStub(AccountLearnerLinkInterface::class);
        $learners->method('learnerLrn')->willReturn('123456789012');
        $command = new GetAccountCommand(7);

        // Execute
        $result = (new GetAccountHandler($this->users($this->user('ana')), $learners))($command);

        // Assert
        /** @var array{learnerLrn: ?string} $data */
        $data = $result->data();
        self::assertSame('123456789012', $data['learnerLrn']);
        self::assertSame(['id' => 7], $command->toLogArray());
    }

    public function testGetOfAnUnknownAccountIsRefused(): void
    {
        // Expect
        $this->expectException(AccountNotFoundException::class);

        // Execute
        $handler = new GetAccountHandler($this->users(null), self::createStub(AccountLearnerLinkInterface::class));
        $handler(new GetAccountCommand(7));
    }

    private function update(
        ?string $role,
        string $username,
        bool $active = true,
        string $fullName = 'Ana',
    ): UpdateAccountCommand {
        return UpdateAccountCommand::fromAccount([
            'id' => 7,
            'role' => $role,
            'fullName' => $fullName,
            'username' => $username,
            'active' => $active,
        ]);
    }

    private function user(string $username, string $role = User::ROLE_TEACHER): User
    {
        return (new User())->setUsername($username)->setFullName(ucfirst($username))->setRole($role);
    }

    private function users(?User $byId, ?User $byUsername = null): UserRepositoryInterface
    {
        $users = self::createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturn($byId);
        $users->method('findOneByUsername')->willReturn($byUsername);

        return $users;
    }

    private function hasher(): PasswordHasherInterface
    {
        $hasher = self::createStub(PasswordHasherInterface::class);
        $hasher->method('hash')->willReturnCallback(static fn (User $user, string $plain) => 'hashed:' . $plain);

        return $hasher;
    }

    private function logger(?string $expectedAction = null): ActivityLoggerInterface
    {
        if (null === $expectedAction) {
            return self::createStub(ActivityLoggerInterface::class);
        }
        $logger = $this->createMock(ActivityLoggerInterface::class);
        $logger->expects(self::once())->method('log')->with($expectedAction);

        return $logger;
    }

    private function roleGuard(?string $refusal): AccountRoleChangeGuardInterface
    {
        $guard = self::createStub(AccountRoleChangeGuardInterface::class);
        $guard->method('refusal')->willReturn($refusal);

        return $guard;
    }

    private function failingReactivationGuard(): AccountReactivationGuardInterface
    {
        $guard = self::createStub(AccountReactivationGuardInterface::class);
        $guard->method('assertCanReactivate')
            ->willThrowException(new AccountReactivationRefusedException('No seat left.'));

        return $guard;
    }

    private function resetHandler(?User $account): ResetAccountPasswordHandler
    {
        return new ResetAccountPasswordHandler(
            $this->users($account),
            $this->hasher(),
            new TemporaryPasswordGenerator(),
            $this->logger(),
        );
    }
}
