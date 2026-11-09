<?php

declare(strict_types=1);

namespace App\Classes\Application\Query\Handler;

use App\Classes\Application\Query\Command\GetClassPeriodFormCommand;
use App\Classes\Application\Service\ClassesScope;
use App\Classes\Application\Transformer\ClassPeriodTransformer;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;

/** @implements QueryHandlerInterface<GetClassPeriodFormCommand> */
#[AsQueryHandler(GetClassPeriodFormCommand::class)]
final class GetClassPeriodFormHandler implements QueryHandlerInterface
{
    public function __construct(private readonly ClassesScope $scope)
    {
    }

    /** @param GetClassPeriodFormCommand $command */
    public function __invoke(Query $command): Result
    {
        $school = $this->scope->school($command->schoolId);
        $schoolYear = $this->scope->currentYear();
        $period = null !== $command->periodId ? $this->scope->period($school, $schoolYear, $command->periodId) : null;

        return Result::success([
            'period' => null !== $period ? ClassPeriodTransformer::toArray($period) : null,
            'schoolYear' => ['name' => $schoolYear->getName()],
            'refusal' => $this->scope->blockedReason($school),
        ]);
    }
}
