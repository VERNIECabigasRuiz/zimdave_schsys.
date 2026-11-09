<?php

declare(strict_types=1);

namespace App\Classes\Application\Transformer;

use App\Classes\Domain\Model\Section;
use App\Classes\Domain\Model\ShsTrack;
use App\Schools\Domain\Model\GradeLevel;

/**
 * @phpstan-type SectionData array{id: ?int, name: string, label: string, gradeLevel: ?GradeLevel,
 *     track: ?ShsTrack, strand: ?string, adviser: ?array{id: int, fullName: string, initials: string},
 *     schoolYear: array{name: string}}
 */
final class SectionTransformer
{
    /** @return SectionData */
    public static function toArray(Section $section): array
    {
        $adviser = $section->getAdviser();

        return [
            'id' => $section->getId(),
            'name' => $section->getName(),
            'label' => $section->getLabel(),
            'gradeLevel' => $section->getGradeLevel(),
            'track' => $section->getTrack(),
            'strand' => $section->getStrand(),
            'adviser' => null !== $adviser ? [
                'id' => (int) $adviser->getId(),
                'fullName' => $adviser->getFullName(),
                'initials' => $adviser->getInitials(),
            ] : null,
            'schoolYear' => ['name' => $section->getSchoolYear()->getName()],
        ];
    }
}
