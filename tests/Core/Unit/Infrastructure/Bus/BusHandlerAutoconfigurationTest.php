<?php

declare(strict_types=1);

namespace App\Tests\Core\Unit\Infrastructure\Bus;

use App\Core\Infrastructure\Bus\BusHandlerAutoconfiguration;
use App\Tests\Core\Unit\Infrastructure\Bus\Fixture\SampleQuery;
use App\Tests\Core\Unit\Infrastructure\Bus\Fixture\SampleQueryHandler;
use App\Tests\Core\Unit\Infrastructure\Bus\Fixture\SampleUseCase;
use App\Tests\Core\Unit\Infrastructure\Bus\Fixture\SampleUseCaseHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\AttributeAutoconfigurationPass;
use Symfony\Component\DependencyInjection\Compiler\ResolveInstanceofConditionalsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class BusHandlerAutoconfigurationTest extends TestCase
{
    public function testUseCaseHandlerIsTaggedForTheUseCaseBus(): void
    {
        // Initialize
        $container = $this->containerWith(SampleUseCaseHandler::class);

        // Execute
        $tags = $container->getDefinition(SampleUseCaseHandler::class)->getTag('messenger.message_handler');

        // Assert
        self::assertSame([['bus' => 'usecase.bus', 'handles' => SampleUseCase::class]], $tags);
    }

    public function testQueryHandlerIsTaggedForTheQueryBus(): void
    {
        // Initialize
        $container = $this->containerWith(SampleQueryHandler::class);

        // Execute
        $tags = $container->getDefinition(SampleQueryHandler::class)->getTag('messenger.message_handler');

        // Assert
        self::assertSame([['bus' => 'query.bus', 'handles' => SampleQuery::class]], $tags);
    }

    /** @param class-string $handlerClass */
    private function containerWith(string $handlerClass): ContainerBuilder
    {
        $container = new ContainerBuilder();
        BusHandlerAutoconfiguration::register($container);
        $container->register($handlerClass, $handlerClass)->setAutoconfigured(true);

        (new AttributeAutoconfigurationPass())->process($container);
        (new ResolveInstanceofConditionalsPass())->process($container);

        return $container;
    }
}
