<?php

declare(strict_types=1);

namespace App\Tests\Grading\Integration\Application;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\TeachingLoad;
use App\Grading\Application\Service\GradeChangeManager;
use App\Grading\Domain\Model\GradeChangeRequest;
use App\Schools\Domain\Model\GradingPeriod;
use Doctrine\ORM\EntityManagerInterface;

/** Calls the change request manager as a use case would, saving after each call that succeeds. */
final class SavingGradeChangeManager
{
    public function __construct(
        private readonly GradeChangeManager $manager,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @param array<int, ?int> $values by enrollment id */
    public function request(
        TeachingLoad $load,
        GradingPeriod $period,
        array $values,
        string $reason,
        User $by,
    ): GradeChangeRequest {
        $request = $this->manager->request($load, $period, $values, $reason, $by);
        $this->em->flush();

        return $request;
    }

    public function approve(GradeChangeRequest $request, User $by): void
    {
        $this->manager->approve($request, $by);
        $this->em->flush();
    }

    public function decline(GradeChangeRequest $request, User $by, string $note): void
    {
        $this->manager->decline($request, $by, $note);
        $this->em->flush();
    }

    public function withdraw(GradeChangeRequest $request, User $by): void
    {
        $this->manager->withdraw($request, $by);
        $this->em->flush();
    }
}
