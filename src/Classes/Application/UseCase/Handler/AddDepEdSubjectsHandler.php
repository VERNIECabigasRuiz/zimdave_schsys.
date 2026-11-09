<?php

declare(strict_types=1);

namespace App\Classes\Application\UseCase\Handler;

use App\Classes\Application\Service\Subject\SubjectScopeResolver;
use App\Classes\Application\Service\SubjectManager;
use App\Classes\Application\UseCase\Command\AddDepEdSubjectsCommand;
use App\Classes\Domain\Exception\SubjectNotFoundException;
use App\Classes\Domain\Service\Subject\DepEdSubjects;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Schools\Domain\Model\GradeLevel;

/** @implements UseCaseHandlerInterface<AddDepEdSubjectsCommand> */
#[AsUseCaseHandler(AddDepEdSubjectsCommand::class)]
final class AddDepEdSubjectsHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly SubjectScopeResolver $resolver,
        private readonly SubjectManager $manager,
    ) {
    }

    /** @param AddDepEdSubjectsCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $scope = $this->resolver->resolve($command->scope);
        $grade = GradeLevel::tryFrom($command->grade);
        if (null === $grade || !$scope->covers($grade) || !DepEdSubjects::hasPreset($grade)) {
            throw new SubjectNotFoundException('There is no DepEd subject list for this grade level here.');
        }

        return Result::success([
            'count' => $this->manager->addDepEdSet($scope->school, $scope->schoolYear, $grade),
            'grade' => $grade->label(),
        ]);
    }
}
