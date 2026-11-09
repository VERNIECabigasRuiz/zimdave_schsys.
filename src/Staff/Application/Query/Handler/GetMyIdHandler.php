<?php

declare(strict_types=1);

namespace App\Staff\Application\Query\Handler;

use App\Access\Domain\Model\User;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Staff\Application\Query\Command\GetMyIdCommand;
use App\Staff\Application\Service\StaffIdCardBuilder;
use App\Staff\Application\Service\StaffScope;
use App\Staff\Domain\Model\StaffIdDetails;

/** @implements QueryHandlerInterface<GetMyIdCommand> */
#[AsQueryHandler(GetMyIdCommand::class)]
final class GetMyIdHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly StaffScope $scope,
        private readonly StaffIdCardBuilder $builder,
        private readonly SchoolDirectoryInterface $schools,
    ) {
    }

    /** @param GetMyIdCommand $command */
    public function __invoke(Query $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        $assignment = $this->scope->idHolder($actor);
        $school = $assignment->getSchool();
        $isPrincipal = User::ROLE_PRINCIPAL === $assignment->getRole();
        $saved = $this->builder->detailsOf($actor);
        $typed = null;
        if (null !== $command->typed && null !== $saved->getId()) {
            // the card shows what was typed, but only over details saved before
            $typed = new StaffIdDetails($actor);
            $command->typed->applyTo($typed);
        }

        return Result::success([
            'card' => $this->builder->build($actor, $school, $typed),
            'details' => $saved,
            'missing' => $this->builder->missing($actor),
            'canPrint' => $this->builder->canPrint($actor),
            'isPrincipal' => $isPrincipal,
            'division' => $isPrincipal ? $this->schools->divisionOf($school) : null,
            'school' => $school,
        ]);
    }
}
