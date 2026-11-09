<?php

declare(strict_types=1);

namespace App\Learners\Application\Transformer;

use App\Classes\Domain\Model\Section;
use App\Learners\Domain\Model\Enrollment;
use App\Learners\Domain\Model\LearningModality;
use App\Learners\Domain\Model\Sf1Remark;
use App\Schools\Domain\Model\School;

/**
 * An enrollment, and the schools and sections around it, as the pages show them.
 *
 * @phpstan-import-type LearnerData from LearnerTransformer
 *
 * @phpstan-type SchoolData array{id: ?int, name: string, plan: ?array{includesPrincipal: bool}}
 * @phpstan-type SectionData array{id: ?int, label: string, adviser: ?array{fullName: string}}
 * @phpstan-type EnrollmentData array{id: ?int, learner: LearnerData, school: SchoolData, section: SectionData,
 *     schoolYear: array{name: string}, learningModality: LearningModality, remarkCode: ?Sf1Remark,
 *     remarkDetails: ?string, remarkDate: ?\DateTimeImmutable}
 */
final class EnrollmentTransformer
{
    /** @return EnrollmentData */
    public static function toArray(Enrollment $enrollment): array
    {
        return [
            'id' => $enrollment->getId(),
            'learner' => LearnerTransformer::toArray($enrollment->getLearner()),
            'school' => self::school($enrollment->getSchool()),
            'section' => self::section($enrollment->getSection()),
            'schoolYear' => ['name' => $enrollment->getSchoolYear()->getName()],
            'learningModality' => $enrollment->getLearningModality(),
            'remarkCode' => $enrollment->getRemarkCode(),
            'remarkDetails' => $enrollment->getRemarkDetails(),
            'remarkDate' => $enrollment->getRemarkDate(),
        ];
    }

    /** @return SchoolData */
    public static function school(School $school): array
    {
        $plan = $school->getPlan();

        return [
            'id' => $school->getId(),
            'name' => $school->getName(),
            'plan' => null !== $plan ? ['includesPrincipal' => $plan->includesPrincipal()] : null,
        ];
    }

    /** @return SectionData */
    public static function section(Section $section): array
    {
        $adviser = $section->getAdviser();

        return [
            'id' => $section->getId(),
            'label' => $section->getLabel(),
            'adviser' => null !== $adviser ? ['fullName' => $adviser->getFullName()] : null,
        ];
    }
}
