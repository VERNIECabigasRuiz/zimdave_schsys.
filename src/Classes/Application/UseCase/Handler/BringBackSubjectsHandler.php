<?php

declare(strict_types=1);

namespace App\Classes\Application\UseCase\Handler;

use App\Classes\Application\Service\ClassesScope;
use App\Classes\Application\Service\Subject\SubjectScopeResolver;
use App\Classes\Application\Service\SubjectManager;
use App\Classes\Application\UseCase\Command\BringBackSubjectsCommand;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;

/** @implements UseCaseHandlerInterface<BringBackSubjectsCommand> */
#[AsUseCaseHandler(BringBackSubjectsCommand::class)]
final class BringBackSubjectsHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly SubjectScopeResolver $resolver,
        private readonly ClassesScope $classes,
        private readonly SubjectManager $manager,
    ) {
    }

    /** @param BringBackSubjectsCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $scope = $this->resolver->resolve($command->scope);
        $previous = $this->classes->previousYear($scope->schoolYear);

        return Result::success([
            'count' => $this->manager->bringBack($scope->school, $previous, $scope->schoolYear, $scope->grades),
            'from' => $previous->getName(),
        ]);
    }
}
