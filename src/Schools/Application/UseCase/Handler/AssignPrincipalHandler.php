<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Handler;

use App\Access\Application\Api\AccountDirectoryInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Schools\Application\Service\PrincipalAssigner;
use App\Schools\Application\Service\SchoolYearResolver;
use App\Schools\Application\UseCase\Command\AssignPrincipalCommand;
use App\Schools\Domain\Exception\AssignmentException;
use App\Schools\Domain\Exception\SchoolNotFoundException;
use App\Schools\Domain\Model\Repository\SchoolRepositoryInterface;

/** @implements UseCaseHandlerInterface<AssignPrincipalCommand> */
#[AsUseCaseHandler(AssignPrincipalCommand::class)]
final class AssignPrincipalHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly SchoolRepositoryInterface $schools,
        private readonly SchoolYearResolver $schoolYears,
        private readonly AccountDirectoryInterface $accounts,
        private readonly PrincipalAssigner $assigner,
    ) {
    }

    /** @param AssignPrincipalCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $school = $this->schools->findById($command->schoolId) ?? throw new SchoolNotFoundException();
        $schoolYear = $this->schoolYears->resolve($command->schoolYearId);
        try {
            $principal = $this->accounts->findById((int) $command->principalId)
                ?? throw new AssignmentException('Only accounts with the Principal role can be assigned.');
            $this->assigner->assign($school, $schoolYear, $principal);
        } catch (AssignmentException $e) {
            throw $e->forSchoolYear($schoolYear->getId());
        }

        return Result::success([
            'principal' => $principal->getFullName(),
            'school' => $school->getName(),
            'schoolYear' => $schoolYear->getName(),
            'schoolYearId' => (int) $schoolYear->getId(),
        ]);
    }
}
