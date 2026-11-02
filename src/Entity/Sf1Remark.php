<?php

namespace App\Entity;

/** The indicators of SF1's REMARKS column; T/O is left out, a transferred learner leaves the section. */
enum Sf1Remark: string
{
    case TransferredIn = 'transferred_in';
    case Dropped = 'dropped';
    case LateEnrollment = 'late_enrollment';
    case Cct = 'cct';
    case BalikAral = 'balik_aral';
    case Sned = 'sned';
    case Accelerated = 'accelerated';

    public function code(): string
    {
        return match ($this) {
            self::TransferredIn => 'T/I',
            self::Dropped => 'DRP',
            self::LateEnrollment => 'LE',
            self::Cct => 'CCT',
            self::BalikAral => 'B/A',
            self::Sned => 'SNED',
            self::Accelerated => 'ACL',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::TransferredIn => 'Transferred In',
            self::Dropped => 'Dropped',
            self::LateEnrollment => 'Late Enrollment',
            self::Cct => 'CCT Recipient',
            self::BalikAral => 'Balik Aral',
            self::Sned => 'Special Needs Education',
            self::Accelerated => 'Accelerated',
        };
    }

    /** What to write in the details, per SF1's legend */
    public function requiredInfo(): string
    {
        return match ($this) {
            self::TransferredIn => 'Name of public (P) / private (PR) school & effectivity date',
            self::Dropped => 'Reason and effectivity date',
            self::LateEnrollment => 'Reason (enrollment beyond 1st Friday of SY)',
            self::Cct => 'CCT control/reference number & effectivity date',
            self::BalikAral => 'Name of school last attended & year',
            self::Sned => 'Specify',
            self::Accelerated => 'Specify level & effectivity date',
        };
    }
}
