<?php

namespace App\Tests\Schools\Integration\Presentation;

use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Access\Domain\Model\User;
use App\Schools\Domain\Model\Repository\SchoolAssignmentRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Schools\Presentation\Security\SchoolVoter;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
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
        static::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken(
            $user,
            'main',
            $user->getRoles(),
        ));

        return static::getContainer()->get(AuthorizationCheckerInterface::class)->isGranted(
            SchoolVoter::MANAGE,
            $school,
        );
    }

    public function testSignedOutVisitorsAreDenied(): void
    {
        // Initialize
        $voter = new SchoolVoter(
            static::getContainer()->get(SchoolYearRepositoryInterface::class),
            static::getContainer()->get(SchoolAssignmentRepositoryInterface::class),
        );

        // Execute
        $vote = $voter->vote(new NullToken(), $this->school, [SchoolVoter::MANAGE]);

        // Assert
        self::assertSame(VoterInterface::ACCESS_DENIED, $vote);
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
        $this->em->createQuery('DELETE FROM App\Schools\Domain\Model\SchoolAssignment')->execute();
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
