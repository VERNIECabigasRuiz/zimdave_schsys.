<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Bus;

use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Registers classes marked #[AsUseCaseHandler] or #[AsQueryHandler] as Messenger
 * handlers on their bus.
 */
final class BusHandlerAutoconfiguration
{
    public static function register(ContainerBuilder $container): void
    {
        $container->registerAttributeForAutoconfiguration(
            AsUseCaseHandler::class,
            static function (ChildDefinition $definition, AsUseCaseHandler $attribute): void {
                $definition->addTag('messenger.message_handler', [
                    'bus' => 'usecase.bus',
                    'handles' => $attribute->useCaseClass,
                ]);
            },
        );

        $container->registerAttributeForAutoconfiguration(
            AsQueryHandler::class,
            static function (ChildDefinition $definition, AsQueryHandler $attribute): void {
                $definition->addTag('messenger.message_handler', [
                    'bus' => 'query.bus',
                    'handles' => $attribute->queryClass,
                ]);
            },
        );
    }
}
