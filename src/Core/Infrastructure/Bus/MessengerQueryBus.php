<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Bus;

use App\Core\Application\Bus\QueryBusInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\MessageBusInterface;

final class MessengerQueryBus extends AbstractMessengerCommandBus implements QueryBusInterface
{
    public function __construct(#[Target('query.bus')] MessageBusInterface $bus)
    {
        parent::__construct($bus);
    }
}
