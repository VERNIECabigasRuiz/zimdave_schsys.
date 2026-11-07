<?php

namespace App\Tests\Classes\Integration\Domain;

use App\Classes\Application\Transformer\ClassPeriodTransformer;
use App\Classes\Application\UseCase\Command\CreateClassPeriodCommand;
use App\Classes\Application\UseCase\Command\UpdateClassPeriodCommand;
use App\Classes\Presentation\Validation\NoOverlappingPeriod;
use App\Classes\Domain\Model\ClassPeriod;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Classes\Domain\Model\Repository\ClassPeriodRepositoryInterface;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ClassPeriodTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    protected EntityManagerInterface $em;
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

    /**
     * What the class period form says about these values: the command's own rules and the form's constraint.
     *
     * @return list<string>
     */
    private function violations(ClassPeriod $period): array
    {
        $command = null !== $period->getId()
            ? UpdateClassPeriodCommand::fromPeriod(
                (int) $this->school->getId(),
                ClassPeriodTransformer::toArray($period),
            )
            : new CreateClassPeriodCommand((int) $this->school->getId());
        $command->startsAt = $period->getStartsAt();
        $command->endsAt = $period->getEndsAt();
        $validator = static::getContainer()->get(ValidatorInterface::class);

        return array_map(
            static fn ($v) => $v->getMessage(),
            [
                ...iterator_to_array($validator->validate($command)),
                ...iterator_to_array($validator->validate($command, [new NoOverlappingPeriod()])),
            ],
        );
    }

    public function testEndMustBeAfterStart(): void
    {
        self::assertSame(
            ['The end time must be after the start time.'],
            $this->violations($this->draft('09:00', '08:00')),
        );
        self::assertSame(
            ['The end time must be after the start time.'],
            $this->violations($this->draft('09:00', '09:00')),
        );
    }

    public function testOverlapRejected(): void
    {
        $this->classPeriod($this->school, $this->sy, '07:30', '08:30');

        self::assertSame(
            ['This time overlaps Period 1 (07:30–08:30).'],
            $this->violations($this->draft('08:00', '09:00')),
        );
    }

    public function testAnOverlappedBreakIsNamed(): void
    {
        // Initialize
        $this->classPeriod($this->school, $this->sy, '10:00', '10:20', 'Recess');

        // Execute
        $violations = $this->violations($this->draft('10:10', '11:10'));

        // Assert
        self::assertSame(['This time overlaps Recess (10:00–10:20).'], $violations);
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

        $repo = static::getContainer()->get(ClassPeriodRepositoryInterface::class);
        $numbers = $repo->numbering($repo->findForSchool($this->school, $this->sy));

        self::assertSame([$p1->getId() => 1, $p2->getId() => 2], $numbers);
        self::assertSame('Recess', $repo->label($recess));
        self::assertSame('Period 2', $repo->label($p2));
        self::assertTrue($recess->isBreak());
        self::assertSame('07:30–08:30', $p1->getTimeRange());
    }
}
