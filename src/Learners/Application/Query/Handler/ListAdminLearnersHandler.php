<?php

declare(strict_types=1);

namespace App\Learners\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Learners\Application\Query\Command\ListAdminLearnersCommand;
use App\Learners\Application\Transformer\EnrollmentTransformer;
use App\Learners\Application\Transformer\LearnerTransformer;
use App\Learners\Domain\Model\Repository\EnrollmentRepositoryInterface;
use App\Learners\Domain\Model\Repository\LearnerRepositoryInterface;
use App\Schools\Application\Api\SchoolDirectoryInterface;

/** @implements QueryHandlerInterface<ListAdminLearnersCommand> */
#[AsQueryHandler(ListAdminLearnersCommand::class)]
final class ListAdminLearnersHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly LearnerRepositoryInterface $learners,
        private readonly EnrollmentRepositoryInterface $enrollments,
        private readonly SchoolDirectoryInterface $schools,
    ) {
    }

    /** @param ListAdminLearnersCommand $command */
    public function __invoke(Query $command): Result
    {
        $query = trim($command->query);
        $schoolYear = $this->schools->currentYear();

        $rows = [];
        if ('' !== $query) {
            foreach ($this->learners->searchByLrnOrName($query, ListAdminLearnersCommand::MAX_RESULTS) as $learner) {
                $current = null !== $schoolYear ? $this->enrollments->findCurrent($learner, $schoolYear) : null;
                // not enrolled this year: the most recent school still gives the admin a way in
                $enrollment = $current ?? ($this->enrollments->findHistory($learner)[0] ?? null);
                $rows[] = [
                    'learner' => LearnerTransformer::toArray($learner),
                    'current' => null !== $current ? EnrollmentTransformer::toArray($current) : null,
                    'enrollment' => null !== $enrollment ? EnrollmentTransformer::toArray($enrollment) : null,
                ];
            }
        }

        return Result::success([
            'query' => $query,
            'rows' => $rows,
            'schoolYear' => null !== $schoolYear ? ['name' => $schoolYear->getName()] : null,
        ]);
    }
}
