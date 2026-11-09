<?php

declare(strict_types=1);

namespace App\Tests\Grading\Integration\Application;

use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Classes\Domain\Model\Section;
use App\Access\Domain\Model\User;
use App\Grading\Application\Service\GradePolicy;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class CardSlipPolicyTest extends KernelTestCase
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
        $this->section = $this->newSection($this->school, $this->sy, GradeLevel::Grade7, 'Active')->setAdviser(
            $this->users['adviser'],
        );
        $this->lastYearSection = $this->newSection($this->school, $lastYear, GradeLevel::Grade7, 'Active')->setAdviser(
            $this->users['adviser'],
        );
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    /** @param array<string, bool> $expected */
    private function assertRoles(string $attribute, Section $subject, array $expected): void
    {
        $policy = static::getContainer()->get(GradePolicy::class);
        foreach ($expected as $who => $granted) {
            self::assertSame(
                $granted,
                $policy->can($this->users[$who], $attribute, $subject),
                \sprintf('%s as %s', $attribute, $who),
            );
        }
    }

    public function testOnlyTheAdviserEditsThisYearsSlips(): void
    {
        $this->assertRoles(GradePolicy::CARD_SLIP_EDIT, $this->section, [
            'adviser' => true, 'teacher' => false, 'principal' => false, 'otherPrincipal' => false, 'admin' => false,
        ]);
        $this->assertRoles(GradePolicy::CARD_SLIP_EDIT, $this->lastYearSection, ['adviser' => false]);
    }

    public function testWhoPrints(): void
    {
        $this->assertRoles(GradePolicy::CARD_SLIP_PRINT, $this->section, [
            'adviser' => true, 'principal' => true, 'admin' => true, 'otherPrincipal' => false, 'teacher' => false,
        ]);
    }
}
