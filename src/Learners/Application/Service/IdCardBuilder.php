<?php

declare(strict_types=1);

namespace App\Learners\Application\Service;

use App\Classes\Domain\Model\Section;
use App\Learners\Domain\Model\Enrollment;
use App\Learners\Domain\Model\Learner;
use App\Learners\Domain\Model\Repository\EnrollmentRepositoryInterface;
use App\Learners\Domain\Model\Sf1Remark;
use App\Learners\Domain\Service\LearnerId\IdCard;
use App\Learners\Domain\Service\LearnerId\IdCardImagesInterface;
use App\Schools\Application\Api\SchoolDirectoryInterface;

/** Gathers what learners' ID cards print. */
class IdCardBuilder
{
    public function __construct(
        private readonly EnrollmentRepositoryInterface $enrollments,
        private readonly SchoolDirectoryInterface $schools,
        private readonly IdCardImagesInterface $images,
    ) {
    }

    /** @return list<IdCard> in class-list order, leaving out learners who dropped out */
    public function forSection(Section $section): array
    {
        $shared = $this->shared($section);
        $enrolled = array_filter(
            $this->enrollments->findClassList($section),
            static fn (Enrollment $e) => Sf1Remark::Dropped !== $e->getRemarkCode(),
        );

        return array_values(array_map(fn (Enrollment $e) => $this->card($e, $shared), $enrolled));
    }

    public function forEnrollment(Enrollment $enrollment): IdCard
    {
        return $this->card($enrollment, $this->shared($enrollment->getSection()));
    }

    /** @return array{logo: ?string, seal: ?string, head: ?string} what every card of the section shares */
    private function shared(Section $section): array
    {
        $principal = $this->schools->principalOf($section->getSchool(), $section->getSchoolYear());
        $logo = $section->getSchool()->getLogoPath();

        return [
            'logo' => null !== $logo ? $this->images->logo($logo) : null,
            'seal' => $this->images->seal(),
            'head' => null !== $principal ? mb_strtoupper($principal->getFullName()) : null,
        ];
    }

    /** @param array{logo: ?string, seal: ?string, head: ?string} $shared */
    private function card(Enrollment $enrollment, array $shared): IdCard
    {
        $learner = $enrollment->getLearner();
        $section = $enrollment->getSection();
        $school = $section->getSchool();
        $schoolYear = $section->getSchoolYear();
        $startYear = (int) ($schoolYear->getStartDate()?->format('Y') ?? substr($schoolYear->getName(), 0, 4));
        [$emergencyLabel, $emergencyName] = $this->emergencyContact($learner);
        $address = implode(', ', array_filter(
            [$learner->getAddressBarangay(), $learner->getAddressCity(), $learner->getAddressProvince()],
        ));
        $photo = $learner->getUser()->getPhotoPath();

        return new IdCard(
            schoolName: $school->getName(),
            schoolId: $school->getSchoolId(),
            region: $school->getRegion(),
            division: $school->getDivision(),
            schoolAddress: $school->getAddress(),
            schoolContact: $school->getContactNumber(),
            logo: $shared['logo'],
            seal: $shared['seal'],
            name: $this->displayName($learner),
            lrn: $learner->getLrn(),
            gradeSection: ($section->getGradeLevel()?->label() ?? '') . ' – ' . mb_strtoupper($section->getName()),
            photo: null !== $photo ? $this->images->photo($photo) : null,
            emergencyLabel: $emergencyLabel,
            emergencyName: $emergencyName,
            emergencyNumber: $learner->getContactNumber(),
            address: '' === $address ? null : $address,
            qr: $this->images->qr($learner->getLrn()),
            schoolHead: $shared['head'],
            schoolYear: $startYear . '–' . ($startYear + 1),
            validUntil: $schoolYear->getEndDate(),
        );
    }

    /** "GERALD JAMES M. ARNOL", "REMUEL B. BAÑEZ JR" */
    private function displayName(Learner $learner): string
    {
        $middle = $learner->getMiddleName();

        return mb_strtoupper(implode(' ', array_filter([
            $learner->getFirstName(),
            null !== $middle && '' !== $middle ? mb_substr($middle, 0, 1) . '.' : null,
            $learner->getLastName(),
            $learner->getExtension(),
        ])));
    }

    /** @return array{0: ?string, 1: ?string} label and name: the guardian, else the mother, else the father */
    private function emergencyContact(Learner $learner): array
    {
        if (null !== $learner->getGuardianName()) {
            $relationship = $learner->getGuardianRelationship();

            $label = null !== $relationship ? 'Guardian (' . $relationship . ')' : 'Guardian';

            return [$label, $learner->getGuardianName()];
        }
        if (null !== $mother = $learner->getMotherSf1Name()) {
            return ['Mother', $mother];
        }
        if (null !== $father = $learner->getFatherSf1Name()) {
            return ['Father', $father];
        }

        return [null, null];
    }
}
