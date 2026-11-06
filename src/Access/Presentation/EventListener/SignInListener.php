<?php

declare(strict_types=1);

namespace App\Access\Presentation\EventListener;

use App\Access\Application\UseCase\Command\RecordSignInCommand;
use App\Access\Domain\Model\User;
use App\Core\Application\Bus\UseCaseBusInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/** Records the last sign-in time. */
class SignInListener
{
    public function __construct(private readonly UseCaseBusInterface $useCaseBus)
    {
    }

    #[AsEventListener]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User || null === $user->getId()) {
            return;
        }

        $result = $this->useCaseBus->handle(new RecordSignInCommand($user->getId()));
        $exception = $result->exception();
        if (null !== $exception) {
            throw $exception;
        }
    }
}
