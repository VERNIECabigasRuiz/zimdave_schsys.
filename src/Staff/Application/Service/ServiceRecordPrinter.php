<?php

declare(strict_types=1);

namespace App\Staff\Application\Service;

use App\Access\Domain\Model\User;
use App\Core\Domain\Service\Clock\SchoolClockInterface;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Model\School;
use App\Staff\Domain\Model\PersonalDataSheet;
use App\Staff\Domain\Model\Repository\PersonalDataSheetRepositoryInterface;
use App\Staff\Domain\Model\Repository\ServiceRecordEntryRepositoryInterface;
use App\Staff\Domain\Model\Repository\ServiceRecordRepositoryInterface;
use App\Staff\Domain\Model\ServiceRecord;
use App\Staff\Domain\Service\ServiceRecord\ServiceRecordPrintout;

/**
 * Gathers a service record for printing: header from the PDS, the rows, and the school's signatories
 * ("Certified correct" falls back to the school's principal).
 */
class ServiceRecordPrinter
{
    /** PDS fields the record prints, by label. */
    private const PDS_FIELDS = [
        'Surname' => 'surname',
        'First name' => 'firstName',
        'Date of birth' => 'dateOfBirth',
        'Place of birth' => 'placeOfBirth',
        'Agency employee no.' => 'agencyEmployeeNo',
    ];

    public function __construct(
        private readonly PersonalDataSheetRepositoryInterface $sheets,
        private readonly ServiceRecordRepositoryInterface $records,
        private readonly ServiceRecordEntryRepositoryInterface $entries,
        private readonly SchoolDirectoryInterface $schools,
        private readonly SchoolClockInterface $clock,
    ) {
    }

    public function printout(User $employee, School $school): ServiceRecordPrintout
    {
        $certifiedName = $school->getSrCertifiedByName();
        $certifiedDesignation = $school->getSrCertifiedByDesignation();
        if (null === $certifiedName) {
            $schoolYear = $this->schools->currentYear();
            $principal = null === $schoolYear ? null : $this->schools->principalOf($school, $schoolYear);
            $certifiedName = null === $principal ? null : mb_strtoupper($principal->getFullName());
            $certifiedDesignation ??= null === $principal ? null : 'School Principal';
        }

        return new ServiceRecordPrintout(
            school: $school,
            sheet: $this->sheets->findFor($employee),
            record: $this->recordOf($employee),
            rows: $this->entries->findForEmployee($employee),
            certifiedName: $certifiedName,
            certifiedDesignation: $certifiedDesignation,
            printedOn: $this->clock->now(),
        );
    }

    /** The employee's record, or a new one that isn't saved yet. */
    public function recordOf(User $employee): ServiceRecord
    {
        return $this->records->findFor($employee) ?? new ServiceRecord($employee);
    }

    /** @return list<string> labels of the PDS fields the record needs that are empty */
    public function missing(?PersonalDataSheet $sheet): array
    {
        $missing = [];
        foreach (self::PDS_FIELDS as $label => $field) {
            $value = $sheet?->{$field};
            if (null === $value || '' === $value) {
                $missing[] = $label;
            }
        }

        return $missing;
    }
}
