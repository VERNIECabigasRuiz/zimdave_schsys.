<?php

declare(strict_types=1);

namespace App\Learners\Application\Service;

use App\Access\Domain\Model\User;
use App\Learners\Domain\Model\Enrollment;
use App\Learners\Domain\Model\Learner;

/** The learner a change acts on, loaded after its locks: who acts, from which page, and the current enrollment. */
final readonly class LearnerTarget
{
    public function __construct(
        public User $actor,
        public LearnerScope $page,
        public Learner $learner,
        public ?Enrollment $current,
    ) {
    }

    /** The current enrollment of a learner the page manages (never null once the page covers it). */
    public function enrollment(): Enrollment
    {
        \assert(null !== $this->current);

        return $this->current;
    }
}
