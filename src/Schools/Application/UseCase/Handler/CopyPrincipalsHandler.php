<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Schools\Application\Service\PrincipalAssigner;
use App\Schools\Application\Service\SchoolYearResolver;
use App\Schools\Application\UseCase\Command\CopyPrincipalsCommand;
use App\Schools\Domain\Exception\AssignmentException;

/** @implements UseCaseHandlerInterface<CopyPrincipalsCommand> */
#[AsUseCaseHandler(CopyPrincipalsCommand::class)]
final class CopyPrincipalsHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly SchoolYearResolver $schoolYears,
        private readonly PrincipalAssigner $assigner,
    ) {
    }

    /** @param CopyPrincipalsCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $schoolYear = $this->schoolYears->resolve($command->schoolYearId);
        try {
            $copy = $this->assigner->copyFromPrevious($schoolYear);
        } catch (AssignmentException $e) {
            throw $e->forSchoolYear($schoolYear->getId());
        }

        return Result::success([
            'copied' => $copy->copied,
            'skipped' => $copy->skipped,
            'schoolYearId' => (int) $schoolYear->getId(),
        ]);
    }
}
