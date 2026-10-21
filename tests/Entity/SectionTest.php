<?php

namespace App\Tests\Entity;

use App\Entity\GradeLevel;
use App\Entity\School;
use App\Entity\SchoolType;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\ShsTrack;
use App\Entity\User;
use App\Repository\SectionRepository;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class SectionTest extends KernelTestCase
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

    private function section(GradeLevel $level, string $name, ?User $adviser = null): Section
    {
        return (new Section($this->school, $this->sy))->setGradeLevel($level)->setName($name)->setAdviser($adviser);
    }

    /** @return list<string> */
    private function violations(Section $section): array
    {
        return array_map(
            static fn ($v) => $v->getPropertyPath().': '.$v->getMessage(),
            iterator_to_array(static::getContainer()->get(ValidatorInterface::class)->validate($section)),
        );
    }

    public function testLabels(): void
    {
        self::assertSame('Grade 7 – Sampaguita', $this->section(GradeLevel::Grade7, 'Sampaguita')->getLabel());
        self::assertSame('Kindergarten – Rose', $this->section(GradeLevel::Kinder, 'Rose')->getLabel());

        $shs = $this->section(GradeLevel::Grade11, 'STEM 1')->setTrack(ShsTrack::Academic)->setStrand('STEM');
        self::assertSame('Grade 11 – STEM 1 (Academic – STEM)', $shs->getLabel());
        self::assertSame('Grade 12 – Ada (TVL / TechPro)', $this->section(GradeLevel::Grade12, 'Ada')->setTrack(ShsTrack::TvlTechPro)->getLabel());
    }

    public function testGradeLevelsFollowSchoolType(): void
    {
        self::assertSame([GradeLevel::Grade7, GradeLevel::Grade8, GradeLevel::Grade9, GradeLevel::Grade10], SchoolType::JuniorHigh->gradeLevels());
        self::assertCount(7, SchoolType::Elementary->gradeLevels());
        self::assertSame([GradeLevel::Grade11, GradeLevel::Grade12], SchoolType::SeniorHigh->gradeLevels());
        self::assertCount(13, SchoolType::Integrated->gradeLevels());

        self::assertContains('gradeLevel: Grade 3 is not offered by a Junior High School.', $this->violations($this->section(GradeLevel::Grade3, 'Rose')));
        self::assertSame([], $this->violations($this->section(GradeLevel::Grade7, 'Rose')));
    }

    public function testSeniorHighNeedsTrackAndOthersMustNotHaveOne(): void
    {
        $this->school->setType(SchoolType::Integrated);

        self::assertContains('track: Choose the track of this Senior High section.', $this->violations($this->section(GradeLevel::Grade11, 'STEM 1')));
        self::assertContains('track: Only Grade 11 and 12 sections have a track and strand.', $this->violations($this->section(GradeLevel::Grade8, 'Rosal')->setTrack(ShsTrack::Academic)->setStrand('STEM')));
    }

    public function testNameIsUniquePerGradeLevelAndSchoolYear(): void
    {
        $this->em->persist($this->section(GradeLevel::Grade7, 'Sampaguita'));
        $this->em->flush();

        self::assertContains('name: This section name already exists in Grade 7 for this school year.', $this->violations($this->section(GradeLevel::Grade7, 'Sampaguita')));
        self::assertSame([], $this->violations($this->section(GradeLevel::Grade8, 'Sampaguita')));
    }

    public function testTeacherAdvisesOnlyOneSectionPerSchoolYear(): void
    {
        $ana = $this->plainUser('ana', User::ROLE_TEACHER);
        $this->em->persist($this->section(GradeLevel::Grade7, 'Sampaguita', $ana));
        $this->em->flush();

        self::assertContains('adviser: Ana Tester is already the adviser of Grade 7 – Sampaguita.', $this->violations($this->section(GradeLevel::Grade8, 'Rosal', $ana)));
    }

    public function testDeletingAdviserLeavesSectionWithoutAdviser(): void
    {
        $ana = $this->plainUser('ana', User::ROLE_TEACHER);
        $section = $this->section(GradeLevel::Grade7, 'Sampaguita', $ana);
        $this->em->persist($section);
        $this->em->flush();
        $id = $section->getId();

        $this->em->getConnection()->executeStatement('DELETE FROM `user` WHERE id = ?', [$ana->getId()]);
        $this->em->clear();

        self::assertNull($this->em->getRepository(Section::class)->find($id)->getAdviser());
    }

    public function testRepositoryListsByGradeThenNameAndFindsAdvisory(): void
    {
        $ana = $this->plainUser('ana', User::ROLE_TEACHER);
        foreach ([[GradeLevel::Grade8, 'Rosal', null], [GradeLevel::Grade7, 'Sampaguita', $ana], [GradeLevel::Grade7, 'Ilang-Ilang', null]] as [$level, $name, $adviser]) {
            $this->em->persist($this->section($level, $name, $adviser));
        }
        $this->em->flush();
        $repository = static::getContainer()->get(SectionRepository::class);

        self::assertSame(['Grade 7 – Ilang-Ilang', 'Grade 7 – Sampaguita', 'Grade 8 – Rosal'], array_map(static fn (Section $s) => $s->getLabel(), $repository->findForSchool($this->school, $this->sy)));
        self::assertSame('Grade 7 – Sampaguita', $repository->findAdvisory($ana, $this->sy)?->getLabel());
    }
}
