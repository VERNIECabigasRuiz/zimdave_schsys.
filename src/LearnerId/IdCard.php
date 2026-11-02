<?php

namespace App\LearnerId;

/** Everything one learner's ID card prints; images are data URIs, null when there is none. */
final readonly class IdCard
{
    public function __construct(
        public string $schoolName,
        public string $schoolId,
        public string $region,
        public string $division,
        public ?string $schoolAddress,
        public ?string $schoolContact,
        public ?string $logo,
        public ?string $seal,
        public string $name,
        public string $lrn,
        public string $gradeSection,
        public ?string $photo,
        public ?string $emergencyLabel,
        public ?string $emergencyName,
        public ?string $emergencyNumber,
        public ?string $address,
        public string $qr,
        public ?string $schoolHead,
        public string $schoolYear,
        public ?\DateTimeImmutable $validUntil,
    ) {
    }
}
