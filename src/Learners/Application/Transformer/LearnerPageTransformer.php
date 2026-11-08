<?php

declare(strict_types=1);

namespace App\Learners\Application\Transformer;

use App\Learners\Application\Service\LearnerScope;

/**
 * What every Learners page shows of its scope.
 *
 * @phpstan-import-type SchoolData from EnrollmentTransformer
 * @phpstan-import-type SectionData from EnrollmentTransformer
 *
 * @phpstan-type PageData array{school: SchoolData, schoolYear: array{name: string}, fixedSection: ?SectionData}
 */
final class LearnerPageTransformer
{
    /** @return PageData */
    public static function toArray(LearnerScope $page): array
    {
        return [
            'school' => EnrollmentTransformer::school($page->school),
            'schoolYear' => ['name' => $page->schoolYear->getName()],
            'fixedSection' => null !== $page->fixedSection ? EnrollmentTransformer::section($page->fixedSection) : null,
        ];
    }
}
