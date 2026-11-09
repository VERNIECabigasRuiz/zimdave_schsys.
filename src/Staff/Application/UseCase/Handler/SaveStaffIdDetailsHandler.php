<?php

declare(strict_types=1);

namespace App\Staff\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Staff\Application\Service\StaffIdCardBuilder;
use App\Staff\Application\Service\StaffScope;
use App\Staff\Application\UseCase\Command\SaveStaffIdDetailsCommand;
use App\Staff\Domain\Model\Repository\StaffIdDetailsRepositoryInterface;

/** @implements UseCaseHandlerInterface<SaveStaffIdDetailsCommand> */
#[AsUseCaseHandler(SaveStaffIdDetailsCommand::class)]
final class SaveStaffIdDetailsHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly StaffScope $scope,
        private readonly StaffIdCardBuilder $builder,
        private readonly StaffIdDetailsRepositoryInterface $details,
    ) {
    }

    /** @param SaveStaffIdDetailsCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        $this->scope->idHolder($actor);
        $details = $this->builder->detailsOf($actor);
        $command->applyTo($details);
        $this->details->add($details);

        return Result::success(null);
    }
}
