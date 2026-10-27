<?php

namespace App\Tests\Entity;

use App\Entity\ClassPeriod;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Repository\ClassPeriodRepository;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ClassPeriodTest extends KernelTestCase
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
        $this->school = $this->school('303456', 'Pagsabungan NHS');
    }

    private function draft(string $start, string $end): ClassPeriod
    {
        return (new ClassPeriod($this->school, $this->sy))
            ->setStartsAt(new \DateTimeImmutable($start))
            ->setEndsAt(new \DateTimeImmutable($end));
    }

    /** @return list<string> */
    private function violations(ClassPeriod $period): array
    {
        return array_map(
            static fn ($v) => $v->getMessage(),
            iterator_to_array(static::getContainer()->get(ValidatorInterface::class)->validate($period)),
        );
    }

    public function testEndMustBeAfterStart(): void
    {
        self::assertSame(['The end time must be after the start time.'], $this->violations($this->draft('09:00', '08:00')));
        self::assertSame(['The end time must be after the start time.'], $this->violations($this->draft('09:00', '09:00')));
    }

    public function testOverlapRejected(): void
    {
        $this->classPeriod($this->school, $this->sy, '07:30', '08:30');

        self::assertSame(['This time overlaps Period 1 (07:30–08:30).'], $this->violations($this->draft('08:00', '09:00')));
    }

    public function testTouchingIsFine(): void
    {
        $this->classPeriod($this->school, $this->sy, '07:30', '08:30');

        self::assertSame([], $this->violations($this->draft('08:30', '09:30')));
    }

    public function testOverlapRejectedOnEdit(): void
    {
        $this->classPeriod($this->school, $this->sy, '07:30', '08:30');
        $second = $this->classPeriod($this->school, $this->sy, '08:30', '09:30');

        self::assertSame([], $this->violations($second));
        $second->setStartsAt(new \DateTimeImmutable('08:00'));

        self::assertSame(['This time overlaps Period 1 (07:30–08:30).'], $this->violations($second));
    }

    public function testNumberingSkipsBreaks(): void
    {
        $p1 = $this->classPeriod($this->school, $this->sy, '07:30', '08:30');
        $recess = $this->classPeriod($this->school, $this->sy, '08:30', '08:50', 'Recess');
        $p2 = $this->classPeriod($this->school, $this->sy, '08:50', '09:50');

        $repo = static::getContainer()->get(ClassPeriodRepository::class);
        $numbers = $repo->numbering($repo->findForSchool($this->school, $this->sy));

        self::assertSame([$p1->getId() => 1, $p2->getId() => 2], $numbers);
        self::assertSame('Recess', $repo->label($recess));
        self::assertSame('Period 2', $repo->label($p2));
        self::assertTrue($recess->isBreak());
        self::assertSame('07:30–08:30', $p1->getTimeRange());
    }
}
