<?php

namespace App\Tests\Controller\Admin;

use App\Entity\GradingPeriod;
use App\Entity\GradingSystem;
use App\Entity\SchoolYear;

class SchoolYearControllerTest extends AdminTestCase
{
    private function createSchoolYear(string $name): void
    {
        [$start, $end] = explode('-', $name);

        $this->client->request('GET', '/admin/school-years/new');
        $this->client->submitForm('Create school year', [
            'school_year_form[name]' => $name,
            'school_year_form[startDate]' => $start.'-06-01',
            'school_year_form[endDate]' => $end.'-04-30',
        ]);
        self::assertResponseRedirects('/admin/school-years');
    }

    private function findSchoolYear(string $name): SchoolYear
    {
        $this->em->clear();

        return $this->em->getRepository(SchoolYear::class)->findOneBy(['name' => $name]);
    }

    public function testFirstSchoolYearIsCurrentAndHasThreeClosedTerms(): void
    {
        $this->createSchoolYear('2026-2027');
        $crawler = $this->client->followRedirect();

        $sy = $this->findSchoolYear('2026-2027');
        self::assertTrue($sy->isCurrent());
        self::assertSame(GradingSystem::Terms, $sy->getGradingSystem());
        self::assertCount(3, $sy->getGradingPeriods());
        foreach ($sy->getGradingPeriods() as $period) {
            self::assertFalse($period->isOpen());
        }

        self::assertSelectorTextContains('.sy-card', 'Current');
        self::assertSame(['Term 1', 'Term 2', 'Term 3'], $crawler->filter('.quarter__name')->each(static fn ($n) => $n->text()));
    }

    public function testNewTermsSplitTheSchoolYearEvenly(): void
    {
        $this->createSchoolYear('2026-2027');

        $periods = $this->findSchoolYear('2026-2027')->getGradingPeriods();
        self::assertSame('2026-06-01', $periods[0]->getStartDate()->format('Y-m-d'));
        self::assertSame('2027-04-30', $periods[2]->getEndDate()->format('Y-m-d'));
        // Back to back: each term starts the day after the previous one ends
        self::assertSame($periods[0]->getEndDate()->modify('+1 day')->format('Y-m-d'), $periods[1]->getStartDate()->format('Y-m-d'));
        self::assertSame($periods[1]->getEndDate()->modify('+1 day')->format('Y-m-d'), $periods[2]->getStartDate()->format('Y-m-d'));
    }

    public function testSchoolYearCanUseFourQuarters(): void
    {
        $this->client->request('GET', '/admin/school-years/new');
        $this->client->submitForm('Create school year', [
            'school_year_form[name]' => '2025-2026',
            'school_year_form[startDate]' => '2025-06-16',
            'school_year_form[endDate]' => '2026-03-31',
            'school_year_form[gradingSystem]' => 'quarters',
        ]);
        $crawler = $this->client->followRedirect();

        $sy = $this->findSchoolYear('2025-2026');
        self::assertSame(GradingSystem::Quarters, $sy->getGradingSystem());
        self::assertCount(4, $sy->getGradingPeriods());
        self::assertSame('Quarter 4', $crawler->filter('.quarter__name')->last()->text());
    }

    public function testAdminSetsTermDatesFromTheMemo(): void
    {
        $this->createSchoolYear('2026-2027');
        $id = $this->findSchoolYear('2026-2027')->getId();

        $this->client->request('GET', '/admin/school-years/'.$id.'/edit');
        $this->client->submitForm('Save changes', [
            'school_year_form[startDate]' => '2026-06-08',
            'school_year_form[endDate]' => '2027-04-08',
            'school_year_form[gradingPeriods][0][startDate]' => '2026-06-08',
            'school_year_form[gradingPeriods][0][endDate]' => '2026-09-15',
            'school_year_form[gradingPeriods][1][startDate]' => '2026-09-16',
            'school_year_form[gradingPeriods][1][endDate]' => '2026-12-18',
            'school_year_form[gradingPeriods][2][startDate]' => '2027-01-04',
            'school_year_form[gradingPeriods][2][endDate]' => '2027-04-08',
        ]);
        self::assertResponseRedirects('/admin/school-years');
        $crawler = $this->client->followRedirect();

        $term3 = $this->findSchoolYear('2026-2027')->getGradingPeriod(3);
        self::assertSame('2027-01-04', $term3->getStartDate()->format('Y-m-d'));
        self::assertStringContainsString('Jun 8 – Sep 15, 2026', $crawler->filter('.quarter')->first()->text());
    }

    public function testTermDatesAreValidated(): void
    {
        $this->createSchoolYear('2026-2027');
        $id = $this->findSchoolYear('2026-2027')->getId();

        $this->client->request('GET', '/admin/school-years/'.$id.'/edit');
        $this->client->submitForm('Save changes', [
            'school_year_form[gradingPeriods][0][startDate]' => '2026-05-01',
            'school_year_form[gradingPeriods][0][endDate]' => '2026-09-15',
            'school_year_form[gradingPeriods][1][startDate]' => '2026-09-10',
            'school_year_form[gradingPeriods][1][endDate]' => '2026-12-18',
            'school_year_form[gradingPeriods][2][startDate]' => '2027-03-01',
            'school_year_form[gradingPeriods][2][endDate]' => '2027-01-04',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-card', 'Term 1 must be within the school year.');
        self::assertSelectorTextContains('.form-card', 'Term 2 must start after Term 1 ends.');
        self::assertSelectorTextContains('.form-card', 'Term 3 must end after it starts.');
        self::assertSame('2026-06-01', $this->findSchoolYear('2026-2027')->getGradingPeriod(1)->getStartDate()->format('Y-m-d'));
    }

    public function testSwitchingToQuartersRebuildsPeriods(): void
    {
        $this->createSchoolYear('2026-2027');
        $id = $this->findSchoolYear('2026-2027')->getId();

        $this->client->request('GET', '/admin/school-years/'.$id.'/edit');
        $this->client->submitForm('Save changes', ['school_year_form[gradingSystem]' => 'quarters']);
        self::assertResponseRedirects('/admin/school-years');

        $sy = $this->findSchoolYear('2026-2027');
        self::assertSame(GradingSystem::Quarters, $sy->getGradingSystem());
        self::assertCount(4, $sy->getGradingPeriods());
        self::assertSame('2027-04-30', $sy->getGradingPeriod(4)->getEndDate()->format('Y-m-d'));
    }

    public function testOlderQuartersWithoutDatesCanSwitchToTerms(): void
    {
        // A school year created before period dates existed: four quarters, no dates
        $sy = (new SchoolYear())
            ->setName('2026-2027')
            ->setStartDate(new \DateTimeImmutable('2026-06-01'))
            ->setEndDate(new \DateTimeImmutable('2027-04-30'))
            ->setGradingSystem(GradingSystem::Quarters);
        for ($number = 1; $number <= 4; ++$number) {
            $sy->getGradingPeriods()->add(new GradingPeriod($sy, $number));
        }
        $this->em->persist($sy);
        $this->em->flush();

        $this->client->request('GET', '/admin/school-years/'.$sy->getId().'/edit');
        $this->client->submitForm('Save changes', [
            'school_year_form[startDate]' => '2026-06-08',
            'school_year_form[endDate]' => '2027-04-08',
            'school_year_form[gradingSystem]' => 'terms',
        ]);
        self::assertResponseRedirects('/admin/school-years');

        $saved = $this->findSchoolYear('2026-2027');
        self::assertSame(GradingSystem::Terms, $saved->getGradingSystem());
        self::assertCount(3, $saved->getGradingPeriods());
        self::assertSame('2026-06-08', $saved->getGradingPeriod(1)->getStartDate()->format('Y-m-d'));
        self::assertSame('2027-04-08', $saved->getGradingPeriod(3)->getEndDate()->format('Y-m-d'));
    }

    /**
     * Submits the edit form the way the page does after the grading periods are redrawn for the chosen system.
     *
     * @param array<string, string>        $fields  top-level fields to change
     * @param list<array{0: string, 1: string}> $periods start/end date per period row
     */
    private function submitEditWithPeriods(SchoolYear $schoolYear, array $fields, array $periods): void
    {
        $crawler = $this->client->request('GET', '/admin/school-years/'.$schoolYear->getId().'/edit');
        $values = $crawler->selectButton('Save changes')->form()->getPhpValues();

        foreach ($fields as $name => $value) {
            $values['school_year_form'][$name] = $value;
        }
        $values['school_year_form']['gradingPeriods'] = array_map(
            static fn (array $dates) => ['startDate' => $dates[0], 'endDate' => $dates[1]],
            $periods,
        );

        $this->client->request('POST', '/admin/school-years/'.$schoolYear->getId().'/edit', $values);
    }

    public function testSwitchToTermsAndSetMemoDatesInOneSave(): void
    {
        $sy = (new SchoolYear())
            ->setName('2026-2027')
            ->setStartDate(new \DateTimeImmutable('2026-06-01'))
            ->setEndDate(new \DateTimeImmutable('2027-04-30'))
            ->setGradingSystem(GradingSystem::Quarters);
        for ($number = 1; $number <= 4; ++$number) {
            $sy->getGradingPeriods()->add(new GradingPeriod($sy, $number));
        }
        $this->em->persist($sy);
        $this->em->flush();

        $this->submitEditWithPeriods(
            $sy,
            ['startDate' => '2026-06-08', 'endDate' => '2027-04-08', 'gradingSystem' => 'terms'],
            [['2026-06-08', '2026-09-15'], ['2026-09-16', '2026-12-18'], ['2027-01-04', '2027-04-08']],
        );
        self::assertResponseRedirects('/admin/school-years');

        $saved = $this->findSchoolYear('2026-2027');
        self::assertSame(GradingSystem::Terms, $saved->getGradingSystem());
        self::assertCount(3, $saved->getGradingPeriods());
        self::assertSame('2026-09-15', $saved->getGradingPeriod(1)->getEndDate()->format('Y-m-d'));
        self::assertSame('2026-12-18', $saved->getGradingPeriod(2)->getEndDate()->format('Y-m-d'));
        self::assertSame('2027-01-04', $saved->getGradingPeriod(3)->getStartDate()->format('Y-m-d'));
    }

    public function testSwitchToQuartersAndSetDatesInOneSave(): void
    {
        $this->createSchoolYear('2025-2026');
        $sy = $this->findSchoolYear('2025-2026');

        $this->submitEditWithPeriods(
            $sy,
            ['gradingSystem' => 'quarters'],
            [['2025-06-01', '2025-08-15'], ['2025-08-18', '2025-10-31'], ['2025-11-03', '2026-01-30'], ['2026-02-02', '2026-04-30']],
        );
        self::assertResponseRedirects('/admin/school-years');

        $saved = $this->findSchoolYear('2025-2026');
        self::assertSame(GradingSystem::Quarters, $saved->getGradingSystem());
        self::assertCount(4, $saved->getGradingPeriods());
        self::assertSame('2026-02-02', $saved->getGradingPeriod(4)->getStartDate()->format('Y-m-d'));
        self::assertSame('2025-08-15', $saved->getGradingPeriod(1)->getEndDate()->format('Y-m-d'));
    }

    public function testDatesTypedWhileSwitchingAreValidated(): void
    {
        $this->createSchoolYear('2026-2027');
        $sy = $this->findSchoolYear('2026-2027');

        $this->submitEditWithPeriods(
            $sy,
            ['gradingSystem' => 'quarters'],
            [['2026-06-01', '2026-08-31'], ['2026-08-15', '2026-10-31'], ['2026-11-01', '2027-01-31'], ['2027-02-01', '2027-04-30']],
        );

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-card', 'Quarter 2 must start after Quarter 1 ends.');
        self::assertSame(GradingSystem::Terms, $this->findSchoolYear('2026-2027')->getGradingSystem());
    }

    public function testEditPageRedrawsPeriodRowsInTheBrowser(): void
    {
        $this->createSchoolYear('2026-2027');
        $id = $this->findSchoolYear('2026-2027')->getId();

        $crawler = $this->client->request('GET', '/admin/school-years/'.$id.'/edit');

        $form = $crawler->filter('form[data-controller="grading-periods"]');
        self::assertCount(1, $form);
        $prototype = $form->attr('data-grading-periods-prototype-value');
        self::assertStringContainsString('name="school_year_form[gradingPeriods][__name__][startDate]"', $prototype);
        self::assertStringContainsString('__label__', $prototype);
        self::assertCount(3, $crawler->filter('[data-grading-periods-target="rows"] .periods__row'));
    }

    public function testSwitchingIsBlockedWhileGradingIsOpen(): void
    {
        $this->createSchoolYear('2026-2027');
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Open grading')->first()->form());
        $id = $this->findSchoolYear('2026-2027')->getId();

        $this->client->request('GET', '/admin/school-years/'.$id.'/edit');
        $this->client->submitForm('Save changes', ['school_year_form[gradingSystem]' => 'quarters']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-card', 'Close grading for every period before switching between terms and quarters.');
        self::assertSame(GradingSystem::Terms, $this->findSchoolYear('2026-2027')->getGradingSystem());
        self::assertCount(3, $this->findSchoolYear('2026-2027')->getGradingPeriods());
    }

    public function testOpenAndCloseQuarterGrading(): void
    {
        $this->createSchoolYear('2026-2027');
        $crawler = $this->client->followRedirect();

        $this->client->submit($crawler->selectButton('Open grading')->first()->form());
        $this->client->followRedirect();

        self::assertTrue($this->findSchoolYear('2026-2027')->getGradingPeriod(1)->isOpen());
        self::assertContains('Opened Term 1 grading (2026-2027)', $this->activityActions());

        $crawler = $this->client->request('GET', '/admin/school-years');
        $this->client->submit($crawler->selectButton('Close grading')->form());
        self::assertFalse($this->findSchoolYear('2026-2027')->getGradingPeriod(1)->isOpen());
    }

    public function testOnlyOneSchoolYearIsCurrent(): void
    {
        $this->createSchoolYear('2025-2026');
        $this->createSchoolYear('2026-2027');
        self::assertFalse($this->findSchoolYear('2026-2027')->isCurrent());

        $crawler = $this->client->request('GET', '/admin/school-years');
        $this->client->submit($crawler->selectButton('Make current')->form());

        self::assertTrue($this->findSchoolYear('2026-2027')->isCurrent());
        self::assertFalse($this->findSchoolYear('2025-2026')->isCurrent());
    }

    public function testSchoolYearIsValidated(): void
    {
        $this->createSchoolYear('2026-2027');

        $this->client->request('GET', '/admin/school-years/new');
        $this->client->submitForm('Create school year', [
            'school_year_form[name]' => '2026-2027',
            'school_year_form[startDate]' => '2026-06-01',
            'school_year_form[endDate]' => '2026-01-01',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-card', 'already exists');
        self::assertSelectorTextContains('.form-card', 'end date must be after');
    }
}
