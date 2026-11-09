<?php

declare(strict_types=1);

namespace App\Learners\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Learners\Application\Query\Command\ListLearnersCommand;
use App\Learners\Application\Service\LearnerScope;
use App\Learners\Application\Service\LearnersScope;
use App\Learners\Application\Transformer\EnrollmentTransformer;
use App\Learners\Application\Transformer\LearnerPageTransformer;
use App\Learners\Domain\Model\Enrollment;
use App\Learners\Domain\Model\Repository\EnrollmentRepositoryInterface;
use App\Schools\Application\Api\SchoolDirectoryInterface;

/** @implements QueryHandlerInterface<ListLearnersCommand> */
#[AsQueryHandler(ListLearnersCommand::class)]
final class ListLearnersHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly LearnersScope $scope,
        private readonly SchoolDirectoryInterface $schools,
        private readonly EnrollmentRepositoryInterface $enrollments,
    ) {
    }

    /** @param ListLearnersCommand $command */
    public function __invoke(Query $command): Result
    {
        $school = $this->scope->school($command->schoolId);
        $schoolYear = $this->schools->currentYear();
        if (null === $schoolYear) {
            return Result::success(['school' => EnrollmentTransformer::school($school), 'schoolYear' => null]);
        }

        $page = new LearnerScope($school, $schoolYear);
        $section = null !== $command->sectionId ? $this->scope->section($page, $command->sectionId) : null;
        $number = max(1, $command->page);
        $found = $this->enrollments->search(
            $school,
            $schoolYear,
            $command->query,
            $section,
            $number,
            ListLearnersCommand::PER_PAGE,
        );

        return Result::success(LearnerPageTransformer::toArray($page) + [
            'enrollments' => array_map(
                static fn (Enrollment $enrollment) => EnrollmentTransformer::toArray($enrollment),
                $found['enrollments'],
            ),
            'page' => $number,
            'matches' => $found['matches'],
            'hasNext' => $number * ListLearnersCommand::PER_PAGE < $found['matches'],
            'sections' => array_map(EnrollmentTransformer::section(...), $this->scope->sections($page)),
            'count' => $this->enrollments->countForSchool($school, $schoolYear),
            'limit' => $school->getStudentLimit(),
            'section' => null !== $section ? EnrollmentTransformer::section($section) : null,
            'refusal' => $this->schools->blockedReason($school),
        ]);
    }
}
