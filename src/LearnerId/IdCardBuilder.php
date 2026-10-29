<?php

namespace App\LearnerId;

use App\Entity\Enrollment;
use App\Entity\Learner;
use App\Entity\Section;
use App\Repository\EnrollmentRepository;
use App\Repository\SchoolAssignmentRepository;
use App\Service\ImageProcessor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Gathers what learners' ID cards print. */
class IdCardBuilder
{
    private const SEAL = '/assets/images/deped-seal.png';

    public function __construct(
        private readonly EnrollmentRepository $enrollments,
        private readonly SchoolAssignmentRepository $assignments,
        private readonly ImageProcessor $images,
        private readonly QrCode $qrCode,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /** @return list<IdCard> in class-list order */
    public function forSection(Section $section): array
    {
        $shared = $this->shared($section);

        return array_map(fn (Enrollment $e) => $this->card($e, $shared), $this->enrollments->findClassList($section));
    }

    public function forEnrollment(Enrollment $enrollment): IdCard
    {
        return $this->card($enrollment, $this->shared($enrollment->getSection()));
    }

    /** @return array{logo: ?string, seal: ?string, head: ?string} what every card of the section shares */
    private function shared(Section $section): array
    {
        $principal = $this->assignments->findPrincipal($section->getSchool(), $section->getSchoolYear())?->getUser();
        $logo = $section->getSchool()->getLogoPath();

        return [
            'logo' => null !== $logo ? $this->webpDataUri($this->images->absolutePath($logo)) : null,
            'seal' => is_file($this->projectDir.self::SEAL) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($this->projectDir.self::SEAL)) : null,
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
        $address = implode(', ', array_filter([$learner->getAddressBarangay(), $learner->getAddressCity(), $learner->getAddressProvince()]));
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
            gradeSection: ($section->getGradeLevel()?->label() ?? '').' – '.mb_strtoupper($section->getName()),
            photo: null !== $photo ? $this->webpDataUri($this->images->absolutePath($photo)) : null,
            emergencyLabel: $emergencyLabel,
            emergencyName: $emergencyName,
            emergencyNumber: $learner->getContactNumber(),
            address: '' === $address ? null : $address,
            qr: $this->qrCode->png($learner->getLrn()),
            schoolHead: $shared['head'],
            schoolYear: $startYear.'–'.($startYear + 1),
            validUntil: $schoolYear->getEndDate(),
        );
    }

    /** "GERALD JAMES M. ARNOL", "REMUEL B. BAÑEZ JR" */
    private function displayName(Learner $learner): string
    {
        $middle = $learner->getMiddleName();

        return mb_strtoupper(implode(' ', array_filter([
            $learner->getFirstName(),
            null !== $middle && '' !== $middle ? mb_substr($middle, 0, 1).'.' : null,
            $learner->getLastName(),
            $learner->getExtension(),
        ])));
    }

    /** @return array{0: ?string, 1: ?string} label and name: the guardian, else the mother, else the father */
    private function emergencyContact(Learner $learner): array
    {
        if (null !== $learner->getGuardianName()) {
            $relationship = $learner->getGuardianRelationship();

            return [null !== $relationship ? 'Guardian ('.$relationship.')' : 'Guardian', $learner->getGuardianName()];
        }
        if (null !== $mother = $learner->getMotherSf1Name()) {
            return ['Mother', $mother];
        }
        if (null !== $father = $learner->getFatherSf1Name()) {
            return ['Father', $father];
        }

        return [null, null];
    }

    /** A stored .webp as an inline PNG (Dompdf may not load files, and reads PNG everywhere) */
    private function webpDataUri(string $file): ?string
    {
        $image = is_file($file) ? @imagecreatefromwebp($file) : false;
        if (!$image instanceof \GdImage) {
            return null;
        }

        ob_start();
        imagepng($image);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }
}
