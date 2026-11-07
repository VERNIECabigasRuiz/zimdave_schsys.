<?php

declare(strict_types=1);

namespace App\Classes\Application\Service\Subject;

use App\Classes\Application\Service\ClassesScope;
use App\Classes\Domain\Exception\ClassesAccessDeniedException;
use App\Classes\Domain\Exception\NoAdvisoryException;
use App\Classes\Domain\Exception\SubjectNotFoundException;
use App\Classes\Domain\Model\Repository\SectionRepositoryInterface;
use App\Classes\Domain\Model\Repository\SubjectRepositoryInterface;
use App\Classes\Domain\Model\Subject;
use App\Schools\Application\Api\SchoolDirectoryInterface;

/** Finds the scope of a Subjects page and the subjects it may change. */
class SubjectScopeResolver
{
    public function __construct(
        private readonly ClassesScope $scope,
        private readonly SchoolDirectoryInterface $schools,
        private readonly SectionRepositoryInterface $sections,
        private readonly SubjectRepositoryInterface $subjects,
    ) {
    }

    /**
     * A school's page covers the grades of its type in the current school year. A teacher's page covers
     * the grade of the section they advise, and only on a plan without a principal.
     *
     * @throws ClassesAccessDeniedException|NoAdvisoryException
     */
    public function resolve(SubjectScopeKey $key): SubjectScope
    {
        if (null !== $key->schoolId) {
            $school = $this->scope->school($key->schoolId);

            return new SubjectScope($school, $this->scope->currentYear(), $school->getType()?->gradeLevels() ?? []);
        }

        $teacher = $this->scope->account((int) $key->teacherId);
        $schoolYear = $this->schools->currentYear();
        if (null === $schoolYear) {
            throw new NoAdvisoryException(null, null);
        }
        $school = $this->scope->teacherSchool($teacher, $schoolYear);
        if (null !== $school && !ClassesScope::teachersOwnClasses($school)) {
            throw new ClassesAccessDeniedException('Your principal sets up the subjects of your school.');
        }
        $advisory = null !== $school ? $this->sections->findAdvisory($teacher, $schoolYear) : null;
        $grade = $advisory?->getGradeLevel();
        if (null === $school || null === $advisory || null === $grade || $advisory->getSchool() !== $school) {
            throw new NoAdvisoryException($school?->getName(), $schoolYear->getName());
        }

        return new SubjectScope($school, $schoolYear, [$grade], $advisory->getTrack(), $advisory->getStrand());
    }

    /** A subject this page manages: same school, its school year, a grade in scope. */
    public function subject(SubjectScope $scope, int $subjectId): Subject
    {
        $subject = $this->subjects->findById($subjectId);
        if (
            null === $subject
            || $subject->getSchool() !== $scope->school
            || $subject->getSchoolYear() !== $scope->schoolYear
            || !$scope->covers($subject->getGradeLevel())
        ) {
            throw new SubjectNotFoundException();
        }

        return $subject;
    }
}
