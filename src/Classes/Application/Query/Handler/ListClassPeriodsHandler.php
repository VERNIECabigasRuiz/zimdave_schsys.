<?php

declare(strict_types=1);

namespace App\Classes\Application\Query\Handler;

use App\Classes\Application\Query\Command\ListClassPeriodsCommand;
use App\Classes\Application\Service\ClassesScope;
use App\Classes\Application\Transformer\ClassPeriodTransformer;
use App\Classes\Domain\Model\Repository\ClassPeriodRepositoryInterface;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Schools\Application\Api\SchoolDirectoryInterface;

/** @implements QueryHandlerInterface<ListClassPeriodsCommand> */
#[AsQueryHandler(ListClassPeriodsCommand::class)]
final class ListClassPeriodsHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly ClassesScope $scope,
        private readonly SchoolDirectoryInterface $schools,
        private readonly ClassPeriodRepositoryInterface $periods,
    ) {
    }

    /** @param ListClassPeriodsCommand $command */
    public function __invoke(Query $command): Result
    {
        $school = $this->scope->school($command->schoolId);
        $schoolYear = $this->schools->currentYear();
        $periods = null !== $schoolYear ? $this->periods->findForSchool($school, $schoolYear) : [];

        return Result::success([
            'schoolYear' => null !== $schoolYear ? ['name' => $schoolYear->getName()] : null,
            'periods' => array_map(ClassPeriodTransformer::toArray(...), $periods),
            'numbers' => $this->periods->numbering($periods),
            'refusal' => $this->scope->blockedReason($school),
        ]);
    }
}
