<?php

declare(strict_types=1);

namespace App\Schools\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Domain\Service\Clock\SchoolClockInterface;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Schools\Application\Query\Command\GetSchoolYearFormCommand;
use App\Schools\Application\Transformer\SchoolYearTransformer;
use App\Schools\Domain\Exception\SchoolYearNotFoundException;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Schools\Domain\Model\SchoolYear;

/** @implements QueryHandlerInterface<GetSchoolYearFormCommand> */
#[AsQueryHandler(GetSchoolYearFormCommand::class)]
final class GetSchoolYearFormHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly SchoolYearRepositoryInterface $schoolYears,
        private readonly SchoolClockInterface $clock,
    ) {
    }

    /** @param GetSchoolYearFormCommand $command */
    public function __invoke(Query $command): Result
    {
        $schoolYear = null === $command->id
            ? $this->suggestNextYear()
            : $this->schoolYears->findById($command->id) ?? throw new SchoolYearNotFoundException();

        return Result::success(SchoolYearTransformer::toArray($schoolYear));
    }

    /** The school year after the latest one (June to April), not saved. */
    private function suggestNextYear(): SchoolYear
    {
        $latest = $this->schoolYears->findAllNewestFirst()[0] ?? null;
        $startYear = (int) ($latest?->getEndDate()?->format('Y') ?? $this->clock->now()->format('Y'));

        return (new SchoolYear())
            ->setName(\sprintf('%d-%d', $startYear, $startYear + 1))
            ->setStartDate(new \DateTimeImmutable(\sprintf('%d-06-01', $startYear)))
            ->setEndDate(new \DateTimeImmutable(\sprintf('%d-04-30', $startYear + 1)));
    }
}
