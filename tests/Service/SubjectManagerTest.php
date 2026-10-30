<?php

namespace App\Tests\Service;

use App\Entity\ActivityLog;
use App\Entity\Grade;
use App\Entity\GradeLevel;
use App\Entity\School;
use App\Entity\SchoolType;
use App\Entity\SchoolYear;
use App\Entity\ShsTrack;
use App\Entity\Subject;
use App\Entity\User;
use App\Repository\SubjectRepository;
use App\Service\ActivityLogger;
use App\Service\InUseException;
use App\Service\PlanLimitException;
use App\Service\PlanLimits;
use App\Service\SubjectManager;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class SubjectManagerTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SubjectManager $manager;
    private SchoolYear $sy;
    private SchoolYear $last;
    private School $school;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2026-10-16');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $container = static::getContainer();
        $this->manager = $container->has(SubjectManager::class) ? $container->get(SubjectManager::class) : new SubjectManager(
            $this->em,
            $container->get(SubjectRepository::class),
            $container->get(PlanLimits::class),
            $container->get(ActivityLogger::class),
            $container->get(\App\Repository\TeachingLoadRepository::class),
            $container->get(\App\Repository\GradeRepository::class),
        );
        $this->clearSchoolData();
        $this->last = $this->schoolYear(2025, current: false);
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school('303456', 'Pagsabungan IS', planValidUntil: new \DateTimeImmutable('2027-04-30'))->setType(SchoolType::Integrated);
        $this->em->flush();
    }

    private function subject(GradeLevel $grade, string $code, string $name, ?Subject $parent = null, ?SchoolYear $sy = null, int $position = 1): Subject
    {
        $subject = (new Subject($this->school, $sy ?? $this->sy))
            ->setGradeLevel($grade)->setCode($code)->setName($name)->setParent($parent)->setPosition($position)->setMeetingsPerWeek(5);
        $this->em->persist($subject);
        $this->em->flush();

        return $subject;
    }

    /** @return list<string> */
    private function log(): array
    {
        return array_map(static fn (ActivityLog $l) => $l->getAction(), $this->em->getRepository(ActivityLog::class)->findBy([], ['id' => 'ASC']));
    }

    /** @return list<string> codes of the year in page order, each component right after its parent as "PARENT>CODE" */
    private function codes(?SchoolYear $sy = null): array
    {
        $this->em->clear();
        $subjects = $this->em->getRepository(Subject::class)->findBy(['schoolYear' => ($sy ?? $this->sy)->getId(), 'parent' => null], ['gradeLevel' => 'ASC', 'semester' => 'ASC', 'position' => 'ASC', 'code' => 'ASC']);

        $codes = [];
        foreach ($subjects as $subject) {
            $codes[] = $subject->getCode();
            foreach ($subject->getComponents() as $component) {
                $codes[] = $subject->getCode().'>'.$component->getCode();
            }
        }

        return $codes;
    }

    public function testSaveLogsCreateAndUpdate(): void
    {
        $math = (new Subject($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade7)->setCode('MATH7')->setName('Mathematics');
        $this->manager->save($math);
        $this->manager->save($math->setName('Math'));

        self::assertSame(['Created subject MATH7 – Mathematics', 'Updated subject MATH7 – Math'], $this->log());
    }

    public function testDeleteRemovesComponentsAndLogs(): void
    {
        $mapeh = $this->subject(GradeLevel::Grade7, 'MAPEH7', 'MAPEH');
        $this->subject(GradeLevel::Grade7, 'MA7', 'Music and Arts', $mapeh);
        $this->subject(GradeLevel::Grade7, 'PEH7', 'Physical Education and Health', $mapeh);
        $this->subject(GradeLevel::Grade7, 'MATH7', 'Mathematics');

        $this->manager->delete($mapeh);

        self::assertSame(['MATH7'], $this->codes());
        self::assertSame(['Deleted subject MAPEH7 – MAPEH with 2 components'], $this->log());
    }

    public function testDeleteRefusedWhileLearnersHaveGrades(): void
    {
        $this->sy->rebuildGradingPeriods();
        $this->em->flush();
        $teacher = $this->plainUser('dave', User::ROLE_TEACHER);
        $section = $this->newSection($this->school, $this->sy, GradeLevel::Grade7, 'Rizal');
        $math = $this->subject(GradeLevel::Grade7, 'MATH7', 'Mathematics');
        $grades = [];
        foreach (['120017170001' => 'Arnol', '120017170002' => 'Bello'] as $lrn => $lastName) {
            $enrollment = $this->enroll($this->learner($lrn, $lastName, 'Ana'), $this->sy, $section, $teacher);
            $grades[] = $grade = new Grade($enrollment, $math, $this->sy->getGradingPeriod(1), 85, $teacher, new \DateTimeImmutable('2026-10-16'));
            $this->em->persist($grade);
        }
        $this->em->flush();

        try {
            $this->manager->delete($math);
            self::fail('Expected the graded subject to be refused');
        } catch (InUseException $e) {
            self::assertSame("Mathematics has grades in 2 learners' records.", $e->getMessage());
        }
        self::assertSame(['MATH7'], $this->codes());

        $this->em->remove($this->em->find(Grade::class, $grades[0]->getId()));
        $this->em->flush();
        try {
            $this->manager->delete($this->em->find(Subject::class, $math->getId()));
            self::fail('Expected the graded subject to be refused');
        } catch (InUseException $e) {
            self::assertSame("Mathematics has grades in 1 learner's record.", $e->getMessage());
        }
    }

    public function testAddDepEdSetForGrade7(): void
    {
        self::assertSame(10, $this->manager->addDepEdSet($this->school, $this->sy, GradeLevel::Grade7));

        self::assertSame(['FIL7', 'ENG7', 'MATH7', 'SCI7', 'AP7', 'VE7', 'TLE7', 'MAPEH7', 'MAPEH7>MA7', 'MAPEH7>PEH7'], $this->codes());
        self::assertSame(['Added 10 DepEd subjects for Grade 7'], $this->log());

        self::assertSame(0, $this->manager->addDepEdSet($this->school, $this->sy, GradeLevel::Grade7));
        self::assertCount(1, $this->log());
    }

    public function testDepEdSetFillsMeetingsPerWeek(): void
    {
        $this->manager->addDepEdSet($this->school, $this->sy, GradeLevel::Grade7);
        $this->em->clear();

        $meetings = fn (string $code) => $this->em->getRepository(Subject::class)->findOneBy(['code' => $code])->getMeetingsPerWeek();
        self::assertSame(5, $meetings('MATH7'));
        self::assertNull($meetings('MAPEH7'));
        self::assertNull($meetings('MA7'));
        self::assertNull($meetings('PEH7'));
    }

    public function testParentWithComponentsStoresNull(): void
    {
        $mapeh = $this->subject(GradeLevel::Grade7, 'MAPEH7', 'MAPEH')->setMeetingsPerWeek(4);
        $this->subject(GradeLevel::Grade7, 'MA7', 'Music and Arts', $mapeh);

        $this->manager->save($mapeh);
        $this->em->clear();

        self::assertNull($this->em->getRepository(Subject::class)->findOneBy(['code' => 'MAPEH7'])->getMeetingsPerWeek());
    }

    public function testAddDepEdSetUsesOldGrade10ForSy2026(): void
    {
        $this->manager->addDepEdSet($this->school, $this->sy, GradeLevel::Grade10);

        self::assertContains('ESP10', $this->codes());
        self::assertContains('MAPEH10>HLTH10', $this->codes());
    }

    public function testAddDepEdSetGoesAfterExistingSubjects(): void
    {
        $this->subject(GradeLevel::Grade7, 'JOUR7', 'Journalism', position: 3);

        $this->manager->addDepEdSet($this->school, $this->sy, GradeLevel::Grade7);

        self::assertSame('JOUR7', $this->codes()[0]);
        self::assertSame(4, $this->em->getRepository(Subject::class)->findOneBy(['code' => 'FIL7'])->getPosition());
    }

    public function testBringBackCopiesWithComponentsAndShsSettings(): void
    {
        $mapeh = $this->subject(GradeLevel::Grade7, 'MAPEH7', 'MAPEH', sy: $this->last, position: 8);
        $this->subject(GradeLevel::Grade7, 'MA7', 'Music and Arts', $mapeh, $this->last, 1);
        $this->subject(GradeLevel::Grade7, 'PEH7', 'Physical Education and Health', $mapeh, $this->last, 2);
        $stem = $this->subject(GradeLevel::Grade11, 'PC11', 'Pre-Calculus', sy: $this->last, position: 3)
            ->setSemester(2)->setTrack(ShsTrack::Academic)->setStrand('STEM');
        $this->em->flush();

        self::assertSame(4, $this->manager->bringBack($this->school, $this->last, $this->sy));

        self::assertSame(['MAPEH7', 'MAPEH7>MA7', 'MAPEH7>PEH7', 'PC11'], $this->codes());
        $copy = $this->em->getRepository(Subject::class)->findOneBy(['code' => 'PC11', 'schoolYear' => $this->sy->getId()]);
        self::assertSame([3, 2, ShsTrack::Academic, 'STEM', 'Pre-Calculus'], [$copy->getPosition(), $copy->getSemester(), $copy->getTrack(), $copy->getStrand(), $copy->getName()]);
        self::assertSame(8, $this->em->getRepository(Subject::class)->findOneBy(['code' => 'MAPEH7', 'schoolYear' => $this->sy->getId()])->getPosition());
        self::assertSame(['Brought back 4 subjects to S.Y. 2026-2027 for Pagsabungan IS'], $this->log());
        self::assertSame(['MAPEH7', 'MAPEH7>MA7', 'MAPEH7>PEH7', 'PC11'], $this->codes($this->last), 'Last year is untouched');
    }

    public function testBringBackCopiesMeetingsPerWeek(): void
    {
        $mapeh = $this->subject(GradeLevel::Grade7, 'MAPEH7', 'MAPEH', sy: $this->last, position: 8);
        $this->subject(GradeLevel::Grade7, 'MA7', 'Music and Arts', $mapeh, $this->last, 1)->setMeetingsPerWeek(3);
        $this->subject(GradeLevel::Grade7, 'PEH7', 'Physical Education and Health', $mapeh, $this->last, 2)->setMeetingsPerWeek(2);
        $this->subject(GradeLevel::Grade7, 'MATH7', 'Mathematics', sy: $this->last)->setMeetingsPerWeek(4);
        $mapeh->setMeetingsPerWeek(null);
        $this->em->flush();

        $this->manager->bringBack($this->school, $this->last, $this->sy);
        $this->em->clear();

        $meetings = fn (string $code) => $this->em->getRepository(Subject::class)->findOneBy(['code' => $code, 'schoolYear' => $this->sy->getId()])->getMeetingsPerWeek();
        self::assertSame([4, null, 3, 2], [$meetings('MATH7'), $meetings('MAPEH7'), $meetings('MA7'), $meetings('PEH7')]);
    }

    public function testBringBackAndDepEdSkipDuplicateNames(): void
    {
        $this->subject(GradeLevel::Grade7, 'M7', 'Mathematics');
        $this->subject(GradeLevel::Grade8, 'M8', 'mathematics');
        $this->subject(GradeLevel::Grade8, 'MATH8', 'Mathematics', sy: $this->last);

        self::assertSame(9, $this->manager->addDepEdSet($this->school, $this->sy, GradeLevel::Grade7));
        self::assertSame(0, $this->manager->bringBack($this->school, $this->last, $this->sy));
        self::assertNotContains('MATH7', $this->codes());
        self::assertNotContains('MATH8', $this->codes());
    }

    public function testBringBackSkipsComponentsWhoseCodeExists(): void
    {
        $mapeh = $this->subject(GradeLevel::Grade7, 'MAPEH7', 'MAPEH', sy: $this->last);
        $this->subject(GradeLevel::Grade7, 'MA7', 'Music and Arts', $mapeh, $this->last);
        $this->subject(GradeLevel::Grade7, 'PEH7', 'Physical Education and Health', $mapeh, $this->last);
        $this->subject(GradeLevel::Grade7, 'MA7', 'Music & Arts');

        self::assertSame(2, $this->manager->bringBack($this->school, $this->last, $this->sy));
        self::assertSame(['MA7', 'MAPEH7', 'MAPEH7>PEH7'], $this->codes());
    }

    public function testBringBackSkipsGradesTheSchoolNoLongerOffers(): void
    {
        $this->subject(GradeLevel::Grade5, 'SCI5', 'Science', sy: $this->last);
        $this->school->setType(SchoolType::JuniorHigh);
        $this->em->flush();

        self::assertSame(0, $this->manager->bringBack($this->school, $this->last, $this->sy));
        self::assertSame([], $this->log());
    }

    public function testComponentsFollowParentShsSettings(): void
    {
        $parent = $this->subject(GradeLevel::Grade11, 'PEH11', 'PE and Health')->setSemester(1);
        $component = $this->subject(GradeLevel::Grade11, 'PE11', 'Physical Education', $parent)->setSemester(1);
        $this->em->flush();

        $this->manager->save($parent->setSemester(2)->setTrack(ShsTrack::Sports));
        self::assertSame([2, ShsTrack::Sports], [$component->getSemester(), $component->getTrack()]);

        $this->manager->save($component->setSemester(1)->setStrand('Coaching'));
        $this->em->clear();
        $component = $this->em->getRepository(Subject::class)->findOneBy(['code' => 'PE11']);
        self::assertSame([2, ShsTrack::Sports, null], [$component->getSemester(), $component->getTrack(), $component->getStrand()]);
    }

    public function testExpiredPlanRefusesEveryWrite(): void
    {
        $math = $this->subject(GradeLevel::Grade7, 'MATH7', 'Mathematics');
        $this->subject(GradeLevel::Grade8, 'SCI8', 'Science', sy: $this->last);
        $this->school->setPlanValidUntil(new \DateTimeImmutable('2026-10-15'));
        $this->em->flush();

        self::assertStringContainsString('expired on Oct 15, 2026', (string) $this->manager->refusal($this->school));
        foreach ([
            fn () => $this->manager->save($math->setName('Math')),
            fn () => $this->manager->delete($math),
            fn () => $this->manager->addDepEdSet($this->school, $this->sy, GradeLevel::Grade8),
            fn () => $this->manager->bringBack($this->school, $this->last, $this->sy),
        ] as $write) {
            try {
                $write();
                self::fail('Expected the expired plan to block the write');
            } catch (PlanLimitException $e) {
                self::assertStringContainsString('expired on Oct 15, 2026', $e->getMessage());
            }
        }
        self::assertSame(['MATH7'], $this->codes());
        self::assertSame([], $this->log());
    }
}
