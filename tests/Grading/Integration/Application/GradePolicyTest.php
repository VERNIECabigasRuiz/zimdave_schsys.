<?php

declare(strict_types=1);

namespace App\Tests\Grading\Integration\Application;

use App\Grading\Domain\Model\GradeChangeRequest;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Classes\Domain\Model\Section;
use App\Classes\Domain\Model\Subject;
use App\Classes\Domain\Model\TeachingLoad;
use App\Access\Domain\Model\User;
use App\Grading\Application\Service\GradePolicy;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class GradePolicyTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private Section $section;
    private TeachingLoad $load;
    private TeachingLoad $kinderLoad;
    /** @var array<string, User> */
    private array $users;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2025-08-22 10:00:00');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2025, current: true);
        $this->school = $this->school('300001', 'Pagsabungan NHS');
        $other = $this->school('300002', 'Other School');

        $this->users = [
            'admin' => $this->plainUser('zim', User::ROLE_ADMIN),
            'principal' => $this->plainUser('maria', User::ROLE_PRINCIPAL),
            'otherPrincipal' => $this->plainUser('otto', User::ROLE_PRINCIPAL),
            'adviser' => $this->plainUser('ana', User::ROLE_TEACHER),
            'teacher' => $this->plainUser('cara', User::ROLE_TEACHER),
            'other' => $this->plainUser('dina', User::ROLE_TEACHER),
            'student' => $this->plainUser('stu', User::ROLE_STUDENT),
        ];
        $this->assign($this->school, $this->sy, $this->users['principal']);
        $this->assign($other, $this->sy, $this->users['otherPrincipal']);
        foreach (['adviser', 'teacher', 'other'] as $who) {
            $this->assign($this->school, $this->sy, $this->users[$who], User::ROLE_TEACHER);
        }
        $this->section = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Artistic')->setAdviser(
            $this->users['adviser'],
        );
        $this->load = new TeachingLoad(
            $this->section,
            $this->subject(GradeLevel::Grade8, 'MATH8'),
            $this->users['teacher'],
        );
        $kinder = $this->newSection($this->school, $this->sy, GradeLevel::Kinder, 'Sampaguita');
        $this->kinderLoad = new TeachingLoad(
            $kinder,
            $this->subject(GradeLevel::Kinder, 'KIN'),
            $this->users['teacher'],
        );
        $this->em->persist($this->load);
        $this->em->persist($this->kinderLoad);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    private function subject(GradeLevel $level, string $code): Subject
    {
        $subject = (new Subject($this->school, $this->sy))
            ->setGradeLevel($level)
            ->setCode($code)
            ->setName($code)
            ->setPosition(1);
        $subject->setMeetingsPerWeek(5);
        $this->em->persist($subject);

        return $subject;
    }

    /** @param array<string, bool> $expected */
    private function assertRoles(
        string $attribute,
        TeachingLoad|Section|School|GradeChangeRequest $subject,
        array $expected,
    ): void {
        $policy = static::getContainer()->get(GradePolicy::class);
        foreach ($expected as $who => $granted) {
            self::assertSame(
                $granted,
                $policy->can($this->users[$who], $attribute, $subject),
                \sprintf('%s as %s', $attribute, $who),
            );
        }
    }

    public function testOnlyTheLoadsTeacherEnters(): void
    {
        $this->assertRoles(GradePolicy::EDIT, $this->load, [
            'teacher' => true,
            'other' => false,
            'adviser' => false,
            'principal' => false,
            'admin' => false,
            'otherPrincipal' => false,
            'student' => false,
        ]);
    }

    public function testAdviserWhoAlsoTeachesTheLoadEnters(): void
    {
        $this->load->setTeacher($this->users['adviser']);
        $this->assertRoles(GradePolicy::EDIT, $this->load, ['adviser' => true, 'teacher' => false]);
    }

    public function testReassignedLoadMovesAccess(): void
    {
        $this->load->setTeacher($this->users['other']);
        $this->assertRoles(GradePolicy::EDIT, $this->load, ['teacher' => false, 'other' => true]);
        $this->assertRoles(GradePolicy::VIEW, $this->load, ['teacher' => false, 'other' => true]);
    }

    public function testNoEntryForKinder(): void
    {
        $this->assertRoles(GradePolicy::EDIT, $this->kinderLoad, ['teacher' => false]);
    }

    public function testWhoViewsALoad(): void
    {
        $this->assertRoles(GradePolicy::VIEW, $this->load, [
            'teacher' => true,
            'adviser' => true,
            'principal' => true,
            'admin' => true,
            'other' => false,
            'otherPrincipal' => false,
            'student' => false,
        ]);
    }

    public function testWhoViewsASection(): void
    {
        $this->assertRoles(GradePolicy::VIEW_SECTION, $this->section, [
            'adviser' => true,
            'principal' => true,
            'admin' => true,
            'teacher' => false,
            'other' => false,
            'otherPrincipal' => false,
            'student' => false,
        ]);
    }

    public function testOnlyThePrincipalLocks(): void
    {
        $this->assertRoles(GradePolicy::LOCK, $this->school, [
            'principal' => true,
            'admin' => false,
            'otherPrincipal' => false,
            'adviser' => false,
            'teacher' => false,
            'student' => false,
        ]);
    }

    public function testOpen(): void
    {
        $this->assertRoles(GradePolicy::OPEN, $this->school, [
            'principal' => true,
            'admin' => true,
            'otherPrincipal' => false,
            'adviser' => false,
            'teacher' => false,
            'student' => false,
        ]);
    }

    private function changeRequest(): GradeChangeRequest
    {
        $this->sy->rebuildGradingPeriods();

        return new GradeChangeRequest(
            $this->section,
            $this->load->getSubject(),
            ($this->sy->getGradingPeriod(1) ?? throw new \LogicException('No period 1')),
            $this->users['teacher'],
            'Encoding mistake in the sheet',
            new \DateTimeImmutable('2025-08-22 10:00:00'),
        );
    }

    public function testChangeWithdraw(): void
    {
        $request = $this->changeRequest();
        $this->load->setTeacher($this->users['other']);
        $this->assertRoles(GradePolicy::CHANGE_WITHDRAW, $request, [
            'teacher' => true,
            'other' => false,
            'principal' => false,
            'admin' => false,
            'otherPrincipal' => false,
            'student' => false,
        ]);
    }

    public function testChangeDecide(): void
    {
        $this->assertRoles(GradePolicy::CHANGE_DECIDE, $this->changeRequest(), [
            'principal' => true,
            'otherPrincipal' => false,
            'admin' => false,
            'teacher' => false,
            'adviser' => false,
            'student' => false,
        ]);
    }

    public function testAttributesMatchTheirSubjectType(): void
    {
        $this->assertRoles(GradePolicy::LOCK, $this->section, ['principal' => false, 'admin' => false]);
        $this->assertRoles(GradePolicy::EDIT, $this->school, ['teacher' => false]);
        $this->assertRoles(GradePolicy::VIEW_SECTION, $this->load, ['adviser' => false, 'admin' => false]);
        $this->assertRoles('GRADE_UNKNOWN', $this->load, ['teacher' => false, 'admin' => false]);
    }
}
