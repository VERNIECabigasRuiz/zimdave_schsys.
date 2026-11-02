<?php

namespace App\Sf1;

use App\Entity\Enrollment;
use App\Entity\TransferRequest;
use App\Repository\TransferRequestRepository;

/** The REMARKS column of SF1: the remark set by hand, else "T/I" for a learner who came by an approved transfer. */
class Sf1Remarks
{
    public function __construct(private readonly TransferRequestRepository $transfers)
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
        return \sprintf('T/I %s %s', $transfer->getFromSchool()->getName(), $transfer->getDecidedAt()?->format('Y-m-d'));
    }
}
