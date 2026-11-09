<?php

declare(strict_types=1);

namespace App\Tests\Staff\Integration\Presentation;

use App\Access\Domain\Model\User;
use App\Staff\Application\UseCase\Command\SaveServiceRowCommand;
use App\Staff\Domain\Model\ServiceRecordEntry;
use App\Staff\Presentation\Validation\ValidServiceRow;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** The row form's rules: the row command's own constraints plus the row-dates check the form adds. */
class ServiceRowValidationTest extends KernelTestCase
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

    /** A complete saved row of Tess's record. Dates as m/d/Y. */
    private function saved(string $from, ?string $to): ServiceRecordEntry
    {
        $row = new ServiceRecordEntry($this->tess, self::date($from), $this->tess, new \DateTimeImmutable());
        $this->typed($from, $to)->applyTo($row);
        $this->em->persist($row);
        $this->em->flush();

        return $row;
    }

    /** A complete new row of Tess's record as the form sends it. Dates as m/d/Y. */
    private function typed(string $from, ?string $to): SaveServiceRowCommand
    {
        $row = new SaveServiceRowCommand();
        $row->employeeId = (int) $this->tess->getId();
        $row->fromDate = self::date($from);
        $row->toDate = null === $to ? null : self::date($to);
        $row->designation = 'Teacher III';
        $row->status = 'Regular/Permanent';
        $row->annualSalary = '443028.00';
        $row->station = 'Mandaue City';
        $row->branch = 'National';

        return $row;
    }

    private static function date(string $date): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!m/d/Y', $date);
        self::assertInstanceOf(\DateTimeImmutable::class, $parsed);

        return $parsed;
    }

    /** @return list<string> "path: message" of each violation, as the row form checks it */
    private function violations(SaveServiceRowCommand $row): array
    {
        $violations = static::getContainer()->get(ValidatorInterface::class)
            ->startContext()
            ->validate($row)
            ->validate($row, new ValidServiceRow())
            ->getViolations();
        $list = [];
        foreach ($violations as $violation) {
            $list[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
        }

        return $list;
    }

    public function testValidRowHasNoViolations(): void
    {
        $this->saved('01/01/2020', '12/31/2020');

        self::assertSame([], $this->violations($this->typed('01/01/2021', null)));
    }

    public function testEndBeforeStart(): void
    {
        self::assertSame(
            ['toDate: The end date is before the start date.'],
            $this->violations($this->typed('12/31/2020', '01/01/2020')),
        );
    }

    public function testOverlapWithAnotherRow(): void
    {
        $this->saved('01/01/2020', '12/31/2020');

        self::assertSame(
            ['fromDate: This service overlaps the row from 01/01/2020.'],
            $this->violations($this->typed('06/01/2020', '06/30/2021')),
        );
    }

    public function testOpenRowOverlapsEverythingAfterIt(): void
    {
        $this->saved('07/20/2026', null);

        self::assertSame(
            ['fromDate: This service overlaps the row from 07/20/2026.'],
            $this->violations($this->typed('01/01/2027', '12/31/2027')),
        );
    }

    public function testSecondOpenRow(): void
    {
        $this->saved('07/20/2026', null);

        self::assertSame(
            ['toDate: There is already a row up to the present. Close it first.'],
            $this->violations($this->typed('01/01/2027', null)),
        );
    }

    public function testEditingARowIgnoresItself(): void
    {
        $this->saved('01/01/2020', '12/31/2020');
        $edited = SaveServiceRowCommand::fromEntry($this->saved('01/01/2021', '12/31/2021'), 0, 0);

        $edited->remarks = 'SG13-1 NBC-591';
        self::assertSame([], $this->violations($edited));

        $edited->fromDate = new \DateTimeImmutable('2020-12-01');
        self::assertSame(['fromDate: This service overlaps the row from 01/01/2020.'], $this->violations($edited));
    }

    public function testLwopNeedsBothDates(): void
    {
        $row = $this->typed('01/01/2020', '12/31/2020');
        $row->lwopFrom = new \DateTimeImmutable('2020-03-01');
        self::assertSame(
            ['lwopTo: Give both leave without pay dates, the end not before the start.'],
            $this->violations($row),
        );

        $row->lwopTo = new \DateTimeImmutable('2020-02-01');
        self::assertSame(
            ['lwopTo: Give both leave without pay dates, the end not before the start.'],
            $this->violations($row),
        );

        $row->lwopTo = new \DateTimeImmutable('2020-03-31');
        self::assertSame([], $this->violations($row));
    }

    public function testCloseOpenLetsALaterRowIn(): void
    {
        $this->saved('01/01/2026', null);

        $later = $this->typed('07/20/2026', null);
        $later->closeOpen = true;
        self::assertSame([], $this->violations($later));

        $same = $this->typed('01/01/2026', null);
        $same->closeOpen = true;
        self::assertSame(['fromDate: This service overlaps the row from 01/01/2026.'], $this->violations($same));
    }

    public function testTheRowsOwnFieldsAreChecked(): void
    {
        // Initialize
        $row = $this->typed('01/01/2020', null);
        $row->fromDate = null;
        $row->designation = null;
        $row->annualSalary = '-1';
        $row->branch = 'Regional';
        $row->remarks = str_repeat('x', 151);

        // Execute
        $violations = $this->violations($row);

        // Assert
        self::assertSame([
            'fromDate: This value should not be null.',
            'designation: This value should not be blank.',
            'annualSalary: This value should be either positive or zero.',
            'branch: The value you selected is not a valid choice.',
            'remarks: This value is too long. It should have 150 characters or less.',
        ], $violations);
    }
}
