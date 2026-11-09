<?php

declare(strict_types=1);

namespace App\Tests\Classes\Integration\Application;

use App\Classes\Application\Query\Command\GetClassPeriodFormCommand;
use App\Classes\Application\Query\Command\ListClassPeriodsCommand;
use App\Classes\Application\UseCase\Command\CreateClassPeriodCommand;
use App\Classes\Application\UseCase\Command\DeleteClassPeriodCommand;
use App\Classes\Application\UseCase\Command\UpdateClassPeriodCommand;
use App\Classes\Domain\Model\ClassPeriod;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Core\Domain\Model\ActivityLog;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ClassPeriodHandlersTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school('303456', 'Alpha NHS');
    }

    public function testAddsEditsAndDeletesPeriodsInDayOrder(): void
    {
        // Initialize
        $existing = $this->classPeriod($this->school, $this->sy, '08:30', '09:30');
        $create = new CreateClassPeriodCommand((int) $this->school->getId());
        $create->startsAt = new \DateTimeImmutable('07:30');
        $create->endsAt = new \DateTimeImmutable('08:30');
        $toBreak = new UpdateClassPeriodCommand((int) $this->school->getId(), (int) $existing->getId());
        $toBreak->startsAt = new \DateTimeImmutable('08:30');
        $toBreak->endsAt = new \DateTimeImmutable('08:50');
        $toBreak->isBreak = true;
        $toBreak->breakLabel = 'Recess';

        // Execute
        $added = $this->handle($create);
        $updated = $this->handle($toBreak);
        $deleted = $this->handle(new DeleteClassPeriodCommand((int) $this->school->getId(), (int) $existing->getId()));

        // Assert
        self::assertSame(['label' => 'Period 1'], $added->data());
        self::assertSame(['label' => 'Recess'], $updated->data());
        self::assertSame(['label' => 'Recess'], $deleted->data());
        $this->em->clear();
        $left = $this->em->getRepository(ClassPeriod::class)->findAll();
        self::assertSame([['07:30', 1]], array_map(
            static fn (ClassPeriod $p) => [$p->getStartsAt()?->format('H:i'), $p->getPosition()],
            $left,
        ));
        self::assertSame([
            'Added class period Period 1',
            'Updated class period Recess',
            'Deleted class period Recess',
        ], array_map(
            static fn (ActivityLog $log) => $log->getAction(),
            $this->em->getRepository(ActivityLog::class)->findBy([], ['id' => 'ASC']),
        ));
    }

    public function testWithoutACurrentYearTheListIsEmptyAndTheFormIsRefused(): void
    {
        // Initialize
        $this->sy->setCurrent(false);
        $this->em->flush();

        // Execute
        $list = $this->query(new ListClassPeriodsCommand((int) $this->school->getId()));
        $form = $this->query(new GetClassPeriodFormCommand((int) $this->school->getId()));

        // Assert
        self::assertSame(['schoolYear' => null, 'periods' => [], 'numbers' => [], 'refusal' => null], $list->data());
        self::assertSame('No current school year.', $form->exception()?->getMessage());
    }

    public function testAnExpiredPlanRefusesPeriodChanges(): void
    {
        // Initialize
        $expired = $this->school('300003', 'Gamma NHS', planValidUntil: new \DateTimeImmutable('2020-01-01'));
        $create = new CreateClassPeriodCommand((int) $expired->getId());
        $create->startsAt = new \DateTimeImmutable('07:30');
        $create->endsAt = new \DateTimeImmutable('08:30');

        // Execute
        $result = $this->handle($create);

        // Assert
        self::assertSame('PLAN_LIMIT', $result->errorCode());
        self::assertSame(0, $this->em->getRepository(ClassPeriod::class)->count([]));
    }

    public function testAPeriodOfAnotherSchoolIsNotFound(): void
    {
        // Initialize
        $other = $this->classPeriod($this->school('300002', 'Beta NHS'), $this->sy, '07:30', '08:30');

        // Execute
        $result = $this->query(new GetClassPeriodFormCommand((int) $this->school->getId(), (int) $other->getId()));

        // Assert
        self::assertSame('CLASS_PERIOD_NOT_FOUND', $result->errorCode());
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
