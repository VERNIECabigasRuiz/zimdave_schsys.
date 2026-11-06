<?php

namespace App\Tests\ServiceRecord;

use App\Entity\ServiceRecordEntry;
use App\Access\Domain\Model\User;
use App\Repository\ServiceRecordEntryRepository;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ServiceRecordEntryTest extends KernelTestCase
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

    /** A complete row of Tess's record, saved unless $persist is false. Dates as m/d/Y. */
    private function row(string $from, ?string $to, bool $persist = true): ServiceRecordEntry
    {
        $row = (new ServiceRecordEntry($this->tess, \DateTimeImmutable::createFromFormat('!m/d/Y', $from), $this->tess, new \DateTimeImmutable()))
            ->setToDate(null === $to ? null : \DateTimeImmutable::createFromFormat('!m/d/Y', $to))
            ->setDesignation('Teacher III')
            ->setStatus('Regular/Permanent')
            ->setAnnualSalary('443028.00')
            ->setStation('Mandaue City')
            ->setBranch('National');
        if ($persist) {
            $this->em->persist($row);
            $this->em->flush();
        }

        return $row;
    }

    /** @return list<string> "path: message" of each violation */
    private function violations(ServiceRecordEntry $row): array
    {
        $list = [];
        foreach (static::getContainer()->get(ValidatorInterface::class)->validate($row) as $violation) {
            $list[] = $violation->getPropertyPath().': '.$violation->getMessage();
        }

        return $list;
    }

    public function testValidRowHasNoViolations(): void
    {
        $this->row('01/01/2020', '12/31/2020');

        self::assertSame([], $this->violations($this->row('01/01/2021', null, persist: false)));
    }

    public function testEndBeforeStart(): void
    {
        self::assertSame(['toDate: The end date is before the start date.'], $this->violations($this->row('12/31/2020', '01/01/2020', persist: false)));
    }

    public function testOverlapWithAnotherRow(): void
    {
        $this->row('01/01/2020', '12/31/2020');

        self::assertSame(['fromDate: This service overlaps the row from 01/01/2020.'], $this->violations($this->row('06/01/2020', '06/30/2021', persist: false)));
    }

    public function testOpenRowOverlapsEverythingAfterIt(): void
    {
        $this->row('07/20/2026', null);

        self::assertSame(['fromDate: This service overlaps the row from 07/20/2026.'], $this->violations($this->row('01/01/2027', '12/31/2027', persist: false)));
    }

    public function testSecondOpenRow(): void
    {
        $this->row('07/20/2026', null);

        self::assertSame(['toDate: There is already a row up to the present. Close it first.'], $this->violations($this->row('01/01/2027', null, persist: false)));
    }

    public function testEditingARowIgnoresItself(): void
    {
        $this->row('01/01/2020', '12/31/2020');
        $edited = $this->row('01/01/2021', '12/31/2021');

        $edited->setRemarks('SG13-1 NBC-591');
        self::assertSame([], $this->violations($edited));

        $edited->setFromDate(new \DateTimeImmutable('2020-12-01'));
        self::assertSame(['fromDate: This service overlaps the row from 01/01/2020.'], $this->violations($edited));
    }

    public function testLwopNeedsBothDates(): void
    {
        $row = $this->row('01/01/2020', '12/31/2020', persist: false)->setLwopFrom(new \DateTimeImmutable('2020-03-01'));
        self::assertSame(['lwopTo: Give both leave without pay dates, the end not before the start.'], $this->violations($row));

        $row->setLwopTo(new \DateTimeImmutable('2020-02-01'));
        self::assertSame(['lwopTo: Give both leave without pay dates, the end not before the start.'], $this->violations($row));

        $row->setLwopTo(new \DateTimeImmutable('2020-03-31'));
        self::assertSame([], $this->violations($row));
    }

    public function testCloseOpenLetsALaterRowIn(): void
    {
        $this->row('01/01/2026', null);

        $later = $this->row('07/20/2026', null, persist: false);
        $later->closeOpen = true;
        self::assertSame([], $this->violations($later));

        $same = $this->row('01/01/2026', null, persist: false);
        $same->closeOpen = true;
        self::assertSame(['fromDate: This service overlaps the row from 01/01/2026.'], $this->violations($same));
    }

    public function testFindCurrentReturnsTheOpenRow(): void
    {
        $this->row('01/01/2025', '12/31/2025');
        $open = $this->row('01/01/2026', null);

        self::assertSame($open, static::getContainer()->get(ServiceRecordEntryRepository::class)->findCurrent($this->tess));
    }

    public function testFindForEmployeeIsOldestFirst(): void
    {
        $this->row('01/01/2026', null);
        $this->row('01/01/2024', '12/31/2024');
        $this->row('01/01/2025', '12/31/2025');

        $rows = static::getContainer()->get(ServiceRecordEntryRepository::class)->findForEmployee($this->tess);

        self::assertSame(['2024-01-01', '2025-01-01', '2026-01-01'], array_map(static fn (ServiceRecordEntry $r) => $r->getFromDate()->format('Y-m-d'), $rows));
    }
}
