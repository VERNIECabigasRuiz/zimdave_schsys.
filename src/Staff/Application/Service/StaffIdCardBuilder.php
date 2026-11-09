<?php

declare(strict_types=1);

namespace App\Staff\Application\Service;

use App\Access\Domain\Model\User;
use App\Learners\Domain\Model\SexAtBirth;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Model\PhilippineDivisions;
use App\Schools\Domain\Model\School;
use App\Staff\Domain\Model\PersonalDataSheet;
use App\Staff\Domain\Model\Repository\PersonalDataSheetRepositoryInterface;
use App\Staff\Domain\Model\Repository\ServiceRecordEntryRepositoryInterface;
use App\Staff\Domain\Model\Repository\StaffIdDetailsRepositoryInterface;
use App\Staff\Domain\Model\StaffIdDetails;
use App\Staff\Domain\Service\StaffId\StaffIdCard;
use App\Staff\Domain\Service\StaffId\StaffIdImagesInterface;

/**
 * Gathers a teacher's or principal's ID: name and numbers from the PDS, position from the service record's Present
 * row, and the head who signs — the school's principal for a teacher, the division's Superintendent for a principal.
 */
class StaffIdCardBuilder
{
    public const PRINCIPAL_FALLBACK = 'School Principal';

    public function __construct(
        private readonly PersonalDataSheetRepositoryInterface $sheets,
        private readonly ServiceRecordEntryRepositoryInterface $entries,
        private readonly StaffIdDetailsRepositoryInterface $details,
        private readonly SchoolDirectoryInterface $schools,
        private readonly StaffIdImagesInterface $images,
    ) {
    }

    /** The person's card; $details, when given, replaces their saved ID details (what they just typed). */
    public function build(User $person, School $school, ?StaffIdDetails $details = null): StaffIdCard
    {
        $sheet = $this->sheets->findFor($person);
        $details ??= $this->detailsOf($person);
        [$fullName, $surname, $givenNames] = $this->names($person);
        [$headName, $headPosition, $headSignature] = $this->head($person, $school);
        $website = $school->getFacebookUrl();
        $website = null === $website
            ? $school->getEmail()
            : preg_replace('#^https?://(www\.)?#i', '', rtrim($website, '/'));

        return new StaffIdCard(
            fullName: $fullName,
            surname: $surname,
            givenNames: $givenNames,
            callName: $details->getCallName() ?? $this->callName($sheet, $person),
            position: $this->entries->findCurrent($person)?->getDesignation(),
            employeeNo: $sheet?->agencyEmployeeNo,
            tin: $sheet?->tin,
            philhealthNo: $sheet?->philhealthNo,
            pagibigNo: $sheet?->pagibigNo,
            bloodType: $sheet?->bloodType,
            gsisPolicyNo: $details->getGsisPolicyNo(),
            emergencyName: $details->getEmergencyName(),
            emergencyContact: $details->getEmergencyContact(),
            region: $school->getRegion(),
            regionLabel: mb_strtoupper(str_replace('–', '-', PhilippineDivisions::label($school->getRegion()))),
            division: $school->getDivision(),
            schoolName: $school->getName(),
            schoolAddress: $school->getAddress(),
            website: $website,
            photo: $this->images->photo($person->getPhotoPath()),
            logo: $this->images->logo($school->getLogoPath()),
            seal: $this->images->seal(),
            headName: $headName,
            headPosition: $headPosition,
            headSignature: $headSignature,
        );
    }

    /** The person's saved ID details, or new empty ones (not saved). */
    public function detailsOf(User $person): StaffIdDetails
    {
        return $this->details->findFor($person) ?? new StaffIdDetails($person);
    }

    /** @return list<'photo'|'position'> what stops the ID from printing: a card without either is useless */
    public function missing(User $person): array
    {
        $missing = [];
        if (null === $person->getPhotoPath()) {
            $missing[] = 'photo';
        }
        if (null === $this->entries->findCurrent($person)?->getDesignation()) {
            $missing[] = 'position';
        }

        return $missing;
    }

    public function canPrint(User $person): bool
    {
        return [] === $this->missing($person);
    }

    /** @return array{0: string, 1: string, 2: string} full name, surname, given names; from the PDS, else the account */
    private function names(User $person): array
    {
        $sheet = $this->sheets->findFor($person);
        $surname = trim((string) $sheet?->surname);
        if (null === $sheet || '' === $surname) {
            $full = mb_strtoupper(trim($person->getFullName()));
            $words = preg_split('/\s+/', $full) ?: [$full];
            $last = (string) array_pop($words);

            return [$full, $last, [] === $words ? $last : implode(' ', $words)];
        }

        $middle = trim((string) $sheet->middleName);
        $initial = '' === $middle || 'N/A' === mb_strtoupper($middle) ? '' : mb_substr($middle, 0, 1) . '.';
        $first = trim($sheet->firstName . ' ' . $initial);
        $extension = trim((string) $sheet->nameExtension);
        $extension = 'N/A' === mb_strtoupper($extension) ? '' : $extension;

        return [
            mb_strtoupper(trim($first . ' ' . $surname . ' ' . $extension)),
            mb_strtoupper($surname),
            mb_strtoupper(trim($first . ' ' . $extension)),
        ];
    }

    /** "Sir JUAN" / "Ma'am ANA" by the PDS sex; just the first name when it isn't filled in. */
    private function callName(?PersonalDataSheet $sheet, User $person): string
    {
        $first = trim((string) $sheet?->firstName);
        if ('' === $first) {
            $first = explode(' ', trim($person->getFullName()))[0];
        }
        $first = mb_strtoupper($first);

        return match ($sheet?->sexAtBirth) {
            SexAtBirth::Male => 'Sir ' . $first,
            SexAtBirth::Female => "Ma'am " . $first,
            default => $first,
        };
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string} name, position, signature of whoever signs this person's ID */
    private function head(User $person, School $school): array
    {
        $schoolYear = $this->schools->currentYear();
        $assignment = null === $schoolYear ? null : $this->schools->assignmentOf($person, $schoolYear);

        if (User::ROLE_PRINCIPAL === $assignment?->getRole()) {
            $division = $this->schools->divisionOf($school);
            if (null === $division) {
                return [null, null, null];
            }
            $sdsName = $division->getSdsName();

            return [
                null === $sdsName ? null : mb_strtoupper($sdsName),
                $division->getSdsPosition(),
                $this->images->signature($division->getSignaturePath()),
            ];
        }

        $principal = null === $schoolYear ? null : $this->schools->principalOf($school, $schoolYear);
        if (null === $principal) {
            return [null, null, null];
        }

        return [
            mb_strtoupper($principal->getFullName()),
            $this->entries->findCurrent($principal)?->getDesignation() ?? self::PRINCIPAL_FALLBACK,
            // a signature left by an earlier principal must not print under the new one's name
            $school->getIdSignatureBy() === $principal ? $this->images->signature($school->getIdSignaturePath()) : null,
        ];
    }
}
