<?php

declare(strict_types=1);

namespace App\Learners\Application\Service;

use App\Learners\Domain\Model\Enrollment;
use App\Learners\Domain\Model\Learner;

final readonly class LookupResult
{
    public function __construct(
        public LookupKind $kind,
        public ?Learner $learner = null,
        public ?Enrollment $enrollment = null,
    ) {
    }
}
