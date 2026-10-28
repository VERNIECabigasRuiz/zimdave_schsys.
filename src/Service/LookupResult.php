<?php

namespace App\Service;

use App\Entity\Enrollment;
use App\Entity\Learner;

final readonly class LookupResult
{
    public function __construct(
        public LookupKind $kind,
        public ?Learner $learner = null,
        public ?Enrollment $enrollment = null,
    ) {
    }
}
