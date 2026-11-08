<?php

declare(strict_types=1);

namespace App\Tests\Grading\Integration\Application;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Section;
use App\Classes\Domain\Model\Subject;
use App\Classes\Domain\Model\TeachingLoad;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Grading\Application\Query\Command\GetCardSlipPdfCommand;
use App\Grading\Application\Query\Command\GetCardSlipsCommand;
use App\Grading\Application\UseCase\Command\ResetCardSlipCommentCommand;
use App\Grading\Application\UseCase\Command\SaveCardSlipCommentsCommand;
use App\Grading\Domain\Exception\GradingAccessDeniedException;
use App\Grading\Domain\Exception\GradingNotFoundException;
use App\Learners\Domain\Model\Enrollment;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\GradingPeriod;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class CardSlipHandlersTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SavesGrades;
    use SchoolFixturesTrait;

    protected EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private Section $artistic;
    private GradingPeriod $term1;
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
        $this->sy->rebuildGradingPeriods();
        $this->em->flush();
        $this->term1 = $this->sy->getGradingPeriod(1) ?? throw new \LogicException('No period 1');
        $this->school = $this->school('303196', 'Pagsabungan NHS');
        $this->adviser = $this->plainUser('dave', User::ROLE_TEACHER);
        $this->principal = $this->plainUser('rene', User::ROLE_PRINCIPAL);
        $this->assign($this->school, $this->sy, $this->principal);
        $this->assign($this->school, $this->sy, $this->adviser, User::ROLE_TEACHER);
        $this->artistic = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Artistic')
            ->setAdviser($this->adviser);
        $math = (new Subject($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade8)->setCode('MATH8')
            ->setName('Mathematics')->setPosition(1)->setMeetingsPerWeek(5);
        $this->em->persist($math);
        $load = new TeachingLoad($this->artistic, $math, $this->adviser);
        $this->em->persist($load);
        foreach (['Arnol', 'Bañez'] as $i => $name) {
            $this->enrolled[] = $this->enroll(
                $this->learner('12001717000' . $i, $name, 'Learner'),
                $this->sy,
                $this->artistic,
                $this->adviser,
            );
        }
        $this->em->flush();
        $this->openGrading($this->school, $this->term1);
        $this->savingRecorder()->saveSheet(
            $load,
            $this->term1,
            [$this->id(0) => 92, $this->id(1) => 70],
            $this->adviser,
        );
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    public function testSavesAndResetsCommentsThroughTheBus(): void
    {
        // Initialize
        $adviserId = (int) $this->adviser->getId();
        $periodId = (int) $this->term1->getId();
        $entries = [$this->id(0) => ['codes' => ['excellent'], 'text' => 'Keep it up.']];

        // Execute
        $saved = $this->handle(new SaveCardSlipCommentsCommand($adviserId, $periodId, $entries, []));
        $countAfterSave = $this->commentCount();
        $reset = $this->handle(new ResetCardSlipCommentCommand($adviserId, $periodId, $this->id(0)));

        // Assert
        self::assertSame([1, 1], [$saved->data(), $countAfterSave]);
        self::assertSame('ARNOL, LEARNER, -', $reset->data());
        self::assertSame(0, $this->commentCount());
    }

    public function testTickedCommentsWithoutASuggestionAreSaved(): void
    {
        // Initialize
        $entries = [$this->id(0) => ['codes' => ['excellent'], 'text' => '']];
        $adviserId = (int) $this->adviser->getId();
        $saving = new SaveCardSlipCommentsCommand($adviserId, (int) $this->term1->getId(), $entries, []);

        // Execute
        $saved = $this->handle($saving);

        // Assert
        self::assertSame([1, 1], [$saved->data(), $this->commentCount()]);
    }

    public function testRefusesWhatTheAccountMayNotReach(): void
    {
        // Initialize
        $teacher = $this->plainUser('cara', User::ROLE_TEACHER);
        $admin = $this->plainUser('admin.slips', User::ROLE_ADMIN);
        $other = $this->school('303197', 'Other NHS');
        $otherSection = $this->newSection($other, $this->sy, GradeLevel::Grade8, 'Rizal');
        $periodId = (int) $this->term1->getId();

        // Execute
        $notAnAdviser = $this->query(new GetCardSlipsCommand((int) $teacher->getId(), null));
        $adminSaves = $this->handle(new SaveCardSlipCommentsCommand((int) $admin->getId(), $periodId, [], []));
        $otherSchool = $this->query(new GetCardSlipPdfCommand(
            (int) $this->principal->getId(),
            (int) $this->school->getId(),
            (int) $otherSection->getId(),
            $periodId,
            null,
        ));
        $noLearner = $this->handle(new ResetCardSlipCommentCommand((int) $this->adviser->getId(), $periodId, -1));
        $noPeriod = $this->query(new GetCardSlipsCommand((int) $this->adviser->getId(), -1));

        // Assert
        self::assertSame('You do not advise a section this school year.', $notAnAdviser->exception()?->getMessage());
        self::assertInstanceOf(GradingAccessDeniedException::class, $adminSaves->exception());
        self::assertInstanceOf(GradingNotFoundException::class, $otherSchool->exception());
        self::assertSame('No such learner in your class.', $noLearner->exception()?->getMessage());
        self::assertSame('No such grading period.', $noPeriod->exception()?->getMessage());
    }

    public function testTheQueriesGiveThePagesWhatTheyShow(): void
    {
        // Initialize
        $empty = $this->newSection($this->school, $this->sy, GradeLevel::Grade7, 'Empty');
        $periodId = (int) $this->term1->getId();
        $principalId = (int) $this->principal->getId();

        // Execute
        $page = $this->query(new GetCardSlipsCommand((int) $this->adviser->getId(), null))->data();
        $adviserId = (int) $this->adviser->getId();
        $all = $this->query(new GetCardSlipPdfCommand($adviserId, null, null, $periodId, null))->data();
        $one = $this->query(new GetCardSlipPdfCommand(
            $principalId,
            (int) $this->school->getId(),
            (int) $this->artistic->getId(),
            $periodId,
            $this->id(0),
        ))->data();
        $none = $this->query(new GetCardSlipPdfCommand(
            $principalId,
            (int) $this->school->getId(),
            (int) $empty->getId(),
            $periodId,
            null,
        ))->data();

        // Assert
        /** @var array{rows: list<array<string, mixed>>, locked: bool, period: GradingPeriod} $page */
        self::assertSame([2, false, $periodId], [\count($page['rows']), $page['locked'], $page['period']->getId()]);
        /** @var array{content: ?string, fileName: string} $all */
        self::assertStringStartsWith('%PDF', (string) $all['content']);
        self::assertSame('Card slips - Grade 8 ARTISTIC - Term 1.pdf', $all['fileName']);
        /** @var array{fileName: string} $one */
        self::assertSame('Card slip - ARNOL, LEARNER, - - Term 1.pdf', $one['fileName']);
        /** @var array{content: ?string} $none */
        self::assertNull($none['content']);
    }

    private function commentCount(): int
    {
        $count = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM card_slip_comment');
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function id(int $i): int
    {
        return (int) $this->enrolled[$i]->getId();
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
