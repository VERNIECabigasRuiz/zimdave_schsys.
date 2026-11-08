<?php

declare(strict_types=1);

namespace App\Tests\LessonPlans\Integration\Application;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\LessonPlans\Application\Query\Command\GetLessonPlanCommand;
use App\LessonPlans\Application\Query\Command\GetLessonPlanIdCommand;
use App\LessonPlans\Application\Query\Command\GetLessonPlanPdfCommand;
use App\LessonPlans\Application\Query\Command\GetLessonPlanStartCommand;
use App\LessonPlans\Application\Query\Command\GetSchoolLessonPlansCommand;
use App\LessonPlans\Application\Query\Command\GetTeacherLessonPlansCommand;
use App\LessonPlans\Application\Service\LessonPlanPolicy;
use App\LessonPlans\Application\UseCase\Command\DeleteLessonPlanCommand;
use App\LessonPlans\Application\UseCase\Command\SaveLessonPlanCommand;
use App\LessonPlans\Application\UseCase\Command\StartLessonPlanCommand;
use App\LessonPlans\Domain\Exception\LessonPlanAccessDeniedException;
use App\LessonPlans\Domain\Exception\LessonPlanNotFoundException;
use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Subject;
use App\Classes\Domain\Model\TeachingLoad;
use App\LessonPlans\Domain\Model\LessonPlan;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class LessonPlanHandlersTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private User $tess;
    private User $ana;
    private User $rene;
    private TeachingLoad $load;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2026-11-24 10:00:00');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school('303196', 'Pagsabungan NHS');
        $this->tess = $this->plainUser('tess', User::ROLE_TEACHER);
        $this->ana = $this->plainUser('ana', User::ROLE_TEACHER);
        $this->rene = $this->plainUser('rene', User::ROLE_PRINCIPAL);
        $this->assign($this->school, $this->sy, $this->tess, User::ROLE_TEACHER);
        $this->assign($this->school, $this->sy, $this->ana, User::ROLE_TEACHER);
        $this->assign($this->school, $this->sy, $this->rene);
        $this->load = $this->load($this->tess, 'Artistic');
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    public function testStartsAWeekOnceAndFindsItsPlan(): void
    {
        // Initialize
        $tessId = (int) $this->tess->getId();
        $loadId = (int) $this->load->getId();

        // Execute
        $first = $this->handle(new StartLessonPlanCommand($tessId, $loadId, new \DateTimeImmutable('2026-11-25')));
        $again = $this->handle(new StartLessonPlanCommand($tessId, $loadId, new \DateTimeImmutable('2026-11-27')));
        $id = $this->data(
            $this->query(new GetLessonPlanIdCommand($tessId, $loadId, new \DateTimeImmutable('2026-11-23'))),
        );

        // Assert
        self::assertTrue($first->isSuccess());
        self::assertTrue($again->isSuccess());
        $plans = $this->em->getRepository(LessonPlan::class)->findAll();
        self::assertCount(1, $plans);
        self::assertSame($plans[0]->getId(), $id['id']);
        self::assertSame('2026-11-23', $plans[0]->getWeekStart()->format('Y-m-d'));
        self::assertSame(['2026-11-23', '2026-11-24', '2026-11-25'], array_column($plans[0]->getDays(), 'date'));
    }

    public function testRefusesAnotherTeachersClassAnUnknownClassAndAMissingPlan(): void
    {
        // Initialize
        $anas = $this->load($this->ana, 'Superb');
        $tessId = (int) $this->tess->getId();
        $week = new \DateTimeImmutable('2026-11-23');

        // Execute
        $others = $this->handle(new StartLessonPlanCommand($tessId, (int) $anas->getId(), $week));
        $unknown = $this->handle(new StartLessonPlanCommand($tessId, -1, $week));
        $noPlan = $this->query(new GetLessonPlanIdCommand($tessId, (int) $this->load->getId(), $week));

        // Assert
        self::assertInstanceOf(LessonPlanAccessDeniedException::class, $others->exception());
        self::assertInstanceOf(LessonPlanNotFoundException::class, $unknown->exception());
        self::assertInstanceOf(LessonPlanNotFoundException::class, $noPlan->exception());
        self::assertSame([], $this->em->getRepository(LessonPlan::class)->findAll());
    }

    public function testSavingTrimsTheTextKeepsTheDaysAndMovesTheUpdatedTime(): void
    {
        // Initialize
        $plan = $this->lessonPlan($this->load);
        $days = $plan->getDays();
        $days[0]['objectives'] = "Add fractions\nCompare fractions";
        static::mockTime('2026-11-24 15:30:00');

        // Execute
        $saved = $this->handle(new SaveLessonPlanCommand(
            (int) $this->tess->getId(),
            (int) $plan->getId(),
            '  Fractions  ',
            ' M8NS-Ia-1 ',
            '   ',
            null,
            ' Drafted with help ',
            $days,
        ));

        // Assert
        self::assertTrue($saved->isSuccess());
        $this->em->clear();
        $reloaded = $this->em->find(LessonPlan::class, $plan->getId());
        self::assertNotNull($reloaded);
        self::assertSame(
            ['Fractions', 'M8NS-Ia-1', null, null, 'Drafted with help'],
            [
                $reloaded->getTopic(),
                $reloaded->getCompetencies(),
                $reloaded->getClassNotes(),
                $reloaded->getReferences(),
                $reloaded->getAiDeclaration(),
            ],
        );
        self::assertSame("Add fractions\nCompare fractions", $reloaded->getDays()[0]['objectives']);
        self::assertSame('Bonifacio Day', $reloaded->getDays()[1]['holiday']);
        self::assertSame('2026-11-24 15:30', $reloaded->getUpdatedAt()->format('Y-m-d H:i'));
    }

    public function testOnlyTheTeacherSavesOrDeletesAndAnUnknownPlanIsNotFound(): void
    {
        // Initialize
        $plan = $this->lessonPlan($this->load);
        $planId = (int) $plan->getId();
        $anaId = (int) $this->ana->getId();

        // Execute
        $anaSaves = $this->handle(new SaveLessonPlanCommand($anaId, $planId, 'Mine now', 'C1', null, null, null, []));
        $anaDeletes = $this->handle(new DeleteLessonPlanCommand($anaId, $planId));
        $unknown = $this->handle(new DeleteLessonPlanCommand((int) $this->tess->getId(), -1));
        $deleted = $this->handle(new DeleteLessonPlanCommand((int) $this->tess->getId(), $planId));

        // Assert
        self::assertInstanceOf(LessonPlanAccessDeniedException::class, $anaSaves->exception());
        self::assertInstanceOf(LessonPlanAccessDeniedException::class, $anaDeletes->exception());
        self::assertInstanceOf(LessonPlanNotFoundException::class, $unknown->exception());
        self::assertTrue($deleted->isSuccess());
        self::assertSame([], $this->em->getRepository(LessonPlan::class)->findAll());
    }

    public function testTheQueriesGiveThePagesWhatTheyShow(): void
    {
        // Initialize
        $plan = $this->lessonPlan($this->load);
        $tessId = (int) $this->tess->getId();
        $reneId = (int) $this->rene->getId();
        $lone = $this->plainUser('lone', User::ROLE_PRINCIPAL);
        $outsider = $this->plainUser('omar', User::ROLE_TEACHER);

        // Execute
        /** @var array{plans: list<LessonPlan>} $mine */
        $mine = $this->data($this->query(new GetTeacherLessonPlansCommand($tessId)));
        /** @var array{loads: list<TeachingLoad>, today: \DateTimeImmutable} $start */
        $start = $this->data($this->query(new GetLessonPlanStartCommand($tessId)));
        /** @var array{loads: list<TeachingLoad>} $outsiderStart */
        $outsiderStart = $this->data($this->query(new GetLessonPlanStartCommand((int) $outsider->getId())));
        /** @var array{plan: LessonPlan} $viewed */
        $viewed = $this->data(
            $this->query(new GetLessonPlanCommand($reneId, (int) $plan->getId(), LessonPlanPolicy::VIEW)),
        );
        $editByPrincipal = $this->query(
            new GetLessonPlanCommand($reneId, (int) $plan->getId(), LessonPlanPolicy::EDIT),
        );
        /** @var array{school: School, schoolYear: SchoolYear, plans: list<LessonPlan>} $school */
        $school = $this->data($this->query(new GetSchoolLessonPlansCommand($reneId)));
        $unassigned = $this->data($this->query(new GetSchoolLessonPlansCommand((int) $lone->getId())));
        /** @var array{content: string, fileName: string} $pdf */
        $pdf = $this->data($this->query(new GetLessonPlanPdfCommand($reneId, (int) $plan->getId())));

        // Assert
        self::assertSame([$plan->getId()], array_map(static fn (LessonPlan $p) => $p->getId(), $mine['plans']));
        self::assertSame(
            [$this->load->getId()],
            array_map(static fn (TeachingLoad $l) => $l->getId(), $start['loads']),
        );
        self::assertSame('2026-11-24 00:00', $start['today']->format('Y-m-d H:i'));
        self::assertSame([], $outsiderStart['loads']);
        self::assertSame($plan->getId(), $viewed['plan']->getId());
        self::assertInstanceOf(LessonPlanAccessDeniedException::class, $editByPrincipal->exception());
        self::assertSame(
            [$this->school->getId(), $this->sy->getId(), [$plan->getId()]],
            [
                $school['school']->getId(),
                $school['schoolYear']->getId(),
                array_map(static fn (LessonPlan $p) => $p->getId(), $school['plans']),
            ],
        );
        self::assertSame([null, []], [$unassigned['school'], $unassigned['plans']]);
        self::assertStringStartsWith('%PDF', $pdf['content']);
        self::assertSame('ILAW - Mathematics - Artistic - 2026-11-23.pdf', $pdf['fileName']);
    }

    public function testWithoutACurrentYearTheListsAreEmpty(): void
    {
        // Initialize
        $this->lessonPlan($this->load);
        $this->sy->setCurrent(false);
        $this->em->flush();

        // Execute
        $mine = $this->data($this->query(new GetTeacherLessonPlansCommand((int) $this->tess->getId())));
        $start = $this->data($this->query(new GetLessonPlanStartCommand((int) $this->tess->getId())));
        $school = $this->data($this->query(new GetSchoolLessonPlansCommand((int) $this->rene->getId())));

        // Assert
        self::assertSame(
            [[], [], null, null],
            [$mine['plans'], $start['loads'], $school['school'], $school['schoolYear']],
        );
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

    private function load(User $teacher, string $section): TeachingLoad
    {
        $subject = (new Subject($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade8)
            ->setCode('MATH8-' . $section)->setName('Mathematics')->setPosition(1)->setMeetingsPerWeek(3);
        $this->em->persist($subject);
        $load = new TeachingLoad(
            $this->newSection($this->school, $this->sy, GradeLevel::Grade8, $section),
            $subject,
            $teacher,
        );
        $this->em->persist($load);
        $this->em->flush();

        return $load;
    }

    private function lessonPlan(TeachingLoad $load, string $monday = '2026-11-23'): LessonPlan
    {
        $plan = (new LessonPlan($load, new \DateTimeImmutable($monday), new \DateTimeImmutable('2026-11-20 08:00')))
            ->setDays([LessonPlan::emptyDay('2026-11-23'), LessonPlan::emptyDay('2026-11-24', 'Bonifacio Day')]);
        $this->em->persist($plan);
        $this->em->flush();

        return $plan;
    }
}
