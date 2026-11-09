<?php

namespace App\Tests;

use App\Core\Domain\Model\ActivityLog;
use App\LessonPlans\Domain\Model\LessonPlan;
use App\Announcements\Domain\Model\Announcement;
use App\Attendance\Domain\Model\AttendanceMark;
use App\Grading\Domain\Model\CardSlipComment;
use App\Classes\Domain\Model\ClassPeriod;
use App\Schools\Domain\Model\Division;
use App\Staff\Domain\Model\StaffIdDetails;
use App\Learners\Domain\Model\Enrollment;
use App\Grading\Domain\Model\Grade;
use App\Grading\Domain\Model\GradeChangeLine;
use App\Grading\Domain\Model\GradeChangeRequest;
use App\Schools\Domain\Model\GradeLevel;
use App\Grading\Domain\Model\GradeLock;
use App\Grading\Domain\Model\GradeOpening;
use App\Schools\Domain\Model\GradingPeriod;
use App\Grading\Domain\Model\GradeUnlock;
use App\Learners\Domain\Model\Learner;
use App\Nutrition\Domain\Model\NutritionAssessment;
use App\Nutrition\Domain\Model\NutritionMeasurement;
use App\Billing\Domain\Model\Plan;
use App\Attendance\Domain\Model\RollCall;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolAssignment;
use App\Classes\Domain\Model\ScheduleEntry;
use App\Classes\Domain\Model\Section;
use App\Staff\Domain\Model\ServiceRecord;
use App\Staff\Domain\Model\ServiceRecordEntry;
use App\Schools\Domain\Model\SchoolDayOff;
use App\Schools\Domain\Model\SchoolType;
use App\Schools\Domain\Model\SchoolYear;
use App\Learners\Domain\Model\SexAtBirth;
use App\Attendance\Domain\Model\Sf2Submission;
use App\Nutrition\Domain\Model\Sf8Submission;
use App\Classes\Domain\Model\Subject;
use App\Classes\Domain\Model\TeachingLoad;
use App\Learners\Domain\Model\TransferRequest;
use App\Access\Domain\Model\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds schools, school years and assignments for tests. Needs $this->em.
 *
 * @property EntityManagerInterface $em
 */
trait SchoolFixturesTrait
{
    protected function clearSchoolData(): void
    {
        foreach ([CardSlipComment::class, GradeChangeLine::class, GradeChangeRequest::class, GradeUnlock::class, GradeOpening::class, GradeLock::class, Grade::class, NutritionMeasurement::class, NutritionAssessment::class, Sf8Submission::class, AttendanceMark::class, RollCall::class, Sf2Submission::class, SchoolDayOff::class, TransferRequest::class, Enrollment::class, Learner::class, ScheduleEntry::class, LessonPlan::class,TeachingLoad::class, ClassPeriod::class, Subject::class, Section::class, ServiceRecordEntry::class, ServiceRecord::class, StaffIdDetails::class, Division::class, SchoolAssignment::class, Announcement::class, ActivityLog::class, School::class, SchoolYear::class, User::class] as $class) {
            $this->em->createQuery('DELETE FROM '.$class)->execute();
        }
        // Basic and Individual come from the migration and are shared by every test
        $this->em->createQuery('DELETE FROM '.Plan::class." p WHERE p.name NOT IN ('Basic', 'Individual')")->execute();
    }

    protected function schoolYear(int $startYear, bool $current): SchoolYear
    {
        $sy = (new SchoolYear())
            ->setName($startYear.'-'.($startYear + 1))
            ->setStartDate(new \DateTimeImmutable($startYear.'-06-01'))
            ->setEndDate(new \DateTimeImmutable(($startYear + 1).'-04-30'))
            ->setCurrent($current);
        $this->em->persist($sy);
        $this->em->flush();

        return $sy;
    }

    protected function classPeriod(School $school, SchoolYear $sy, string $start, string $end, ?string $breakLabel = null): ClassPeriod
    {
        $period = (new ClassPeriod($school, $sy))
            ->setStartsAt(new \DateTimeImmutable($start))
            ->setEndsAt(new \DateTimeImmutable($end))
            ->setBreakLabel($breakLabel)
            ->setPosition($this->em->getRepository(ClassPeriod::class)->count(['school' => $school, 'schoolYear' => $sy]) + 1);
        $this->em->persist($period);
        $this->em->flush();

        return $period;
    }

    protected function plan(string $name = 'Basic'): Plan
    {
        return $this->em->getRepository(Plan::class)->findOneBy(['name' => $name])
            ?? throw new \LogicException(\sprintf('The "%s" plan is missing; the migrations seed it.', $name));
    }

    /** A plan of its own, so a test never edits the shared Basic row; clearSchoolData removes it. */
    protected function limitedPlan(string $name, int $studentsPerSeat, bool $includesPrincipal = true): Plan
    {
        $plan = (new Plan())->setName($name)->setTeachersPerSeat(15)->setStudentsPerSeat($studentsPerSeat)->setIncludesPrincipal($includesPrincipal);
        $this->em->persist($plan);
        $this->em->flush();

        return $plan;
    }

    protected function school(
        string $schoolId,
        string $name,
        bool $active = true,
        string $plan = 'Basic',
        int $seats = 1,
        ?\DateTimeImmutable $planValidUntil = null,
    ): School {
        $school = (new School())
            ->setSchoolId($schoolId)
            ->setName($name)
            ->setType(SchoolType::JuniorHigh)
            ->setRegion('Region VII')
            ->setDivision('Mandaue City')
            ->setActive($active)
            ->setPlan($this->plan($plan))
            ->setSeats($seats)
            ->setPlanValidUntil($planValidUntil);
        $this->em->persist($school);
        $this->em->flush();

        return $school;
    }

    protected function assign(School $school, SchoolYear $sy, User $user, string $role = User::ROLE_PRINCIPAL): SchoolAssignment
    {
        $assignment = new SchoolAssignment($school, $sy, $user, $role);
        $this->em->persist($assignment);
        $this->em->flush();

        return $assignment;
    }

    /**
     * A user without a usable password, for service-level tests.
     */
    protected function plainUser(string $username, string $role, bool $active = true): User
    {
        $user = (new User())
            ->setUsername($username)
            ->setFullName(ucfirst($username).' Tester')
            ->setRole($role)
            ->setActive($active)
            ->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    protected function newSection(School $school, SchoolYear $sy, GradeLevel $level, string $name): Section
    {
        $section = (new Section($school, $sy))->setGradeLevel($level)->setName($name);
        $this->em->persist($section);
        $this->em->flush();

        return $section;
    }

    protected function learner(string $lrn, string $lastName, string $firstName): Learner
    {
        $user = $this->plainUser('learner'.$lrn, User::ROLE_STUDENT);
        $learner = (new Learner($user, new \DateTimeImmutable()))->setLrn($lrn)->setLastName($lastName)->setFirstName($firstName)->setSex(SexAtBirth::Female)->setBirthDate(new \DateTimeImmutable('2012-05-01'));
        $this->em->persist($learner);
        $this->em->flush();

        return $learner;
    }

    protected function enroll(Learner $learner, SchoolYear $sy, Section $section, User $by): Enrollment
    {
        $enrollment = new Enrollment($learner, $sy, $section, $by, new \DateTimeImmutable());
        $this->em->persist($enrollment);
        $this->em->flush();

        return $enrollment;
    }

    protected function openGrading(School $school, GradingPeriod $period): GradeOpening
    {
        $opening = new GradeOpening($school, $period, null, new \DateTimeImmutable());
        $this->em->persist($opening);
        $this->em->flush();

        return $opening;
    }
}
