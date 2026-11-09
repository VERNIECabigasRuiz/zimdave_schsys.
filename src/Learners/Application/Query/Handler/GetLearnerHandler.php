<?php

declare(strict_types=1);

namespace App\Learners\Application\Query\Handler;

use App\Access\Domain\Model\User;
use App\Classes\Application\Api\SectionDirectoryInterface;
use App\Classes\Domain\Model\Section;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Learners\Application\Query\Command\GetLearnerCommand;
use App\Learners\Application\Service\LearnerPolicy;
use App\Learners\Application\Service\LearnersScope;
use App\Learners\Application\Transformer\EnrollmentTransformer;
use App\Learners\Application\Transformer\LearnerPageTransformer;
use App\Learners\Application\Transformer\LearnerTransformer;
use App\Learners\Application\Transformer\TransferRequestTransformer;
use App\Learners\Domain\Model\Enrollment;
use App\Learners\Domain\Model\Repository\EnrollmentRepositoryInterface;
use App\Learners\Domain\Model\Repository\TransferRequestRepositoryInterface;
use App\Learners\Domain\Model\TransferRequest;
use App\Learners\Domain\Service\Sf1\Sf1Remarks;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Model\School;

/** @implements QueryHandlerInterface<GetLearnerCommand> */
#[AsQueryHandler(GetLearnerCommand::class)]
final class GetLearnerHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly LearnersScope $scope,
        private readonly LearnerPolicy $policy,
        private readonly EnrollmentRepositoryInterface $enrollments,
        private readonly TransferRequestRepositoryInterface $transfers,
        private readonly SchoolDirectoryInterface $schools,
        private readonly SectionDirectoryInterface $sections,
        private readonly Sf1Remarks $remarks,
    ) {
    }

    /** @param GetLearnerCommand $command */
    public function __invoke(Query $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        $page = $this->scope->page($command->schoolId, $command->fixedSectionId);
        [$learner, $current] = $this->scope->learner($page, $command->learnerId, $actor, true);
        $here = $page->covers($current);
        $schoolPage = null === $page->fixedSection;
        $isAdmin = \in_array(User::ROLE_ADMIN, $actor->getRoles(), true);
        $canMove = $here && $schoolPage && $this->policy->can($actor, LearnerPolicy::MOVE, $learner);
        $canTransfer = $here && $schoolPage && $isAdmin;
        $toSchool = $canTransfer && null !== $command->toSchoolId
            ? $this->schools->findSchool($command->toSchoolId)
            : null;
        if (null !== $toSchool && ($toSchool->getId() === $page->school->getId() || !$toSchool->isActive())) {
            $toSchool = null;
        }
        $canEdit = $this->policy->can($actor, LearnerPolicy::EDIT, $learner);

        return Result::success(LearnerPageTransformer::toArray($page) + [
            'learner' => LearnerTransformer::toArray($learner),
            'current' => null !== $current ? EnrollmentTransformer::toArray($current) : null,
            'history' => array_map(
                static fn (Enrollment $enrollment) => EnrollmentTransformer::toArray($enrollment),
                $this->enrollments->findHistory($learner),
            ),
            'transfers' => array_map(
                static fn (TransferRequest $transfer) => TransferRequestTransformer::toArray($transfer),
                $this->transfers->findForLearner($learner),
            ),
            'remarksText' => null !== $current ? $this->remarks->textFor($current) : null,
            'moveSections' => $canMove && null !== $current ? array_map(
                EnrollmentTransformer::section(...),
                array_values(array_filter(
                    $this->scope->sections($page),
                    static fn (Section $s) => $s !== $current->getSection(),
                )),
            ) : [],
            'transferSchools' => $canTransfer ? array_map(
                EnrollmentTransformer::school(...),
                array_values(array_filter(
                    $this->schools->allSchools(),
                    static fn (School $s) => $s->isActive() && $s->getId() !== $page->school->getId(),
                )),
            ) : [],
            'transferToSchool' => null !== $toSchool ? EnrollmentTransformer::school($toSchool) : null,
            'transferSections' => null !== $toSchool
                ? array_map(
                    EnrollmentTransformer::section(...),
                    $this->sections->sectionsOf($toSchool, $page->schoolYear),
                )
                : [],
            'can' => [
                'edit' => $canEdit,
                'record' => $here && $canEdit,
                'reset_password' => $this->policy->can($actor, LearnerPolicy::RESET_PASSWORD, $learner),
                'move' => $canMove,
                'unenroll' => $here && $this->policy->can($actor, LearnerPolicy::UNENROLL, $learner),
            ],
        ]);
    }
}
