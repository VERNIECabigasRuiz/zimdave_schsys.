<?php

declare(strict_types=1);

namespace App\Learners\Domain\Service\Sf1;

use App\Learners\Domain\Model\Enrollment;
use App\Learners\Domain\Model\TransferRequest;
use App\Learners\Domain\Model\Repository\TransferRequestRepositoryInterface;

/** The REMARKS column of SF1: the remark set by hand, else "T/I" for a learner who came by an approved transfer. */
class Sf1Remarks
{
    public function __construct(private readonly TransferRequestRepositoryInterface $transfers)
    {
    }

    public function textFor(Enrollment $enrollment): ?string
    {
        return $enrollment->getManualRemarkText() ?? $this->automaticFor($enrollment);
    }

    /** "T/I Mandaue NHS 2026-06-16" */
    public function automaticFor(Enrollment $enrollment): ?string
    {
        $transfer = $this->transfers->findApprovedInto($enrollment);

        return null === $transfer ? null : self::transferIn($transfer);
    }

    /** "T/I Mandaue NHS 2026-06-16" */
    public static function transferIn(TransferRequest $transfer): string
    {
        return \sprintf(
            'T/I %s %s',
            $transfer->getFromSchool()->getName(),
            $transfer->getDecidedAt()?->format('Y-m-d'),
        );
    }
}
