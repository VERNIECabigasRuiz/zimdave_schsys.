<?php

namespace App\Tests\Entity;

use App\Entity\GradeLevel;
use App\Entity\School;
use App\Entity\SchoolType;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\ShsTrack;
use App\Entity\Subject;
use App\Repository\SubjectRepository;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class SubjectTest extends KernelTestCase
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

    private function subject(GradeLevel $grade, string $code, string $name, ?Subject $parent = null, ?School $school = null, ?SchoolYear $sy = null, int $position = 1): Subject
    {
        return (new Subject($school ?? $this->school, $sy ?? $this->sy))
            ->setGradeLevel($grade)->setCode($code)->setName($name)->setParent($parent)->setPosition($position)->setMeetingsPerWeek(5);
    }

    private function saved(Subject $subject): Subject
    {
        $this->em->persist($subject);
        $this->em->flush();

        return $subject;
    }

    /** @return list<string> */
    private function violations(Subject $subject): array
    {
        return array_map(
            static fn ($v) => $v->getPropertyPath().': '.$v->getMessage(),
            iterator_to_array(static::getContainer()->get(ValidatorInterface::class)->validate($subject)),
        );
    }

    /** @return list<string> */
    private static function codes(array $subjects): array
    {
        return array_map(static fn (Subject $s) => $s->getCode(), $subjects);
    }

    private function repository(): SubjectRepository
    {
        return static::getContainer()->get(SubjectRepository::class);
    }

    public function testLabel(): void
    {
        $mapeh = $this->subject(GradeLevel::Grade7, 'MAPEH7', 'MAPEH');

        self::assertSame('MATH7 – Mathematics', $this->subject(GradeLevel::Grade7, 'MATH7', 'Mathematics')->getLabel());
        self::assertSame('MA7 – Music and Arts (MAPEH)', $this->subject(GradeLevel::Grade7, 'MA7', 'Music and Arts', $mapeh)->getLabel());
    }

    public function testCodeIsUppercasedAndValidated(): void
    {
        self::assertSame('MATH7', $this->subject(GradeLevel::Grade7, ' math7 ', 'Mathematics')->getCode());
        self::assertSame([], $this->violations($this->subject(GradeLevel::Grade7, 'TLE-ICT7', 'TLE ICT')));
        self::assertContains('code: Use only letters, numbers and dashes in the code.', $this->violations($this->subject(GradeLevel::Grade7, 'MATH 7', 'Mathematics')));
        self::assertContains('code: This value is too long. It should have 20 characters or less.', $this->violations($this->subject(GradeLevel::Grade7, str_repeat('A', 21), 'Mathematics')));
        self::assertContains('code: Enter the subject code.', $this->violations($this->subject(GradeLevel::Grade7, ' ', 'Mathematics')));
    }

    public function testGradeMustFitSchoolType(): void
    {
        self::assertContains('gradeLevel: Grade 3 is not offered by a Junior High School.', $this->violations($this->subject(GradeLevel::Grade3, 'SCI3', 'Science')));
        self::assertSame([], $this->violations($this->subject(GradeLevel::Grade7, 'SCI7', 'Science')));
    }

    public function testSeniorHighNeedsSemesterAndK10RejectsShsFields(): void
    {
        $this->school->setType(SchoolType::Integrated);

        self::assertContains('semester: Choose the semester of this Senior High subject.', $this->violations($this->subject(GradeLevel::Grade11, 'OC11', 'Oral Communication')));
        self::assertSame([], $this->violations($this->subject(GradeLevel::Grade11, 'OC11', 'Oral Communication')->setSemester(1)->setTrack(ShsTrack::Academic)->setStrand('STEM')));

        $k10 = 'semester: Only Grade 11 and 12 subjects have a semester, track and strand.';
        self::assertContains($k10, $this->violations($this->subject(GradeLevel::Grade7, 'SCI7', 'Science')->setSemester(1)));
        self::assertContains($k10, $this->violations($this->subject(GradeLevel::Grade7, 'SCI7', 'Science')->setTrack(ShsTrack::Academic)));
        self::assertContains($k10, $this->violations($this->subject(GradeLevel::Grade7, 'SCI7', 'Science')->setStrand('STEM')));
    }

    public function testMeetingsPerWeekRange(): void
    {
        foreach ([0, 11] as $bad) {
            self::assertCount(1, array_filter($this->violations($this->subject(GradeLevel::Grade7, 'MATH7', 'Mathematics')->setMeetingsPerWeek($bad)), static fn (string $v) => str_starts_with($v, 'meetingsPerWeek:')), (string) $bad);
        }
        foreach ([1, 10] as $ok) {
            self::assertSame([], $this->violations($this->subject(GradeLevel::Grade7, 'MATH7', 'Mathematics')->setMeetingsPerWeek($ok)), (string) $ok);
        }
    }

    public function testMeetingsPerWeekRequiredWithoutComponents(): void
    {
        $mapeh = $this->saved($this->subject(GradeLevel::Grade7, 'MAPEH7', 'MAPEH'));
        $this->saved($this->subject(GradeLevel::Grade7, 'MA7', 'Music and Arts', $mapeh));

        self::assertContains('meetingsPerWeek: Set how many times a week this subject meets.', $this->violations($this->subject(GradeLevel::Grade7, 'MATH7', 'Mathematics')->setMeetingsPerWeek(null)));
        self::assertSame([], $this->violations($mapeh->setMeetingsPerWeek(null)));
        self::assertTrue($this->subject(GradeLevel::Grade7, 'MATH7', 'Mathematics')->isLoadable());
        self::assertFalse($mapeh->isLoadable());
    }

    public function testDuplicateCodeRejected(): void
    {
        $this->saved($this->subject(GradeLevel::Grade7, 'MATH7', 'Mathematics'));

        self::assertContains('code: The code MATH7 is already used this school year.', $this->violations($this->subject(GradeLevel::Grade8, 'math7', 'Math Eight')));
        $nextYear = $this->schoolYear(2027, current: false);
        self::assertSame([], $this->violations($this->subject(GradeLevel::Grade7, 'MATH7', 'Mathematics', sy: $nextYear)));
    }

    public function testDuplicateNameRejectedPerGradeAndSemester(): void
    {
        $this->school->setType(SchoolType::Integrated);
        $this->saved($this->subject(GradeLevel::Grade7, 'MATH7', 'Mathematics'));
        $this->saved($this->subject(GradeLevel::Grade11, 'OC11-1', 'Oral Communication')->setSemester(1));

        self::assertContains('name: Grade 7 already has a subject named Mathematics.', $this->violations($this->subject(GradeLevel::Grade7, 'M7', 'mathematics')));
        self::assertSame([], $this->violations($this->subject(GradeLevel::Grade8, 'MATH8', 'Mathematics')));
        self::assertSame([], $this->violations($this->subject(GradeLevel::Grade11, 'OC11-2', 'Oral Communication')->setSemester(2)));
        self::assertNotSame([], $this->violations($this->subject(GradeLevel::Grade11, 'OC11-X', 'Oral Communication')->setSemester(1)));
    }

    public function testComponentRules(): void
    {
        $mapeh7 = $this->saved($this->subject(GradeLevel::Grade7, 'MAPEH7', 'MAPEH'));
        $ma7 = $this->saved($this->subject(GradeLevel::Grade7, 'MA7', 'Music and Arts', $mapeh7));
        $other = $this->saved($this->subject(GradeLevel::Grade7, 'MAPEH7', 'MAPEH', school: $this->school('303457', 'Other NHS')));
        $tle7 = $this->saved($this->subject(GradeLevel::Grade7, 'TLE7', 'TLE'));

        self::assertContains('parent: A component must be in the same grade level as its subject.', $this->violations($this->subject(GradeLevel::Grade8, 'PEH8', 'PE and Health', $mapeh7)));
        self::assertContains('parent: A component cannot have components of its own.', $this->violations($this->subject(GradeLevel::Grade7, 'X7', 'Extra', $ma7)));
        self::assertContains('parent: A component must be a subject of the same school and school year.', $this->violations($this->subject(GradeLevel::Grade7, 'PEH7', 'PE and Health', $other)));
        self::assertContains('parent: MAPEH7 – MAPEH has components, so it cannot be a component itself.', $this->violations($mapeh7->setParent($tle7)));
        self::assertContains('parent: A subject cannot be a component of itself.', $this->violations($ma7->setParent($ma7)));
    }

    public function testInheritFromParent(): void
    {
        $this->school->setType(SchoolType::Integrated);
        $parent = $this->subject(GradeLevel::Grade11, 'PEH11', 'PE and Health')->setSemester(2)->setTrack(ShsTrack::Academic)->setStrand('STEM');
        $component = $this->subject(GradeLevel::Grade11, 'PE11', 'Physical Education', $parent)->setSemester(1);

        $component->inheritFromParent();

        self::assertSame([2, ShsTrack::Academic, 'STEM'], [$component->getSemester(), $component->getTrack(), $component->getStrand()]);
        self::assertSame(1, $this->subject(GradeLevel::Grade11, 'X11', 'Alone')->setSemester(1)->inheritFromParent()->getSemester());
    }

    public function testFindForSection(): void
    {
        $this->school->setType(SchoolType::Integrated);
        $this->saved($this->subject(GradeLevel::Grade7, 'SCI7', 'Science', position: 2));
        $this->saved($this->subject(GradeLevel::Grade7, 'MATH7', 'Mathematics', position: 1));
        $this->saved($this->subject(GradeLevel::Grade8, 'MATH8', 'Mathematics'));
        $this->saved($this->subject(GradeLevel::Grade11, 'OC11', 'Oral Communication')->setSemester(1));
        $this->saved($this->subject(GradeLevel::Grade11, 'PC11', 'Pre-Calculus')->setSemester(1)->setTrack(ShsTrack::Academic)->setStrand('STEM'));
        $this->saved($this->subject(GradeLevel::Grade11, 'BM11', 'Business Math')->setSemester(1)->setTrack(ShsTrack::Academic)->setStrand('ABM'));
        $this->saved($this->subject(GradeLevel::Grade11, 'CSS11', 'Computer Systems Servicing')->setSemester(2)->setTrack(ShsTrack::TvlTechPro));

        $grade7 = (new Section($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade7)->setName('Sampaguita');
        $stem = (new Section($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade11)->setName('STEM 1')->setTrack(ShsTrack::Academic)->setStrand('stem');

        self::assertSame(['MATH7', 'SCI7'], self::codes($this->repository()->findForSection($grade7)));
        self::assertSame(['OC11', 'PC11'], self::codes($this->repository()->findForSection($stem)));
    }

    public function testNextPosition(): void
    {
        self::assertSame(1, $this->repository()->nextPosition($this->school, $this->sy, GradeLevel::Grade7));
        $this->saved($this->subject(GradeLevel::Grade7, 'MATH7', 'Mathematics', position: 4));

        self::assertSame(5, $this->repository()->nextPosition($this->school, $this->sy, GradeLevel::Grade7));
        self::assertSame(1, $this->repository()->nextPosition($this->school, $this->sy, GradeLevel::Grade8));
    }

    public function testFindBringBackCandidates(): void
    {
        $last = $this->schoolYear(2025, current: false);
        $this->saved($this->subject(GradeLevel::Grade7, 'MATH7', 'Mathematics', sy: $last));
        $this->saved($this->subject(GradeLevel::Grade7, 'SCI7', 'Science', sy: $last));
        $mapeh = $this->saved($this->subject(GradeLevel::Grade7, 'MAPEH7', 'MAPEH', sy: $last));
        $this->saved($this->subject(GradeLevel::Grade7, 'MA7', 'Music and Arts', $mapeh, sy: $last));
        $this->saved($this->subject(GradeLevel::Grade7, 'MATH7', 'Mathematics'));
        $this->saved($this->subject(GradeLevel::Grade7, 'SCIENCE7', 'Science'));

        self::assertSame(['MAPEH7'], self::codes($this->repository()->findBringBackCandidates($this->school, $last, $this->sy)));
    }

    public function testFindForSchoolOrdersByGradeSemesterPosition(): void
    {
        $this->saved($this->subject(GradeLevel::Grade8, 'MATH8', 'Mathematics'));
        $this->saved($this->subject(GradeLevel::Grade7, 'SCI7', 'Science', position: 2));
        $this->saved($this->subject(GradeLevel::Grade7, 'MATH7', 'Mathematics', position: 1));

        self::assertSame(['MATH7', 'SCI7', 'MATH8'], self::codes($this->repository()->findForSchool($this->school, $this->sy)));
    }

    public function testDeletingParentCascadesToComponents(): void
    {
        $mapeh = $this->saved($this->subject(GradeLevel::Grade7, 'MAPEH7', 'MAPEH'));
        $this->saved($this->subject(GradeLevel::Grade7, 'MA7', 'Music and Arts', $mapeh));
        $this->em->clear();

        $this->em->remove($this->em->getRepository(Subject::class)->findOneBy(['code' => 'MAPEH7']));
        $this->em->flush();

        self::assertSame(0, $this->em->getRepository(Subject::class)->count([]));
    }
}
