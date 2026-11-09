<?php

declare(strict_types=1);

namespace App\Classes\Application\Transformer;

use App\Classes\Domain\Model\ShsTrack;
use App\Classes\Domain\Model\Subject;
use App\Schools\Domain\Model\GradeLevel;

/**
 * @phpstan-type SubjectData array{id: int, code: string, name: string, label: string, component: bool,
 *     components: list<array<string, mixed>>, hasComponents: bool, gradeLevel: ?GradeLevel, parentId: ?int,
 *     position: ?int, meetingsPerWeek: ?int, semester: ?int, track: ?ShsTrack, strand: ?string,
 *     componentGrades: list<GradeLevel>}
 */
final class SubjectTransformer
{
    /**
     * @param bool $withComponents false for a component: components have none of their own
     *
     * @return SubjectData
     */
    public static function toArray(Subject $subject, bool $withComponents = true): array
    {
        $components = $withComponents ? array_values($subject->getComponents()->toArray()) : [];

        return [
            'id' => (int) $subject->getId(),
            'code' => $subject->getCode(),
            'name' => $subject->getName(),
            'label' => $subject->getLabel(),
            'component' => $subject->isComponent(),
            'components' => array_map(static fn (Subject $component) => self::toArray($component, false), $components),
            'hasComponents' => [] !== $components,
            'gradeLevel' => $subject->getGradeLevel(),
            'parentId' => $subject->getParent()?->getId(),
            'position' => $subject->getPosition(),
            'meetingsPerWeek' => $subject->getMeetingsPerWeek(),
            'semester' => $subject->getSemester(),
            'track' => $subject->getTrack(),
            'strand' => $subject->getStrand(),
            'componentGrades' => array_values(array_filter(array_map(
                static fn (Subject $component) => $component->getGradeLevel(),
                $components,
            ))),
        ];
    }
}
