<?php

namespace App\Tests\Security;

use App\Grading\Domain\Model\GradeChangeRequest;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Classes\Domain\Model\Section;
use App\Classes\Domain\Model\Subject;
use App\Classes\Domain\Model\TeachingLoad;
use App\Access\Domain\Model\User;
use App\Security\Voter\GradeVoter;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

class GradeVoterTest extends KernelTestCase
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
        $this->section = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Artistic')->setAdviser($this->users['adviser']);
        $this->load = new TeachingLoad($this->section, $this->subject(GradeLevel::Grade8, 'MATH8'), $this->users['teacher']);
        $kinder = $this->newSection($this->school, $this->sy, GradeLevel::Kinder, 'Sampaguita');
        $this->kinderLoad = new TeachingLoad($kinder, $this->subject(GradeLevel::Kinder, 'KIN'), $this->users['teacher']);
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
        $subject = (new Subject($this->school, $this->sy))->setGradeLevel($level)->setCode($code)->setName($code)->setPosition(1);
        $subject->setMeetingsPerWeek(5);
        $this->em->persist($subject);

        return $subject;
    }

    /** @param array<string, bool> $expected */
    private function assertRoles(string $attribute, object $subject, array $expected): void
    {
        $checker = static::getContainer()->get(AuthorizationCheckerInterface::class);
        foreach ($expected as $who => $granted) {
            static::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($this->users[$who], 'main', $this->users[$who]->getRoles()));
            self::assertSame($granted, $checker->isGranted($attribute, $subject), \sprintf('%s as %s', $attribute, $who));
        }
    }

    private function changeRequest(): GradeChangeRequest
    {
        $this->sy->rebuildGradingPeriods();

        return new GradeChangeRequest($this->section, $this->load->getSubject(), $this->sy->getGradingPeriod(1), $this->users['teacher'], 'Encoding mistake in the sheet', new \DateTimeImmutable('2025-08-22 10:00:00'));
    }

    public function testChangeWithdraw(): void
    {
        $request = $this->changeRequest();
        $this->load->setTeacher($this->users['other']);
        $this->assertRoles(GradeVoter::CHANGE_WITHDRAW, $request, [
            'teacher' => true, 'other' => false, 'principal' => false, 'admin' => false, 'otherPrincipal' => false, 'student' => false,
        ]);
    }

    public function testChangeDecide(): void
    {
        $this->assertRoles(GradeVoter::CHANGE_DECIDE, $this->changeRequest(), [
            'principal' => true, 'otherPrincipal' => false, 'admin' => false, 'teacher' => false, 'adviser' => false, 'student' => false,
        ]);
    }

    public function testAttributesMatchTheirSubjectType(): void
    {
        $this->assertRoles(GradeVoter::CHANGE_DECIDE, $this->section, ['principal' => false]);
        $this->assertRoles(GradeVoter::CHANGE_WITHDRAW, $this->load, ['teacher' => false]);
        $this->assertRoles('GRADE_EDIT', $this->load, ['teacher' => false]);
    }
}
