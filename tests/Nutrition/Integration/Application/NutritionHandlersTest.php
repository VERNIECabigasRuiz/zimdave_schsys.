<?php

declare(strict_types=1);

namespace App\Tests\Nutrition\Integration\Application;

use App\Nutrition\Domain\Service\Sf8\Sf8Summary;
use App\Access\Domain\Model\User;
use App\Classes\Domain\Exception\SectionNotFoundException;
use App\Classes\Domain\Model\Section;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Learners\Domain\Model\Enrollment;
use App\Nutrition\Application\Query\Command\GetNutritionIndexCommand;
use App\Nutrition\Application\Query\Command\GetNutritionOverviewCommand;
use App\Nutrition\Application\Query\Command\GetNutritionPeriodCommand;
use App\Nutrition\Application\Query\Command\GetSf8FileCommand;
use App\Nutrition\Application\UseCase\Command\MeasurementInput;
use App\Nutrition\Application\UseCase\Command\ReopenSf8Command;
use App\Nutrition\Application\UseCase\Command\SaveWeighingCommand;
use App\Nutrition\Application\UseCase\Command\SubmitSf8Command;
use App\Nutrition\Domain\Exception\NutritionAccessDeniedException;
use App\Nutrition\Domain\Exception\NutritionException;
use App\Nutrition\Domain\Model\NutritionPeriod;
use App\Nutrition\Domain\Service\Sf8\Sf8Report;
use App\Nutrition\Domain\Service\Sf8\Sf8Status;
use App\Schools\Domain\Exception\SchoolYearNotFoundException;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Lock\LockFactory;

/**
 * @phpstan-type Overview array{schoolYear: SchoolYear, reports: list<Sf8Report>, endlines: array<int, Sf8Report>,
 *     total: Sf8Summary, endlineTotal: ?Sf8Summary}
 */
final class NutritionHandlersTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private const BUSY = 'Another change is being saved. Please try again.';

    protected EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private Section $artistic;
    private Section $honest;
    private User $adviser;
    private User $principal;
    /** @var list<Enrollment> */
    private array $enrolled = [];

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2025-08-22 10:00:00');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2025, current: true);
        $this->school = $this->school('303196', 'Pagsabungan NHS');
        $this->adviser = $this->plainUser('dave', User::ROLE_TEACHER);
        $this->assign($this->school, $this->sy, $this->adviser, User::ROLE_TEACHER);
        $this->principal = $this->plainUser('rene', User::ROLE_PRINCIPAL);
        $this->assign($this->school, $this->sy, $this->principal);
        $this->artistic = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Artistic')
            ->setAdviser($this->adviser);
        $this->honest = $this->newSection($this->school, $this->sy, GradeLevel::Grade7, 'Honest');
        foreach (['Arnol', 'Bañez'] as $i => $name) {
            $enrollment = new Enrollment(
                $this->learner('12001717000' . $i, $name, 'Learner'),
                $this->sy,
                $this->artistic,
                $this->adviser,
                new \DateTimeImmutable('2025-05-20'),
            );
            $this->em->persist($enrollment);
            $this->enrolled[] = $enrollment;
        }
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    public function testSavesSubmitsAndReopensThroughTheBus(): void
    {
        // Initialize
        $adviserId = (int) $this->adviser->getId();
        $sectionId = (int) $this->artistic->getId();
        $inputs = [
            new MeasurementInput($this->id(0), 31.5, 1.32, null),
            new MeasurementInput($this->id(1), 30.0, 1.30, 'Absent on Monday'),
        ];

        // Execute
        $saved = $this->handle(new SaveWeighingCommand(
            $adviserId,
            $sectionId,
            NutritionPeriod::Baseline,
            new \DateTimeImmutable('2025-08-04'),
            $inputs,
        ));
        $submitted = $this->handle(new SubmitSf8Command($adviserId, $sectionId, NutritionPeriod::Baseline, false));
        $reopened = $this->handle(new ReopenSf8Command(
            (int) $this->principal->getId(),
            (int) $this->school->getId(),
            $sectionId,
            NutritionPeriod::Baseline,
        ));

        // Assert
        self::assertTrue($saved->isSuccess());
        self::assertTrue($submitted->isSuccess());
        self::assertSame('Grade 8 – Artistic', $reopened->data());
        self::assertSame(2, $this->rows('nutrition_measurement'));
        self::assertSame(0, $this->rows('sf8_submission'));
    }

    public function testAHeldLockRefusesEveryChangeAndSavesNothing(): void
    {
        // Initialize
        $lock = static::getContainer()->get(LockFactory::class)->createLock(
            'nutrition-section-' . $this->artistic->getId(),
        );
        $adviserId = (int) $this->adviser->getId();
        $sectionId = (int) $this->artistic->getId();
        $commands = [
            new SaveWeighingCommand(
                $adviserId,
                $sectionId,
                NutritionPeriod::Baseline,
                new \DateTimeImmutable('2025-08-04'),
                [],
            ),
            new SubmitSf8Command($adviserId, $sectionId, NutritionPeriod::Baseline, true),
            new ReopenSf8Command(
                (int) $this->principal->getId(),
                (int) $this->school->getId(),
                $sectionId,
                NutritionPeriod::Baseline,
            ),
        ];
        self::assertTrue($lock->acquire());

        // Execute
        try {
            $answers = array_map(
                fn (UseCase $command) => $this->answer($this->handle($command)),
                $commands,
            );
        } finally {
            $lock->release();
        }

        // Assert
        self::assertSame(array_fill(0, 3, ['RESOURCE_BUSY', self::BUSY]), $answers);
        self::assertSame(0, $this->rows('nutrition_assessment'));
    }

    public function testASubmissionMadeAfterThePageWasReadRefusesTheSave(): void
    {
        // Initialize
        $page = $this->query(
            new GetNutritionPeriodCommand((int) $this->adviser->getId(), null, null, NutritionPeriod::Baseline),
        );
        self::assertTrue($page->isSuccess());
        $this->sql(
            'INSERT INTO sf8_submission (section_id, period, submitted_by_id, submitted_at) VALUES (?, ?, ?, ?)',
            [$this->artistic->getId(), 'baseline', $this->adviser->getId(), '2025-08-22 09:00:00'],
        );

        // Execute
        $result = $this->save('2025-08-04', [new MeasurementInput($this->id(0), 31.5, 1.32, null)]);

        // Assert
        self::assertSame(
            [NutritionException::class, 'Baseline is submitted and locked.'],
            [get_debug_type($result->exception()), $result->exception()?->getMessage()],
        );
    }

    public function testChangesMadeAfterThePageWasReadAreSeenUnderTheLock(): void
    {
        // Initialize
        self::assertTrue(
            $this->save('2025-08-04', [new MeasurementInput($this->id(0), 31.5, 1.32, null)])->isSuccess(),
        );
        $adviserId = (int) $this->adviser->getId();
        $sectionId = (int) $this->artistic->getId();
        $page = $this->query(new GetNutritionPeriodCommand($adviserId, null, null, NutritionPeriod::Baseline));
        self::assertSame(1, $this->data($page)['unweighed']);
        // another request weighs Bañez before this one submits
        $assessmentId = $this->sql('SELECT id FROM nutrition_assessment', []);
        $this->sql(
            'INSERT INTO nutrition_measurement (assessment_id, enrollment_id, weight_kg, height_m) VALUES (?, ?, ?, ?)',
            [$assessmentId, $this->id(1), '30.00', '1.30'],
        );

        // Execute
        $submitted = $this->handle(new SubmitSf8Command($adviserId, $sectionId, NutritionPeriod::Baseline, false));
        // another request reopens it before this one does
        $this->sql('DELETE FROM sf8_submission', []);
        $reopened = $this->handle(new ReopenSf8Command(
            (int) $this->principal->getId(),
            (int) $this->school->getId(),
            $sectionId,
            NutritionPeriod::Baseline,
        ));
        $this->sql(
            'INSERT INTO sf8_submission (section_id, period, submitted_by_id, submitted_at) VALUES (?, ?, ?, ?)',
            [$sectionId, 'baseline', $adviserId, '2025-08-22 09:00:00'],
        );
        $again = $this->handle(new SubmitSf8Command($adviserId, $sectionId, NutritionPeriod::Baseline, true));

        // Assert
        self::assertTrue($submitted->isSuccess());
        self::assertSame('Baseline is not submitted.', $reopened->exception()?->getMessage());
        self::assertSame('Baseline is already submitted.', $again->exception()?->getMessage());
    }

    public function testAnEndlineDateMovedElsewhereIsSeenUnderTheLock(): void
    {
        // Initialize
        self::assertTrue($this->save('2025-08-04', [])->isSuccess());
        $page = $this->query(
            new GetNutritionPeriodCommand((int) $this->adviser->getId(), null, null, NutritionPeriod::Baseline),
        );
        self::assertTrue($page->isSuccess());
        $this->sql('UPDATE nutrition_assessment SET weighed_on = ?', ['2025-08-10']);

        // Execute
        $result = $this->handle(new SaveWeighingCommand(
            (int) $this->adviser->getId(),
            (int) $this->artistic->getId(),
            NutritionPeriod::Endline,
            new \DateTimeImmutable('2025-08-08'),
            [],
        ));

        // Assert
        self::assertSame('The endline must be after the baseline (Aug 10, 2025).', $result->exception()?->getMessage());
    }

    public function testRefusesWhatTheAccountOrThePageMayNotReach(): void
    {
        // Initialize
        $teacher = $this->plainUser('cara', User::ROLE_TEACHER);
        $admin = $this->plainUser('zim.admin', User::ROLE_ADMIN);
        $this->honest->setAdviser($admin);
        $this->em->flush();
        $sectionId = (int) $this->artistic->getId();

        // Execute
        $principalSaves = $this->handle(new SaveWeighingCommand(
            (int) $this->principal->getId(),
            $sectionId,
            NutritionPeriod::Baseline,
            new \DateTimeImmutable('2025-08-04'),
            [],
        ));
        $otherSection = $this->handle(new SubmitSf8Command(
            (int) $this->adviser->getId(),
            (int) $this->honest->getId(),
            NutritionPeriod::Baseline,
            true,
        ));
        $adminFills = $this->handle(new SaveWeighingCommand(
            (int) $admin->getId(),
            (int) $this->honest->getId(),
            NutritionPeriod::Baseline,
            new \DateTimeImmutable('2025-08-04'),
            [],
        ));
        $adviserReopens = $this->handle(new ReopenSf8Command(
            (int) $this->adviser->getId(),
            (int) $this->school->getId(),
            $sectionId,
            NutritionPeriod::Baseline,
        ));
        $teacherIndex = $this->query(new GetNutritionIndexCommand((int) $teacher->getId()));
        $unknownSection = $this->query(new GetNutritionPeriodCommand(
            (int) $this->principal->getId(),
            (int) $this->school->getId(),
            $sectionId + 1000,
            NutritionPeriod::Baseline,
        ));

        // Assert
        self::assertSame(
            [NutritionAccessDeniedException::class, 'You do not advise a section this school year.'],
            [get_debug_type($principalSaves->exception()), $principalSaves->exception()?->getMessage()],
        );
        self::assertSame('You do not advise a section this school year.', $otherSection->exception()?->getMessage());
        self::assertSame(
            [NutritionAccessDeniedException::class, 'You can no longer weigh this section.'],
            [get_debug_type($adminFills->exception()), $adminFills->exception()?->getMessage()],
        );
        self::assertInstanceOf(NutritionAccessDeniedException::class, $adviserReopens->exception());
        self::assertSame('You do not advise a section this school year.', $teacherIndex->exception()?->getMessage());
        self::assertInstanceOf(SectionNotFoundException::class, $unknownSection->exception());
    }

    public function testTheQueriesGiveThePagesWhatTheyShow(): void
    {
        // Initialize
        self::assertTrue(
            $this->save('2025-08-04', [new MeasurementInput($this->id(0), 31.5, 1.32, null)])->isSuccess(),
        );
        $adviserId = (int) $this->adviser->getId();
        $principalId = (int) $this->principal->getId();
        $schoolId = (int) $this->school->getId();
        $sectionId = (int) $this->artistic->getId();

        // Execute
        /** @var array{section: Section, reports: list<Sf8Report>} $index */
        $index = $this->data($this->query(new GetNutritionIndexCommand($adviserId)));
        /** @var array{canFill: bool, canReopen: bool, unweighed: int, report: Sf8Report} $adviserPage */
        $adviserPage = $this->data(
            $this->query(new GetNutritionPeriodCommand($adviserId, null, null, NutritionPeriod::Baseline)),
        );
        /** @var array{canFill: bool, canReopen: bool, report: Sf8Report} $schoolPage */
        $schoolPage = $this->data($this->query(
            new GetNutritionPeriodCommand($principalId, $schoolId, $sectionId, NutritionPeriod::Endline),
        ));
        /** @var Overview $overview */
        $overview = $this->data($this->query(
            new GetNutritionOverviewCommand($principalId, $schoolId, NutritionPeriod::Endline, false),
        ));
        /** @var Overview $compare */
        $compare = $this->data($this->query(
            new GetNutritionOverviewCommand($principalId, $schoolId, NutritionPeriod::Endline, true),
        ));
        /** @var array{fileName: string, content: string} $file */
        $file = $this->data($this->query(new GetSf8FileCommand($adviserId, null, null, NutritionPeriod::Baseline)));

        // Assert
        self::assertSame($sectionId, $index['section']->getId());
        self::assertSame(
            [Sf8Status::Draft, Sf8Status::NotStarted],
            array_map(static fn (Sf8Report $r) => $r->status, $index['reports']),
        );
        self::assertSame(
            [true, false, 1, Sf8Status::Draft],
            [
                $adviserPage['canFill'],
                $adviserPage['canReopen'],
                $adviserPage['unweighed'],
                $adviserPage['report']->status,
            ],
        );
        self::assertSame(
            [false, true, NutritionPeriod::Endline],
            [$schoolPage['canFill'], $schoolPage['canReopen'], $schoolPage['report']->period],
        );
        self::assertSame(
            [NutritionPeriod::Endline, [], null, $this->sy->getId()],
            [
                $overview['reports'][0]->period,
                $overview['endlines'],
                $overview['endlineTotal'],
                $overview['schoolYear']->getId(),
            ],
        );
        self::assertCount(2, $overview['reports']);
        self::assertSame(NutritionPeriod::Baseline, $compare['reports'][0]->period);
        self::assertSame([(int) $this->honest->getId(), $sectionId], array_keys($compare['endlines']));
        self::assertSame(1, $compare['total']->weighed());
        self::assertSame(0, $compare['endlineTotal']?->weighed());
        self::assertSame('SF8_303196_Grade 8-ARTISTIC_Baseline_2025-2026.xlsx', $file['fileName']);
        self::assertStringStartsWith('PK', $file['content']);
    }

    public function testTheOverviewNeedsACurrentSchoolYear(): void
    {
        // Initialize
        $this->sy->setCurrent(false);
        $this->em->flush();

        // Execute
        $result = $this->query(new GetNutritionOverviewCommand(
            (int) $this->principal->getId(),
            (int) $this->school->getId(),
            NutritionPeriod::Baseline,
            false,
        ));

        // Assert
        self::assertInstanceOf(SchoolYearNotFoundException::class, $result->exception());
        self::assertSame('No current school year.', $result->exception()->getMessage());
    }

    /** @param list<MeasurementInput> $inputs */
    private function save(string $date, array $inputs): Result
    {
        return $this->handle(new SaveWeighingCommand(
            (int) $this->adviser->getId(),
            (int) $this->artistic->getId(),
            NutritionPeriod::Baseline,
            new \DateTimeImmutable($date),
            $inputs,
        ));
    }

    private function id(int $i): int
    {
        return (int) $this->enrolled[$i]->getId();
    }

    /** @return array{?string, ?string} */
    private function answer(Result $result): array
    {
        return [$result->errorCode(), $result->exception()?->getMessage()];
    }

    private function rows(string $table): int
    {
        $count = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $table);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    /** @param list<mixed> $params */
    private function sql(string $sql, array $params): mixed
    {
        if (str_starts_with($sql, 'SELECT')) {
            return $this->em->getConnection()->fetchOne($sql, $params);
        }

        return $this->em->getConnection()->executeStatement($sql, $params);
    }

    /** @return array<string, mixed> */
    private function data(Result $result): array
    {
        self::assertTrue($result->isSuccess(), (string) $result->exception()?->getMessage());
        $data = $result->data();
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    private function handle(UseCase $command): Result
    {
        return static::getContainer()->get(UseCaseBusInterface::class)->handle($command);
    }

    private function query(Query $command): Result
    {
        return static::getContainer()->get(QueryBusInterface::class)->handle($command);
    }
}
