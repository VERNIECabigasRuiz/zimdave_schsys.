<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Schools\Application\Service\PrincipalAssigner;
use App\Schools\Application\Service\SchoolYearResolver;
use App\Schools\Application\UseCase\Command\RemovePrincipalCommand;
use App\Schools\Domain\Exception\SchoolNotFoundException;
use App\Schools\Domain\Model\Repository\SchoolRepositoryInterface;

/** @implements UseCaseHandlerInterface<RemovePrincipalCommand> */
#[AsUseCaseHandler(RemovePrincipalCommand::class)]
final class RemovePrincipalHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly SchoolRepositoryInterface $schools,
        private readonly SchoolYearResolver $schoolYears,
        private readonly PrincipalAssigner $assigner,
    ) {
    }

    /** @param RemovePrincipalCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $school = $this->schools->findById($command->schoolId) ?? throw new SchoolNotFoundException();
        $schoolYear = $this->schoolYears->resolve($command->schoolYearId);
        $this->assigner->remove($school, $schoolYear);

        return Result::success([
            'school' => $school->getName(),
            'schoolYear' => $schoolYear->getName(),
            'schoolYearId' => (int) $schoolYear->getId(),
        ]);
    }
}
