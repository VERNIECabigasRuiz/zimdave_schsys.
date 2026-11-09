<?php

declare(strict_types=1);

namespace App\Tests\Learners\Integration\Application;

use App\Learners\Domain\Model\Enrollment;
use App\Schools\Domain\Model\GradeLevel;
use App\Learners\Domain\Model\Learner;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Classes\Domain\Model\Section;
use App\Learners\Domain\Model\TransferRequest;
use App\Learners\Domain\Model\TransferStatus;
use App\Access\Domain\Model\User;
use App\Learners\Application\Service\LearnerPolicy;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class LearnerPolicyTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private School $other;
    private Section $section;
    private Section $otherSection;
    private Learner $learner;
    private Enrollment $enrollment;
    /** @var array<string, User> */
    private array $users;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school('300001', 'Pagsabungan NHS');
        $this->other = $this->school('300002', 'Other School');
        $this->section = $this->newSection($this->school, $this->sy, GradeLevel::Grade7, 'Rizal');
        $this->otherSection = $this->newSection($this->school, $this->sy, GradeLevel::Grade7, 'Bonifacio');

        $this->users = [
            'admin' => $this->plainUser('zim', User::ROLE_ADMIN),
            'principal' => $this->plainUser('maria', User::ROLE_PRINCIPAL),
            'otherPrincipal' => $this->plainUser('otto', User::ROLE_PRINCIPAL),
            'adviser' => $this->plainUser('ana', User::ROLE_TEACHER),
            'otherAdviser' => $this->plainUser('ben', User::ROLE_TEACHER),
            'teacher' => $this->plainUser('cara', User::ROLE_TEACHER),
            'student' => $this->plainUser('stu', User::ROLE_STUDENT),
        ];
        $this->assign($this->school, $this->sy, $this->users['principal']);
        $this->assign($this->other, $this->sy, $this->users['otherPrincipal']);
        $this->assign($this->school, $this->sy, $this->users['adviser'], User::ROLE_TEACHER);
        $this->assign($this->school, $this->sy, $this->users['otherAdviser'], User::ROLE_TEACHER);
        $this->assign($this->school, $this->sy, $this->users['teacher'], User::ROLE_TEACHER);
        $this->section->setAdviser($this->users['adviser']);
        $this->otherSection->setAdviser($this->users['otherAdviser']);
        $this->em->flush();

        $this->learner = $this->learner('100000000001', 'Cruz', 'Juan');
        $this->enrollment = $this->enroll($this->learner, $this->sy, $this->section, $this->users['admin']);
    }

    /**
     * @param array<string, bool> $expected
     */
    private function assertRoles(
        string $attribute,
        Section|Learner|School|TransferRequest $subject,
        array $expected,
    ): void {
        $policy = static::getContainer()->get(LearnerPolicy::class);
        foreach ($expected as $who => $granted) {
            self::assertSame(
                $granted,
                $policy->can($this->users[$who], $attribute, $subject),
                \sprintf('%s on %s as %s', $attribute, $subject::class, $who),
            );
        }
    }

    /**
     * @return array<string, bool>
     */
    private function matrix(bool $admin, bool $principal, bool $adviser, bool $otherPrincipal = false): array
    {
        return [
            'admin' => $admin,
            'principal' => $principal,
            'otherPrincipal' => $otherPrincipal,
            'adviser' => $adviser,
            'otherAdviser' => false,
            'teacher' => false,
            'student' => false,
        ];
    }

    public function testEnroll(): void
    {
        $this->assertRoles(LearnerPolicy::ENROLL, $this->section, $this->matrix(true, true, true));
    }

    public function testViewClassMatchesEnroll(): void
    {
        $this->assertRoles(LearnerPolicy::VIEW_CLASS, $this->section, $this->matrix(true, true, true));
    }

    public function testEnrollIntoPreviousYearSectionDenied(): void
    {
        $old = $this->newSection($this->school, $this->schoolYear(2025, current: false), GradeLevel::Grade7, 'Old');
        $old->setAdviser($this->users['adviser']);
        $this->em->flush();

        $this->assertRoles(LearnerPolicy::ENROLL, $old, $this->matrix(false, false, false));
    }

    public function testEdit(): void
    {
        $this->assertRoles(LearnerPolicy::EDIT, $this->learner, $this->matrix(true, true, true));
    }

    public function testResetPassword(): void
    {
        $this->assertRoles(LearnerPolicy::RESET_PASSWORD, $this->learner, $this->matrix(true, true, true));
    }

    public function testUnenroll(): void
    {
        $this->assertRoles(LearnerPolicy::UNENROLL, $this->learner, $this->matrix(true, true, true));
    }

    public function testChangeLrn(): void
    {
        $this->assertRoles(LearnerPolicy::CHANGE_LRN, $this->learner, $this->matrix(true, true, false));
    }

    public function testMove(): void
    {
        $this->assertRoles(LearnerPolicy::MOVE, $this->learner, $this->matrix(true, true, false));
    }

    public function testLearnerWithoutCurrentEnrollmentIsAdminOnly(): void
    {
        $free = $this->learner('100000000002', 'Reyes', 'Ana');
        $attributes = [
            LearnerPolicy::EDIT,
            LearnerPolicy::RESET_PASSWORD,
            LearnerPolicy::UNENROLL,
            LearnerPolicy::CHANGE_LRN,
            LearnerPolicy::MOVE,
        ];
        foreach ($attributes as $attribute) {
            $this->assertRoles($attribute, $free, $this->matrix(true, false, false));
        }
    }

    public function testNobodyChangesALearnerWithoutACurrentSchoolYear(): void
    {
        // Initialize
        $this->sy->setCurrent(false);
        $this->em->flush();

        // Execute
        $this->assertRoles(LearnerPolicy::EDIT, $this->learner, $this->matrix(false, false, false));

        // Assert
        self::assertNull($this->em->getRepository(SchoolYear::class)->findOneBy(['current' => true]));
    }

    public function testRequestTransfer(): void
    {
        $this->assertRoles(LearnerPolicy::REQUEST_TRANSFER, $this->school, $this->matrix(false, true, false));
    }

    private function pendingRequest(): TransferRequest
    {
        $target = $this->newSection($this->other, $this->sy, GradeLevel::Grade7, 'Mabini');
        $request = new TransferRequest(
            $this->enrollment,
            $target,
            $this->users['otherPrincipal'],
            new \DateTimeImmutable(),
        );
        $this->em->persist($request);
        $this->em->flush();

        return $request;
    }

    public function testDecideTransferWhenSchoolHasPrincipal(): void
    {
        $this->assertRoles(LearnerPolicy::DECIDE_TRANSFER, $this->pendingRequest(), $this->matrix(true, true, false));
    }

    public function testDecideTransferAdviserOnlyWhenPlanHasNoPrincipal(): void
    {
        $request = $this->pendingRequest();
        $this->school->setPlan($this->plan('Individual'));
        $this->em->flush();

        $this->assertRoles(LearnerPolicy::DECIDE_TRANSFER, $request, $this->matrix(true, true, true));
    }

    public function testCancelTransfer(): void
    {
        $this->assertRoles(
            LearnerPolicy::CANCEL_TRANSFER,
            $this->pendingRequest(),
            $this->matrix(true, false, false, true),
        );
    }

    public function testDecidedRequestCannotBeDecidedAgain(): void
    {
        $request = $this->pendingRequest();
        $request->decide(TransferStatus::Declined, $this->users['principal'], new \DateTimeImmutable());
        $this->em->flush();

        $none = $this->matrix(false, false, false);
        $this->assertRoles(LearnerPolicy::DECIDE_TRANSFER, $request, $none);
        $this->assertRoles(LearnerPolicy::CANCEL_TRANSFER, $request, $none);
    }

    public function testPendingRequestFromPreviousYearIsDeniedToEveryone(): void
    {
        $oldYear = $this->schoolYear(2025, current: false);
        $from = $this->newSection($this->school, $oldYear, GradeLevel::Grade7, 'Old');
        $from->setAdviser($this->users['adviser']);
        $learner = $this->learner('100000000003', 'Old', 'Pupil');
        $enrollment = $this->enroll($learner, $oldYear, $from, $this->users['admin']);
        $target = $this->newSection($this->other, $oldYear, GradeLevel::Grade7, 'OldTarget');
        $request = new TransferRequest($enrollment, $target, $this->users['otherPrincipal'], new \DateTimeImmutable());
        $this->em->persist($request);
        $this->school->setPlan($this->plan('Individual'));
        $this->em->flush();

        $none = $this->matrix(false, false, false);
        $this->assertRoles(LearnerPolicy::DECIDE_TRANSFER, $request, $none);
        $this->assertRoles(LearnerPolicy::CANCEL_TRANSFER, $request, $none);
    }

    public function testFormerAdviserLosesAccess(): void
    {
        $this->assertRoles(LearnerPolicy::EDIT, $this->learner, ['adviser' => true]);
        $this->section->setAdviser(null);
        $this->em->flush();

        $this->assertRoles(LearnerPolicy::EDIT, $this->learner, ['adviser' => false]);
        $this->assertRoles(LearnerPolicy::ENROLL, $this->section, ['adviser' => false]);
    }
}
