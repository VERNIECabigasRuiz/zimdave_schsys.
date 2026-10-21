<?php

namespace App\Tests\Security;

use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Security\Voter\SchoolVoter;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

class SchoolVoterTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private School $other;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school('300001', 'Pagsabungan NHS');
        $this->other = $this->school('300002', 'Other School');
    }

    private function canManage(User $user, School $school): bool
    {
        static::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        return static::getContainer()->get(AuthorizationCheckerInterface::class)->isGranted(SchoolVoter::MANAGE, $school);
    }

    public function testAdminCanManageAnySchool(): void
    {
        $admin = $this->plainUser('zim', User::ROLE_ADMIN);

        self::assertTrue($this->canManage($admin, $this->school));
        self::assertTrue($this->canManage($admin, $this->other));
    }

    public function testPrincipalCanManageOwnSchoolOnly(): void
    {
        $maria = $this->plainUser('maria', User::ROLE_PRINCIPAL);
        $this->assign($this->school, $this->sy, $maria);

        self::assertTrue($this->canManage($maria, $this->school));
        self::assertFalse($this->canManage($maria, $this->other));
    }

    public function testPrincipalLosesAccessWhenReassigned(): void
    {
        $maria = $this->plainUser('maria', User::ROLE_PRINCIPAL);
        $this->assign($this->school, $this->sy, $maria);
        $this->em->createQuery('DELETE FROM App\Entity\SchoolAssignment')->execute();
        $this->assign($this->other, $this->sy, $maria);

        self::assertFalse($this->canManage($maria, $this->school));
        self::assertTrue($this->canManage($maria, $this->other));
    }

    public function testPrincipalOfPreviousYearOnlyIsDenied(): void
    {
        $maria = $this->plainUser('maria', User::ROLE_PRINCIPAL);
        $this->assign($this->school, $this->schoolYear(2025, current: false), $maria);

        self::assertFalse($this->canManage($maria, $this->school));
    }

    public function testTeacherDenied(): void
    {
        $ana = $this->plainUser('ana', User::ROLE_TEACHER);
        $this->assign($this->school, $this->sy, $ana, User::ROLE_TEACHER);

        self::assertFalse($this->canManage($ana, $this->school));
    }
}
