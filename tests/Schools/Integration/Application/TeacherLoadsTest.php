<?php

namespace App\Tests\Schools\Integration\Application;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Section;
use App\Classes\Domain\Model\Subject;
use App\Classes\Domain\Model\TeachingLoad;
use App\Schools\Application\Service\SchoolTeacherManager;
use App\Schools\Domain\Exception\AssignmentException;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/** A teacher who still has teaching loads stays at their school. */
class TeacherLoadsTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private Section $rizal;
    private User $santos;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2026-10-16');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school(
            '303456',
            'Pagsabungan NHS',
            planValidUntil: new \DateTimeImmutable('2027-04-30'),
        );
        $this->rizal = (new Section($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade7)->setName('Rizal');
        $this->em->persist($this->rizal);
        $this->santos = $this->plainUser('santos', User::ROLE_TEACHER)->setFullName('Maria Santos');
        $this->assign($this->school, $this->sy, $this->santos, User::ROLE_TEACHER);
        $this->em->flush();
    }

    private function load(string $code, string $name): void
    {
        $subject = (new Subject($this->school, $this->sy))
            ->setGradeLevel(GradeLevel::Grade7)->setCode($code)->setName($name)->setPosition(1)->setMeetingsPerWeek(5);
        $this->em->persist($subject);
        $this->em->persist(new TeachingLoad($this->rizal, $subject, $this->santos));
        $this->em->flush();
    }

    /** @param class-string<\Throwable> $class */
    private function refused(string $class, string $message, callable $change): void
    {
        try {
            $change();
        } catch (\Throwable $e) {
            self::assertInstanceOf($class, $e);
            self::assertSame($message, $e->getMessage());

            return;
        }
        self::fail('Expected ' . $class . ': ' . $message);
    }

    public function testTeacherWithLoadsCannotBeRemovedOrMoved(): void
    {
        $manager = static::getContainer()->get(SchoolTeacherManager::class);
        $other = $this->school('309999', 'Other NHS', planValidUntil: new \DateTimeImmutable('2027-04-30'));
        $this->load('MATH7', 'Mathematics');
        $this->load('SCI7', 'Science');
        $copy = 'Maria Santos still has 2 loads this school year.';

        $this->refused(
            AssignmentException::class,
            $copy,
            fn () => $manager->removeFromSchool($this->sy, $this->santos),
        );
        $this->refused(
            AssignmentException::class,
            $copy,
            fn () => $manager->assignToSchool($other, $this->sy, $this->santos),
        );
    }
}
