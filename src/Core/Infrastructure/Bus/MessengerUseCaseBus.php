<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Bus;

use App\Core\Application\Bus\UseCaseBusInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\MessageBusInterface;

final class MessengerUseCaseBus extends AbstractMessengerCommandBus implements UseCaseBusInterface
{
    public function __construct(#[Target('usecase.bus')] MessageBusInterface $bus)
    {
        parent::__construct($bus);
    }
}
