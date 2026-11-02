<?php

namespace App\Tests\Nutrition;

use App\Attendance\Sf2Roster;
use App\Entity\Enrollment;
use App\Entity\GradeLevel;
use App\Entity\NutritionAssessment;
use App\Entity\NutritionMeasurement;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\SexAtBirth;
use App\Entity\Sf8Submission;
use App\Entity\User;
use App\Nutrition\MeasurementInput;
use App\Nutrition\NutritionClassifier;
use App\Nutrition\NutritionException;
use App\Nutrition\NutritionPeriod;
use App\Nutrition\NutritionRecorder;
use App\Nutrition\NutritionRules;
use App\Nutrition\Sf8ReportBuilder;
use App\Repository\EnrollmentRepository;
use App\Repository\NutritionAssessmentRepository;
use App\Repository\SchoolAssignmentRepository;
use App\Repository\SectionRepository;
use App\Repository\Sf8SubmissionRepository;
use App\Repository\TransferRequestRepository;
use App\Service\ActivityLogger;
use App\Service\SchoolClock;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Lock\LockFactory;

class NutritionRecorderTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private Section $artistic;
    private User $adviser;
    /** @var list<Enrollment> */
    private array $enrolled = [];
    private int $lrn = 120017170010;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2025-08-22 10:00:00');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2025, current: true);
        $this->school = $this->school('303196', 'Pagsabungan National High School');
        $this->adviser = $this->plainUser('dave', User::ROLE_TEACHER);
        $this->artistic = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Artistic')->setAdviser($this->adviser);
        foreach (['Arnol', 'Bañez', 'Cruz'] as $name) {
            $this->enrolled[] = $this->enrolled($name, $this->artistic);
        }
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    private function enrolled(string $last, Section $section): Enrollment
    {
        $learner = $this->learner((string) ++$this->lrn, $last, 'Learner')->setSex(SexAtBirth::Male);
        $enrollment = new Enrollment($learner, $this->sy, $section, $this->adviser, new \DateTimeImmutable('2025-05-20 09:00'));
        $this->em->persist($enrollment);

        return $enrollment;
    }

    private function recorder(): NutritionRecorder
    {
        $c = static::getContainer();
        $clock = new SchoolClock($c->get(ClockInterface::class), 'Asia/Manila');
        $roster = new Sf2Roster($c->get(EnrollmentRepository::class), $c->get(TransferRequestRepository::class));

        return new NutritionRecorder(
            $this->em,
            $c->get(LockFactory::class),
            new NutritionRules($c->get(NutritionAssessmentRepository::class), $c->get(Sf8SubmissionRepository::class), $clock),
            $roster,
            new Sf8ReportBuilder(
                $roster,
                new NutritionClassifier(\dirname(__DIR__, 2)),
                $c->get(NutritionAssessmentRepository::class),
                $c->get(Sf8SubmissionRepository::class),
                $c->get(SectionRepository::class),
                $c->get(SchoolAssignmentRepository::class),
                $clock,
            ),
            $c->get(NutritionAssessmentRepository::class),
            $c->get(Sf8SubmissionRepository::class),
            $c->get(ActivityLogger::class),
            $clock,
        );
    }

    private function id(int $i): int
    {
        return $this->enrolled[$i]->getId();
    }

    private function date(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date);
    }

    private function saveAt(string $date, NutritionPeriod $period = NutritionPeriod::Baseline, array $inputs = []): NutritionAssessment
    {
        return $this->recorder()->save($this->artistic, $period, $this->date($date), $inputs, $this->adviser);
    }

    private function refusal(callable $action): string
    {
        try {
            $action();
        } catch (NutritionException $e) {
            return $e->getMessage();
        }
        self::fail('Expected a NutritionException.');
    }

    public function testFirstSaveCreatesAssessment(): void
    {
        $assessment = $this->saveAt('2025-08-04', NutritionPeriod::Baseline, [
            new MeasurementInput($this->id(0), 41.5, 1.5, '  Wears glasses  '),
            new MeasurementInput($this->id(1), null, null, 'Absent'),
            new MeasurementInput($this->id(2), null, null, '   '),
        ]);

        self::assertSame('2025-08-04', $assessment->getWeighedOn()->format('Y-m-d'));
        self::assertNull($assessment->getUpdatedAt());
        self::assertSame(2, $this->em->getRepository(NutritionMeasurement::class)->count([]));
        $first = $assessment->measurementFor($this->enrolled[0]);
        self::assertSame('41.50', $first->getWeightKg());
        self::assertSame('1.50', $first->getHeightM());
        self::assertSame('Wears glasses', $first->getRemarks());
        $second = $assessment->measurementFor($this->enrolled[1]);
        self::assertNull($second->getWeightKg());
        self::assertSame('Absent', $second->getRemarks());
    }

    public function testSaveUpdatesAndClears(): void
    {
        $first = $this->saveAt('2025-08-04', NutritionPeriod::Baseline, [
            new MeasurementInput($this->id(0), 41.5, 1.5, null),
            new MeasurementInput($this->id(1), 50.0, 1.6, null),
        ]);

        $again = $this->saveAt('2025-08-05', NutritionPeriod::Baseline, [
            new MeasurementInput($this->id(0), 42.25, 1.5, null),
            new MeasurementInput($this->id(1), null, null, null),
        ]);

        self::assertSame($first->getId(), $again->getId());
        self::assertSame(1, $this->em->getRepository(NutritionAssessment::class)->count([]));
        self::assertSame('2025-08-05', $again->getWeighedOn()->format('Y-m-d'));
        self::assertNotNull($again->getUpdatedAt());
        $rows = $this->em->getRepository(NutritionMeasurement::class)->findAll();
        self::assertCount(1, $rows);
        self::assertSame('42.25', $rows[0]->getWeightKg());
    }

    public function testIgnoresEnrollmentNotOnRoster(): void
    {
        $other = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Brilliant');
        $stranger = $this->enrolled('Zamora', $other);
        $this->em->flush();

        $this->saveAt('2025-08-04', NutritionPeriod::Baseline, [
            new MeasurementInput($stranger->getId(), 40.0, 1.5, null),
            new MeasurementInput(999999, 40.0, 1.5, null),
        ]);

        self::assertSame(0, $this->em->getRepository(NutritionMeasurement::class)->count([]));
    }

    public function testRefusesFutureAndOutOfYearDates(): void
    {
        self::assertSame("The date of weighing can't be in the future.", $this->refusal(fn () => $this->saveAt('2025-08-23')));
        self::assertSame('Weigh inside the school year (Jun 1, 2025 – Apr 30, 2026).', $this->refusal(fn () => $this->saveAt('2025-05-30')));
        self::assertSame(0, $this->em->getRepository(NutritionAssessment::class)->count([]));
        self::assertSame('2025-08-22', $this->saveAt('2025-08-22')->getWeighedOn()->format('Y-m-d'));
    }

    public function testEndlineMustFollowBaseline(): void
    {
        $this->saveAt('2025-08-04', NutritionPeriod::Baseline);

        self::assertSame('The endline must be after the baseline (Aug 4, 2025).', $this->refusal(fn () => $this->saveAt('2025-08-04', NutritionPeriod::Endline)));
        self::assertSame('The endline must be after the baseline (Aug 4, 2025).', $this->refusal(fn () => $this->saveAt('2025-07-01', NutritionPeriod::Endline)));
        self::assertSame('2025-08-05', $this->saveAt('2025-08-05', NutritionPeriod::Endline)->getWeighedOn()->format('Y-m-d'));
    }

    public function testBaselineMustPrecedeEndline(): void
    {
        $this->saveAt('2025-08-04', NutritionPeriod::Baseline);
        $this->saveAt('2025-08-20', NutritionPeriod::Endline);

        self::assertSame('The baseline must be before the endline (Aug 20, 2025).', $this->refusal(fn () => $this->saveAt('2025-08-20', NutritionPeriod::Baseline)));
        self::assertSame('The baseline must be before the endline (Aug 20, 2025).', $this->refusal(fn () => $this->saveAt('2025-08-21', NutritionPeriod::Baseline)));
        self::assertSame('2025-08-19', $this->saveAt('2025-08-19', NutritionPeriod::Baseline)->getWeighedOn()->format('Y-m-d'));
    }

    public function testSubmitNeedsConfirmForUnweighed(): void
    {
        $recorder = $this->recorder();
        self::assertSame('Set the date of weighing and save first.', $this->refusal(fn () => $recorder->submit($this->artistic, NutritionPeriod::Baseline, $this->adviser, false)));

        $this->saveAt('2025-08-04', NutritionPeriod::Baseline, [new MeasurementInput($this->id(0), 41.5, 1.5, null), new MeasurementInput($this->id(1), 40.0, null, null)]);
        self::assertSame('2 learners have no weight and height. Confirm to submit anyway.', $this->refusal(fn () => $recorder->submit($this->artistic, NutritionPeriod::Baseline, $this->adviser, false)));

        $this->saveAt('2025-08-04', NutritionPeriod::Baseline, [new MeasurementInput($this->id(1), 40.0, 1.4, null)]);
        self::assertSame('1 learner has no weight and height. Confirm to submit anyway.', $this->refusal(fn () => $recorder->submit($this->artistic, NutritionPeriod::Baseline, $this->adviser, false)));
        self::assertSame(0, $this->em->getRepository(Sf8Submission::class)->count([]));

        $submission = $recorder->submit($this->artistic, NutritionPeriod::Baseline, $this->adviser, true);

        self::assertSame(NutritionPeriod::Baseline, $submission->getPeriod());
        self::assertSame(1, $this->em->getRepository(Sf8Submission::class)->count([]));
        self::assertSame('Baseline is already submitted.', $this->refusal(fn () => $recorder->submit($this->artistic, NutritionPeriod::Baseline, $this->adviser, true)));
    }

    public function testSubmittedPeriodRefusesSave(): void
    {
        $this->saveAt('2025-08-04', NutritionPeriod::Baseline, [new MeasurementInput($this->id(0), 41.5, 1.5, null)]);
        $this->recorder()->submit($this->artistic, NutritionPeriod::Baseline, $this->adviser, true);

        $message = $this->refusal(fn () => $this->saveAt('2025-08-05', NutritionPeriod::Baseline, [new MeasurementInput($this->id(0), 50.0, 1.6, null)]));

        self::assertSame('Baseline is submitted and locked.', $message);
        $this->em->clear();
        $measurement = $this->em->getRepository(NutritionMeasurement::class)->findAll()[0];
        self::assertSame('41.50', $measurement->getWeightKg());
        self::assertSame('2025-08-04', $measurement->getAssessment()->getWeighedOn()->format('Y-m-d'));
        $this->artistic = $this->em->getRepository(Section::class)->find($this->artistic->getId());
        $this->adviser = $this->em->getRepository(User::class)->find($this->adviser->getId());
        // the endline is a separate period and stays open
        self::assertSame('2025-08-05', $this->saveAt('2025-08-05', NutritionPeriod::Endline)->getWeighedOn()->format('Y-m-d'));
    }

    public function testReopenDeletesSubmission(): void
    {
        $this->saveAt('2025-08-04');
        $this->recorder()->submit($this->artistic, NutritionPeriod::Baseline, $this->adviser, true);

        $this->recorder()->reopen($this->artistic, NutritionPeriod::Baseline);

        self::assertSame(0, $this->em->getRepository(Sf8Submission::class)->count([]));
        self::assertSame('2025-08-06', $this->saveAt('2025-08-06')->getWeighedOn()->format('Y-m-d'));
    }

    public function testReopenNotSubmittedRefused(): void
    {
        self::assertSame('Baseline is not submitted.', $this->refusal(fn () => $this->recorder()->reopen($this->artistic, NutritionPeriod::Baseline)));
    }
}
