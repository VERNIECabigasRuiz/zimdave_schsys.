<?php

declare(strict_types=1);

namespace App\Grading\Domain\Model\Repository;

use App\Grading\Domain\Model\CardSlipComment;
use App\Schools\Domain\Model\GradingPeriod;

interface CardSlipCommentRepositoryInterface
{
    /**
     * @param list<int> $enrollmentIds
     *
     * @return array<int, CardSlipComment> keyed by enrollment id
     */
    public function findForEnrollments(array $enrollmentIds, GradingPeriod $period): array;
}
