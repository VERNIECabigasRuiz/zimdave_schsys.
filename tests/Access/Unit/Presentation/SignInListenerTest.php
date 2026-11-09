<?php

declare(strict_types=1);

namespace App\Tests\Access\Unit\Presentation;

use App\Access\Application\UseCase\Command\RecordSignInCommand;
use App\Access\Domain\Model\User;
use App\Access\Presentation\EventListener\SignInListener;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCaseBusInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

final class SignInListenerTest extends TestCase
{
    public function testRecordsTheSignIn(): void
    {
        // Expect
        $bus = $this->createMock(UseCaseBusInterface::class);
        $bus->expects(self::once())
            ->method('handle')
            ->with(self::equalTo(new RecordSignInCommand(7)))
            ->willReturn(Result::success());

        // Execute
        (new SignInListener($bus))->onLoginSuccess($this->event($this->savedUser(7)));
    }

    public function testOtherUsersAreIgnored(): void
    {
        // Expect
        $bus = $this->createMock(UseCaseBusInterface::class);
        $bus->expects(self::never())->method('handle');

        // Execute
        (new SignInListener($bus))->onLoginSuccess($this->event(new InMemoryUser('ana', 'x')));
        (new SignInListener($bus))->onLoginSuccess($this->event(new User()));
    }

    public function testAFailureIsThrown(): void
    {
        // Expect
        $this->expectException(\RuntimeException::class);

        // Initialize
        $bus = self::createStub(UseCaseBusInterface::class);
        $bus->method('handle')
            ->willReturn(Result::failure(new \RuntimeException('Database is down.'), 'INTERNAL_ERROR'));

        // Execute
        (new SignInListener($bus))->onLoginSuccess($this->event($this->savedUser(7)));
    }

    private function savedUser(int $id): User
    {
        $user = (new User())->setUsername('ana');
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private function event(UserInterface $user): LoginSuccessEvent
    {
        $passport = new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn () => $user));

        return new LoginSuccessEvent(
            self::createStub(AuthenticatorInterface::class),
            $passport,
            self::createStub(TokenInterface::class),
            new Request(),
            null,
            'main',
        );
    }
}
