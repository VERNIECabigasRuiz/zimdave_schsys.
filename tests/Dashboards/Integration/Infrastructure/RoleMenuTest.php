<?php

declare(strict_types=1);

namespace App\Tests\Dashboards\Integration\Infrastructure;

use App\Access\Domain\Model\User;
use App\Core\Domain\Service\Menu\RoleMenuInterface;
use App\Schools\Domain\Model\GradeLevel;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The answers behind the role layouts' menus. */
final class RoleMenuTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private RoleMenuInterface $menu;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->menu = static::getContainer()->get(RoleMenuInterface::class);
        $this->clearSchoolData();
    }

    public function testWithoutACurrentSchoolYearOnlyTheTeachingLoadShows(): void
    {
        // Initialize
        $this->schoolYear(2025, false);
        $id = (int) $this->plainUser('ana', User::ROLE_TEACHER)->getId();

        // Execute
        $answers = [$this->menu->hasTeachingLoad($id), $this->menu->hasId($id), $this->menu->advisesSection($id)];

        // Assert
        self::assertSame([true, false, false], $answers);
    }

    public function testTeachingLoadDependsOnThePlanOfTheTeachersSchool(): void
    {
        // Initialize
        $sy = $this->schoolYear(2026, true);
        $basic = $this->school('300001', 'Alpha NHS');
        $solo = $this->school('300002', 'Beta Learning Center', plan: 'Individual');
        $onBasic = $this->plainUser('ana', User::ROLE_TEACHER);
        $onIndividual = $this->plainUser('ben', User::ROLE_TEACHER);
        $withoutSchool = $this->plainUser('cris', User::ROLE_TEACHER);
        $this->assign($basic, $sy, $onBasic, User::ROLE_TEACHER);
        $this->assign($solo, $sy, $onIndividual, User::ROLE_TEACHER);

        // Execute
        $answers = array_map(
            fn (User $teacher): bool => $this->menu->hasTeachingLoad((int) $teacher->getId()),
            [$onBasic, $onIndividual, $withoutSchool],
        );

        // Assert
        self::assertSame([true, false, true], $answers);
    }

    public function testAnIdNeedsATeacherOrPrincipalAssignmentThisYear(): void
    {
        // Initialize
        $sy = $this->schoolYear(2026, true);
        $school = $this->school('300001', 'Alpha NHS');
        $teacher = $this->plainUser('ana', User::ROLE_TEACHER);
        $principal = $this->plainUser('maria', User::ROLE_PRINCIPAL);
        $unassigned = $this->plainUser('ben', User::ROLE_TEACHER);
        $admin = $this->plainUser('zim', User::ROLE_ADMIN);
        $this->assign($school, $sy, $teacher, User::ROLE_TEACHER);
        $this->assign($school, $sy, $principal);

        // Execute
        $answers = array_map(
            fn (User $account): bool => $this->menu->hasId((int) $account->getId()),
            [$teacher, $principal, $unassigned, $admin],
        );

        // Assert
        self::assertSame([true, true, false, false], $answers);
    }

    public function testAdvisesSectionOnlyForTheAdviserThisYear(): void
    {
        // Initialize
        $sy = $this->schoolYear(2026, true);
        $school = $this->school('300001', 'Alpha NHS');
        $adviser = $this->plainUser('ana', User::ROLE_TEACHER);
        $other = $this->plainUser('ben', User::ROLE_TEACHER);
        $this->newSection($school, $sy, GradeLevel::Grade7, 'Rizal')->setAdviser($adviser);
        $this->em->flush();

        // Execute
        $answers = [
            $this->menu->advisesSection((int) $adviser->getId()),
            $this->menu->advisesSection((int) $other->getId()),
        ];

        // Assert
        self::assertSame([true, false], $answers);
    }

    public function testAnUnknownAccountGetsTheAnswersForNoSchool(): void
    {
        // Initialize
        $this->schoolYear(2026, true);

        // Execute
        $answers = [$this->menu->hasTeachingLoad(-1), $this->menu->hasId(-1), $this->menu->advisesSection(-1)];

        // Assert
        self::assertSame([true, false, false], $answers);
    }
}
