<?php

declare(strict_types=1);

namespace App\Schools\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Domain\Service\Clock\SchoolClockInterface;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Schools\Application\Query\Command\GetSchoolProfileCommand;
use App\Schools\Application\Service\PrincipalSchoolFinder;
use App\Schools\Application\Transformer\SchoolTransformer;
use App\Schools\Application\Transformer\SchoolYearTransformer;

/** @implements QueryHandlerInterface<GetSchoolProfileCommand> */
#[AsQueryHandler(GetSchoolProfileCommand::class)]
final class GetSchoolProfileHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly PrincipalSchoolFinder $finder,
        private readonly SchoolClockInterface $clock,
    ) {
    }

    /** @param GetSchoolProfileCommand $command */
    public function __invoke(Query $command): Result
    {
        [, $schoolYear, $school] = $this->finder->find($command->userId);

        return Result::success([
            'school' => null !== $school ? SchoolTransformer::toArray($school, $this->clock->now()) : null,
            'schoolYear' => null !== $schoolYear ? SchoolYearTransformer::toArray($schoolYear) : null,
        ]);
    }
}
