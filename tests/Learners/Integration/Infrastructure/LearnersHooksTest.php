<?php

declare(strict_types=1);

namespace App\Tests\Learners\Integration\Infrastructure;

use App\Access\Domain\Model\User;
use App\Access\Domain\Service\Account\AccountLearnerLinkInterface;
use App\Classes\Domain\Model\Subject;
use App\Entity\Grade;
use App\Learners\Domain\Service\Usage\LearnerGradesInterface;
use App\Learners\Infrastructure\Access\LearnerRoleChangeGuard;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\GradingPeriod;
use App\Schools\Domain\Service\Plan\EnrolledLearnersInterface;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The hooks Learners implements for Schools and Access, and the legacy implementation of its grades hook. */
final class LearnersHooksTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
    }

    public function testCountsTheLearnersOfASchoolAndOfASection(): void
    {
        // Initialize
        $sy = $this->schoolYear(2026, true);
        $school = $this->school('300001', 'Alpha NHS');
        $adviser = $this->plainUser('rt-adviser', User::ROLE_TEACHER);
        $rizal = $this->newSection($school, $sy, GradeLevel::Grade7, 'Rizal');
        $mabini = $this->newSection($school, $sy, GradeLevel::Grade7, 'Mabini');
        $this->enroll($this->learner('120000000001', 'Abad', 'Ana'), $sy, $rizal, $adviser);
        $this->enroll($this->learner('120000000002', 'Bautista', 'Ben'), $sy, $rizal, $adviser);
        $this->enroll($this->learner('120000000003', 'Cruz', 'Carla'), $sy, $mabini, $adviser);
        $learners = static::getContainer()->get(EnrolledLearnersInterface::class);

        // Execute
        $inSchool = $learners->countForSchool($school, $sy);
        $inRizal = $learners->countForSection((int) $rizal->getId());

        // Assert
        self::assertSame(3, $inSchool);
        self::assertSame(2, $inRizal);
    }

    public function testLinksALearnerAccountAndKeepsItAStudentAccount(): void
    {
        // Initialize
        $learner = $this->learner('120000000001', 'Abad', 'Ana');
        $account = $learner->getUser();
        $teacher = $this->plainUser('rt-teacher', User::ROLE_TEACHER);
        $link = static::getContainer()->get(AccountLearnerLinkInterface::class);
        $guard = static::getContainer()->get(LearnerRoleChangeGuard::class);

        // Execute
        $lrn = $link->learnerLrn($account);
        $noLrn = $link->learnerLrn($teacher);
        $unchanged = $guard->refusal($account, User::ROLE_STUDENT);
        $account->setRole(User::ROLE_TEACHER);
        $changed = $guard->refusal($account, User::ROLE_STUDENT);
        $teacher->setRole(User::ROLE_PRINCIPAL);
        $notALearner = $guard->refusal($teacher, User::ROLE_TEACHER);

        // Assert
        self::assertSame('120000000001', $lrn);
        self::assertNull($noLrn);
        self::assertNull($unchanged);
        self::assertSame('This account belongs to learner 120000000001.', $changed);
        self::assertNull($notALearner);
    }

    public function testCountsTheGradesOfAnEnrollment(): void
    {
        // Initialize
        $sy = $this->schoolYear(2026, true);
        $school = $this->school('300001', 'Alpha NHS');
        $teacher = $this->plainUser('rt-teacher', User::ROLE_TEACHER);
        $rizal = $this->newSection($school, $sy, GradeLevel::Grade7, 'Rizal');
        $math = (new Subject($school, $sy))->setGradeLevel(GradeLevel::Grade7)->setCode('MATH7')
            ->setName('Mathematics')->setPosition(1)->setMeetingsPerWeek(5);
        $term = new GradingPeriod($sy, 1);
        $this->em->persist($math);
        $this->em->persist($term);
        $graded = $this->enroll($this->learner('120000000001', 'Abad', 'Ana'), $sy, $rizal, $teacher);
        $ungraded = $this->enroll($this->learner('120000000002', 'Bautista', 'Ben'), $sy, $rizal, $teacher);
        $this->em->persist(new Grade($graded, $math, $term, 85, $teacher, new \DateTimeImmutable('2026-10-16')));
        $this->em->flush();
        $grades = static::getContainer()->get(LearnerGradesInterface::class);

        // Execute
        $withGrades = $grades->countForEnrollment($graded);
        $withoutGrades = $grades->countForEnrollment($ungraded);

        // Assert
        self::assertSame(1, $withGrades);
        self::assertSame(0, $withoutGrades);
    }
}
