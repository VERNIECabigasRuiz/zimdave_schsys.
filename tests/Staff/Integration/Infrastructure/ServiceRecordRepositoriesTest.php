<?php

declare(strict_types=1);

namespace App\Tests\Staff\Integration\Infrastructure;

use App\Access\Domain\Model\User;
use App\Staff\Domain\Model\Repository\ServiceRecordEntryRepositoryInterface;
use App\Staff\Domain\Model\Repository\ServiceRecordRepositoryInterface;
use App\Staff\Domain\Model\Repository\StaffIdDetailsRepositoryInterface;
use App\Staff\Domain\Model\ServiceRecord;
use App\Staff\Domain\Model\ServiceRecordEntry;
use App\Staff\Domain\Model\StaffIdDetails;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ServiceRecordRepositoriesTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private User $tess;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->tess = $this->plainUser('tess', User::ROLE_TEACHER);
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    /** A complete row of Tess's record, saved. Dates as m/d/Y. */
    private function row(string $from, ?string $to): ServiceRecordEntry
    {
        $start = \DateTimeImmutable::createFromFormat('!m/d/Y', $from);
        self::assertInstanceOf(\DateTimeImmutable::class, $start);
        $row = (new ServiceRecordEntry($this->tess, $start, $this->tess, new \DateTimeImmutable()))
            ->setToDate(null === $to ? null : (\DateTimeImmutable::createFromFormat('!m/d/Y', $to) ?: null))
            ->setDesignation('Teacher III')
            ->setStatus('Regular/Permanent')
            ->setAnnualSalary('443028.00')
            ->setStation('Mandaue City')
            ->setBranch('National');
        $this->em->persist($row);
        $this->em->flush();

        return $row;
    }

    private function entries(): ServiceRecordEntryRepositoryInterface
    {
        return static::getContainer()->get(ServiceRecordEntryRepositoryInterface::class);
    }

    public function testFindCurrentReturnsTheOpenRow(): void
    {
        $this->row('01/01/2025', '12/31/2025');
        $open = $this->row('01/01/2026', null);

        self::assertSame($open, $this->entries()->findCurrent($this->tess));
    }

    public function testFindForEmployeeIsOldestFirst(): void
    {
        $this->row('01/01/2026', null);
        $this->row('01/01/2024', '12/31/2024');
        $this->row('01/01/2025', '12/31/2025');

        $rows = $this->entries()->findForEmployee($this->tess);

        self::assertSame(
            ['2024-01-01', '2025-01-01', '2026-01-01'],
            array_map(static fn (ServiceRecordEntry $r) => $r->getFromDate()?->format('Y-m-d'), $rows),
        );
    }

    public function testRowsAreFoundAddedAndRemoved(): void
    {
        // Initialize
        $kept = $this->row('01/01/2024', '12/31/2024');
        $added = (new ServiceRecordEntry(
            $this->tess,
            new \DateTimeImmutable('2025-01-01'),
            $this->tess,
            new \DateTimeImmutable(),
        ))
            ->setDesignation('Teacher III')
            ->setStatus('Regular/Permanent')
            ->setAnnualSalary('443028.00')
            ->setStation('Mandaue City')
            ->setBranch('National');

        // Execute
        $this->entries()->add($added);
        $this->em->flush();
        $found = $this->entries()->findById((int) $added->getId());
        $this->entries()->remove($kept);
        $this->em->flush();

        // Assert
        self::assertSame($added, $found);
        self::assertNull($this->entries()->findById(999999));
        self::assertSame([$added], $this->entries()->findForEmployee($this->tess));
    }

    public function testTheRecordIsFoundOnceAdded(): void
    {
        // Initialize
        $records = static::getContainer()->get(ServiceRecordRepositoryInterface::class);
        $before = $records->findFor($this->tess);
        $record = (new ServiceRecord($this->tess))->setBpNumber('2003334189');

        // Execute
        $records->add($record);
        $this->em->flush();
        $this->em->clear();

        // Assert
        self::assertNull($before);
        self::assertSame(
            '2003334189',
            $records->findFor($this->findUserById((int) $this->tess->getId()))?->getBpNumber(),
        );
    }

    public function testTheIdDetailsAreFoundOnceAdded(): void
    {
        // Initialize
        $details = static::getContainer()->get(StaffIdDetailsRepositoryInterface::class);
        $before = $details->findFor($this->tess);
        $saved = (new StaffIdDetails($this->tess))->setCallName('Sir Jun');

        // Execute
        $details->add($saved);
        $this->em->flush();
        $this->em->clear();

        // Assert
        self::assertNull($before);
        self::assertSame('Sir Jun', $details->findFor($this->findUserById((int) $this->tess->getId()))?->getCallName());
    }

    public function testTheSavedRecordsNameTheirPerson(): void
    {
        // Initialize
        $record = new ServiceRecord($this->tess);
        $details = new StaffIdDetails($this->tess);
        $this->em->persist($record);
        $this->em->persist($details);
        $closed = $this->row('01/01/2024', '12/31/2024');
        $open = $this->row('01/01/2025', null);

        // Execute
        $this->em->flush();

        // Assert
        self::assertNotNull($record->getId());
        self::assertNotNull($details->getId());
        self::assertSame([$this->tess, $this->tess], [$record->getEmployee(), $details->getUser()]);
        self::assertSame([false, true], [$closed->isOpen(), $open->isOpen()]);
    }

    private function findUserById(int $id): User
    {
        $user = $this->em->find(User::class, $id);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
