<?php

namespace App\Sf1;

/** One learner's line of SF1, as printed. */
final readonly class Sf1Row
{
    public function __construct(
        public string $lrn,
        public string $name,
        public string $sex,
        public string $birthDate,
        public ?int $age,
        public ?string $motherTongue,
        public ?string $ipGroup,
        public ?string $religion,
        public ?string $street,
        public ?string $barangay,
        public ?string $city,
        public ?string $province,
        public ?string $father,
        public ?string $mother,
        public ?string $guardianName,
        public ?string $guardianRelationship,
        public ?string $contactNumber,
        public string $learningModality,
        public ?string $remarks,
    ) {
    }
}
