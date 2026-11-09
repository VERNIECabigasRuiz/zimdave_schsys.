<?php

declare(strict_types=1);

namespace App\Tests\Nutrition\Integration\Application;

use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Classes\Domain\Model\Section;
use App\Access\Domain\Model\User;
use App\Nutrition\Application\Service\NutritionPolicy;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class NutritionPolicyTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private Section $section;
    private Section $lastYear;
    /** @var array<string, User> */
    private array $users;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $previous = $this->schoolYear(2024, current: false);
        $this->sy = $this->schoolYear(2025, current: true);
        $this->school = $this->school('300001', 'Pagsabungan NHS');
        $other = $this->school('300002', 'Other School');

        $this->users = [
            'admin' => $this->plainUser('zim', User::ROLE_ADMIN),
            'principal' => $this->plainUser('maria', User::ROLE_PRINCIPAL),
            'otherPrincipal' => $this->plainUser('otto', User::ROLE_PRINCIPAL),
            'adviser' => $this->plainUser('ana', User::ROLE_TEACHER),
            'teacher' => $this->plainUser('cara', User::ROLE_TEACHER),
            'student' => $this->plainUser('stu', User::ROLE_STUDENT),
        ];
        $this->assign($this->school, $this->sy, $this->users['principal']);
        $this->assign($other, $this->sy, $this->users['otherPrincipal']);
        $this->assign($this->school, $this->sy, $this->users['adviser'], User::ROLE_TEACHER);
        $this->assign($this->school, $this->sy, $this->users['teacher'], User::ROLE_TEACHER);
        $this->section = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Artistic')->setAdviser(
            $this->users['adviser'],
        );
        $this->lastYear = $this->newSection($this->school, $previous, GradeLevel::Grade7, 'Artistic')->setAdviser(
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
    private function assertRoles(string $attribute, Section $section, array $expected): void
    {
        $policy = static::getContainer()->get(NutritionPolicy::class);
        foreach ($expected as $who => $granted) {
            self::assertSame(
                $granted,
                $policy->can($this->users[$who], $attribute, $section),
                \sprintf('%s as %s', $attribute, $who),
            );
        }
    }

    public function testOnlyTheAdviserFillsSf8(): void
    {
        $this->assertRoles(NutritionPolicy::FILL, $this->section, [
            'adviser' => true,
            'principal' => false,
            'admin' => false,
            'otherPrincipal' => false,
            'teacher' => false,
            'student' => false,
        ]);
    }

    public function testNoSf8ForLastYearsSection(): void
    {
        $this->assertRoles(NutritionPolicy::FILL, $this->lastYear, ['adviser' => false]);
    }

    public function testWhoViews(): void
    {
        $this->assertRoles(NutritionPolicy::VIEW, $this->section, [
            'adviser' => true,
            'principal' => true,
            'admin' => true,
            'otherPrincipal' => false,
            'teacher' => false,
            'student' => false,
        ]);
    }

    public function testWhoReopens(): void
    {
        $this->assertRoles(NutritionPolicy::REOPEN, $this->section, [
            'adviser' => false,
            'principal' => true,
            'admin' => true,
            'otherPrincipal' => false,
            'teacher' => false,
            'student' => false,
        ]);
    }
}
