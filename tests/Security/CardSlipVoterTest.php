<?php

namespace App\Tests\Security;

use App\Entity\GradeLevel;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\User;
use App\Security\Voter\CardSlipVoter;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

class CardSlipVoterTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private Section $section;
    private Section $lastYearSection;
    /** @var array<string, User> */
    private array $users;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2026-10-31 10:00:00');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $lastYear = $this->schoolYear(2025, current: false);
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school('300001', 'Pagsabungan NHS');
        $other = $this->school('300002', 'Other School');

        $this->users = [
            'admin' => $this->plainUser('zim', User::ROLE_ADMIN),
            'principal' => $this->plainUser('maria', User::ROLE_PRINCIPAL),
            'otherPrincipal' => $this->plainUser('otto', User::ROLE_PRINCIPAL),
            'adviser' => $this->plainUser('ana', User::ROLE_TEACHER),
            'teacher' => $this->plainUser('cara', User::ROLE_TEACHER),
        ];
        $this->assign($this->school, $this->sy, $this->users['principal']);
        $this->assign($other, $this->sy, $this->users['otherPrincipal']);
        foreach (['adviser', 'teacher'] as $who) {
            $this->assign($this->school, $this->sy, $this->users[$who], User::ROLE_TEACHER);
        }
        $this->section = $this->newSection($this->school, $this->sy, GradeLevel::Grade7, 'Active')->setAdviser($this->users['adviser']);
        $this->lastYearSection = $this->newSection($this->school, $lastYear, GradeLevel::Grade7, 'Active')->setAdviser($this->users['adviser']);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
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

    public function testOnlyTheAdviserEditsThisYearsSlips(): void
    {
        $this->assertRoles(CardSlipVoter::EDIT, $this->section, [
            'adviser' => true, 'teacher' => false, 'principal' => false, 'otherPrincipal' => false, 'admin' => false,
        ]);
        $this->assertRoles(CardSlipVoter::EDIT, $this->lastYearSection, ['adviser' => false]);
    }

    public function testWhoPrints(): void
    {
        $this->assertRoles(CardSlipVoter::PRINT, $this->section, [
            'adviser' => true, 'principal' => true, 'admin' => true, 'otherPrincipal' => false, 'teacher' => false,
        ]);
    }
}
