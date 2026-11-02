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
use App\Entity\TransferRequest;
use App\Entity\TransferStatus;
use App\Entity\User;
use App\Nutrition\BmiCategory;
use App\Nutrition\HfaCategory;
use App\Nutrition\NutritionClassifier;
use App\Nutrition\NutritionPeriod;
use App\Nutrition\Sf8Report;
use App\Nutrition\Sf8ReportBuilder;
use App\Nutrition\Sf8Row;
use App\Nutrition\Sf8Status;
use App\Nutrition\Sf8Summary;
use App\Repository\EnrollmentRepository;
use App\Repository\NutritionAssessmentRepository;
use App\Repository\SchoolAssignmentRepository;
use App\Repository\SectionRepository;
use App\Repository\Sf8SubmissionRepository;
use App\Repository\TransferRequestRepository;
use App\Service\SchoolClock;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Arnol and Bañez (M), Cruz and Diaz (F) are enrolled on time in Grade 8 Artistic, all born 2012-05-01.
 */
class Sf8ReportBuilderTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private Section $artistic;
    private User $adviser;
    /** @var array<string, Enrollment> */
    private array $e = [];
    private int $lrn = 120017170010;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2025-08-22 10:00:00');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2025, current: true);
        $this->school = $this->school('303196', 'Pagsabungan National High School')
            ->setSf8CertifierName('Ma. Luz Cabrera')->setSf8CertifierPosition('School Nurse');
        $this->adviser = $this->plainUser('dave', User::ROLE_TEACHER)->setFullName('Dave Sosmeña');
        $this->artistic = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Artistic')->setAdviser($this->adviser);
        $this->assign($this->school, $this->sy, $this->plainUser('rene', User::ROLE_PRINCIPAL)->setFullName('Rene Petancio'));

        $this->e['Arnol'] = $this->enrolled('Arnol', SexAtBirth::Male);
        $this->e['Bañez'] = $this->enrolled('Bañez', SexAtBirth::Male);
        $this->e['Cruz'] = $this->enrolled('Cruz', SexAtBirth::Female);
        $this->e['Diaz'] = $this->enrolled('Diaz', SexAtBirth::Female);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    private function enrolled(string $last, SexAtBirth $sex, ?Section $section = null, string $enrolledAt = '2025-05-20'): Enrollment
    {
        $learner = $this->learner((string) ++$this->lrn, $last, 'Learner')->setSex($sex);
        $enrollment = new Enrollment($learner, $this->sy, $section ?? $this->artistic, $this->adviser, new \DateTimeImmutable($enrolledAt.' 09:00'));
        $this->em->persist($enrollment);
        $this->em->flush();

        return $enrollment;
    }

    private function builder(): Sf8ReportBuilder
    {
        $c = static::getContainer();

        return new Sf8ReportBuilder(
            new Sf2Roster($c->get(EnrollmentRepository::class), $c->get(TransferRequestRepository::class)),
            new NutritionClassifier(\dirname(__DIR__, 2)),
            $c->get(NutritionAssessmentRepository::class),
            $c->get(Sf8SubmissionRepository::class),
            $c->get(SectionRepository::class),
            $c->get(SchoolAssignmentRepository::class),
            new SchoolClock($c->get(ClockInterface::class), 'Asia/Manila'),
        );
    }

    private function build(NutritionPeriod $period = NutritionPeriod::Baseline, ?Section $section = null): Sf8Report
    {
        return $this->builder()->build($section ?? $this->artistic, $period);
    }

    /** @param array<string, array{?string, ?string}> $measurements weight kg and height m, by last name */
    private function assessment(string $weighedOn, array $measurements, NutritionPeriod $period = NutritionPeriod::Baseline, ?Section $section = null): NutritionAssessment
    {
        $assessment = new NutritionAssessment($section ?? $this->artistic, $period, new \DateTimeImmutable($weighedOn), $this->adviser, new \DateTimeImmutable());
        foreach ($measurements as $name => [$weight, $height]) {
            $assessment->addMeasurement((new NutritionMeasurement($assessment, $this->e[$name]))->setWeightKg($weight)->setHeightM($height));
        }
        $this->em->persist($assessment);
        $this->em->flush();

        return $assessment;
    }

    private function transfer(Enrollment $enrollment, Section $to, string $decidedAt): void
    {
        $request = new TransferRequest($enrollment, $to, $this->adviser, new \DateTimeImmutable($decidedAt.' 08:00'));
        $request->decide(TransferStatus::Approved, $this->adviser, new \DateTimeImmutable($decidedAt.' 10:00'));
        $enrollment->moveTo($to);
        $this->em->persist($request);
        $this->em->flush();
    }

    /** @return list<string> last names of the rows, in order */
    private static function names(Sf8Report $report): array
    {
        return array_map(static fn (Sf8Row $r) => $r->entry->enrollment->getLearner()->getLastName(), $report->rows);
    }

    private static function row(Sf8Report $report, string $lastName): ?Sf8Row
    {
        foreach ($report->rows as $row) {
            if ($lastName === $row->entry->enrollment->getLearner()->getLastName()) {
                return $row;
            }
        }

        return null;
    }

    public function testRowsAndSummary(): void
    {
        $this->assessment('2025-08-04', [
            'Arnol' => ['40.50', '1.52'],
            'Bañez' => ['31.00', '1.45'],
            'Cruz' => ['45.00', '1.50'],
            'Diaz' => [null, null],
        ]);

        $report = $this->build();

        self::assertSame(Sf8Status::Draft, $report->status);
        self::assertTrue($report->dateSet);
        self::assertSame('2025-08-04', $report->weighedOn->format('Y-m-d'));
        self::assertSame(['Arnol', 'Bañez', 'Cruz', 'Diaz'], self::names($report));
        self::assertSame(['Arnol', 'Bañez'], array_map(static fn (Sf8Row $r) => $r->entry->enrollment->getLearner()->getLastName(), $report->males()));
        self::assertSame(['Cruz', 'Diaz'], array_map(static fn (Sf8Row $r) => $r->entry->enrollment->getLearner()->getLastName(), $report->females()));

        $arnol = self::row($report, 'Arnol');
        self::assertSame((string) ($this->lrn - 3), $arnol->lrn);
        self::assertSame('2012-05-01', $arnol->birthDate?->format('Y-m-d'));
        self::assertSame(40.5, $arnol->weightKg);
        self::assertSame(1.52, $arnol->heightM);
        self::assertTrue($arnol->weighed);
        self::assertSame(159, $arnol->result->ageMonths);
        self::assertFalse(self::row($report, 'Diaz')->weighed);
        self::assertNull(self::row($report, 'Diaz')->result->bmiCategory);

        $classifier = new NutritionClassifier(\dirname(__DIR__, 2));
        $on = new \DateTimeImmutable('2025-08-04');
        $born = new \DateTimeImmutable('2012-05-01');
        $boys = [$classifier->classify(true, $born, $on, 40.5, 1.52), $classifier->classify(true, $born, $on, 31.0, 1.45)];
        $girl = $classifier->classify(false, $born, $on, 45.0, 1.50);
        self::assertEquals($boys[0], $arnol->result);

        $summary = $report->summary;
        self::assertSame(3, $summary->weighed());
        self::assertSame(2, $summary->weighed(true));
        self::assertSame(1, $summary->weighed(false));
        self::assertSame(4, $summary->onRoster());
        self::assertSame(2, $summary->onRoster(true));
        $normalBoys = \count(array_filter($boys, static fn ($r) => BmiCategory::Normal === $r->bmiCategory));
        self::assertSame($normalBoys, $summary->bmi(BmiCategory::Normal, true));
        foreach (BmiCategory::cases() as $category) {
            $expected = \count(array_filter([...$boys, $girl], static fn ($r) => $category === $r->bmiCategory));
            self::assertSame($expected, $summary->bmi($category), $category->value);
        }
        foreach (HfaCategory::cases() as $category) {
            $expected = \count(array_filter([...$boys, $girl], static fn ($r) => $category === $r->hfaCategory));
            self::assertSame($expected, $summary->hfa($category), $category->value);
        }
        self::assertSame(3, array_sum(array_map($summary->bmi(...), BmiCategory::cases())));

        self::assertSame('DAVE SOSMEÑA', $report->adviserName);
        self::assertSame('RENE PETANCIO', $report->principalName);
        self::assertSame('School Principal', $report->principalPosition);
        self::assertSame('Ma. Luz Cabrera', $report->certifierName);
        self::assertSame('School Nurse', $report->certifierPosition);
        self::assertNull($report->submission);
    }

    public function testNotStartedUsesToday(): void
    {
        $report = $this->build();

        self::assertSame(Sf8Status::NotStarted, $report->status);
        self::assertSame('Not started', $report->status->label());
        self::assertFalse($report->dateSet);
        self::assertSame('2025-08-22', $report->weighedOn->format('Y-m-d'));
        self::assertCount(4, $report->rows);
        foreach ($report->rows as $row) {
            self::assertFalse($row->weighed);
            self::assertNull($row->weightKg);
            self::assertNull($row->result->bmiCategory);
        }
        self::assertSame(0, $report->summary->weighed());
        self::assertSame(4, $report->summary->onRoster());
        foreach (BmiCategory::cases() as $category) {
            self::assertSame(0, $report->summary->bmi($category));
        }
    }

    public function testUnder60MonthsListedButNotCounted(): void
    {
        $this->e['Arnol']->getLearner()->setBirthDate(new \DateTimeImmutable('2021-01-10'));
        $this->em->flush();
        $this->assessment('2025-08-04', ['Arnol' => ['17.00', '1.05'], 'Cruz' => ['45.00', '1.50']]);

        $report = $this->build();

        $arnol = self::row($report, 'Arnol');
        self::assertNotNull($arnol);
        self::assertTrue($arnol->weighed);
        self::assertSame(54, $arnol->result->ageMonths);
        self::assertNull($arnol->result->bmiCategory);
        self::assertNull($arnol->result->hfaCategory);
        self::assertSame(2, $report->summary->weighed());
        self::assertSame(1, $report->summary->weighed(true));
        self::assertSame(1, array_sum(array_map($report->summary->bmi(...), BmiCategory::cases())));
        self::assertSame(1, array_sum(array_map($report->summary->hfa(...), HfaCategory::cases())));
        self::assertSame(0, array_sum(array_map(static fn (BmiCategory $c) => $report->summary->bmi($c, true), BmiCategory::cases())));
    }

    public function testTransferredOutAfterBaselineOnlyOnBaseline(): void
    {
        $other = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Benevolent');
        $this->transfer($this->e['Bañez'], $other, '2025-10-01');
        $this->assessment('2025-08-04', ['Bañez' => ['31.00', '1.45']]);
        $this->assessment('2026-03-02', ['Arnol' => ['42.00', '1.54']], NutritionPeriod::Endline);

        $baseline = $this->build();
        self::assertContains('Bañez', self::names($baseline));
        self::assertTrue(self::row($baseline, 'Bañez')->weighed);
        self::assertSame(1, $baseline->summary->weighed());

        $endline = $this->build(NutritionPeriod::Endline);
        self::assertSame(['Arnol', 'Cruz', 'Diaz'], self::names($endline));
        self::assertSame(3, $endline->summary->onRoster());
    }

    public function testDateChangeIgnoresOffRosterMeasurement(): void
    {
        $other = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Benevolent');
        $this->e['Estrada'] = $this->enrolled('Estrada', SexAtBirth::Female, $other);
        $this->transfer($this->e['Estrada'], $this->artistic, '2025-09-15');
        $assessment = $this->assessment('2025-09-20', ['Estrada' => ['45.00', '1.50'], 'Cruz' => ['44.00', '1.49']]);

        $report = $this->build();
        self::assertContains('Estrada', self::names($report));
        self::assertSame(2, $report->summary->weighed());

        $assessment->setWeighedOn(new \DateTimeImmutable('2025-09-01'));
        $this->em->flush();

        $report = $this->build();
        self::assertNotContains('Estrada', self::names($report));
        self::assertSame(1, $report->summary->weighed());
        self::assertSame(1, $report->summary->weighed(false));
        self::assertSame(4, $report->summary->onRoster());
        self::assertSame(1, array_sum(array_map($report->summary->bmi(...), BmiCategory::cases())));
    }

    public function testSubmittedStatus(): void
    {
        $this->assessment('2025-08-04', ['Arnol' => ['40.50', '1.52']]);
        $submission = new Sf8Submission($this->artistic, NutritionPeriod::Baseline, $this->adviser, new \DateTimeImmutable('2025-08-05 15:00'));
        $this->em->persist($submission);
        $this->em->flush();

        $report = $this->build();
        self::assertSame(Sf8Status::Submitted, $report->status);
        self::assertSame('Submitted', $report->status->label());
        self::assertSame($submission, $report->submission);
        // the endline is a period of its own
        self::assertSame(Sf8Status::NotStarted, $this->build(NutritionPeriod::Endline)->status);
    }

    public function testBuildForSchoolOrdersSections(): void
    {
        $zinnia = $this->newSection($this->school, $this->sy, GradeLevel::Grade7, 'Zinnia');
        $acacia = $this->newSection($this->school, $this->sy, GradeLevel::Grade7, 'Acacia');
        $this->assessment('2025-08-04', ['Arnol' => ['40.50', '1.52']]);
        $this->e['Estrada'] = $this->enrolled('Estrada', SexAtBirth::Female, $zinnia);
        $this->assessment('2025-08-05', ['Estrada' => ['45.00', '1.50']], section: $zinnia);
        $this->em->persist(new Sf8Submission($zinnia, NutritionPeriod::Baseline, $this->adviser, new \DateTimeImmutable('2025-08-05 15:00')));
        $this->em->flush();
        // another school's section is left out
        $this->newSection($this->school('303197', 'Other National High School'), $this->sy, GradeLevel::Grade7, 'Aster');

        $reports = $this->builder()->buildForSchool($this->school, $this->sy, NutritionPeriod::Baseline);

        self::assertSame(['Acacia', 'Zinnia', 'Artistic'], array_map(static fn (Sf8Report $r) => $r->section->getName(), $reports));
        self::assertSame([Sf8Status::NotStarted, Sf8Status::Submitted, Sf8Status::Draft], array_map(static fn (Sf8Report $r) => $r->status, $reports));
        self::assertSame($acacia, $reports[0]->section);
        self::assertSame(1, $reports[2]->summary->weighed());
        self::assertSame(4, $reports[2]->summary->onRoster());
        self::assertSame(0, $reports[0]->summary->onRoster());
        self::assertSame('2025-08-05', $reports[1]->weighedOn->format('Y-m-d'));

        $total = Sf8Summary::sum(array_map(static fn (Sf8Report $r) => $r->summary, $reports));
        self::assertSame(2, $total->weighed());
        self::assertSame(1, $total->weighed(true));
        self::assertSame(5, $total->onRoster());
        self::assertSame(3, $total->onRoster(false));
        foreach (BmiCategory::cases() as $category) {
            self::assertSame($reports[1]->summary->bmi($category) + $reports[2]->summary->bmi($category), $total->bmi($category));
            self::assertSame($reports[2]->summary->bmi($category, true), $total->bmi($category, true));
        }
        foreach (HfaCategory::cases() as $category) {
            self::assertSame($reports[1]->summary->hfa($category, false), $total->hfa($category, false));
        }
        self::assertSame(2, array_sum(array_map($total->bmi(...), BmiCategory::cases())));
        self::assertSame(0, Sf8Summary::sum([])->weighed());

        $c = static::getContainer();
        self::assertSame([], $c->get(NutritionAssessmentRepository::class)->findForSections([], NutritionPeriod::Baseline));
        self::assertSame([], $c->get(Sf8SubmissionRepository::class)->findForSections([], NutritionPeriod::Baseline));
    }
}
