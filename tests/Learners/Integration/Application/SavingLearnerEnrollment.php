<?php

declare(strict_types=1);

namespace App\Tests\Learners\Integration\Application;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Section;
use App\Learners\Application\Service\LearnerEnrollment;
use App\Learners\Domain\Model\Enrollment;
use App\Learners\Domain\Model\Learner;
use Doctrine\ORM\EntityManagerInterface;

/** Calls the learner enrollment service as a use case would, saving after each call that succeeds. */
final class SavingLearnerEnrollment
{
    public function __construct(
        private readonly LearnerEnrollment $service,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function enrollNew(Learner $learner, Section $section, User $by): string
    {
        $password = $this->service->enrollNew($learner, $section, $by);
        $this->em->flush();

        return $password;
    }

    public function enrollExisting(Learner $learner, Section $section, User $by): void
    {
        $this->service->enrollExisting($learner, $section, $by);
        $this->em->flush();
    }

    public function update(Learner $learner, ?string $lrn = null): void
    {
        $this->service->update($learner, $lrn);
        $this->em->flush();
    }

    public function updatePhoto(Learner $learner, ?string $path): void
    {
        $this->service->updatePhoto($learner, $path);
        $this->em->flush();
    }

    public function move(Enrollment $enrollment, Section $to): void
    {
        $this->service->move($enrollment, $to);
        $this->em->flush();
    }

    public function unenroll(Enrollment $enrollment): void
    {
        $this->service->unenroll($enrollment);
        $this->em->flush();
    }

    public function resetPassword(Learner $learner): string
    {
        $password = $this->service->resetPassword($learner);
        $this->em->flush();

        return $password;
    }

    public function transferDirectly(Enrollment $enrollment, Section $to): void
    {
        $this->service->transferDirectly($enrollment, $to);
        $this->em->flush();
    }
}
