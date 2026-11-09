<?php

declare(strict_types=1);

namespace App\Grading\Domain\Model\Repository;

use App\Classes\Domain\Model\Subject;
use App\Grading\Domain\Model\Grade;
use App\Learners\Domain\Model\Enrollment;
use App\Schools\Domain\Model\GradingPeriod;
use App\Schools\Domain\Model\School;

interface GradeRepositoryInterface
{
    /**
     * The grades of these enrollments in the subject for the period. Looked up by enrollment, not by
     * section: an approved transfer moves the enrollment to the receiving section, and the grades go with it.
     *
     * @param list<int> $enrollmentIds
     *
     * @return array<int, Grade> keyed by enrollment id
     */
    public function findForEnrollments(array $enrollmentIds, Subject $subject, GradingPeriod $period): array;

    /**
     * findForEnrollments() as the grades are saved now, even when older copies were loaded earlier in the same request.
     *
     * @param list<int> $enrollmentIds
     *
     * @return array<int, Grade> keyed by enrollment id
     */
    public function findFreshForEnrollments(array $enrollmentIds, Subject $subject, GradingPeriod $period): array;

    /**
     * Every subject's grades of these enrollments for the period.
     *
     * @param list<int> $enrollmentIds
     *
     * @return array<int, array<int, Grade>> keyed by enrollment id, then subject id
     */
    public function findForEnrollmentsByPeriod(array $enrollmentIds, GradingPeriod $period): array;

    /** Grades of the period in any school, which deleting the period would delete. */
    public function countForPeriod(GradingPeriod $period): int;

    /** Grades of the enrollment in any subject and period. */
    public function countForEnrollment(Enrollment $enrollment): int;

    /** Grades entered in the school's sections for the period. */
    public function countForSchool(School $school, GradingPeriod $period): int;

    /** Learners with a grade in the subject or in one of its components. */
    public function countForSubject(Subject $subject): int;

    public function add(Grade $grade): void;

    public function remove(Grade $grade): void;
}
