<?php

declare(strict_types=1);

namespace App\Tests\Staff\Integration\Application;

use App\Access\Domain\Model\User;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Staff\Application\Query\Command\GetServiceRecordCommand;
use App\Staff\Application\Query\Command\GetServiceRecordPdfCommand;
use App\Staff\Application\Query\Command\GetServiceRowFormCommand;
use App\Staff\Application\UseCase\Command\DeleteServiceRowCommand;
use App\Staff\Application\UseCase\Command\SaveBpNumberCommand;
use App\Staff\Application\UseCase\Command\SaveServiceRowCommand;
use App\Staff\Domain\Exception\StaffNotFoundException;
use App\Staff\Domain\Model\ServiceRecord;
use App\Staff\Domain\Model\ServiceRecordEntry;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class ServiceRecordHandlersTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private User $rosa;
    private User $tess;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2026-11-02 10:00:00');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school('303196', 'Pagsabungan NHS');
        $this->school->setAddress('Z. Estreras, Pagsabungan, Mandaue City');
        $this->rosa = $this->plainUser('rosa', User::ROLE_PRINCIPAL)->setFullName('Rosa Cruz');
        $this->assign($this->school, $this->sy, $this->rosa);
        $this->tess = $this->plainUser('tess', User::ROLE_TEACHER)->setFullName('Tess Reyes');
        $this->assign($this->school, $this->sy, $this->tess, User::ROLE_TEACHER);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    public function testTheBpNumberIsCutTo30AndABlankOneClearsIt(): void
    {
        // Initialize
        $long = str_repeat('7', 35);

        // Execute
        $saved = $this->handle(new SaveBpNumberCommand($this->rosaId(), $this->schoolId(), $this->tessId(), $long));
        $kept = $this->record()?->getBpNumber();
        $this->handle(new SaveBpNumberCommand($this->rosaId(), $this->schoolId(), $this->tessId(), '  '));

        // Assert
        self::assertTrue($saved->isSuccess());
        self::assertSame(str_repeat('7', 30), $kept);
        self::assertNull($this->record()?->getBpNumber());
    }

    public function testAFirstRowIsDraftedFromTheSchoolAndSaved(): void
    {
        // Initialize
        $form = $this->query(new GetServiceRowFormCommand($this->rosaId(), $this->schoolId(), $this->tessId()));
        $data = $form->data();
        self::assertIsArray($data);
        $row = $data['row'];
        self::assertInstanceOf(SaveServiceRowCommand::class, $row);
        $drafted = [$row->fromDate?->format('Y-m-d'), $row->station, $row->branch, $row->closeOpen];
        $row->designation = 'Teacher I';
        $row->status = 'Regular/Permanent';
        $row->annualSalary = '300000';

        // Execute
        $result = $this->handle($row);

        // Assert
        self::assertTrue($result->isSuccess());
        self::assertSame(['2026-11-02', 'Mandaue City', 'National', false], $drafted);
        self::assertFalse($data['canCloseOpen']);
        self::assertSame([['2026-11-02', null, 'Teacher I', '300000.00']], $this->rows());
        self::assertSame($this->rosaId(), $this->entries()[0]->getUpdatedBy()?->getId());
    }

    public function testAddingAfterAnOpenRowOffersToCloseItAndClosesIt(): void
    {
        // Initialize
        $this->entry('2024-01-01', null);
        $data = $this->query(new GetServiceRowFormCommand($this->rosaId(), $this->schoolId(), $this->tessId()))->data();
        self::assertIsArray($data);
        $row = $data['row'];
        self::assertInstanceOf(SaveServiceRowCommand::class, $row);
        $drafted = [$row->fromDate, $row->designation, $row->closeOpen, $data['canCloseOpen']];
        $row->fromDate = new \DateTimeImmutable('2026-07-20');
        $row->annualSalary = '443028';

        // Execute
        $this->handle($row);

        // Assert
        self::assertSame([null, 'Teacher III', true, true], $drafted);
        self::assertSame(
            [
                ['2024-01-01', '2026-07-19', 'Teacher III', '420588.00'],
                ['2026-07-20', null, 'Teacher III', '443028.00'],
            ],
            $this->rows(),
        );
    }

    public function testARowAfterAClosedOneStartsTheDayAfter(): void
    {
        // Initialize
        $this->entry('2024-01-01', '2025-12-31');

        // Execute
        $data = $this->query(new GetServiceRowFormCommand($this->rosaId(), $this->schoolId(), $this->tessId()))->data();

        // Assert
        self::assertIsArray($data);
        self::assertInstanceOf(SaveServiceRowCommand::class, $data['row']);
        self::assertSame('2026-01-01', $data['row']->fromDate?->format('Y-m-d'));
        self::assertFalse($data['canCloseOpen']);
    }

    public function testEditingChangesTheRowInPlace(): void
    {
        // Initialize
        $entry = $this->entry('2024-01-01', null);
        $data = $this->query(
            new GetServiceRowFormCommand($this->rosaId(), $this->schoolId(), $this->tessId(), $entry->getId()),
        )->data();
        self::assertIsArray($data);
        $row = $data['row'];
        self::assertInstanceOf(SaveServiceRowCommand::class, $row);
        $loaded = [$row->entryId, $row->designation, $row->annualSalary, $data['canCloseOpen']];
        $row->toDate = new \DateTimeImmutable('2024-12-31');
        $row->remarks = 'SG13-4';

        // Execute
        $this->handle($row);

        // Assert
        self::assertSame([$entry->getId(), 'Teacher III', '420588.00', false], $loaded);
        self::assertSame([['2024-01-01', '2024-12-31', 'Teacher III', '420588.00']], $this->rows());
        self::assertSame('SG13-4', $this->entries()[0]->getRemarks());
    }

    public function testDeletingRemovesTheRow(): void
    {
        // Initialize
        $entry = $this->entry('2024-01-01', null);

        // Execute
        $result = $this->handle(
            new DeleteServiceRowCommand($this->rosaId(), $this->schoolId(), $this->tessId(), (int) $entry->getId()),
        );

        // Assert
        self::assertTrue($result->isSuccess());
        self::assertSame([], $this->rows());
    }

    public function testThePersonTheRowAndTheYearMustBeFound(): void
    {
        // Initialize
        $other = $this->school('303197', 'Other NHS');
        $owen = $this->plainUser('owen', User::ROLE_TEACHER);
        $this->assign($other, $this->sy, $owen, User::ROLE_TEACHER);
        $rosasRow = $this->entry('2020-01-01', null, $this->rosa);
        $delete = fn (int $employeeId, int $entryId) => $this->handle(
            new DeleteServiceRowCommand($this->rosaId(), $this->schoolId(), $employeeId, $entryId),
        );

        // Execute
        $elsewhere = $delete((int) $owen->getId(), (int) $rosasRow->getId());
        $nobody = $delete(999999, (int) $rosasRow->getId());
        $notTheirs = $delete($this->tessId(), (int) $rosasRow->getId());
        $unknownSchool = $this->query(new GetServiceRecordCommand($this->rosaId(), -1, $this->tessId()));
        $this->sy->setCurrent(false);
        $this->em->flush();
        $noYear = $this->query(new GetServiceRecordPdfCommand($this->rosaId(), $this->schoolId(), $this->tessId()));

        // Assert
        self::assertSame(
            [
                'This person does not belong to the school this school year.',
                'This person does not belong to the school this school year.',
                'No such row in this service record.',
                'Not Found',
                'No current school year.',
            ],
            array_map(
                static fn (Result $result) => $result->exception()?->getMessage(),
                [$elsewhere, $nobody, $notTheirs, $unknownSchool, $noYear],
            ),
        );
        self::assertInstanceOf(StaffNotFoundException::class, $notTheirs->exception());
        self::assertCount(1, $this->em->getRepository(ServiceRecordEntry::class)->findAll());
    }

    public function testTheRecordPageAndItsPdf(): void
    {
        // Initialize
        $this->entry('2024-01-01', null);
        $this->handle(new SaveBpNumberCommand($this->rosaId(), $this->schoolId(), $this->tessId(), '2003334189'));

        // Execute
        $page = $this->query(new GetServiceRecordCommand($this->rosaId(), $this->schoolId(), $this->tessId()));
        $pdf = $this->query(new GetServiceRecordPdfCommand($this->rosaId(), $this->schoolId(), $this->tessId()));

        // Assert
        $data = $page->data();
        self::assertIsArray($data);
        self::assertInstanceOf(School::class, $data['school']);
        self::assertInstanceOf(User::class, $data['employee']);
        self::assertInstanceOf(ServiceRecord::class, $data['record']);
        self::assertIsArray($data['rows']);
        self::assertSame($this->schoolId(), $data['school']->getId());
        self::assertSame($this->tessId(), $data['employee']->getId());
        self::assertNull($data['sheet']);
        self::assertSame(
            ['Surname', 'First name', 'Date of birth', 'Place of birth', 'Agency employee no.'],
            $data['missing'],
        );
        self::assertSame('2003334189', $data['record']->getBpNumber());
        self::assertCount(1, $data['rows']);
        $file = $pdf->data();
        self::assertIsArray($file);
        self::assertIsString($file['content']);
        self::assertStringStartsWith('%PDF', $file['content']);
        self::assertSame('Service record.pdf', $file['fileName']);
    }

    /** A saved row of $employee's record (Tess by default). Dates as Y-m-d. */
    private function entry(string $from, ?string $to, ?User $employee = null): ServiceRecordEntry
    {
        $row = (new ServiceRecordEntry(
            $employee ?? $this->tess,
            new \DateTimeImmutable($from),
            $this->rosa,
            new \DateTimeImmutable('2026-10-01'),
        ))
            ->setToDate(null === $to ? null : new \DateTimeImmutable($to))
            ->setDesignation('Teacher III')
            ->setStatus('Regular/Permanent')
            ->setAnnualSalary('420588.00')
            ->setStation('Mandaue City')
            ->setBranch('National');
        $this->em->persist($row);
        $this->em->flush();

        return $row;
    }

    /** @return list<ServiceRecordEntry> Tess's rows as saved, oldest first */
    private function entries(): array
    {
        $this->em->clear();

        return $this->em->getRepository(ServiceRecordEntry::class)->findBy(
            ['employee' => $this->tessId()],
            ['fromDate' => 'ASC'],
        );
    }

    /** @return list<array{0: ?string, 1: ?string, 2: ?string, 3: ?string}> from, to, designation and salary */
    private function rows(): array
    {
        return array_map(static fn (ServiceRecordEntry $row) => [
            $row->getFromDate()?->format('Y-m-d'),
            $row->getToDate()?->format('Y-m-d'),
            $row->getDesignation(),
            $row->getAnnualSalary(),
        ], $this->entries());
    }

    private function record(): ?ServiceRecord
    {
        $this->em->clear();

        return $this->em->getRepository(ServiceRecord::class)->findOneBy(['employee' => $this->tessId()]);
    }

    private function rosaId(): int
    {
        return (int) $this->rosa->getId();
    }

    private function tessId(): int
    {
        return (int) $this->tess->getId();
    }

    private function schoolId(): int
    {
        return (int) $this->school->getId();
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
