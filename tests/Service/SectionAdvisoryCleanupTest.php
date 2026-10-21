<?php

namespace App\Tests\Service;

use App\Entity\ActivityLog;
use App\Entity\GradeLevel;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\User;
use App\Service\SchoolTeacherManager;
use App\Service\SectionManager;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/** A teacher who leaves a school this S.Y. stops being an adviser there. */
class SectionAdvisoryCleanupTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $schoolA;
    private School $schoolB;
    private User $ana;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2026-10-16');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2026, current: true);
        $this->schoolA = $this->school('303456', 'School A');
        $this->schoolB = $this->school('303457', 'School B');
        $this->ana = $this->plainUser('ana', User::ROLE_TEACHER);
        $this->assign($this->schoolA, $this->sy, $this->ana, User::ROLE_TEACHER);
        $this->em->persist((new Section($this->schoolA, $this->sy))->setGradeLevel(GradeLevel::Grade7)->setName('Sampaguita')->setAdviser($this->ana));
        $this->em->flush();
    }

    private function advisoryOfAna(): ?Section
    {
        $this->em->clear();

        return $this->em->getRepository(Section::class)->findOneBy(['adviser' => $this->ana->getId()]);
    }

    /** @return list<string> */
    private function log(): array
    {
        return array_map(static fn (ActivityLog $l) => $l->getAction(), $this->em->getRepository(ActivityLog::class)->findAll());
    }

    public function testRemovingTeacherFromSchoolClearsAdvisory(): void
    {
        static::getContainer()->get(SchoolTeacherManager::class)->removeFromSchool($this->sy, $this->ana);
        $this->em->flush();

        self::assertNull($this->advisoryOfAna());
        self::assertContains('Removed ana as adviser of Grade 7 – Sampaguita', $this->log());
    }

    public function testMovingTeacherToAnotherSchoolClearsAdvisory(): void
    {
        static::getContainer()->get(SchoolTeacherManager::class)->assignToSchool($this->schoolB, $this->sy, $this->ana);
        $this->em->flush();

        self::assertNull($this->advisoryOfAna());
    }

    public function testBringBackSkipsNamesDifferingOnlyByAccentOrCase(): void
    {
        $last = $this->schoolYear(2025, current: false);
        $this->em->persist((new Section($this->schoolA, $last))->setGradeLevel(GradeLevel::Grade8)->setName('Sto. Niño'));
        $this->em->persist((new Section($this->schoolA, $last))->setGradeLevel(GradeLevel::Grade8)->setName('ROSAL'));
        $this->em->persist((new Section($this->schoolA, $this->sy))->setGradeLevel(GradeLevel::Grade8)->setName('Sto. Nino'));
        $this->em->persist((new Section($this->schoolA, $this->sy))->setGradeLevel(GradeLevel::Grade8)->setName('Rosal'));
        $this->em->flush();

        $count = static::getContainer()->get(SectionManager::class)->bringBack($this->schoolA, $last, $this->sy);

        self::assertSame(0, $count);
    }
}
