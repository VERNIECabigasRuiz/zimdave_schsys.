<?php

declare(strict_types=1);

namespace App\Tests\LessonPlans\Integration\Application;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Subject;
use App\LessonPlans\Application\Service\LessonPlanPolicy;
use App\Classes\Domain\Model\TeachingLoad;
use App\LessonPlans\Domain\Model\LessonPlan;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class LessonPlanPolicyTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private User $tess;
    private User $ana;
    private User $rene;
    private TeachingLoad $load;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2026-11-24 10:00:00');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school('303196', 'Pagsabungan NHS');
        $this->tess = $this->plainUser('tess', User::ROLE_TEACHER);
        $this->ana = $this->plainUser('ana', User::ROLE_TEACHER);
        $this->rene = $this->plainUser('rene', User::ROLE_PRINCIPAL);
        $this->assign($this->school, $this->sy, $this->tess, User::ROLE_TEACHER);
        $this->assign($this->school, $this->sy, $this->ana, User::ROLE_TEACHER);
        $this->assign($this->school, $this->sy, $this->rene);
        $this->load = $this->load($this->tess, 'Artistic');
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    public function testOnlyTheTeacherEditsAndTheirPrincipalThisYearAlsoViews(): void
    {
        // Initialize
        $plan = $this->lessonPlan($this->load);
        $otherSchool = $this->school('303197', 'Other NHS');
        $otto = $this->plainUser('otto', User::ROLE_PRINCIPAL);
        $this->assign($otherSchool, $this->sy, $otto);
        $lastYear = $this->schoolYear(2025, current: false);
        $leah = $this->plainUser('leah', User::ROLE_PRINCIPAL);
        $this->assign($this->school, $lastYear, $leah);
        $admin = $this->plainUser('zim.admin', User::ROLE_ADMIN);
        $people = ['tess' => $this->tess, 'rene' => $this->rene, 'otto' => $otto, 'ana' => $this->ana,
            'admin' => $admin, 'leah' => $leah];
        $policy = static::getContainer()->get(LessonPlanPolicy::class);

        // Execute
        $answers = [];
        foreach ($people as $who => $user) {
            $answers[$who] = [
                $policy->can($user, LessonPlanPolicy::VIEW, $plan),
                $policy->can($user, LessonPlanPolicy::EDIT, $plan),
            ];
        }
        $this->sy->setCurrent(false);
        $this->em->flush();
        $withoutAYear = $policy->can($this->rene, LessonPlanPolicy::VIEW, $plan);

        // Assert
        self::assertSame([
            'tess' => [true, true],
            'rene' => [true, false],
            'otto' => [false, false],
            'ana' => [false, false],
            'admin' => [false, false],
            'leah' => [false, false],
        ], $answers);
        self::assertFalse($withoutAYear);
    }

    private function load(User $teacher, string $section): TeachingLoad
    {
        $subject = (new Subject($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade8)
            ->setCode('MATH8-' . $section)->setName('Mathematics')->setPosition(1)->setMeetingsPerWeek(3);
        $this->em->persist($subject);
        $load = new TeachingLoad(
            $this->newSection($this->school, $this->sy, GradeLevel::Grade8, $section),
            $subject,
            $teacher,
        );
        $this->em->persist($load);
        $this->em->flush();

        return $load;
    }

    private function lessonPlan(TeachingLoad $load, string $monday = '2026-11-23'): LessonPlan
    {
        $plan = (new LessonPlan($load, new \DateTimeImmutable($monday), new \DateTimeImmutable('2026-11-20 08:00')))
            ->setDays([LessonPlan::emptyDay('2026-11-23'), LessonPlan::emptyDay('2026-11-24', 'Bonifacio Day')]);
        $this->em->persist($plan);
        $this->em->flush();

        return $plan;
    }
}
