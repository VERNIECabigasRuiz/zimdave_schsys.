<?php

namespace App\Tests\Classes\Functional;

use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Access\Domain\Model\User;
use App\Tests\Support\AdminTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class TeacherSectionControllerTest extends AdminTestCase
{
    use ClockSensitiveTrait;

    private SchoolYear $sy;
    private School $school;
    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();
        static::mockTime('2026-10-16');
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school(
            '303456',
            'Pagsabungan NHS',
            planValidUntil: new \DateTimeImmutable('2027-04-30'),
        );
        $this->ana = $this->createUser('ana', User::ROLE_TEACHER, 'Ana Reyes');
        $this->client->loginUser($this->ana);
    }

    public function testBasicPlanTeacherCannotCreateOwnSection(): void
    {
        $this->assign($this->school, $this->sy, $this->ana, User::ROLE_TEACHER);

        $this->client->request('GET', '/teacher/section');

        self::assertResponseStatusCodeSame(403);
    }

    public function testIndividualPlanTeacherCreatesOneOwnSection(): void
    {
        $own = $this->school(
            '303999',
            'Ana Home Class',
            plan: 'Individual',
            planValidUntil: new \DateTimeImmutable('2027-04-30'),
        );
        $this->assign($own, $this->sy, $this->ana, User::ROLE_TEACHER);

        $this->client->request('GET', '/teacher');
        $this->client->clickLink('Create my section');
        $this->client->submitForm('Create my section', [
            'section_form[gradeLevel]' => '8',
            'section_form[name]' => 'Narra',
        ]);

        self::assertResponseRedirects('/teacher');
        $this->em->clear();
        $sections = $this->em->getRepository(\App\Classes\Domain\Model\Section::class)->findAll();
        self::assertCount(1, $sections);
        self::assertSame(
            ['Grade 8 – Narra', 'ana', $own->getId()],
            [$sections[0]->getLabel(), $sections[0]->getAdviser()?->getUsername(), $sections[0]->getSchool()->getId()],
        );
        self::assertSelectorNotExists('input[name="section_form[adviser]"]');

        $this->client->request('GET', '/teacher/section');
        self::assertResponseRedirects('/teacher');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'You already advise Grade 8 – Narra.');
    }

    public function testATeacherWithoutASchoolGoesBackToTheDashboard(): void
    {
        // Execute
        $this->client->request('GET', '/teacher/section');

        // Assert
        self::assertResponseRedirects('/teacher');
    }

    public function testABlockedPlanIsRefusedOnTheDashboard(): void
    {
        // Initialize
        $own = $this->school(
            '303999',
            'Ana Home Class',
            plan: 'Individual',
            planValidUntil: new \DateTimeImmutable('2026-10-15'),
        );
        $this->assign($own, $this->sy, $this->ana, User::ROLE_TEACHER);

        // Execute
        $this->client->request('GET', '/teacher/section');

        // Assert
        self::assertResponseRedirects('/teacher');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'expired on Oct 15, 2026');
    }

    public function testAMissingNameIsShownOnTheForm(): void
    {
        // Initialize
        $own = $this->school(
            '303999',
            'Ana Home Class',
            plan: 'Individual',
            planValidUntil: new \DateTimeImmutable('2027-04-30'),
        );
        $this->assign($own, $this->sy, $this->ana, User::ROLE_TEACHER);
        $this->client->request('GET', '/teacher/section');

        // Execute
        $this->client->submitForm('Create my section', ['section_form[gradeLevel]' => '8']);

        // Assert
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Enter the section name.');
        self::assertSame(0, $this->em->getRepository(\App\Classes\Domain\Model\Section::class)->count([]));
    }
}
