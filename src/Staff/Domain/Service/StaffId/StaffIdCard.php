<?php

declare(strict_types=1);

namespace App\Staff\Domain\Service\StaffId;

/** Everything printed on a teacher's or principal's ID. Images are data URIs; a null prints as a blank line. */
final readonly class StaffIdCard
{
    public function __construct(
        /** "JUAN A. DELA CRUZ" */
        public string $fullName,
        /** "DELA CRUZ" */
        public string $surname,
        /** "JUAN A." (with the extension: "JUAN A. JR.") */
        public string $givenNames,
        /** the big name on the Big ID: "Sir JUAN", "Ma'am ANA", or what the person typed */
        public string $callName,
        public ?string $position,
        public ?string $employeeNo,
        public ?string $tin,
        public ?string $philhealthNo,
        public ?string $pagibigNo,
        public ?string $bloodType,
        public ?string $gsisPolicyNo,
        public ?string $emergencyName,
        public ?string $emergencyContact,
        public string $region,
        /** "REGION VII - CENTRAL VISAYAS" */
        public string $regionLabel,
        public string $division,
        public string $schoolName,
        public ?string $schoolAddress,
        /** the school's Facebook page without https://, else its email */
        public ?string $website,
        public ?string $photo,
        public ?string $logo,
        public ?string $seal,
        /** upper-case */
        public ?string $headName,
        public ?string $headPosition,
        public ?string $headSignature,
    ) {
    }
}
