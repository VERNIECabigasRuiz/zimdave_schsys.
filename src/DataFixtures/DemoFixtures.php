<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Attendance\AttendanceRecorder;
use App\Schools\Domain\Service\Calendar\SchoolCalendar;
use App\Classes\Domain\Model\ClassPeriod;
use App\Schools\Domain\Model\Division;
use App\Schools\Domain\Model\GradeLevel;
use App\Entity\Learner;
use App\Entity\LearnerMark;
use App\Billing\Domain\Model\Plan;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolType;
use App\Schools\Domain\Model\SchoolYear;
use App\Classes\Domain\Model\Section;
use App\Entity\SexAtBirth;
use App\Classes\Domain\Model\Subject;
use App\Classes\Domain\Model\TeachingLoad;
use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Weekday;
use App\Grading\GradeRecorder;
use App\LessonPlan\LessonWeek;
use App\Repository\EnrollmentRepository;
use App\Schools\Domain\Model\Repository\SchoolRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Classes\Domain\Model\Repository\SubjectRepositoryInterface;
use App\Classes\Application\Service\ClassPeriodManager;
use App\Service\LearnerEnrollment;
use App\Schools\Application\Service\PrincipalAssigner;
use App\Core\Domain\Service\Clock\SchoolClockInterface;
use App\Schools\Application\Service\SchoolTeacherManager;
use App\Classes\Application\Service\SectionManager;
use App\Classes\Application\Service\SubjectManager;
use App\Classes\Domain\Exception\TimetableException;
use App\Classes\Application\Service\Timetable\TimetableManager;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;
use Faker\Generator;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Demo schools for trying the app locally: make fixture-load, then make fixture-seed.
 * Everything goes through the app's own services, so plan limits and rules apply.
 */
final class DemoFixtures extends Fixture implements FixtureGroupInterface
{
    public const PASSWORD = 'demo1234';
    public const PRINCIPAL = 'demo.principal';
    public const TEACHERS = [
        'demo.teacher1',
        'demo.teacher2',
        'demo.teacher3',
        'demo.teacher4',
        'demo.teacher5',
        'demo.teacher6',
    ];
    public const SOLO_TEACHER = 'demo.solo';
    public const BASIC_SCHOOL_ID = '999001';
    public const INDIVIDUAL_SCHOOL_ID = '999002';

    private const LEARNERS_PER_SECTION = 25;
    private const SOLO_LEARNERS = 20;
    private const ROLL_CALL_DAYS = 15;
    private const CLASS_PERIODS = 8;
    private const SECTION_NAMES = ['Sampaguita', 'Narra', 'Molave', 'Ilang-Ilang', 'Kamagong'];

    private Generator $faker;
    private int $nextLrn = 1;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly SchoolClockInterface $clock,
        private readonly SchoolRepositoryInterface $schools,
        private readonly SchoolYearRepositoryInterface $schoolYears,
        private readonly SubjectRepositoryInterface $subjects,
        private readonly EnrollmentRepository $enrollments,
        private readonly SchoolTeacherManager $teachers,
        private readonly PrincipalAssigner $principals,
        private readonly SectionManager $sections,
        private readonly SubjectManager $subjectManager,
        private readonly ClassPeriodManager $periods,
        private readonly TimetableManager $timetable,
        private readonly LearnerEnrollment $learnerEnrollment,
        private readonly AttendanceRecorder $attendance,
        private readonly SchoolCalendar $calendar,
        private readonly GradeRecorder $grades,
        private readonly LessonWeek $lessonWeek,
    ) {
    }

    public static function getGroups(): array
    {
        return ['demo'];
    }

    public function load(ObjectManager $manager): void
    {
        if (null !== $this->schools->findBySchoolId(self::BASIC_SCHOOL_ID)) {
            throw new \RuntimeException('Demo data is already loaded. Run make fixture-load first.');
        }

        $this->faker = Factory::create('en_PH');
        $this->faker->seed(2026);

        $division = (new Division())->setRegion('Region VII')->setName('Mandaue City');
        $this->entityManager->persist($division);
        $schoolYear = $this->currentSchoolYear();

        $this->loadBasicSchool($schoolYear);
        $this->loadIndividualSchool($schoolYear);
    }

    private function loadBasicSchool(SchoolYear $schoolYear): void
    {
        $school = $this->school(self::BASIC_SCHOOL_ID, 'Mandaue City Demo National High School', 'Basic');

        $principal = $this->staff(self::PRINCIPAL, User::ROLE_PRINCIPAL);
        $this->principals->assign($school, $schoolYear, $principal);
        $this->entityManager->flush();

        $teachers = array_map(
            fn (string $username): User => $this->teacher($school, $schoolYear, $username),
            self::TEACHERS,
        );

        $periods = $this->classPeriods($school, $schoolYear);
        $loads = [];
        foreach ([GradeLevel::Grade7, GradeLevel::Grade8, GradeLevel::Grade9] as $i => $grade) {
            $section = $this->section($school, $schoolYear, $grade, $teachers[$i]);
            $sectionLoads = $this->loads($section, $teachers);
            $this->plot($section, $sectionLoads, $periods, $i);
            $this->enroll($section, self::LEARNERS_PER_SECTION, $principal);
            $this->takeRollCalls($section, $schoolYear, $teachers[$i]);
            $loads = [...$loads, ...$sectionLoads];
        }

        $this->gradeFirstPeriod($school, $schoolYear, $principal, $loads);

        $plan = $this->lessonWeek->start($loads[0], $this->clock->now(), $this->clock->now())
            ->setTopic('Introduction to the lesson');
        $this->entityManager->persist($plan);
        $this->entityManager->flush();
    }

    private function loadIndividualSchool(SchoolYear $schoolYear): void
    {
        $school = $this->school(self::INDIVIDUAL_SCHOOL_ID, 'Mandaue City Demo Learning Center', 'Individual');
        $teacher = $this->teacher($school, $schoolYear, self::SOLO_TEACHER);

        $section = $this->section($school, $schoolYear, GradeLevel::Grade7, $teacher);
        $this->loads($section, [$teacher]);
        $this->enroll($section, self::SOLO_LEARNERS, $teacher);
    }

    /** The current school year, or a new one from June to April that contains today. */
    private function currentSchoolYear(): SchoolYear
    {
        $current = $this->schoolYears->findCurrent();
        if (null !== $current) {
            return $current;
        }

        $today = $this->clock->now();
        $startYear = (int) $today->format('Y') - ((int) $today->format('n') < 6 ? 1 : 0);
        $schoolYear = (new SchoolYear())
            ->setName($startYear . '-' . ($startYear + 1))
            ->setStartDate(new \DateTimeImmutable($startYear . '-06-01'))
            ->setEndDate(new \DateTimeImmutable(($startYear + 1) . '-04-30'))
            ->setCurrent(true);
        $schoolYear->rebuildGradingPeriods();
        $this->entityManager->persist($schoolYear);
        $this->entityManager->flush();

        return $schoolYear;
    }

    private function school(string $schoolId, string $name, string $planName): School
    {
        $school = (new School())
            ->setSchoolId($schoolId)
            ->setName($name)
            ->setType(SchoolType::JuniorHigh)
            ->setRegion('Region VII')
            ->setDivision('Mandaue City')
            ->setActive(true)
            ->setPlan($this->entityManager->getRepository(Plan::class)->findOneBy(['name' => $planName]))
            ->setSeats(1);
        $this->entityManager->persist($school);
        $this->entityManager->flush();

        return $school;
    }

    private function staff(string $username, string $role): User
    {
        $user = (new User())
            ->setUsername($username)
            ->setFullName($this->faker->firstName() . ' ' . $this->faker->lastName())
            ->setRole($role)
            ->setActive(true);
        $this->knownPassword($user);
        $this->entityManager->persist($user);

        return $user;
    }

    private function teacher(School $school, SchoolYear $schoolYear, string $username): User
    {
        $teacher = (new User())
            ->setUsername($username)
            ->setFullName($this->faker->firstName() . ' ' . $this->faker->lastName())
            ->setActive(true);
        $this->teachers->create($school, $schoolYear, $teacher);
        $this->knownPassword($teacher);
        $this->entityManager->flush();

        return $teacher;
    }

    private function knownPassword(User $user): void
    {
        $user->setPassword($this->passwordHasher->hashPassword($user, self::PASSWORD));
        $user->setMustChangePassword(false);
    }

    /** @return list<ClassPeriod> one-hour periods from 7:30 */
    private function classPeriods(School $school, SchoolYear $schoolYear): array
    {
        $periods = [];
        $start = new \DateTimeImmutable('07:30');
        for ($i = 0; $i < self::CLASS_PERIODS; ++$i) {
            $period = (new ClassPeriod($school, $schoolYear))
                ->setPosition($i + 1)
                ->setStartsAt($start->modify(\sprintf('+%d hours', $i)))
                ->setEndsAt($start->modify(\sprintf('+%d hours', $i + 1)));
            $this->periods->create($period);
            $this->entityManager->flush();
            $periods[] = $period;
        }

        return $periods;
    }

    private function section(School $school, SchoolYear $schoolYear, GradeLevel $grade, User $adviser): Section
    {
        $this->subjectManager->addDepEdSet($school, $schoolYear, $grade);

        $name = $this->faker->randomElement(self::SECTION_NAMES);
        \assert(\is_string($name));
        $section = (new Section($school, $schoolYear))
            ->setGradeLevel($grade)
            ->setName($name . ' ' . $grade->value)
            ->setAdviser($adviser);
        $this->sections->create($section);
        $this->entityManager->flush();

        return $section;
    }

    /**
     * Gives every loadable subject of the section a teacher, taking turns.
     *
     * @param list<User> $teachers
     *
     * @return list<TeachingLoad>
     */
    private function loads(Section $section, array $teachers): array
    {
        $loadable = array_values(array_filter(
            $this->subjects->findForSection($section),
            static fn (Subject $subject): bool => $subject->isLoadable(),
        ));

        $loads = [];
        foreach ($loadable as $i => $subject) {
            $load = $this->timetable->setTeacher($section, $subject, $teachers[$i % \count($teachers)]);
            $this->entityManager->flush();
            if (null !== $load) {
                $loads[] = $load;
            }
        }

        return $loads;
    }

    /**
     * Plots each subject's weekly meetings, starting at a different period per section
     * and moving on when the section or the teacher is already busy.
     *
     * @param list<TeachingLoad>  $loads
     * @param list<ClassPeriod> $periods
     */
    private function plot(Section $section, array $loads, array $periods, int $offset): void
    {
        foreach ($loads as $i => $load) {
            $perWeek = $load->getSubject()->getMeetingsPerWeek() ?? 0;
            $placed = 0;
            foreach (Weekday::cases() as $day) {
                for ($try = 0; $try < \count($periods) && $placed < $perWeek; ++$try) {
                    $period = $periods[($i + $offset + $try) % \count($periods)];
                    try {
                        $this->timetable->plot($section, $load->getSubject(), $day, $period);
                        $this->entityManager->flush();
                        ++$placed;
                        break;
                    } catch (TimetableException) {
                        // that slot is taken; try the next period
                    }
                }
            }
        }
    }

    private function enroll(Section $section, int $count, User $by): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $sex = 0 === $i % 2 ? SexAtBirth::Male : SexAtBirth::Female;
            $gender = SexAtBirth::Male === $sex ? 'male' : 'female';
            $age = 12 + ($section->getGradeLevel()->value ?? 7) - 7;
            $learner = (new Learner(new User(), $this->clock->now()))
                ->setLrn(\sprintf('9%011d', $this->nextLrn++))
                ->setLastName($this->faker->lastName())
                ->setFirstName($this->faker->firstName($gender))
                ->setSex($sex)
                ->setBirthDate($this->birthDate($age));
            $this->learnerEnrollment->enrollNew($learner, $section, $by);
        }
    }

    private function birthDate(int $age): \DateTimeImmutable
    {
        $daysPastBirthday = $this->faker->numberBetween(0, 364);

        return $this->clock->now()->modify(\sprintf('-%d years -%d days', $age, $daysPastBirthday))->setTime(0, 0);
    }

    /** Roll calls for the last school days before today, with a few absences and lates. */
    private function takeRollCalls(Section $section, SchoolYear $schoolYear, User $adviser): void
    {
        $classList = $this->enrollments->findClassList($section);
        $start = $schoolYear->getStartDate();
        $day = $this->clock->now()->setTime(0, 0);

        for ($taken = 0; $taken < self::ROLL_CALL_DAYS;) {
            $day = $day->modify('-1 day');
            if (null !== $start && $day < $start) {
                return;
            }
            if (!$this->calendar->isSchoolDay($section->getSchool(), $schoolYear, $day)) {
                continue;
            }

            $marks = [];
            foreach ($classList as $enrollment) {
                $mark = $this->faker->optional(0.1)->randomElement([LearnerMark::Absent, LearnerMark::Late]);
                \assert(null === $mark || $mark instanceof LearnerMark);
                $marks[(int) $enrollment->getId()] = $mark;
            }
            $this->attendance->takeRollCall($section, $day, $marks, $adviser);
            ++$taken;
        }
    }

    /** @param list<TeachingLoad> $loads */
    private function gradeFirstPeriod(School $school, SchoolYear $schoolYear, User $principal, array $loads): void
    {
        $firstPeriod = $schoolYear->getGradingPeriod(1);
        if (null === $firstPeriod) {
            return;
        }

        $this->grades->openPeriod($school, $firstPeriod, $principal);
        foreach ($loads as $load) {
            $values = [];
            foreach ($this->enrollments->findClassList($load->getSection()) as $enrollment) {
                $values[(int) $enrollment->getId()] = $this->faker->numberBetween(75, 98);
            }
            $this->grades->saveSheet($load, $firstPeriod, $values, $load->getTeacher());
        }
    }
}
