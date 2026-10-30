<?php

namespace App\Tests\Controller;

use App\Entity\Enrollment;
use App\Entity\GradeLevel;
use App\Entity\NutritionAssessment;
use App\Entity\NutritionMeasurement;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Sf8Submission;
use App\Entity\User;
use App\Nutrition\NutritionPeriod;
use App\Tests\Controller\Admin\AdminTestCase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class SchoolNutritionControllerTest extends AdminTestCase
{
    use ClockSensitiveTrait;

    private SchoolYear $sy;
    private School $school;
    private School $other;
    private User $principal;
    private User $adviser;
    private Section $artistic;
    private Enrollment $enrollment;

    protected function setUp(): void
    {
        parent::setUp();
        static::mockTime('2025-08-22 10:00:00');
        $this->sy = $this->schoolYear(2025, current: true);
        $this->school = $this->school('303196', 'Pagsabungan NHS', planValidUntil: new \DateTimeImmutable('2026-04-30'));
        $this->other = $this->school('303197', 'Other NHS', planValidUntil: new \DateTimeImmutable('2026-04-30'));
        $this->principal = $this->createUser('rene', User::ROLE_PRINCIPAL, 'Rene Petancio');
        $this->assign($this->school, $this->sy, $this->principal);
        $this->adviser = $this->createUser('tess', User::ROLE_TEACHER, 'Tess Reyes');
        $this->assign($this->school, $this->sy, $this->adviser, User::ROLE_TEACHER);
        $this->artistic = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Artistic')->setAdviser($this->adviser);
        $this->enrollment = new Enrollment($this->learner('120017170001', 'Abad', 'Ana'), $this->sy, $this->artistic, $this->adviser, new \DateTimeImmutable('2025-05-20'));
        $this->em->persist($this->enrollment);
        $this->em->flush();
        $this->client->loginUser($this->principal);
    }

    private function url(string $path = ''): string
    {
        return '/schools/'.$this->school->getId().$path;
    }

    private function sectionUrl(string $path = '', string $period = 'baseline'): string
    {
        return $this->url('/sections/'.$this->artistic->getId().'/nutrition/'.$period.$path);
    }

    private function saveBaseline(bool $submit = false): void
    {
        $assessment = new NutritionAssessment($this->artistic, NutritionPeriod::Baseline, new \DateTimeImmutable('2025-08-20'), $this->adviser, new \DateTimeImmutable());
        $assessment->addMeasurement((new NutritionMeasurement($assessment, $this->enrollment))->setWeightKg('40.00')->setHeightM('1.50'));
        $this->em->persist($assessment);
        if ($submit) {
            $this->em->persist(new Sf8Submission($this->artistic, NutritionPeriod::Baseline, $this->adviser, new \DateTimeImmutable('2025-08-21')));
        }
        $this->em->flush();
    }

    public function testOverviewNotStarted(): void
    {
        $this->client->request('GET', $this->url('/nutrition'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Nutritional status');
        self::assertSelectorTextContains('main', 'Grade 8 – Artistic');
        self::assertSelectorTextContains('main', 'Tess Reyes');
        self::assertSelectorTextContains('main', 'Not started');
        self::assertSelectorTextContains('.sidebar__link.is-active', 'Nutrition');
    }

    public function testOverviewCountsADraft(): void
    {
        $this->saveBaseline();

        $crawler = $this->client->request('GET', $this->url('/nutrition'));

        self::assertSelectorTextContains('main', 'Draft');
        self::assertSelectorTextContains('.nutrition-overview tbody tr:first-child', '1 / 1');
        self::assertCount(1, $crawler->filter('a[href="'.$this->sectionUrl().'"]'));
        self::assertSelectorTextContains('.nutrition-overview .attendance-subtotal', 'School total');
        // one Normal BMI and one Normal HFA in the school total
        self::assertSame('1', $crawler->filter('.nutrition-overview .attendance-subtotal td.nutrition-bmi-normal')->text());
    }

    public function testOverviewEndlinePeriod(): void
    {
        $this->saveBaseline();

        $this->client->request('GET', $this->url('/nutrition?period=endline'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Not started');
        self::assertSelectorTextContains('.nutrition-overview tbody tr:first-child', '0 / 1');
    }

    public function testOverviewCompare(): void
    {
        $this->saveBaseline();

        $crawler = $this->client->request('GET', $this->url('/nutrition?compare=1'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.nutrition-overview', '→');
        self::assertSame('1 → 0', $crawler->filter('.nutrition-overview .attendance-subtotal td.nutrition-bmi-normal')->text());
    }

    public function testSectionPageIsReadOnly(): void
    {
        $this->saveBaseline();

        $crawler = $this->client->request('GET', $this->sectionUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Baseline weighing');
        self::assertCount(0, $crawler->filter('input[name^="nutrition_sheet_form"]'));
        self::assertSelectorNotExists('form[name="sf8_submit_form"]');
        self::assertSelectorNotExists('.sf2-status button');
        self::assertCount(1, $crawler->filter('a[href="'.$this->sectionUrl('/sf8').'"]'));
        self::assertSelectorTextContains('.sidebar__link.is-active', 'Nutrition');

        $this->client->request('GET', $this->sectionUrl('/sf8'));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function testReopen(): void
    {
        $this->saveBaseline(submit: true);

        $this->client->request('GET', $this->sectionUrl());
        self::assertSelectorExists('.sf2-status button');

        $this->client->request('POST', $this->sectionUrl('/reopen'), ['_token' => $this->csrfToken('nutrition-reopen-'.$this->artistic->getId().'-baseline')]);
        self::assertResponseRedirects($this->sectionUrl());
        self::assertSame(0, $this->em->getRepository(Sf8Submission::class)->count([]));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Baseline for Grade 8 – Artistic reopened.');
    }

    public function testReopenWithBadTokenChangesNothing(): void
    {
        $this->saveBaseline(submit: true);
        $this->client->request('GET', $this->sectionUrl());

        $this->client->request('POST', $this->sectionUrl('/reopen'), ['_token' => 'nope']);

        self::assertSame(1, $this->em->getRepository(Sf8Submission::class)->count([]));
    }

    public function testReopenOfADraftSaysNotSubmitted(): void
    {
        $this->saveBaseline();
        $this->client->request('GET', $this->sectionUrl());

        $this->client->request('POST', $this->sectionUrl('/reopen'), ['_token' => $this->csrfToken('nutrition-reopen-'.$this->artistic->getId().'-baseline')]);

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--error', 'Baseline is not submitted.');
    }

    public function testAdminSeesTheOverviewAndReopens(): void
    {
        $this->saveBaseline(submit: true);
        $this->client->loginUser($this->createUser('ana_admin', User::ROLE_ADMIN));

        $this->client->request('GET', $this->url('/nutrition'));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $this->sectionUrl());
        self::assertSelectorExists('.sf2-status button');
        $this->client->request('POST', $this->sectionUrl('/reopen'), ['_token' => $this->csrfToken('nutrition-reopen-'.$this->artistic->getId().'-baseline')]);
        self::assertResponseRedirects($this->sectionUrl());
        self::assertSame(0, $this->em->getRepository(Sf8Submission::class)->count([]));
    }

    public function testAdviserCannotOpenTheSchoolPages(): void
    {
        $this->client->loginUser($this->adviser);

        $this->client->request('GET', $this->url('/nutrition'));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', $this->sectionUrl());
        self::assertResponseStatusCodeSame(403);
    }

    public function testSectionOfAnotherSchoolIs404(): void
    {
        $elsewhere = $this->newSection($this->other, $this->sy, GradeLevel::Grade8, 'Elsewhere');
        $base = $this->url('/sections/'.$elsewhere->getId().'/nutrition/baseline');

        $this->client->request('GET', $base);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $base.'/sf8');
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnknownPeriodIs404(): void
    {
        $this->client->request('GET', $this->sectionUrl('', 'midline'));

        self::assertResponseStatusCodeSame(404);
    }

    public function testPrincipalShortcut(): void
    {
        $this->client->request('GET', '/principal/nutrition');

        self::assertResponseRedirects($this->url('/nutrition'));
    }

    public function testAdminSchoolListLinksToNutrition(): void
    {
        $this->client->loginUser($this->createUser('ana_admin', User::ROLE_ADMIN));

        $crawler = $this->client->request('GET', '/admin/schools');

        self::assertCount(1, $crawler->filter('a[href="'.$this->url('/nutrition').'"]'));
    }

    public function testClassListLinksToNutrition(): void
    {
        $crawler = $this->client->request('GET', $this->url('/sections/'.$this->artistic->getId().'/learners'));

        self::assertCount(1, $crawler->filter('a[href="'.$this->sectionUrl().'"]:contains("Nutrition · SF8")'));
    }

    public function testCertifierIsSavedAndPrinted(): void
    {
        $this->client->request('GET', '/principal/school');
        self::assertSelectorTextContains('main', 'School forms');
        $this->client->submitForm('Save school profile', [
            'school_profile_form[sf8CertifierName]' => 'Ma. Luz Cabrera',
            'school_profile_form[sf8CertifierPosition]' => 'School Nurse',
        ]);
        self::assertResponseRedirects('/principal/school');

        $this->em->clear();
        $school = $this->em->getRepository(School::class)->find($this->school->getId());
        self::assertSame('Ma. Luz Cabrera', $school->getSf8CertifierName());
        self::assertSame('School Nurse', $school->getSf8CertifierPosition());

        $this->client->request('GET', $this->sectionUrl('/sf8'));
        self::assertResponseIsSuccessful();
        $file = tempnam(sys_get_temp_dir(), 'sf8test');
        try {
            file_put_contents($file, $this->client->getResponse()->getContent());
            $sheet = IOFactory::load($file)->getActiveSheet();
            self::assertSame('Ma. Luz Cabrera', $sheet->getCell('I124')->getValue());
        } finally {
            unlink($file);
        }
    }
}
